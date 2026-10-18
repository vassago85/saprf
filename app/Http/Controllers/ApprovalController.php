<?php

namespace App\Http\Controllers;

use App\Models\FirearmCalibre;
use App\Models\FirearmMake;
use App\Models\FirearmModel;
use App\Models\OpticMake;
use App\Models\OpticModel;
use App\Models\Venue;
use App\Navigation\SidebarMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    private const TYPE_MAP = [
        'venue' => Venue::class,
        'firearm-make' => FirearmMake::class,
        'firearm-model' => FirearmModel::class,
        'firearm-calibre' => FirearmCalibre::class,
        'optic-make' => OpticMake::class,
        'optic-model' => OpticModel::class,
    ];

    public function index(): View
    {
        $pendingVenues = Venue::pendingApproval()->with(['province', 'submitter'])->orderBy('created_at')->get();
        $pendingFirearmMakes = FirearmMake::pendingApproval()->orderBy('created_at')->get();
        $pendingFirearmModels = FirearmModel::pendingApproval()->with('make')->orderBy('created_at')->get();
        $pendingFirearmCalibres = FirearmCalibre::pendingApproval()->orderBy('created_at')->get();
        $pendingOpticMakes = OpticMake::pendingApproval()->orderBy('created_at')->get();
        $pendingOpticModels = OpticModel::pendingApproval()->with('make')->orderBy('created_at')->get();

        $totalPending = $pendingVenues->count()
            + $pendingFirearmMakes->count()
            + $pendingFirearmModels->count()
            + $pendingFirearmCalibres->count()
            + $pendingOpticMakes->count()
            + $pendingOpticModels->count();

        // Self-heal the sidebar badge: whatever an admin lands on this page
        // with, we know the authoritative count right now. Dropping the cache
        // guarantees the next sidebar render recomputes against this truth.
        SidebarMenu::clearBadge('pending_approvals');

        return view('admin.approvals.index', compact(
            'pendingVenues',
            'pendingFirearmMakes',
            'pendingFirearmModels',
            'pendingFirearmCalibres',
            'pendingOpticMakes',
            'pendingOpticModels',
            'totalPending',
        ));
    }

    public function approve(string $type, int $id): RedirectResponse
    {
        $model = $this->resolveModel($type, $id);

        $model->update(['is_approved' => true]);

        // The sidebar caches the pending-approvals count for 60 seconds under
        // a GLOBAL key (every admin shares it), so this one forget() makes the
        // badge drop immediately on the next render instead of lingering while
        // the page body already shows "All clear". See SidebarMenu::GLOBAL_BADGES.
        SidebarMenu::clearBadge('pending_approvals');

        return redirect()->route('approvals.index')
            ->with('success', "'{$model->name}' approved.");
    }

    public function reject(string $type, int $id): RedirectResponse
    {
        $model = $this->resolveModel($type, $id);

        $name = $model->name;
        $model->delete();

        SidebarMenu::clearBadge('pending_approvals');

        return redirect()->route('approvals.index')
            ->with('success', "'{$name}' rejected and removed.");
    }

    private function resolveModel(string $type, int $id)
    {
        $class = self::TYPE_MAP[$type] ?? null;

        abort_unless($class, 404, 'Unknown approval type.');

        return $class::findOrFail($id);
    }

    public static function totalPendingCount(): int
    {
        return Venue::pendingApproval()->count()
            + FirearmMake::pendingApproval()->count()
            + FirearmModel::pendingApproval()->count()
            + FirearmCalibre::pendingApproval()->count()
            + OpticMake::pendingApproval()->count()
            + OpticModel::pendingApproval()->count();
    }
}
