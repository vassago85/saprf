import { test, expect, Page } from '@playwright/test';
import {
    DiscoverPayload,
    SessionPayload,
    assertLocal,
    authedContext,
    clickFilter,
    forgetSession,
    openDashboard,
    discoverFixtures,
    openSession,
    seedFixtures,
} from './helpers/dashboard';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8090';
assertLocal(baseURL);

let fixtures: DiscoverPayload;
const sessions: SessionPayload[] = [];

test.beforeAll(() => {
    // Prefer fixtures already seeded by dashboard-shell (same worker, file order).
    fixtures = discoverFixtures();
    if (!fixtures.dual || !fixtures.prs || !fixtures.pr22 || !fixtures.empty) {
        fixtures = seedFixtures();
    }
    for (const key of ['dual', 'prs', 'pr22', 'empty'] as const) {
        expect(fixtures[key], `${key} persona`).toBeTruthy();
    }
});

test.afterAll(() => {
    for (const session of sessions) {
        forgetSession(session.session_id);
    }
});

async function asPersona(
    browser: Parameters<typeof authedContext>[0],
    key: 'dual' | 'prs' | 'pr22' | 'empty',
    viewMode?: 'shooter' | 'admin',
): Promise<{ page: Page; cleanup: () => Promise<void> }> {
    const persona = fixtures[key]!;
    const session = openSession(persona.id, viewMode);
    sessions.push(session);
    const { context } = await authedContext(browser, baseURL, session, { allowMutations: true });
    const page = await context.newPage();
    await openDashboard(page);

    return {
        page,
        cleanup: async () => {
            await context.close();
        },
    };
}

test('dual shooter: identity, both season cards, rifles, and filter chrome', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'dual');

    await expect(page.getByRole('heading', { name: 'PW Dual Shooter' })).toBeVisible();
    await expect(page.getByRole('banner').getByText('#90001')).toBeVisible();
    await expect(page.getByRole('banner').getByText('Gauteng', { exact: true })).toBeVisible();
    await expect(page.getByText('PRS and PR22, from the disciplines you shoot')).toBeVisible();

    await expect(page.getByRole('group', { name: 'Filter upcoming matches by discipline' })).toBeVisible();
    await expect(page.getByRole('group', { name: 'Filter by discipline' })).toBeVisible();

    await expect(page.locator('article').filter({ hasText: 'Centrefire' }).first()).toBeVisible();
    await expect(page.locator('article').filter({ hasText: 'Rimfire' }).first()).toBeVisible();
    await expect(page.getByRole('heading', { name: `${fixtures.season} season` })).toBeVisible();

    await expect(page.getByRole('link', { name: /PW Centrefire Beast/ })).toBeVisible();
    await expect(page.getByRole('link', { name: /PW Rimfire Whip/ })).toBeVisible();

    await expect(page.getByText('PW Dash Dual Upcoming PRS')).toBeVisible();
    await expect(page.getByText('PW Dash Dual Upcoming PR22')).toBeVisible();
    await expect(page.getByText('PW Dash PRS Past Dual')).toBeVisible();
    await expect(page.getByText('PW Dash PR22 Past Dual')).toBeVisible();

    await cleanup();
});

test('dual shooter: next-match filter isolates PR22 and restores All', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'dual');

    await clickFilter(page, 'Filter upcoming matches by discipline', 'PR22');
    await expect(page.getByText('PW Dash Dual Upcoming PR22')).toBeVisible();
    await expect(page.getByText('PW Dash Dual Upcoming PRS')).toHaveCount(0);

    await clickFilter(page, 'Filter upcoming matches by discipline', 'PRS');
    await expect(page.getByText('PW Dash Dual Upcoming PRS')).toBeVisible();
    await expect(page.getByText('PW Dash Dual Upcoming PR22')).toHaveCount(0);

    await clickFilter(page, 'Filter upcoming matches by discipline', 'All');
    await expect(page.getByText('PW Dash Dual Upcoming PRS')).toBeVisible();
    await expect(page.getByText('PW Dash Dual Upcoming PR22')).toBeVisible();

    await cleanup();
});

test('dual shooter: results filter isolates disciplines and URL syncs', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'dual');

    await clickFilter(page, 'Filter by discipline', 'PRS');
    await expect(page).toHaveURL(/resultsFilter=PRS/);
    await expect(page.getByText('PW Dash PRS Past Dual')).toBeVisible();
    await expect(page.getByText('PW Dash PR22 Past Dual')).toHaveCount(0);

    await clickFilter(page, 'Filter by discipline', 'PR22');
    await expect(page).toHaveURL(/resultsFilter=PR22/);
    await expect(page.getByText('PW Dash PR22 Past Dual')).toBeVisible();
    await expect(page.getByText('PW Dash PRS Past Dual')).toHaveCount(0);

    await clickFilter(page, 'Filter by discipline', 'All');
    await expect(page.getByText('PW Dash PRS Past Dual')).toBeVisible();
    await expect(page.getByText('PW Dash PR22 Past Dual')).toBeVisible();

    await cleanup();
});

test('dual shooter: OOP attention and Counts for finals badge', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'dual');

    await expect(page.getByText('Needs your attention')).toBeVisible();
    await expect(page.getByText(/PR22 finals: out-of-province national needed/i)).toBeVisible();
    await expect(page.getByText(/0 of 1 shot/i)).toBeVisible();

    // Filter to PR22 so the OOP national (further out) enters the next-matches window.
    await clickFilter(page, 'Filter upcoming matches by discipline', 'PR22');
    await expect(page.getByText('PW Dash PR22 OOP National')).toBeVisible();
    await expect(page.getByText('Counts for finals').first()).toBeVisible();

    await cleanup();
});

test('PRS-only shooter: single discipline chrome, no toggles, no PR22 upcoming', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'prs');

    await expect(page.getByRole('heading', { name: 'PW PRS Only' })).toBeVisible();
    await expect(page.getByText('PRS only, from the disciplines you shoot')).toBeVisible();
    await expect(page.locator('article').filter({ hasText: 'Centrefire' })).toHaveCount(1);
    await expect(page.locator('article').filter({ hasText: 'Rimfire' })).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Filter upcoming matches by discipline' })).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Filter by discipline' })).toHaveCount(0);

    await expect(page.getByText('PW Dash PRS Only Upcoming')).toBeVisible();
    await expect(page.getByText('PW Dash PR22 Only Upcoming')).toHaveCount(0);
    await expect(page.getByText('Add centrefire rifle')).toBeVisible();
    await expect(page.getByText('Add rimfire rifle')).toHaveCount(0);

    await cleanup();
});

test('PR22-only shooter: rimfire chrome and no PRS upcoming', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'pr22');

    await expect(page.getByRole('heading', { name: 'PW PR22 Only' })).toBeVisible();
    await expect(page.getByText('PR22 only, from the disciplines you shoot')).toBeVisible();
    await expect(page.locator('article').filter({ hasText: 'Rimfire' })).toHaveCount(1);
    await expect(page.locator('article').filter({ hasText: 'Centrefire' })).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Filter by discipline' })).toHaveCount(0);

    await expect(page.getByText('PW Dash PR22 Only Upcoming')).toBeVisible();
    await expect(page.getByText('PW Dash PRS Only Upcoming')).toHaveCount(0);
    await expect(page.getByText('Add rimfire rifle')).toBeVisible();

    await cleanup();
});

test('no-scores member: both disciplines and empty rifle CTA', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'empty');

    await expect(page.getByRole('heading', { name: 'PW No Scores' })).toBeVisible();
    await expect(page.getByText('PRS and PR22, from the disciplines you shoot')).toBeVisible();
    await expect(page.getByRole('group', { name: 'Filter upcoming matches by discipline' })).toBeVisible();
    await expect(page.getByText('No results yet this season.')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Add centrefire rifle' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Add rimfire rifle' })).toBeVisible();
    // Shared upcoming pool may push "Open * NoScores" past the next-3 window —
    // assert both discipline badges still appear in the next-matches panel.
    await expect(page.locator('section').filter({ hasText: 'Next matches' }).getByText('PRS', { exact: true }).first()).toBeVisible();
    await expect(page.locator('section').filter({ hasText: 'Next matches' }).getByText('PR22', { exact: true }).first()).toBeVisible();

    await cleanup();
});

test('sidebar collapse toggles rail and persists via localStorage', async ({ browser }) => {
    const { page, cleanup } = await asPersona(browser, 'dual');
    await page.setViewportSize({ width: 1440, height: 960 });

    // Desktop-only control (`hidden lg:inline-flex`). Assert attached + force-click
    // so the suite still verifies Alpine/localStorage even if a CSS media quirk
    // makes Playwright's visibility check treat `lg:inline-flex` as hidden.
    const toggle = page.locator('button.sidebar-collapse-toggle');
    await expect(toggle).toBeVisible({ timeout: 15_000 });

    const marked = page.locator('[x-data*="sidebarCollapsed"]').first();
    await expect(marked).toBeVisible();

    await toggle.click();
    await expect.poll(async () => page.evaluate(() => localStorage.getItem('saprf.sidebarCollapsed'))).toBe('1');
    await expect(marked).toHaveAttribute('data-sidebar-collapsed', '');
    await expect(toggle).toHaveAttribute('title', 'Expand sidebar');

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('h1').first()).toBeVisible();
    await expect.poll(async () => page.evaluate(() => localStorage.getItem('saprf.sidebarCollapsed'))).toBe('1');
    await expect(page.locator('[x-data*="sidebarCollapsed"]').first()).toHaveAttribute('data-sidebar-collapsed', '');

    await page.locator('button.sidebar-collapse-toggle').click();
    await expect.poll(async () => page.evaluate(() => localStorage.getItem('saprf.sidebarCollapsed'))).toBe('0');
    await expect(page.locator('[x-data*="sidebarCollapsed"]').first()).not.toHaveAttribute('data-sidebar-collapsed', '');

    await cleanup();
});
