// @ts-check
const { defineConfig } = require('@playwright/test');

const launchArgs = process.env.PLAYWRIGHT_LAUNCH_ARGS
  ? process.env.PLAYWRIGHT_LAUNCH_ARGS.split(',').map(arg => arg.trim()).filter(Boolean)
  : ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'];

module.exports = defineConfig({
  timeout: 90000,
  retries: process.env.CI ? 1 : 0,
  use: {
    baseURL: process.env.BASE_URL || 'http://localhost:8080',
    actionTimeout: 15000,
    navigationTimeout: 45000,
    ignoreHTTPSErrors: true,
    headless: process.env.PLAYWRIGHT_HEADFUL ? false : true,
    launchOptions: {
      args: launchArgs,
    },
  },
  reporter: process.env.PLAYWRIGHT_REPORT || 'list',
  testDir: 'tests/ui',
});
