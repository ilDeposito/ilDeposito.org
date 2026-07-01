import { test, expect } from '@playwright/test';

test('ricerca Pagefind restituisce risultati', async ({ page }) => {
  await page.goto('/');

  const searchInput = page.locator('[data-search-input]');
  await expect(searchInput).toBeVisible();
  await searchInput.fill('bella');

  // Pagefind carica /pagefind/pagefind.js on-demand con debounce 200ms
  const results = page.locator('[data-search-results]');
  await expect(results.locator('li a').first()).toBeVisible({ timeout: 8_000 });
});
