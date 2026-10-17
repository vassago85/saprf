import { defineConfig } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:8090';

export default defineConfig({
    testDir: './playwright',
    fullyParallel: false,
    workers: 1,
    timeout: 900_000,
    reporter: [['list']],
    use: {
        baseURL,
        trace: 'off',
        screenshot: 'off',
        video: 'off',
    },
});
