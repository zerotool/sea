import { defineConfig } from '@playwright/test';

const apiBase = process.env.API_BASE || 'http://localhost:4000';

export default defineConfig({
  testDir: './tests/e2e',
  timeout: 30_000,
  use: {
    baseURL: apiBase,
  },
});
