import { test, expect } from '@playwright/test';

test.describe('API state endpoint', () => {
  test('returns grid and assigns player id', async ({ request, baseURL }) => {
    const response = await request.get(`${baseURL || 'http://localhost:4000'}/api/state`);
    expect(response.ok()).toBeTruthy();
    const body = await response.json();
    expect(body.playerId).toMatch(/[a-f0-9]{32}/);
    expect(body.grid.cols).toBe(6);
    expect(body.grid.labels).toHaveLength(4);
  });
});
