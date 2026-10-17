import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';

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

test('email log and site settings explain delivery without sending mail', async ({ browser }) => {
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

    const emailLog = await context.request.get('/email-logs', { maxRedirects: 5, timeout: 20_000 });
    expect(emailLog.status(), 'email log did not load').toBe(200);
    const emailHtml = await emailLog.text();
    expect(emailHtml).toContain('not yet reported delivery');
    expect(emailHtml).toContain('Closed');
    expect(emailHtml).not.toContain('>Complete<');

    const settings = await context.request.get('/site-settings', { maxRedirects: 5, timeout: 20_000 });
    expect(settings.status(), 'site settings did not load').toBe(200);
    const settingsHtml = await settings.text();
    expect(settingsHtml).toContain('Backup notification email');
    expect(settingsHtml).toContain('your@example.com is never used');

    expect(blocked).toEqual([]);
    await context.close();
});
