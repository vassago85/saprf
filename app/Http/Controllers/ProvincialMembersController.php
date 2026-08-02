<?php

namespace App\Http\Controllers;

use App\Models\Province;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProvincialMembersController extends Controller
{
    public function __construct(private readonly AuditLogService $auditLogService)
    {
    }

    public function index(Request $request): View
    {
        $actor = $request->user();
        $query = $this->buildQuery($request);

        $users = $query->paginate(25)->withQueryString();
        $provinces = Province::orderBy('name')->get();
        $showSaId = $actor->hasRole(['developer', 'exco', 'owner', 'admin']);
        $search = $request->input('search');

        return view('provincial-members.index', compact('users', 'provinces', 'search', 'showSaId'));
    }

    public function downloadCsv(Request $request): StreamedResponse
    {
        $actor = $request->user();
        $showSaId = $actor->hasRole(['developer', 'exco', 'owner', 'admin']);
        $query = $this->buildQuery($request);
        $users = $query->get();

        $provinceFilter = $request->input('province_id');
        $provinceName = $provinceFilter
            ? Province::find($provinceFilter)?->name ?? 'Unknown'
            : 'All_Provinces';

        $filename = 'Provincial_Members_' . str_replace(' ', '_', $provinceName) . '_' . now()->format('Y-m-d') . '.csv';

        // POPIA: log every provincial-members CSV export so admin exports are traceable.
        $this->auditLogService->log(
            $actor,
            'provincial_members.csv_exported',
            'ProvincialMembers',
            null,
            null,
            [
                'row_count' => $users->count(),
                'includes_sa_id' => $showSaId,
                'province_id' => $provinceFilter,
                'search' => $request->input('search'),
                'filename' => $filename,
            ],
        );

        return response()->streamDownload(function () use ($users, $showSaId) {
            $handle = fopen('php://output', 'w');

            $headers = ['Name', 'SAPRF Number', 'Email', 'Phone', 'Province', 'Membership Status', 'Payment Status', 'Start Date', 'Expiry Date'];
            if ($showSaId) {
                array_splice($headers, 5, 0, ['SA ID Number']);
            }
            fputcsv($handle, $headers);

            foreach ($users as $user) {
                $row = [
                    $user->name,
                    $user->membership?->saprf_number ?? '',
                    $user->email,
                    $user->phone ?? '',
                    $user->province?->name ?? '',
                    ucfirst($user->membership?->status ?? ''),
                    ucfirst($user->membership?->payment_status ?? ''),
                    $user->membership?->start_date?->format('Y-m-d') ?? '',
                    $user->membership?->expiry_date?->format('Y-m-d') ?? '',
                ];
                if ($showSaId) {
                    array_splice($row, 5, 0, [$user->sa_id_number ?? '']);
                }
                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildQuery(Request $request)
    {
        $actor = $request->user();
        $provinceIds = $actor->getAdminProvinceIds();

        // Federation-wide roles see every province; only provincial admins are
        // scoped to the provinces they sit on a committee for.
        if ($actor->hasRole(['developer', 'exco', 'owner', 'admin'])) {
            $provinceIds = null;
        }

        $query = User::with(['province', 'membership', 'roles'])
            ->whereHas('membership');

        if ($provinceIds !== null) {
            $query->whereIn('province_id', $provinceIds);
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhereHas('membership', fn ($mq) => $mq->where('saprf_number', 'like', "%{$search}%"));
            });
        }

        if ($provinceFilter = $request->input('province_id')) {
            $query->where('province_id', $provinceFilter);
        }

        return $query->orderBy('name');
    }
}
