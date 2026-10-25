<?php

use App\Models\AuditLog;
use App\Models\User;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();
});

/**
 * Pins the 25 Oct 2026 "admin screens overflowing on tablet / landscape
 * phone" fix. Two complementary regressions:
 *   1. Audit log index hides low-priority columns (Source, Entity) on
 *      screens below `md` so the five essentials fit at ~718px wide.
 *   2. Pagination view uses the compact Previous / Page X of Y / Next
 *      layout up through `lg` (1024px) — the shipped Tailwind pager
 *      flips to the full numeric row at `sm` (640px) and overflows
 *      every tablet-sized admin screen.
 */

// ── Audit log index: responsive column visibility ──────────────────────

it('audit log index hides Source and Entity columns on narrow screens with md:table-cell', function () {
    $admin = User::factory()->create();
    Role::findByName('admin');
    $admin->assignRole('admin');

    AuditLog::create([
        'actor_type' => AuditLog::ACTOR_USER,
        'user_id' => $admin->id,
        'action_type' => 'created',
        'entity_type' => User::class,
        'entity_id' => $admin->id,
    ]);

    $html = $this->actingAs($admin)->get(route('audit-logs.index'))->assertOk()->getContent();

    // Both <th> and <td> for Source and Entity are gated behind
    // `hidden md:table-cell`. Count the hiding class — it has to
    // appear at least four times (2 columns × thead + tbody).
    expect(substr_count($html, 'hidden md:table-cell'))->toBeGreaterThanOrEqual(4);

    // Keep the essential columns always visible — they MUST NOT have
    // the hiding class applied to their <th>s.
    expect($html)->toContain('>Date</th>');
    expect($html)->toContain('>User</th>');
    expect($html)->toContain('>Action</th>');
    expect($html)->toContain('>Subject</th>');
    expect($html)->toContain('>Details</th>');
});

// ── Custom pagination view: compact up to lg, full on desktop ──────────

it('custom pagination view hides the numeric pager below lg and shows a Page X of Y chip instead', function () {
    // Generate enough rows to force > 1 page under the default per-page.
    $admin = User::factory()->create();
    Role::findByName('admin');
    $admin->assignRole('admin');

    // Default page size on the audit-logs index is 30, so we need > 30
    // rows for $paginator->hasPages() to return true. AuditLog has no
    // factory — plain inserts work fine for this view-markup test.
    for ($i = 0; $i < 55; $i++) {
        AuditLog::create([
            'actor_type' => AuditLog::ACTOR_USER,
            'user_id' => $admin->id,
            'action_type' => 'created',
            'entity_type' => User::class,
            'entity_id' => $admin->id,
        ]);
    }

    $html = $this->actingAs($admin)
        ->get(route('audit-logs.index'))
        ->assertOk()
        ->getContent();

    // Compact block visibility: `lg:hidden` wraps the Prev / Page X of Y / Next trio.
    expect($html)->toContain('lg:hidden');

    // "Page X of Y" chip wording is the signature of the custom
    // compact variant — if the shipped `pagination::tailwind` view
    // ever gets reinstated the shipped `sm:hidden` compact variant
    // only shows plain Previous/Next with no page context.
    expect($html)->toContain('Page');
    // Shape: "…</span>  of  <span class="font-semibold text-stone-900">2</span>"
    expect($html)->toMatch('/of\s*<span class="font-semibold text-stone-900">\d+<\/span>/');

    // Full numeric pager is behind `hidden lg:flex` — not `hidden sm:flex`
    // (which was the shipped breakpoint we overrode).
    expect($html)->toContain('hidden lg:flex');
    expect($html)->not->toContain('hidden sm:flex-1 sm:flex');
});

// ── Sweep: wide admin tables apply the responsive-column pattern ───────

it('every swept admin index renders with hidden md/lg table-cell gating on at least one column', function (string $routeName) {
    $admin = User::factory()->create();
    Role::findByName('admin');
    $admin->assignRole('admin');

    // Owner role for user-management, which is owner-only. Harmless on
    // the other routes — assigning both doesn't change their behaviour.
    Role::findByName('owner');
    $admin->assignRole('owner');

    $html = $this->actingAs($admin)->get(route($routeName))->assertOk()->getContent();

    // Each swept page MUST hide at least one column below `md` — the
    // sweep's floor-level signature. Wider tables (payouts, matches,
    // user-management) also hide a second tier below `lg`, but the
    // audit-log only needs the `md` tier because its five essential
    // columns fit comfortably on tablets. If a future refactor rips
    // out the `md:table-cell` gating this test fails loudly instead
    // of the user noticing it on a 718px screenshot three weeks later.
    expect(substr_count($html, 'hidden md:table-cell'))
        ->toBeGreaterThan(0, 'Route '.$routeName.' is missing hidden md:table-cell');
})->with([
    'matches.index'              => 'matches.index',
    'user-management.index'      => 'user-management.index',
    'email-logs.index'           => 'email-logs.index',
    'scores.index'               => 'scores.index',
    'financials.transactions'    => 'financials.transactions',
    'financials.payouts'         => 'financials.payouts',
    'audit-logs.index'           => 'audit-logs.index',
]);
