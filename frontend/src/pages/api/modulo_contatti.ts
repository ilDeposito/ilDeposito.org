export const prerender = false;

import type { APIRoute } from 'astro';

const tag = '[modulo_contatti]';
const ts = () => new Date().toISOString();

const json = (body: object, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

// --- Anti-replay ALTCHA ---
// verify() valida firma e scadenza ma non impedisce il riuso dello stesso
// payload risolto entro la finestra di validità del challenge (5 min):
// registriamo le firme dei challenge già consumati finché non scadono.
// frontend-api è un processo singolo, quindi una Map in-memory basta; si
// svuota al riavvio (deploy), accettabile perché la finestra è comunque breve.
const CONSUMED_TTL_MS = 6 * 60 * 1000; // > expiresAt del challenge (5 min)
const CONSUMED_MAX = 10_000; // tetto di sicurezza, mai raggiunto sotto rate limit
const consumedChallenges = new Map<string, number>();

// Ritorna false se la firma è già stata consumata (replay).
function consumeChallenge(signature: string): boolean {
  const now = Date.now();
  // TTL uniforme → la Map è ordinata per scadenza: si pota dalla testa.
  for (const [sig, expiry] of consumedChallenges) {
    if (expiry > now) break;
    consumedChallenges.delete(sig);
  }
  if (consumedChallenges.has(signature)) return false;
  if (consumedChallenges.size >= CONSUMED_MAX) {
    const oldest = consumedChallenges.keys().next().value;
    if (oldest !== undefined) consumedChallenges.delete(oldest);
  }
  consumedChallenges.set(signature, now + CONSUMED_TTL_MS);
  return true;
}

export const POST: APIRoute = async ({ request, clientAddress }) => {
  console.log(`${ts()} ${tag} POST ricevuto da ${clientAddress} — UA: ${request.headers.get('user-agent')?.slice(0, 60)}`);

  // Blocca richieste cross-origin: origin deve corrispondere al dominio del server (Host).
  // checkOrigin di Astro è disabilitato perché il Node adapter legge l'URL come http://
  // dietro proxy; questa validazione esplicita sostituisce quella protezione.
  const origin = request.headers.get('origin');
  const host = request.headers.get('host');
  if (origin && host && !import.meta.env.DEV) {
    if (origin !== `https://${host}`) {
      console.warn(`${ts()} ${tag} Origin non consentita: ${origin} (host: ${host})`);
      return json({ ok: false, error: 'invio_fallito' }, 403);
    }
  }

  // --- Lettura form data ---
  let data: FormData;
  try {
    data = await request.formData();
  } catch (err) {
    console.error(`${ts()} ${tag} Impossibile leggere FormData:`, err);
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  const honeypot = (data.get('website') as string | null) ?? '';
  if (honeypot) {
    // Bot ha compilato il campo nascosto: risposta silenziosamente positiva per non rivelare il filtro
    console.warn(`${ts()} ${tag} Honeypot attivato — richiesta scartata`);
    return json({ ok: true });
  }

  // Privacy obbligatoria lato server: difesa in profondità rispetto alla validazione JS.
  const privacy = (data.get('privacy') as string | null) ?? '';
  if (!privacy) {
    console.warn(`${ts()} ${tag} Validazione fallita — privacy non accettata`);
    return json({ ok: false, error: 'privacy_non_accettata' }, 400);
  }

  const nome = (data.get('nome') as string | null)?.trim() ?? '';
  const email = (data.get('email') as string | null)?.trim() ?? '';
  const messaggio = (data.get('messaggio') as string | null)?.trim() ?? '';
  const titolo = (data.get('titolo') as string | null)?.trim() || 'Modulo contatti';
  const link = (data.get('link') as string | null)?.trim() ?? '';

  console.log(`${ts()} ${tag} Campi ricevuti — nome: ${nome.length}ch, email: ${email.length}ch, messaggio: ${messaggio.length}ch, titolo: "${titolo}", link: ${link || '(nessuno)'}`);

  // --- Validazione ---
  if (!nome || !email || !messaggio) {
    const mancanti = [!nome && 'nome', !email && 'email', !messaggio && 'messaggio'].filter(Boolean);
    console.warn(`${ts()} ${tag} Validazione fallita — campi mancanti: ${mancanti.join(', ')}`);
    return json({ ok: false, error: 'campi_mancanti' }, 400);
  }

  if (nome.length < 2) {
    console.warn(`${ts()} ${tag} Validazione fallita — nome troppo corto: ${nome.length}ch`);
    return json({ ok: false, error: 'nome_troppo_corto' }, 400);
  }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  if (!emailRegex.test(email)) {
    console.warn(`${ts()} ${tag} Validazione fallita — email non valida (${email.length}ch)`);
    return json({ ok: false, error: 'email_non_valida' }, 400);
  }

  if (messaggio.length > 50_000) {
    console.warn(`${ts()} ${tag} Validazione fallita — messaggio troppo lungo: ${messaggio.length} bytes`);
    return json({ ok: false, error: 'messaggio_troppo_lungo' }, 400);
  }
  const righe = messaggio.split('\n').length;
  if (righe > 200) {
    console.warn(`${ts()} ${tag} Validazione fallita — messaggio troppo lungo: ${righe} righe`);
    return json({ ok: false, error: 'messaggio_troppo_lungo' }, 400);
  }

  console.log(`${ts()} ${tag} Validazione OK`);

  // --- Verifica Altcha ---
  const altchaMasterKey = (import.meta.env.ALTCHA_HMAC_KEY as string) || process.env.ALTCHA_HMAC_KEY || '';
  const altchaPayload = (data.get('altcha') as string | null) ?? '';

  // Estrazione best-effort della signature, indipendente dall'esito di verify():
  // permette di correlare nei log il challenge emesso da /api/altcha con
  // l'esito qui (OK, replay, o verifica fallita), anche quando verify() rigetta il payload.
  // La signature del challenge è annidata sotto "challenge" nel payload che il
  // widget invia — { challenge: { parameters, signature }, solution } — non a
  // livello root (vedi altcha/dist/main/altcha.js, costruzione del payload v2).
  let altchaSignature: string | undefined;
  try {
    const parsed = JSON.parse(Buffer.from(altchaPayload, 'base64').toString('utf-8')) as { challenge?: { signature?: string } };
    altchaSignature = parsed.challenge?.signature;
  } catch {
    // payload non decodificabile — resta undefined, verify() lo rigetterà comunque
  }
  const sigLog = altchaSignature ?? '(non decodificabile)';

  if (altchaMasterKey) {
    if (!altchaPayload) {
      console.warn(`${ts()} ${tag} Altcha — payload mancante`);
      return json({ ok: false, error: 'verifica_fallita' }, 400);
    }
    console.log(`${ts()} ${tag} Altcha — verifica in corso — signature: ${sigLog}`);
    try {
      const { verify, deriveHmacKeySecret } = await import('altcha-lib/frameworks/shared');
      const { deriveKey } = await import('altcha-lib/algorithms/pbkdf2');
      const hmacSignatureSecret = await deriveHmacKeySecret(altchaMasterKey);
      const hmacKeySignatureSecret = await deriveHmacKeySecret(altchaMasterKey + '-key');
      const { error } = await verify(altchaPayload, deriveKey, hmacSignatureSecret, hmacKeySignatureSecret);
      if (error) {
        console.warn(`${ts()} ${tag} Altcha — verifica fallita (signature: ${sigLog}): ${error}`);
        return json({ ok: false, error: 'verifica_fallita' }, 400);
      }
      // La firma identifica univocamente il challenge (salt random + scadenza):
      // un payload già consumato è un replay, anche se la firma è valida.
      if (!altchaSignature || !consumeChallenge(altchaSignature)) {
        console.warn(`${ts()} ${tag} Altcha — payload già utilizzato (replay) — signature: ${sigLog}`);
        return json({ ok: false, error: 'verifica_fallita' }, 400);
      }
      console.log(`${ts()} ${tag} Altcha OK — signature: ${sigLog}`);
    } catch (err) {
      console.error(`${ts()} ${tag} Altcha — eccezione durante la verifica (signature: ${sigLog}):`, err);
      return json({ ok: false, error: 'verifica_fallita' }, 400);
    }
  } else {
    console.error(`${ts()} ${tag} Altcha — ALTCHA_HMAC_KEY non configurata — endpoint non disponibile`);
    return json({ ok: false, error: 'invio_fallito' }, 503);
  }

  // --- Configurazione Drupal ---
  // import.meta.env: dev → da frontend/.env via Vite; prod → da process.env via Node adapter
  const drupalUrl = (import.meta.env.DRUPAL_API_URL as string) || process.env.DRUPAL_API_URL || '';
  const drupalUser = (import.meta.env.DRUPAL_API_USER as string) || process.env.DRUPAL_API_USER || '';
  const drupalPass = (import.meta.env.DRUPAL_API_PASS as string) || process.env.DRUPAL_API_PASS || '';

  if (!drupalUrl || !drupalUser || !drupalPass) {
    console.error(`${ts()} ${tag} Configurazione mancante — DRUPAL_API_URL: ${!!drupalUrl}, DRUPAL_API_USER: ${!!drupalUser}, DRUPAL_API_PASS: ${!!drupalPass}`);
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  const endpoint = `${drupalUrl}/jsonapi/ildeposito_contatto/modulo_contatti`;
  console.log(`${ts()} ${tag} Chiamata JSON:API → ${endpoint}`);

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
            field_titolo: titolo,
            field_nome: nome,
            field_email: email,
            field_messaggio: messaggio,
            ...(link ? { field_link: { uri: link, title: '' } } : {}),
          },
        },
      }),
    });
  } catch (err) {
    console.error(`${ts()} ${tag} Errore di rete verso Drupal (${endpoint}):`, err);
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  console.log(`${ts()} ${tag} Risposta Drupal: HTTP ${res.status}`);

  if (!res.ok) {
    const body = await res.text();
    console.error(`${ts()} ${tag} Drupal ha risposto con errore ${res.status}:`);
    try {
      const parsed = JSON.parse(body);
      const errors = parsed?.errors ?? [];
      errors.forEach((e: Record<string, unknown>) =>
        console.error(`${ts()} ${tag}   → [${e.status}] ${e.title}: ${e.detail}`)
      );
    } catch {
      console.error(`${ts()} ${tag}   → Body grezzo: ${body.slice(0, 500)}`);
    }
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  console.log(`${ts()} ${tag} Entità creata con successo`);
  return json({ ok: true });
};
