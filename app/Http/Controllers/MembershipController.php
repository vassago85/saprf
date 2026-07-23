<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use App\Models\Score;
use App\Models\Standing;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ChromePdfRenderer;
use App\Services\GreenQrCodePng;
use App\Services\SettingsService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class MembershipController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function myMembership(Request $request): View
    {
        $user = $request->user();
        $membership = Membership::where('user_id', $user->id)->latest()->first();
        $fee = (float) app(SettingsService::class)->get('annual_membership_fee', 500);
        $paymentsEnabled = (bool) app(SettingsService::class)->get('payments_enabled', false);

        $seasons = Standing::distinct()->pluck('season')->sort()->reverse()->values();
        if ($seasons->isEmpty()) {
            $seasons = collect([(string) now()->year]);
        }

        return view('memberships.my-membership', compact('membership', 'user', 'fee', 'paymentsEnabled', 'seasons'));
    }

    private const SORTABLE = [
        'name' => 'users.name',
        'saprf_number' => 'memberships.saprf_number',
        'type' => 'memberships.membership_type',
        'province' => 'provinces.name',
        'expiry' => 'memberships.expiry_date',
    ];

    public function index(Request $request): View
    {
        $user = $request->user();
        $isAdmin = $user->hasAnyRole(['developer', 'exco', 'owner', 'admin']);

        if (! $isAdmin) {
            $memberships = Membership::query()->where('user_id', $user->id)->with('user')->paginate(20);

            return view('memberships.index', [
                'memberships' => $memberships,
                'isAdmin' => false,
                'provinces' => collect(),
                'filters' => [],
                'sort' => 'name',
                'dir' => 'asc',
            ]);
        }

        $memberships = $this->buildIndexQuery($request)->paginate(25)->withQueryString();

        return view('memberships.index', [
            'memberships' => $memberships,
            'isAdmin' => true,
            'provinces' => \App\Models\Province::orderBy('name')->get(),
            'filters' => $request->only(['search', 'status', 'province_id']),
            'sort' => $this->resolveSort($request),
            'dir' => $this->resolveDir($request),
        ]);
    }

    public function downloadCsv(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        abort_unless($request->user()->hasAnyRole(['developer', 'exco', 'owner', 'admin']), 403);

        $memberships = $this->buildIndexQuery($request)->get();
        $filename = 'Memberships_'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($memberships) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Member', 'Email', 'Phone', 'SAPRF Number', 'Type', 'Status', 'Province', 'Start Date', 'Expiry Date']);
            foreach ($memberships as $m) {
                fputcsv($handle, [
                    $m->user?->name ?? '',
                    $m->user?->email ?? '',
                    $m->user?->phone ?? '',
                    $m->saprf_number ?? '',
                    ucfirst((string) $m->membership_type),
                    $m->effective_status_label,
                    $m->user?->province?->name ?? '',
                    $m->start_date?->format('Y-m-d') ?? '',
                    $m->expiry_date?->format('Y-m-d') ?? '',
                ]);
            }
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildIndexQuery(Request $request)
    {
        $query = Membership::query()
            ->with(['user.province'])
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->leftJoin('provinces', 'provinces.id', '=', 'users.province_id')
            ->select('memberships.*');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                    ->orWhere('users.email', 'like', "%{$search}%")
                    ->orWhere('memberships.saprf_number', 'like', "%{$search}%");
            });
        }

        // Filter by the same simplified status we display: active vs expired,
        // plus the non-member / revoked overrides — all derived from the
        // expiry date and type, not the noisy legacy status flags.
        $today = now()->startOfDay()->toDateString();
        match ($request->input('status')) {
            'active' => $query->where('memberships.membership_type', '!=', 'free')
                ->where('memberships.status', '!=', 'revoked')
                ->where(function ($q) use ($today) {
                    $q->whereNull('memberships.expiry_date')
                        ->orWhereDate('memberships.expiry_date', '>=', $today);
                }),
            'expired' => $query->where('memberships.membership_type', '!=', 'free')
                ->where('memberships.status', '!=', 'revoked')
                ->whereDate('memberships.expiry_date', '<', $today),
            'non_member' => $query->where('memberships.membership_type', 'free'),
            'revoked' => $query->where('memberships.status', 'revoked'),
            default => null,
        };

        if ($provinceId = $request->input('province_id')) {
            $query->where('users.province_id', $provinceId);
        }

        return $query->orderBy(self::SORTABLE[$this->resolveSort($request)], $this->resolveDir($request));
    }

    private function resolveSort(Request $request): string
    {
        $sort = (string) $request->input('sort', 'name');

        return array_key_exists($sort, self::SORTABLE) ? $sort : 'name';
    }

    private function resolveDir(Request $request): string
    {
        return strtolower((string) $request->input('dir')) === 'desc' ? 'desc' : 'asc';
    }

    public function show(Membership $membership): View
    {
        $this->authorize('view', $membership);

        $membership->load(['user', 'payments', 'revokedByUser']);

        return view('memberships.show', compact('membership'));
    }

    public function create(): View
    {
        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return view('memberships.create', compact('users'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Membership::class);

        $validated = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'membership_type' => ['required', 'string', 'max:50'],
            'status' => ['required', 'in:active,pending,lapsed,expired,revoked'],
            'payment_status' => ['required', 'in:paid,unpaid,partial'],
            'start_date' => ['required', 'date'],
            'expiry_date' => ['required', 'date', 'after:start_date'],
        ]);

        $membership = Membership::query()->create($validated);

        $this->auditLogService->log(
            $request->user(),
            'membership.created',
            'Membership',
            $membership->id,
            null,
            $membership->toArray(),
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership created successfully.');
    }

    public function edit(Membership $membership): View
    {
        $this->authorize('update', $membership);

        $membership->load('user');

        return view('memberships.edit', compact('membership'));
    }

    public function update(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        $validated = $request->validate([
            'status' => ['required', 'in:active,pending,lapsed,expired,revoked'],
            'payment_status' => ['required', 'in:paid,unpaid,partial'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $old = $membership->only(['status', 'payment_status', 'expiry_date']);
        $membership->update($validated);

        $this->auditLogService->log(
            $request->user(),
            'membership.updated',
            'Membership',
            $membership->id,
            $old,
            $membership->only(['status', 'payment_status', 'expiry_date']),
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership updated successfully.');
    }

    public function revoke(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        $validated = $request->validate([
            'revocation_reason' => ['required', 'string', 'max:1000'],
        ]);

        $old = $membership->only(['status', 'revoked_at', 'revocation_reason', 'revoked_by']);

        $membership->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $validated['revocation_reason'],
            'revoked_by' => $request->user()->id,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'membership.revoked',
            'Membership',
            $membership->id,
            $old,
            [
                'status' => 'revoked',
                'revocation_reason' => $validated['revocation_reason'],
            ],
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership has been revoked.');
    }

    public function reinstate(Request $request, Membership $membership): RedirectResponse
    {
        $this->authorize('update', $membership);

        if (! $membership->isRevoked()) {
            return back()->with('error', 'This membership is not revoked.');
        }

        $old = $membership->only(['status', 'revoked_at', 'revocation_reason', 'revoked_by']);

        $membership->update([
            'status' => 'active',
            'revoked_at' => null,
            'revocation_reason' => null,
            'revoked_by' => null,
        ]);

        $this->auditLogService->log(
            $request->user(),
            'membership.reinstated',
            'Membership',
            $membership->id,
            $old,
            ['status' => 'active'],
        );

        return redirect()->route('memberships.show', $membership)
            ->with('success', 'Membership has been reinstated.');
    }

    // ── Public Verification ──

    public function verify(string $saprfNumber): View
    {
        $membership = Membership::with('user')
            ->where('saprf_number', $saprfNumber)
            ->latest()
            ->first();

        return view('memberships.verify', compact('membership'));
    }

    // ── Certificate PDF ──

    public function certificate(Request $request): Response
    {
        $user = $request->user();
        $user->load('province');
        $membership = Membership::where('user_id', $user->id)
            ->where('status', 'active')
            ->where('payment_status', 'paid')
            ->latest()
            ->firstOrFail();

        $verifyUrl = url("/verify/{$membership->saprf_number}");
        $qrBase64 = app(GreenQrCodePng::class)->toDataUri($verifyUrl, 240);

        $logoPath = public_path('saprf-logo-black-text.png');
        $logoBase64 = 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath));

        $status = $membership->effective_status;
        $statusLabel = match ($status) {
            'active' => 'ACTIVE MEMBER',
            'expired' => 'EXPIRED MEMBER',
            'revoked' => 'REVOKED',
            'pending' => 'PENDING',
            'non_member' => 'NON-MEMBER',
            default => strtoupper($membership->effective_status_label),
        };
        $chipMuted = in_array($status, ['expired', 'revoked', 'pending', 'non_member'], true)
            || str_contains(strtolower((string) $membership->status), 'suspend');

        $nameLen = mb_strlen((string) $user->name);
        $memberNameSize = match (true) {
            $nameLen >= 28 => '22pt',
            $nameLen >= 22 => '26pt',
            $nameLen >= 18 => '29pt',
            default => '32pt',
        };

        $generatedAt = now()->timezone('Africa/Johannesburg');

        $viewData = [
            'user' => $user,
            'membership' => $membership,
            'qrBase64' => $qrBase64,
            'logoBase64' => $logoBase64,
            'verifyUrl' => $verifyUrl,
            'statusLabel' => $statusLabel,
            'chipMuted' => $chipMuted,
            'memberNameSize' => $memberNameSize,
            'generatedDate' => $generatedAt->format('d M Y'),
            'generatedTime' => $generatedAt->format('H:i'),
            'recordRows' => [
                ['label' => 'SAPRF NO', 'value' => $membership->saprf_number ?: '—'],
                ['label' => 'MEMBERSHIP', 'value' => ucfirst((string) ($membership->membership_type ?? 'Standard'))],
                ['label' => 'MEMBER SINCE', 'value' => $membership->start_date?->format('d M Y') ?? '—'],
                ['label' => 'VALID UNTIL', 'value' => $membership->expiry_date?->format('d M Y') ?? '—'],
                ['label' => 'PROVINCE', 'value' => $user->province?->name ?? '—'],
            ],
        ];

        $filename = "SAPRF-Certificate-{$membership->saprf_number}.pdf";
        $html = view('memberships.certificate-pdf', $viewData)->render();

        return $this->downloadHtmlAsPdf($html, $filename);
    }

    // ── Activity Report PDF ──

    public function activityReport(Request $request): Response
    {
        $user = $request->user();
        $user->load('province');
        $membership = Membership::where('user_id', $user->id)->latest()->first();

        abort_unless($membership, 404, 'No membership found.');

        $season = $request->input('season', (string) now()->year);
        $includeStandings = $request->boolean('include_standings', false);

        $scores = Score::with(['match.province', 'division'])
            ->where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->where('season', $season)->where('status', 'completed'))
            ->where('status', 'valid')
            ->orderBy('match_date')
            ->get();

        $standingsSummary = [];
        if ($includeStandings) {
            foreach (['PRS', 'PR22'] as $series) {
                $overall = Standing::where('user_id', $user->id)
                    ->where('season', $season)
                    ->where('series', $series)
                    ->whereNull('province_id')
                    ->whereNull('division_id')
                    ->first();

                if ($overall) {
                    $divisionStanding = Standing::where('user_id', $user->id)
                        ->where('season', $season)
                        ->where('series', $series)
                        ->whereNull('province_id')
                        ->whereNotNull('division_id')
                        ->with('division')
                        ->first();

                    $standingsSummary[] = [
                        'series' => $series,
                        'overall_rank' => $overall->rank,
                        'overall_points' => $overall->points,
                        'division_name' => $divisionStanding?->division?->name,
                        'division_rank' => $divisionStanding?->rank,
                        'division_points' => $divisionStanding?->points,
                    ];
                }
            }
        }

        $verifyUrl = url("/verify/{$membership->saprf_number}");
        $qrBase64 = app(GreenQrCodePng::class)->toDataUri($verifyUrl, 200);
        $logoBase64 = 'data:image/png;base64,'.base64_encode((string) file_get_contents(public_path('saprf-logo-black-text.png')));

        $status = $membership->effective_status;
        $statusLabel = match ($status) {
            'active' => 'ACTIVE MEMBER',
            'expired' => 'EXPIRED MEMBER',
            'revoked' => 'REVOKED',
            'pending' => 'PENDING',
            'non_member' => 'NON-MEMBER',
            default => strtoupper($membership->effective_status_label),
        };
        $chipMuted = in_array($status, ['expired', 'revoked', 'pending', 'non_member'], true)
            || str_contains(strtolower((string) $membership->status), 'suspend');

        $nameLen = mb_strlen((string) $user->name);
        $memberNameSize = match (true) {
            $nameLen >= 28 => '18pt',
            $nameLen >= 22 => '20pt',
            $nameLen >= 18 => '22pt',
            default => '24pt',
        };

        $generatedAt = now()->timezone('Africa/Johannesburg');

        $html = view('memberships.activity-report-pdf', [
            'user' => $user,
            'membership' => $membership,
            'season' => $season,
            'scores' => $scores,
            'includeStandings' => $includeStandings,
            'standingsSummary' => $standingsSummary,
            'qrBase64' => $qrBase64,
            'logoBase64' => $logoBase64,
            'verifyUrl' => $verifyUrl,
            'statusLabel' => $statusLabel,
            'chipMuted' => $chipMuted,
            'memberNameSize' => $memberNameSize,
            'generatedDate' => $generatedAt->format('d M Y'),
            'generatedTime' => $generatedAt->format('H:i'),
            'stats' => [
                'total' => $scores->count(),
                'prs' => $scores->filter(fn ($s) => in_array($s->match?->series ?? $s->match?->match_type, ['PRS'], true))->count(),
                'pr22' => $scores->filter(fn ($s) => in_array($s->match?->series ?? $s->match?->match_type, ['PR22'], true))->count(),
            ],
        ])->render();

        $filename = "SAPRF-Activity-Report-{$membership->saprf_number}-{$season}.pdf";

        return $this->downloadHtmlAsPdf($html, $filename);
    }

    private function downloadHtmlAsPdf(string $html, string $filename): Response
    {
        $chrome = app(ChromePdfRenderer::class);
        if ($chrome->available()) {
            try {
                return response($chrome->render($html), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'attachment; filename="'.$filename.'"',
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // DomPDF fallback: ensure font cache dir exists (remote Google Fonts need it).
        $fontDir = storage_path('fonts');
        if (! is_dir($fontDir)) {
            mkdir($fontDir, 0775, true);
        }

        $pdf = Pdf::loadHTML($html)
            ->setPaper('A4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('fontDir', $fontDir)
            ->setOption('fontCache', $fontDir);

        return $pdf->download($filename);
    }
}
