import { test, expect } from '@playwright/test';

test('lista canti e pagina singolo canto', async ({ page }) => {
  await page.goto('/canti');
  await expect(page.locator('h1')).toBeVisible();
  await expect(page.getByRole('link', { name: "Tutti i canti dell'archivio" }).first()).toBeVisible();

  // Primo link a un canto specifico (esclude /canti/elenco)
  const primoLink = page.locator('a[href^="/canti/"]:not([href="/canti/elenco"])').first();
  await expect(primoLink).toBeVisible();
  await primoLink.click();

  await expect(page.locator('h1')).toBeVisible();
  await expect(page.locator('nav[aria-label="Breadcrumb"]')).toContainText('Canti');
});
