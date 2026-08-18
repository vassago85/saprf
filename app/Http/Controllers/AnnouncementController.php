<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementPriority;
use App\Enums\AnnouncementStatus;
use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\Club;
use App\Models\Division;
use App\Models\MembershipFeeTier;
use App\Models\Province;
use App\Models\SavedDistributionList;
use App\Models\User;
use App\Services\Announcements\AnnouncementPublisher;
use App\Services\Announcements\AudienceResolver;
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
 * Exco / Chair side of the Notification Centre. Members hit
 * CommunicationsController; this file owns everything that composes,
 * schedules, approves and reports on a broadcast.
 *
 * Every method is `role:developer|exco|chair` gated at the route level;
 * Gate::before auto-allows abilities for developer + exco, but Chair-only
 * transitions (e.g. self-approval refusal) are enforced by the publisher
 * service, not the policy — a Gate check alone would silently pass them.
 */
class AnnouncementController extends Controller
{
    public function __construct(
        private readonly AnnouncementPublisher $publisher,
        private readonly AudienceResolver $resolver,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function index(Request $request): View
    {
        $announcements = Announcement::query()
            ->with(['creator:id,name', 'approver:id,name'])
            ->latest('id')
            ->paginate(20);

        return view('announcements.index', [
            'announcements' => $announcements,
        ]);
    }

    public function create(): View
    {
        return view('announcements.create', $this->composerContext());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateComposer($request);
        $actor = $request->user();

        $announcement = DB::transaction(function () use ($data, $actor, $request) {
            $announcement = Announcement::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'category' => $data['category'],
                'priority' => $data['priority'],
                'requires_acknowledgement' => (bool) ($data['requires_acknowledgement'] ?? false),
                'status' => AnnouncementStatus::Draft,
                'created_by' => $actor->id,
                'expires_at' => $data['expires_at'] ?? null,
            ]);

            $this->persistAudiences($announcement, $data['audiences'] ?? []);
            $this->storeAttachments($announcement, $request);

            $this->auditLogService->log(
                $actor,
                'announcement.created',
                'Announcement',
                $announcement->id,
                null,
                ['title' => $announcement->title, 'category' => $announcement->category->value],
            );

            return $announcement;
        });

        // "Save as draft" vs "Send now". A dedicated Send button posts
        // `action=send`; anything else keeps the row as a draft.
        if ($request->input('action') === 'send') {
            return $this->sendDraft($request, $announcement);
        }

        return redirect()->route('announcements.show', $announcement)
            ->with('success', 'Draft announcement saved.');
    }

    public function show(Announcement $announcement): View
    {
        $announcement->load(['creator:id,name', 'approver:id,name', 'audiences', 'attachments']);

        $stats = null;
        $recipients = collect();

        if ($announcement->status === AnnouncementStatus::Sent) {
            $stats = $this->buildStats($announcement);
            $recipients = $this->buildRecipientTable($announcement);
        }

        return view('announcements.show', [
            'announcement' => $announcement,
            'stats' => $stats,
            'recipients' => $recipients,
        ]);
    }

    /**
     * Serve an attachment to Exco/Chair from the composer/show page — the
     * member-side route lives on CommunicationsController and enforces
     * recipient-only + exco access. This route is exco-only (route group).
     */
    public function attachment(Announcement $announcement, AnnouncementAttachment $attachment): StreamedResponse|Response
    {
        if ($attachment->announcement_id !== $announcement->id) {
            abort(404);
        }

        if (! Storage::disk('announcements')->exists($attachment->path)) {
            abort(404);
        }

        return Storage::disk('announcements')->download(
            $attachment->path,
            $attachment->filename,
            ['Content-Type' => $attachment->mime],
        );
    }

    /**
     * Delete an attachment from a draft/scheduled announcement. Blocked
     * once the announcement has been sent so the audit trail can not be
     * quietly amended.
     */
    public function destroyAttachment(Request $request, Announcement $announcement, AnnouncementAttachment $attachment): RedirectResponse
    {
        if ($attachment->announcement_id !== $announcement->id) {
            abort(404);
        }

        if (! $announcement->status->isEditable()) {
            return back()->with('error', 'Attachments cannot be removed once the announcement has been sent.');
        }

        Storage::disk('announcements')->delete($attachment->path);
        $attachment->delete();

        $this->auditLogService->log(
            $request->user(),
            'announcement.attachment_removed',
            'Announcement',
            $announcement->id,
            ['filename' => $attachment->filename],
            null,
        );

        return back()->with('success', "Removed attachment {$attachment->filename}.");
    }

    /**
     * CSV of every recipient who has NOT yet acknowledged. Only Policy
     * change / opt-in-ack announcements generate a meaningful list; for
     * others the export is empty.
     */
    public function outstandingAcknowledgementsCsv(Announcement $announcement): Response
    {
        abort_unless($announcement->requires_acknowledgement, 404);

        $rows = $announcement->recipients()
            ->with('user:id,name,email,saprf_number,province_id,club_id')
            ->whereNull('acknowledged_at')
            ->get();

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['SAPRF #', 'Name', 'Email', 'Read at', 'Recipient created at']);

        foreach ($rows as $recipient) {
            $user = $recipient->user;
            fputcsv($csv, [
                $user?->saprf_number ?? '',
                $user?->name ?? '',
                $user?->email ?? '',
                optional($recipient->read_at)?->format('Y-m-d H:i'),
                $recipient->created_at?->format('Y-m-d H:i'),
            ]);
        }

        rewind($csv);
        $body = stream_get_contents($csv);
        fclose($csv);

        $filename = 'outstanding-acknowledgements-' . $announcement->id . '.csv';

        return response($body, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    public function send(Request $request, Announcement $announcement): RedirectResponse
    {
        return $this->sendDraft($request, $announcement);
    }

    public function approve(Request $request, Announcement $announcement): RedirectResponse
    {
        $actor = $request->user();

        try {
            $this->publisher->approve($announcement, $actor);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->auditLogService->log(
            $actor,
            'announcement.approved',
            'Announcement',
            $announcement->id,
            null,
            ['approver_id' => $actor->id],
        );

        return back()->with('success', 'Approved. You can now send this announcement.');
    }

    public function cancel(Request $request, Announcement $announcement): RedirectResponse
    {
        $actor = $request->user();

        try {
            $this->publisher->cancel($announcement);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $this->auditLogService->log(
            $actor,
            'announcement.cancelled',
            'Announcement',
            $announcement->id,
            ['status' => $announcement->getOriginal('status')],
            ['status' => AnnouncementStatus::Cancelled->value],
        );

        return redirect()->route('announcements.index')
            ->with('success', 'Announcement cancelled.');
    }

    /**
     * Live audience preview called from the composer as the operator
     * toggles rules. Returns `count` + a small sample so they can spot
     * "wait, that's everyone" before hitting Send.
     */
    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audiences' => ['array'],
            'audiences.*.type' => ['required', 'string', 'in:' . implode(',', array_column(AudienceType::cases(), 'value'))],
            'audiences.*.mode' => ['required', 'string', 'in:include,exclude'],
            'audiences.*.value' => ['nullable', 'array'],
        ]);

        $rules = collect($data['audiences'] ?? [])->map(function (array $rule) {
            return [
                'type' => AudienceType::from($rule['type']),
                'mode' => AudienceMode::from($rule['mode']),
                'value' => $rule['value'] ?? [],
            ];
        });

        $preview = $this->resolver->preview($rules, sample: 10);

        return response()->json([
            'count' => $preview->count,
            'sample' => $preview->sample->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
        ]);
    }

    /**
     * Common data payload the composer view needs. Kept in one place so
     * create/edit stay identical.
     *
     * @return array<string, mixed>
     */
    private function composerContext(): array
    {
        return [
            'categories' => AnnouncementCategory::cases(),
            'priorities' => AnnouncementPriority::cases(),
            'audienceTypes' => AudienceType::cases(),
            'divisions' => Division::query()->orderBy('display_order')->orderBy('name')->get(['id', 'name']),
            'provinces' => Province::query()->orderBy('name')->get(['id', 'name']),
            'clubs' => Club::query()->orderBy('name')->get(['id', 'name']),
            'feeTiers' => MembershipFeeTier::query()->orderBy('display_order')->orderBy('name')->get(['id', 'name']),
            'roles' => ['exco', 'chair', 'admin', 'match_director', 'iprf_selector', 'provincial_admin', 'member'],
            'savedLists' => SavedDistributionList::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    private function validateComposer(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:10000'],
            'category' => ['required', 'string', 'in:' . implode(',', array_column(AnnouncementCategory::cases(), 'value'))],
            'priority' => ['nullable', 'string', 'in:normal,high'],
            'requires_acknowledgement' => ['nullable', 'boolean'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*.type' => ['required', 'string', 'in:' . implode(',', array_column(AudienceType::cases(), 'value'))],
            'audiences.*.mode' => ['required', 'string', 'in:include,exclude'],
            'audiences.*.value' => ['nullable', 'array'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => [
                'file',
                'max:10240', // 10 MB per file
                'mimetypes:application/pdf,image/jpeg,image/png,image/webp,application/vnd.openxmlformats-officedocument.wordprocessingml.document,application/msword,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/plain',
            ],
        ]);
    }

    /**
     * Persist uploaded files to the private `announcements` disk. Kept
     * separate from the audience persistence so the create/edit code
     * can call it in isolation from tests.
     */
    private function storeAttachments(Announcement $announcement, Request $request): void
    {
        $files = $request->file('attachments', []);

        if (! is_array($files) || $files === []) {
            return;
        }

        foreach ($files as $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $path = $file->store((string) $announcement->id, 'announcements');

            AnnouncementAttachment::create([
                'announcement_id' => $announcement->id,
                'path' => $path,
                'filename' => mb_substr($file->getClientOriginalName(), 0, 200),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'size' => $file->getSize(),
            ]);
        }
    }

    /**
     * @param  array<int, array{type: string, mode: string, value?: array}>  $rules
     */
    private function persistAudiences(Announcement $announcement, array $rules): void
    {
        foreach ($rules as $rule) {
            $announcement->audiences()->create([
                'type' => $rule['type'],
                'mode' => $rule['mode'],
                'value' => $rule['value'] ?? [],
            ]);
        }
    }

    private function sendDraft(Request $request, Announcement $announcement): RedirectResponse
    {
        $actor = $request->user();

        try {
            $this->publisher->sendNow($announcement);
        } catch (\RuntimeException $e) {
            return redirect()->route('announcements.show', $announcement)
                ->with('error', $e->getMessage());
        }

        $announcement->refresh();

        $this->auditLogService->log(
            $actor,
            'announcement.sent',
            'Announcement',
            $announcement->id,
            null,
            [
                'category' => $announcement->category->value,
            ],
        );

        return redirect()->route('announcements.show', $announcement)
            ->with('success', 'Announcement queued for delivery. Recipients and per-channel status will populate as the workers process the send.');
    }

    /**
     * @return array{
     *   total: int,
     *   read: int,
     *   unread: int,
     *   acknowledged: int,
     *   outstanding_acknowledgements: int,
     *   per_channel: array<string, array{sent: int, queued: int, failed: int}>,
     * }
     */
    private function buildStats(Announcement $announcement): array
    {
        $total = $announcement->recipients()->count();
        $read = $announcement->recipients()->whereNotNull('read_at')->count();
        $acknowledged = $announcement->recipients()->whereNotNull('acknowledged_at')->count();

        $perChannel = [];
        foreach (DeliveryChannel::cases() as $channel) {
            $counts = DB::table('announcement_deliveries')
                ->join('announcement_recipients', 'announcement_recipients.id', '=', 'announcement_deliveries.announcement_recipient_id')
                ->where('announcement_recipients.announcement_id', $announcement->id)
                ->where('announcement_deliveries.channel', $channel->value)
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS sent', [DeliveryStatus::Sent->value])
                ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS queued', [DeliveryStatus::Queued->value])
                ->selectRaw('SUM(CASE WHEN status IN (?,?) THEN 1 ELSE 0 END) AS failed', [DeliveryStatus::Failed->value, DeliveryStatus::Bounced->value])
                ->first();

            $perChannel[$channel->value] = [
                'sent' => (int) ($counts->sent ?? 0),
                'queued' => (int) ($counts->queued ?? 0),
                'failed' => (int) ($counts->failed ?? 0),
            ];
        }

        return [
            'total' => $total,
            'read' => $read,
            'unread' => $total - $read,
            'acknowledged' => $acknowledged,
            'outstanding_acknowledgements' => $announcement->requires_acknowledgement
                ? $total - $acknowledged
                : 0,
            'per_channel' => $perChannel,
        ];
    }

    /**
     * Build the per-recipient table for the show page: one row per user,
     * with read/ack timestamps and their per-channel delivery status
     * pivoted alongside. Capped at 200 rows so an "everyone" broadcast
     * doesn't blow up the show page; CSV export handles the full list
     * for outstanding acknowledgements.
     *
     * @return \Illuminate\Support\Collection<int, array{
     *   name: string, email: ?string, read_at: ?\Carbon\Carbon,
     *   acknowledged_at: ?\Carbon\Carbon, channels: array<string, ?string>,
     * }>
     */
    private function buildRecipientTable(Announcement $announcement): \Illuminate\Support\Collection
    {
        $recipients = $announcement->recipients()
            ->with(['user:id,name,email', 'deliveries:announcement_recipient_id,channel,status,error,sent_at'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        return $recipients->map(function ($recipient) {
            $channels = [];
            foreach (DeliveryChannel::cases() as $channel) {
                $delivery = $recipient->deliveries->firstWhere('channel', $channel);
                $channels[$channel->value] = $delivery?->status?->value;
            }

            return [
                'name' => $recipient->user?->name ?? 'Unknown user',
                'email' => $recipient->user?->email,
                'read_at' => $recipient->read_at,
                'acknowledged_at' => $recipient->acknowledged_at,
                'channels' => $channels,
            ];
        });
    }
}
