export const prerender = false;

import type { APIRoute } from 'astro';

const tag = '[modulo_contatti]';

export const POST: APIRoute = async ({ request, redirect, clientAddress }) => {
  console.log(`${tag} POST ricevuto da ${clientAddress}`);

  // --- Lettura form data ---
  let data: FormData;
  try {
    data = await request.formData();
  } catch (err) {
    console.error(`${tag} Impossibile leggere FormData:`, err);
    return redirect('/contatti?error=invio_fallito', 302);
  }

  const nome = (data.get('nome') as string | null)?.trim() ?? '';
  const email = (data.get('email') as string | null)?.trim() ?? '';
  const messaggio = (data.get('messaggio') as string | null)?.trim() ?? '';

  console.log(`${tag} Campi ricevuti — nome: ${nome.length}ch, email: ${email.length}ch, messaggio: ${messaggio.length}ch`);

  // --- Validazione ---
  if (!nome || !email || !messaggio) {
    const mancanti = [!nome && 'nome', !email && 'email', !messaggio && 'messaggio'].filter(Boolean);
    console.warn(`${tag} Validazione fallita — campi mancanti: ${mancanti.join(', ')}`);
    return redirect('/contatti?error=campi_mancanti', 302);
  }
  console.log(`${tag} Validazione OK`);

  // --- Configurazione Drupal ---
  // import.meta.env: dev → da frontend/.env via Vite; prod → da process.env via Node adapter
  const drupalUrl = (import.meta.env.DRUPAL_API_URL as string) || process.env.DRUPAL_API_URL || '';
  const drupalUser = (import.meta.env.DRUPAL_API_USER as string) || process.env.DRUPAL_API_USER || '';
  const drupalPass = (import.meta.env.DRUPAL_API_PASS as string) || process.env.DRUPAL_API_PASS || '';

  if (!drupalUrl || !drupalUser || !drupalPass) {
    console.error(`${tag} Configurazione mancante — DRUPAL_API_URL: ${!!drupalUrl}, DRUPAL_API_USER: ${!!drupalUser}, DRUPAL_API_PASS: ${!!drupalPass}`);
    return redirect('/contatti?error=invio_fallito', 302);
  }

  const endpoint = `${drupalUrl}/jsonapi/ildeposito_contatto/modulo_contatti`;
  console.log(`${tag} Chiamata JSON:API → ${endpoint}`);

  const credentials = Buffer.from(`${drupalUser}:${drupalPass}`).toString('base64');

  // --- Chiamata Drupal JSON:API ---
  let res: Response;
  try {
    res = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/vnd.api+json',
        'Accept': 'application/vnd.api+json',
        'Authorization': `Basic ${credentials}`,
      },
      body: JSON.stringify({
        data: {
          type: 'ildeposito_contatto--modulo_contatti',
          attributes: {
            field_nome: nome,
            field_email: email,
            field_messaggio: messaggio,
          },
        },
      }),
    });
  } catch (err) {
    console.error(`${tag} Errore di rete verso Drupal (${endpoint}):`, err);
    return redirect('/contatti?error=invio_fallito', 302);
  }

  console.log(`${tag} Risposta Drupal: HTTP ${res.status}`);

  if (!res.ok) {
    const body = await res.text();
    console.error(`${tag} Drupal ha risposto con errore ${res.status}:`);
    // Tenta di parsare il body JSON:API per estrarre i dettagli dell'errore
    try {
      const parsed = JSON.parse(body);
      const errors = parsed?.errors ?? [];
      errors.forEach((e: Record<string, unknown>) =>
        console.error(`${tag}   → [${e.status}] ${e.title}: ${e.detail}`)
      );
    } catch {
      console.error(`${tag}   → Body grezzo: ${body.slice(0, 500)}`);
    }
    return redirect('/contatti?error=invio_fallito', 302);
  }

  console.log(`${tag} Entità creata con successo`);
  return redirect('/contatti?inviato=1', 302);
};
