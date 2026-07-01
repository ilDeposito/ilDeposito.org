import { test, expect } from '@playwright/test';

test('homepage carica con logo e sezione canti', async ({ page }) => {
  await page.goto('/');
  await expect(page).toHaveTitle(/ilDeposito/i);
  await expect(page.locator('a[aria-label="ilDeposito - home"]')).toBeVisible();
  await expect(page.getByText('I canti più visti')).toBeVisible();
  await expect(page.locator('a[href^="/canti/"]').first()).toBeVisible();
});
