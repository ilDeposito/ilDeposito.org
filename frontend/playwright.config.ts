import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'https://stage.ildeposito.org';
const hasAuth = !!(process.env.STAGE_AUTH_USER && process.env.STAGE_AUTH_PASS);

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  retries: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL,
    ...(hasAuth && {
      httpCredentials: {
        username: process.env.STAGE_AUTH_USER!,
        password: process.env.STAGE_AUTH_PASS!,
      },
    }),
    ignoreHTTPSErrors: true,
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
