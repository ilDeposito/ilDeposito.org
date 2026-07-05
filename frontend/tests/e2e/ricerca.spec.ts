import { test, expect } from '@playwright/test';

test('ricerca Pagefind mostra risultati o messaggio vuoto', async ({ page }) => {
  await page.goto('/');

  // La homepage ha due search-modal (header + hero): scopiamo a `main` per
  // evitare la strict mode violation di Playwright su locator multipli.
  const searchInput = page.locator('main [data-search-input]');
  await expect(searchInput).toBeVisible();

  // pressSequentially simula la tastiera carattere per carattere,
  // così il custom element riceve gli input events correttamente
  await searchInput.pressSequentially('bella', { delay: 50 });

  const results = page.locator('main [data-search-results]');
  // Aspetta che il container sia visibile (classe 'hidden' rimossa da Pagefind)
  await expect(results).not.toHaveClass(/\bhidden\b/, { timeout: 12_000 });
  // Almeno un <li> — con link (risultato trovato) o span (nessun risultato)
  await expect(results.locator('li').first()).toBeVisible();
});
