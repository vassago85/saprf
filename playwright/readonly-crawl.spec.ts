import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { test, expect } from '@playwright/test';

type RoutePayload = {
    base_url: string;
    visit: { name: string | null; uri: string }[];
    skipped: { uri: string; reason: string }[];
};

type SessionPayload = {
    cookie: string;
    value: string;
    session_id: string;
    user_id: number;
};

function php(args: string[]): string {
    return execFileSync(
        'docker',
        ['exec', '-w', '/var/www', 'saprf_app', 'php', 'scripts/playwright-readonly.php', ...args],
        { encoding: 'utf8', cwd: process.cwd() },
    );
}

function assertLocal(url: string): void {
    const host = new URL(url).hostname.toLowerCase();
    const local = host === 'localhost' || host === '127.0.0.1' || host === '::1' || host.endsWith('.test');
    if (!local) {
        throw new Error(`Refusing to crawl ${url}. This suite only opens a local app.`);
    }
}

const routes = JSON.parse(php(['routes'])) as RoutePayload;
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8090';
assertLocal(baseURL);
assertLocal(routes.base_url);

let session: SessionPayload;

test.beforeAll(async () => {
    session = JSON.parse(php(['session'])) as SessionPayload;
});

test.afterAll(async () => {
    if (session?.session_id) {
        php(['forget', session.session_id]);
    }
});

test('every safe GET route renders without a server error', async ({ browser }) => {
    const context = await browser.newContext({ baseURL });
    const blocked: string[] = [];

    await context.route('**/*', (route) => {
        const method = route.request().method();
        if (method !== 'GET' && method !== 'HEAD') {
            blocked.push(`${method} ${route.request().url()}`);
            return route.abort();
        }

        return route.continue();
    });

    await context.addCookies([
        {
            name: session.cookie,
            value: session.value,
            url: baseURL,
        },
    ]);

    const page = await context.newPage();
    const probe = await page.goto('/dashboard', { waitUntil: 'domcontentloaded', timeout: 20_000 });
    expect(probe?.status() ?? 0, 'dashboard did not load').toBeLessThan(400);
    await expect(page).not.toHaveTitle(/Sign In/);

    const results: { uri: string; status: number }[] = [];

    for (const route of routes.visit) {
        const url = new URL(route.uri, baseURL).toString();
        assertLocal(url);

        try {
            const response = await context.request.get(url, { maxRedirects: 5, timeout: 20_000 });
            results.push({ uri: route.uri, status: response.status() });
        } catch {
            results.push({ uri: route.uri, status: 0 });
        }
    }

    await context.close();

    const failures = results.filter((row) => row.status >= 500 || row.status === 0);
    const denied = results.filter((row) => row.status === 401 || row.status === 403 || row.status === 404);

    writeFileSync(
        'playwright/readonly-report.json',
        JSON.stringify(
            {
                visited: results.length,
                skipped: routes.skipped,
                failures,
                denied,
                blocked_writes: blocked.length,
            },
            null,
            2,
        ),
    );

    expect(failures, failures.map((row) => `${row.status} ${row.uri}`).join('\n')).toEqual([]);
});
