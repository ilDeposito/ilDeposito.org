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

test('il leggio mostra e conserva gli accordi trasposti', async ({ page }) => {
  await page.goto('/canti');

  const cardConAccordi = page.getByRole('img', { name: 'Accordi disponibili' }).first().locator('..').locator('..');
  await expect(cardConAccordi).toBeVisible();
  await cardConAccordi.locator('a[href^="/canti/"]').click();

  await page.getByRole('button', { name: 'Apri il leggio' }).click();
  const dialog = page.locator('dialog[data-lectern-dialog]');
  await expect(dialog).toBeVisible();
  await expect(dialog.locator('[data-lectern-toggle-accordi]')).toHaveAttribute('aria-pressed', 'true');

  const contenuto = dialog.locator('[data-lectern-content]');
  const tonalitaOriginale = await contenuto.textContent();
  await dialog.locator('[data-lectern-transpose="1"]').click();
  await expect(contenuto).not.toHaveText(tonalitaOriginale ?? '');

  await dialog.locator('[data-lectern-toggle-accordi]').click();
  await expect(dialog.locator('[data-lectern-toggle-accordi]')).toHaveAttribute('aria-pressed', 'false');
  await dialog.locator('[data-lectern-toggle-accordi]').click();
  await expect(contenuto).not.toHaveText(tonalitaOriginale ?? '');

  await dialog.locator('[data-lectern-theme]').click();
  await expect(dialog.locator('.lectern-shell')).toHaveAttribute('data-theme', 'dark');
  await dialog.locator('[data-lectern-close]').click();
  await expect(dialog).not.toBeVisible();
});
