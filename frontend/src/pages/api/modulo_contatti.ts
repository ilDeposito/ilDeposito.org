export const prerender = false;

import type { APIRoute } from 'astro';

const tag = '[modulo_contatti]';

const json = (body: object, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

export const POST: APIRoute = async ({ request, clientAddress }) => {
  console.log(`${tag} POST ricevuto da ${clientAddress}`);

  // --- Lettura form data ---
  let data: FormData;
  try {
    data = await request.formData();
  } catch (err) {
    console.error(`${tag} Impossibile leggere FormData:`, err);
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  const honeypot = (data.get('website') as string | null) ?? '';
  if (honeypot) {
    // Bot ha compilato il campo nascosto: risposta silenziosamente positiva per non rivelare il filtro
    console.warn(`${tag} Honeypot attivato — richiesta scartata`);
    return json({ ok: true });
  }

  const nome = (data.get('nome') as string | null)?.trim() ?? '';
  const email = (data.get('email') as string | null)?.trim() ?? '';
  const messaggio = (data.get('messaggio') as string | null)?.trim() ?? '';

  console.log(`${tag} Campi ricevuti — nome: ${nome.length}ch, email: ${email.length}ch, messaggio: ${messaggio.length}ch`);

  // --- Validazione ---
  if (!nome || !email || !messaggio) {
    const mancanti = [!nome && 'nome', !email && 'email', !messaggio && 'messaggio'].filter(Boolean);
    console.warn(`${tag} Validazione fallita — campi mancanti: ${mancanti.join(', ')}`);
    return json({ ok: false, error: 'campi_mancanti' }, 400);
  }

  if (nome.length < 2) {
    console.warn(`${tag} Validazione fallita — nome troppo corto: ${nome.length}ch`);
    return json({ ok: false, error: 'nome_troppo_corto' }, 400);
  }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  if (!emailRegex.test(email)) {
    console.warn(`${tag} Validazione fallita — email non valida: ${email}`);
    return json({ ok: false, error: 'email_non_valida' }, 400);
  }

  const righe = messaggio.split('\n').length;
  if (righe > 200) {
    console.warn(`${tag} Validazione fallita — messaggio troppo lungo: ${righe} righe`);
    return json({ ok: false, error: 'messaggio_troppo_lungo' }, 400);
  }

  console.log(`${tag} Validazione OK`);

  // --- Verifica Altcha ---
  const altchaMasterKey = (import.meta.env.ALTCHA_HMAC_KEY as string) || process.env.ALTCHA_HMAC_KEY || '';
  const altchaPayload = (data.get('altcha') as string | null) ?? '';

  if (altchaMasterKey) {
    if (!altchaPayload) {
      console.warn(`${tag} Altcha — payload mancante`);
      return json({ ok: false, error: 'verifica_fallita' }, 400);
    }
    const { verify, deriveHmacKeySecret } = await import('altcha-lib/frameworks/shared');
    const { deriveKey } = await import('altcha-lib/algorithms/pbkdf2');
    const hmacSignatureSecret = await deriveHmacKeySecret(altchaMasterKey);
    const hmacKeySignatureSecret = await deriveHmacKeySecret(altchaMasterKey + '-key');
    const { error } = await verify(altchaPayload, deriveKey, hmacSignatureSecret, hmacKeySignatureSecret);
    if (error) {
      console.warn(`${tag} Altcha — verifica fallita: ${error}`);
      return json({ ok: false, error: 'verifica_fallita' }, 400);
    }
    console.log(`${tag} Altcha OK`);
  } else {
    console.log(`${tag} Altcha — ALTCHA_HMAC_KEY non configurata, verifica saltata`);
  }

  // --- Configurazione Drupal ---
  // import.meta.env: dev → da frontend/.env via Vite; prod → da process.env via Node adapter
  const drupalUrl = (import.meta.env.DRUPAL_API_URL as string) || process.env.DRUPAL_API_URL || '';
  const drupalUser = (import.meta.env.DRUPAL_API_USER as string) || process.env.DRUPAL_API_USER || '';
  const drupalPass = (import.meta.env.DRUPAL_API_PASS as string) || process.env.DRUPAL_API_PASS || '';

  if (!drupalUrl || !drupalUser || !drupalPass) {
    console.error(`${tag} Configurazione mancante — DRUPAL_API_URL: ${!!drupalUrl}, DRUPAL_API_USER: ${!!drupalUser}, DRUPAL_API_PASS: ${!!drupalPass}`);
    return json({ ok: false, error: 'invio_fallito' }, 500);
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
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  console.log(`${tag} Risposta Drupal: HTTP ${res.status}`);

  if (!res.ok) {
    const body = await res.text();
    console.error(`${tag} Drupal ha risposto con errore ${res.status}:`);
    try {
      const parsed = JSON.parse(body);
      const errors = parsed?.errors ?? [];
      errors.forEach((e: Record<string, unknown>) =>
        console.error(`${tag}   → [${e.status}] ${e.title}: ${e.detail}`)
      );
    } catch {
      console.error(`${tag}   → Body grezzo: ${body.slice(0, 500)}`);
    }
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  console.log(`${tag} Entità creata con successo`);
  return json({ ok: true });
};
