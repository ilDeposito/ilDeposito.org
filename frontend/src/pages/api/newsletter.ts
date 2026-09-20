export const prerender = false;

import type { APIRoute } from 'astro';

const tag = '[newsletter]';
const ts = () => new Date().toISOString();

const json = (body: object, status = 200) =>
  new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });

// --- Anti-replay ALTCHA ---
// Stessa logica di /api/modulo_contatti: verify() valida firma e scadenza ma
// non impedisce il riuso dello stesso payload risolto entro la finestra di
// validità del challenge (5 min). Map separata perché ogni modulo ha il suo
// stato (le firme sono comunque univoche per challenge, il riuso cross-form
// resta impossibile oltre la TTL).
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

interface ListmonkList {
  id: number;
  subscription_status?: string;
}

interface ListmonkSubscriber {
  id: number;
  email?: string;
  lists?: ListmonkList[];
}

function listmonkAuth(username: string, token: string): string {
  return `Basic ${Buffer.from(`${username}:${token}`).toString('base64')}`;
}

async function listmonkFetch(
  baseUrl: string,
  username: string,
  token: string,
  path: string,
  init?: RequestInit,
): Promise<Response> {
  return fetch(`${baseUrl}${path}`, {
    ...init,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      Authorization: listmonkAuth(username, token),
      ...(init?.headers ?? {}),
    },
    // Evita worker SSR appesi se Listmonk non risponde (rete interna Docker).
    signal: AbortSignal.timeout(15_000),
  });
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

  const email = (data.get('email') as string | null)?.trim() ?? '';
  console.log(`${ts()} ${tag} Iscrizione richiesta per email da ${email.length}ch`);

  // --- Validazione ---
  if (!email) {
    console.warn(`${ts()} ${tag} Validazione fallita — email mancante`);
    return json({ ok: false, error: 'campi_mancanti' }, 400);
  }

  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  if (email.length > 254 || !emailRegex.test(email)) {
    console.warn(`${ts()} ${tag} Validazione fallita — email non valida (${email.length}ch)`);
    return json({ ok: false, error: 'email_non_valida' }, 400);
  }

  console.log(`${ts()} ${tag} Validazione OK`);

  // --- Verifica Altcha ---
  const altchaMasterKey = (import.meta.env.ALTCHA_HMAC_KEY as string) || process.env.ALTCHA_HMAC_KEY || '';
  const altchaPayload = (data.get('altcha') as string | null) ?? '';

  // Estrazione best-effort della signature, indipendente dall'esito di verify():
  // permette di correlare nei log il challenge emesso da /api/altcha con
  // l'esito qui (OK, replay, o verifica fallita), anche quando verify() rigetta il payload.
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

  // --- Configurazione Listmonk ---
  // import.meta.env: dev → da .env root via Vite; prod → da process.env via Node adapter.
  // Stesse variabili del backend (settings.php), più l'ID della lista newsletter:
  // SSR-runtime only, la build statica non le usa.
  const baseUrl = ((import.meta.env.LISTMONK_BASE_URL as string) || process.env.LISTMONK_BASE_URL || '').replace(/\/$/, '');
  const username = (import.meta.env.LISTMONK_USERNAME as string) || process.env.LISTMONK_USERNAME || '';
  const token = (import.meta.env.LISTMONK_TOKEN as string) || process.env.LISTMONK_TOKEN || '';
  const listIdRaw = (import.meta.env.LISTMONK_NEWSLETTER_LIST_ID as string) || process.env.LISTMONK_NEWSLETTER_LIST_ID || '';
  const listId = Number.parseInt(listIdRaw, 10);

  if (!baseUrl || !username || !token || !Number.isInteger(listId) || listId <= 0) {
    console.error(
      `${ts()} ${tag} Configurazione mancante — LISTMONK_BASE_URL: ${!!baseUrl}, LISTMONK_USERNAME: ${!!username}, LISTMONK_TOKEN: ${!!token}, LISTMONK_NEWSLETTER_LIST_ID: ${listIdRaw || '(vuota)'}`,
    );
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  // --- Iscrizione double opt-in ---
  // preconfirm_subscriptions=false: se la lista richiede il double opt-in,
  // Listmonk invia l'email di conferma invece di attivare subito.
  // L'API richiede anche `name`: il form chiede solo l'email, si riusa
  // l'indirizzo come nome visualizzato.
  console.log(`${ts()} ${tag} Chiamata Listmonk → POST /api/subscribers (lista ${listId})`);
  let res: Response;
  try {
    res = await listmonkFetch(baseUrl, username, token, '/api/subscribers', {
      method: 'POST',
      body: JSON.stringify({
        email,
        name: email,
        status: 'enabled',
        lists: [listId],
        preconfirm_subscriptions: false,
      }),
    });
  } catch (err) {
    console.error(`${ts()} ${tag} Errore di rete verso Listmonk:`, err);
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  if (res.ok) {
    console.log(`${ts()} ${tag} Iscritto con double opt-in — email di conferma in partenza`);
    return json({ ok: true });
  }

  const body = await res.text().catch(() => '(corpo illeggibile)');
  console.warn(`${ts()} ${tag} Listmonk ha risposto HTTP ${res.status}: ${body.slice(0, 300)}`);

  // L'email esiste già: si ricade sull'upsert — se è già confermata sulla
  // lista si risponde gia_iscritto, altrimenti si riaggiunge come unconfirmed
  // così Listmonk rispedisce l'email di conferma (copre anche chi non aveva
  // mai confermato o si era disiscritto e ci ripensa dal form).
  if (res.status === 400 || res.status === 409) {
    return upsertExisting(baseUrl, username, token, listId, email);
  }

  return json({ ok: false, error: 'invio_fallito' }, 500);
};

/**
 * Gestisce l'iscrizione di un indirizzo già presente su Listmonk.
 */
async function upsertExisting(
  baseUrl: string,
  username: string,
  token: string,
  listId: number,
  email: string,
): Promise<Response> {
  let subscriber: ListmonkSubscriber | null = null;
  try {
    const res = await listmonkFetch(
      baseUrl,
      username,
      token,
      `/api/subscribers?search=${encodeURIComponent(email)}&per_page=20`,
      { method: 'GET' },
    );
    if (res.ok) {
      const payload = (await res.json()) as { data?: { results?: ListmonkSubscriber[] } };
      const results = payload?.data?.results ?? [];
      subscriber = results.find((s) => s.email?.toLowerCase() === email.toLowerCase()) ?? null;
    } else {
      console.error(`${ts()} ${tag} Ricerca iscritto fallita — HTTP ${res.status}`);
    }
  } catch (err) {
    console.error(`${ts()} ${tag} Errore di rete durante la ricerca iscritto:`, err);
  }

  if (!subscriber) {
    // La POST è fallita ma l'anagrafica non si ritrova: errore generico, nei
    // log resta il corpo della risposta Listmonk per la diagnosi.
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  const current = subscriber.lists?.find((l) => l.id === listId)?.subscription_status;
  if (current === 'confirmed') {
    console.log(`${ts()} ${tag} Indirizzo già confermato sulla lista ${listId}`);
    return json({ ok: false, error: 'gia_iscritto' });
  }

  try {
    const res = await listmonkFetch(baseUrl, username, token, '/api/subscribers/lists', {
      method: 'PUT',
      body: JSON.stringify({
        ids: [subscriber.id],
        action: 'add',
        target_list_ids: [listId],
        status: 'unconfirmed',
      }),
    });
    if (!res.ok) {
      const errBody = await res.text().catch(() => '(corpo illeggibile)');
      console.error(`${ts()} ${tag} Aggiunta alla lista fallita — HTTP ${res.status}: ${errBody.slice(0, 300)}`);
      return json({ ok: false, error: 'invio_fallito' }, 500);
    }
  } catch (err) {
    console.error(`${ts()} ${tag} Errore di rete durante l'aggiunta alla lista:`, err);
    return json({ ok: false, error: 'invio_fallito' }, 500);
  }

  console.log(`${ts()} ${tag} Iscrizione ripristinata come unconfirmed (stato precedente: ${current ?? 'assente'}) — email di conferma in partenza`);
  return json({ ok: true });
}
