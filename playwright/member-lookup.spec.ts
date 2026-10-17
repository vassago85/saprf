import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';

type SessionPayload = {
    cookie: string;
    value: string;
    session_id: string;
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
        throw new Error(`Refusing to open ${url}. This suite only opens a local app.`);
    }
}

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8090';
assertLocal(baseURL);

let session: SessionPayload;

test.beforeAll(async () => {
    session = JSON.parse(php(['session'])) as SessionPayload;
});

test.afterAll(async () => {
    if (session?.session_id) {
        php(['forget', session.session_id]);
    }
});

test('staff forms search members by name and show the number in brackets', async ({ browser }) => {
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

    await context.addCookies([{ name: session.cookie, value: session.value, url: baseURL }]);

    for (const path of ['/memberships/create', '/appearances/create', '/provincial-committees/create']) {
        const response = await context.request.get(path, { maxRedirects: 5, timeout: 20_000 });
        expect(response.status(), path).toBe(200);
        expect(await response.text()).toContain('Search by name or number');
    }

    const search = await context.request.get('/members/search?q=an', { maxRedirects: 0, timeout: 20_000 });
    expect(search.status()).toBe(200);
    const body = await search.json();
    expect(Array.isArray(body.results)).toBe(true);
    for (const person of body.results) {
        expect(person.email).toBeUndefined();
        if (person.saprf_number) {
            expect(person.label).toBe(`${person.name} (${person.saprf_number})`);
        }
    }

    expect(blocked).toEqual([]);
    await context.close();
});
