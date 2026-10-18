import { test, expect } from '@playwright/test';
import {
    DiscoverPayload,
    SessionPayload,
    assertLocal,
    authedContext,
    forgetSession,
    openDashboard,
    openSession,
    seedFixtures,
} from './helpers/dashboard';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8090';
assertLocal(baseURL);

let fixtures: DiscoverPayload;
const sessions: SessionPayload[] = [];

test.beforeAll(() => {
    // Always reseed once for this worker; shooter specs discover (do not reseed).
    fixtures = seedFixtures();
    for (const key of ['admin', 'owner', 'md', 'pa', 'dual'] as const) {
        expect(fixtures[key], `${key} persona`).toBeTruthy();
    }
});

test.afterAll(() => {
    for (const session of sessions) {
        forgetSession(session.session_id);
    }
});

async function visitAs(browser: Parameters<typeof authedContext>[0], key: 'admin' | 'owner' | 'md' | 'pa' | 'dual', viewMode?: 'admin' | 'shooter') {
    const persona = fixtures[key]!;
    const session = openSession(persona.id, viewMode);
    sessions.push(session);
    const { context } = await authedContext(browser, baseURL, session, { allowMutations: true });
    const page = await context.newPage();
    await openDashboard(page);
    return { page, context, persona };
}

test('admin shell: greeting, KPIs, panels, season select', async ({ browser }) => {
    const { page, context, persona } = await visitAs(browser, 'admin', 'admin');

    await expect(page.getByText(`Welcome back, ${persona.name.split(' ')[0]}`)).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Dashboard' })).toBeVisible();
    await expect(page.getByLabel('Season')).toBeVisible();
    await expect(page.getByRole('link', { name: 'New match' })).toBeVisible();

    await expect(page.getByText('Pending memberships')).toBeVisible();
    await expect(page.getByText('Upcoming matches')).toBeVisible();
    await expect(page.getByText('Pending scores')).toBeVisible();
    await expect(page.getByText('MD payouts pending')).toBeVisible();
    await expect(page.getByText('Upcoming events')).toBeVisible();
    await expect(page.getByText('Recent activity')).toBeVisible();

    await context.close();
});

test('owner shell: membership + income KPIs and pricing strip', async ({ browser }) => {
    const { page, context } = await visitAs(browser, 'owner', 'admin');

    await expect(page.getByText('Active members')).toBeVisible();
    await expect(page.getByText('Lapsing in 30 days')).toBeVisible();
    await expect(page.getByText('Season income')).toBeVisible();
    await expect(page.getByText('Pricing & rules')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Edit pricing' })).toBeVisible();

    await context.close();
});

test('match director shell: create match + upload scores actions', async ({ browser }) => {
    const { page, context } = await visitAs(browser, 'md', 'admin');

    await expect(page.getByRole('link', { name: 'Create match' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Upload scores' })).toBeVisible();
    await expect(page.getByRole('link', { name: /My matches/ })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Pending registrations' })).toBeVisible();

    await context.close();
});

test('provincial admin shell: province member KPIs', async ({ browser }) => {
    const { page, context } = await visitAs(browser, 'pa', 'admin');

    await expect(page.getByText(/Members in Gauteng|Members in your province/)).toBeVisible();
    await expect(page.getByText('Active memberships')).toBeVisible();
    await expect(page.getByText('Committee seats')).toBeVisible();

    await context.close();
});

test('staff can switch into shooter mode and see Livewire dashboard', async ({ browser }) => {
    const { page, context } = await visitAs(browser, 'admin', 'shooter');

    await expect(page.getByText('Shooter mode')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Switch to Admin' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'PW Admin' })).toBeVisible();
    await expect(page.getByText('Next matches')).toBeVisible();
    await expect(page.getByText('Find a match')).toBeVisible();

    await context.close();
});

test('pure member never sees staff shell KPIs', async ({ browser }) => {
    const { page, context } = await visitAs(browser, 'dual');

    await expect(page.getByRole('heading', { name: 'PW Dual Shooter' })).toBeVisible();
    await expect(page.getByText('Pending memberships')).toHaveCount(0);
    await expect(page.getByText('Season income')).toHaveCount(0);
    await expect(page.getByText('MD payouts pending')).toHaveCount(0);
    await expect(page.getByLabel('Season')).toHaveCount(0);

    await context.close();
});
