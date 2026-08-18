<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * One-click email unsubscribe (RFC 8058) for federation announcements.
 *
 * The route is `signed`, so anyone with a valid link — pasted from the
 * email header, clicked in Gmail's "Unsubscribe" prompt, or POSTed by
 * Gmail directly via `List-Unsubscribe-Post: List-Unsubscribe=One-Click`
 * — can act on their own behalf without a login session.
 *
 * Behaviour:
 *   - If the source announcement's category is non-mandatory, we mute
 *     just that category. The recipient probably wanted to shut *this
 *     kind of email* up, not lose Policy-change notices.
 *   - If it's mandatory (Policy change / Urgent), muting the category
 *     is a no-op because mandatory categories bypass mutes. To respect
 *     their intent we mute **every non-mandatory category instead** so
 *     future non-critical emails stop. Mandatory notices still go out —
 *     they carry compliance weight and members are contractually opted
 *     in via membership.
 *
 * We do NOT delete their notification_preferences row on POST — Gmail
 * will retry a failed one-click, so the endpoint has to be idempotent.
 */
class EmailUnsubscribeController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(Request $request, User $user): View
    {
        $categoryValue = $request->query('category');
        $category = $categoryValue !== null
            ? AnnouncementCategory::tryFrom((string) $categoryValue)
            : null;

        [$mutedCategories, $mode] = $this->mutedCategoriesFor($category);

        $pref = NotificationPreference::firstOrNew(['user_id' => $user->id]);

        $existing = $pref->muted_email_categories ?? [];
        $merged = collect($existing)
            ->merge($mutedCategories)
            ->unique()
            ->values()
            ->all();

        $pref->fill([
            'muted_email_categories' => $merged,
            'push_enabled' => $pref->exists ? $pref->push_enabled : true,
        ])->save();

        // Audit against the user themself as actor — the signed URL is
        // effectively their permission to act, even without a session.
        $this->auditLogService->log(
            $user,
            'notification.unsubscribed_via_email',
            'User',
            $user->id,
            null,
            [
                'source_category' => $category?->value,
                'muted_now' => $mutedCategories,
                'mode' => $mode,
            ],
        );

        return view('email.unsubscribed', [
            'user' => $user,
            'category' => $category,
            'muted' => $mutedCategories,
            'mode' => $mode,
        ]);
    }

    /**
     * Work out which categories to add to the mute list.
     *
     * @return array{0: array<int, string>, 1: string}
     *         [category values to add, 'single' or 'all_non_mandatory']
     */
    private function mutedCategoriesFor(?AnnouncementCategory $category): array
    {
        // No category on the URL → treat as "unsubscribe from everything
        // you can". Same fallback used for mandatory categories, since
        // muting a mandatory one is a no-op.
        if ($category === null || $category->isMandatory()) {
            $muted = collect(AnnouncementCategory::cases())
                ->reject(fn (AnnouncementCategory $c) => $c->isMandatory())
                ->map(fn (AnnouncementCategory $c) => $c->value)
                ->values()
                ->all();

            return [$muted, 'all_non_mandatory'];
        }

        return [[$category->value], 'single'];
    }
}
