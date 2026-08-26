<?php

namespace App\Http\Controllers\Exco;

use App\Enums\DisciplinaryCaseStatus;
use App\Http\Controllers\Controller;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseAttachment;
use App\Models\DisciplinaryCaseNote;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Disciplinary case register — POPIA-sensitive, ExCo/Chair only.
 *
 * Files land on the private `disciplinary` disk (never `public`) and
 * are served through a route on this controller after the role
 * middleware has already confirmed the caller. Note bodies and case
 * summaries are stored as plain text so they show up in a diff-style
 * audit trail via `AuditLogService` without needing a bespoke
 * viewer.
 */
class DisciplinaryCaseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $status = $request->query('status', 'all');
        $valid = array_column(DisciplinaryCaseStatus::cases(), 'value');
        $status = in_array($status, $valid, true) ? $status : 'all';

        $query = DisciplinaryCase::query()
            ->with(['subject:id,name', 'creator:id,name'])
            ->orderByDesc('id');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        return view('exco.disciplinary.index', [
            'cases' => $query->paginate(30)->withQueryString(),
            'statuses' => DisciplinaryCaseStatus::cases(),
            'currentStatus' => $status,
        ]);
    }

    public function create(): View
    {
        return view('exco.disciplinary.form', [
            'case' => null,
            'statuses' => DisciplinaryCaseStatus::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateForm($request);

        $case = DB::transaction(function () use ($data, $request) {
            $case = DisciplinaryCase::create([
                'reference' => DisciplinaryCase::nextReference(),
                'subject_user_id' => $data['subject_user_id'] ?? null,
                'subject_name' => $data['subject_name'] ?? null,
                'title' => $data['title'],
                'summary' => $data['summary'] ?? null,
                'status' => $data['status'],
                'opened_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            $this->auditLogService->log(
                $request->user(),
                'disciplinary_case.created',
                'DisciplinaryCase',
                $case->id,
                null,
                [
                    'reference' => $case->reference,
                    'subject' => $case->subjectLabel(),
                    'status' => $case->status->value,
                ],
            );

            return $case;
        });

        return redirect()->route('exco.disciplinary.show', $case)
            ->with('success', "Case {$case->reference} opened.");
    }

    public function show(DisciplinaryCase $case): View
    {
        $case->load([
            'subject:id,name',
            'creator:id,name',
            'notes.creator:id,name',
            'attachments.uploader:id,name',
            'actions' => fn ($q) => $q->orderBy('status')->orderBy('due_on'),
            'actions.assignee:id,name',
            'agendaItems.meeting:id,title,scheduled_at',
        ]);

        return view('exco.disciplinary.show', [
            'case' => $case,
            'statuses' => DisciplinaryCaseStatus::cases(),
        ]);
    }

    public function edit(DisciplinaryCase $case): View
    {
        return view('exco.disciplinary.form', [
            'case' => $case,
            'statuses' => DisciplinaryCaseStatus::cases(),
        ]);
    }

    public function update(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $this->validateForm($request);

        $original = $case->only(['title', 'status', 'subject_user_id', 'subject_name']);

        $case->update([
            'subject_user_id' => $data['subject_user_id'] ?? null,
            'subject_name' => $data['subject_name'] ?? null,
            'title' => $data['title'],
            'summary' => $data['summary'] ?? null,
            'status' => $data['status'],
            'closed_at' => $data['status'] === DisciplinaryCaseStatus::Closed->value
                ? ($case->closed_at ?? now())
                : null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'disciplinary_case.updated',
            'DisciplinaryCase',
            $case->id,
            $original,
            $case->only(['title', 'status', 'subject_user_id', 'subject_name']),
        );

        return redirect()->route('exco.disciplinary.show', $case)
            ->with('success', 'Case updated.');
    }

    /**
     * Deletion is only permitted while the case is empty — otherwise
     * the timeline of who added what would disappear. Closing (status
     * = closed) is the usual way to retire a case.
     */
    public function destroy(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        if ($case->notes()->exists() || $case->attachments()->exists()) {
            return back()->with('error', 'Cannot delete a case that has notes or attachments. Close it instead.');
        }

        $snapshot = [
            'reference' => $case->reference,
            'title' => $case->title,
        ];

        $case->delete();

        $this->auditLogService->log(
            $request->user(),
            'disciplinary_case.deleted',
            'DisciplinaryCase',
            $case->id,
            $snapshot,
            null,
        );

        return redirect()->route('exco.disciplinary.index')
            ->with('success', "Case {$snapshot['reference']} deleted.");
    }

    // ── Notes ──────────────────────────────────────────────────────

    public function storeNote(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:10000'],
        ]);

        $note = DisciplinaryCaseNote::create([
            'case_id' => $case->id,
            'body' => $data['body'],
            'created_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'disciplinary_case.note_added',
            'DisciplinaryCase',
            $case->id,
            null,
            ['note_id' => $note->id, 'reference' => $case->reference],
        );

        return redirect()->route('exco.disciplinary.show', $case)
            ->with('success', 'Note added.');
    }

    /**
     * A note may only be deleted by its author (or a developer via
     * Gate::before). ExCo peers can add clarifying notes but cannot
     * silently rewrite a colleague's contribution.
     */
    public function destroyNote(Request $request, DisciplinaryCase $case, DisciplinaryCaseNote $note): RedirectResponse
    {
        abort_unless($note->case_id === $case->id, 404);

        $actor = $request->user();
        if ($note->created_by !== $actor->id && ! $actor->hasRole('developer')) {
            return back()->with('error', 'Only the note author (or a developer) may remove this note.');
        }

        $note->delete();

        return redirect()->route('exco.disciplinary.show', $case)
            ->with('success', 'Note removed.');
    }

    // ── Attachments ────────────────────────────────────────────────

    public function uploadAttachment(Request $request, DisciplinaryCase $case): RedirectResponse
    {
        $request->validate([
            'file' => [
                'required',
                'file',
                'max:20480', // 20 MB — evidentiary docs and photos
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/plain',
            ],
        ]);

        $file = $request->file('file');
        $path = $file->store((string) $case->id, 'disciplinary');

        $attachment = DisciplinaryCaseAttachment::create([
            'case_id' => $case->id,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'disciplinary_case.attachment_uploaded',
            'DisciplinaryCase',
            $case->id,
            null,
            [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->filename,
                'size' => $attachment->size,
            ],
        );

        return redirect()->route('exco.disciplinary.show', $case)
            ->with('success', 'Attachment uploaded.');
    }

    public function downloadAttachment(Request $request, DisciplinaryCase $case, DisciplinaryCaseAttachment $attachment): StreamedResponse|Response
    {
        abort_unless($attachment->case_id === $case->id, 404);

        if (! Storage::disk('disciplinary')->exists($attachment->path)) {
            abort(404);
        }

        $this->auditLogService->log(
            $request->user(),
            'disciplinary_case.attachment_downloaded',
            'DisciplinaryCase',
            $case->id,
            null,
            [
                'attachment_id' => $attachment->id,
                'filename' => $attachment->filename,
            ],
        );

        return Storage::disk('disciplinary')->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime],
        );
    }

    public function destroyAttachment(Request $request, DisciplinaryCase $case, DisciplinaryCaseAttachment $attachment): RedirectResponse
    {
        abort_unless($attachment->case_id === $case->id, 404);

        Storage::disk('disciplinary')->delete($attachment->path);
        $attachment->delete();

        $this->auditLogService->log(
            $request->user(),
            'disciplinary_case.attachment_removed',
            'DisciplinaryCase',
            $case->id,
            ['filename' => $attachment->filename],
            null,
        );

        return redirect()->route('exco.disciplinary.show', $case)
            ->with('success', 'Attachment removed.');
    }

    /**
     * Subject-picker typeahead — narrow (id + name only). Route-gated
     * to ExCo already; deliberately does not leak email or phone. Two
     * character minimum so the endpoint cannot dump the whole member
     * roster in a single hit.
     */
    public function subjectSearch(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%' . $term . '%';

        $users = User::query()
            ->select('users.id', 'users.name')
            ->leftJoin('memberships', 'memberships.user_id', '=', 'users.id')
            ->addSelect('memberships.saprf_number as saprf_number')
            ->where('users.is_active', true)
            ->where(function ($q) use ($like, $term): void {
                $q->where('users.name', 'like', $like)
                    ->orWhere('memberships.saprf_number', 'like', $term . '%');
            })
            ->orderBy('users.name')
            ->limit(15)
            ->get();

        return response()->json([
            'results' => $users->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'saprf_number' => $u->saprf_number,
            ])->values(),
        ]);
    }

    private function validateForm(Request $request): array
    {
        $data = $request->validate([
            'subject_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'subject_name' => ['nullable', 'string', 'max:200'],
            'title' => ['required', 'string', 'max:200'],
            'summary' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', 'string', 'in:' . implode(',', array_column(DisciplinaryCaseStatus::cases(), 'value'))],
        ]);

        if (empty($data['subject_user_id']) && empty($data['subject_name'])) {
            abort(422, 'A case needs either a linked member or a subject name.');
        }

        return $data;
    }
}
