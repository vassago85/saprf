<?php

namespace App\Http\Controllers\Exco;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Manages the ExCo committee roster and each member's named position
 * (Chair, Secretary, Treasurer, Events Schedule, Rules & Technical,
 * Legal Adviser, PR22 Chair, etc.).
 *
 * Positions are stored as a free-text string on the user (see the
 * add_exco_position_to_users_table migration for the reasoning). This
 * controller is the only place the field is written from — the general
 * users admin has no knowledge of ExCo portfolios.
 *
 * Route middleware (`role:developer|exco|chair`) already gates every
 * method here, so no per-action policy call is needed.
 */
class ExcoMemberController extends Controller
{
    /**
     * Suggested position labels offered as autocomplete on the edit
     * form. This is a hint, not a validation list — the field is still
     * free-text so a new portfolio (e.g. a newly created sub-committee
     * chair) can be added without a code deploy.
     *
     * @var list<string>
     */
    public const SUGGESTED_POSITIONS = [
        'Chair',
        'Vice Chair',
        'Secretary',
        'Treasurer',
        'Events Schedule',
        'Rules & Technical',
        'Legal Adviser',
        'PR22 Chair',
        'Member',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    /**
     * List every user with the exco or chair role, alphabetised by
     * name, with an inline "position" edit form per row.
     */
    public function index(): View
    {
        $members = User::query()
            ->role(['exco', 'chair'])
            ->orderBy('name')
            ->get();

        return view('exco.members.index', [
            'members' => $members,
            'suggestedPositions' => self::SUGGESTED_POSITIONS,
        ]);
    }

    /**
     * Update a single ExCo member's position. The route-model-bound
     * user must actually hold an exco/chair role — otherwise this
     * would be a back door into editing any user's arbitrary metadata.
     */
    public function update(Request $request, User $user): RedirectResponse
    {
        if (! $user->hasAnyRole(['exco', 'chair'])) {
            abort(404);
        }

        $validated = $request->validate([
            'exco_position' => ['nullable', 'string', 'max:100'],
        ]);

        $before = $user->exco_position;
        $after = trim((string) ($validated['exco_position'] ?? '')) ?: null;

        if ($before === $after) {
            return redirect()->route('exco.members.index')
                ->with('info', 'No change — the position is already set to that value.');
        }

        $user->update(['exco_position' => $after]);

        $this->auditLogService->log(
            $request->user(),
            'exco_member.position_updated',
            'User',
            $user->id,
            ['exco_position' => $before],
            ['exco_position' => $after],
        );

        $label = $after ?: 'no portfolio';

        return redirect()->route('exco.members.index')
            ->with('success', "Updated {$user->name} → {$label}.");
    }
}
