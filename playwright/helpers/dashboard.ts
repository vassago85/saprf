import { execFileSync } from 'node:child_process';
import { Browser, BrowserContext, expect, Page } from '@playwright/test';

export type SessionPayload = {
    cookie: string;
    value: string;
    session_id: string;
    user_id: number;
    name: string;
    view_mode?: string | null;
};

export type Persona = {
    id: number;
    name: string;
    email: string;
    saprf_number: string | null;
    province: string | null;
    roles: string[];
    can_switch: boolean;
};

export type DiscoverPayload = {
    base_url: string;
    season: string;
    dual: Persona | null;
    prs: Persona | null;
    pr22: Persona | null;
    empty: Persona | null;
    admin: Persona | null;
    owner: Persona | null;
    md: Persona | null;
    pa: Persona | null;
};

export function assertLocal(url: string): void {
    const host = new URL(url).hostname.toLowerCase();
    const local = host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.endsWith('.test');
    if (!local) {
        throw new Error(`Refusing to open ${url}. This suite only opens a local app.`);
    }
}

export function php(args: string[]): string {
    return execFileSync(
        'docker',
        ['exec', '-w', '/var/www', 'saprf_app', 'php', 'scripts/playwright-shooter-dashboard.php', ...args],
        { encoding: 'utf8', cwd: process.cwd() },
    );
}

export function seedFixtures(): DiscoverPayload {
    return JSON.parse(php(['seed'])) as DiscoverPayload;
}

export function discoverFixtures(): DiscoverPayload {
    return JSON.parse(php(['discover'])) as DiscoverPayload;
}

export function openSession(userId: number, viewMode?: 'shooter' | 'admin'): SessionPayload {
    const args = viewMode ? ['session', String(userId), viewMode] : ['session', String(userId)];
    return JSON.parse(php(args)) as SessionPayload;
}

export function forgetSession(sessionId: string): void {
    if (!sessionId) return;
    php(['forget', sessionId]);
}

export async function authedContext(
    browser: Browser,
    baseURL: string,
    session: SessionPayload,
    options?: { allowMutations?: boolean },
): Promise<{ context: BrowserContext; blocked: string[] }> {
    const context = await browser.newContext({
        baseURL,
        viewport: { width: 1440, height: 960 },
    });
    const blocked: string[] = [];

    if (!options?.allowMutations) {
        await context.route('**/*', (route) => {
            const method = route.request().method();
            if (method !== 'GET' && method !== 'HEAD') {
                // Livewire needs POST for wire:click — allow those.
                const url = route.request().url();
                if (url.includes('/livewire/')) {
                    return route.continue();
                }
                blocked.push(`${method} ${url}`);
                return route.abort();
            }
            return route.continue();
        });
    }

    await context.addCookies([{ name: session.cookie, value: session.value, url: baseURL }]);

    return { context, blocked };
}

export async function openDashboard(page: Page): Promise<void> {
    const response = await page.goto('/dashboard', { waitUntil: 'domcontentloaded', timeout: 60_000 });
    expect(response, 'dashboard response').toBeTruthy();
    expect(response!.status(), `dashboard status was ${response!.status()}`).toBeLessThan(400);
    await expect(page).not.toHaveURL(/\/login|\/password\/force-change|\/profile$/);
    // Prefer a concrete dashboard landmark over networkidle (Livewire/poll keeps sockets open).
    await expect(page.locator('h1').first()).toBeVisible({ timeout: 30_000 });
}

export async function clickFilter(page: Page, groupLabel: string, label: string): Promise<void> {
    const group = page.getByRole('group', { name: groupLabel });
    const button = group.getByRole('button', { name: label, exact: true });
    await button.click();
    await expect(button).toHaveAttribute('aria-pressed', 'true', { timeout: 15_000 });
}
