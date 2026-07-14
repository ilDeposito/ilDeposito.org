export const prerender = false;

import type { APIRoute } from 'astro';
import { createChallenge } from 'altcha-lib';
import { deriveKey } from 'altcha-lib/algorithms/pbkdf2';
import { deriveHmacKeySecret } from 'altcha-lib/frameworks/shared';

const tag = '[altcha]';
const ts = () => new Date().toISOString();

export const GET: APIRoute = async ({ request, clientAddress }) => {
  console.log(`${ts()} ${tag} GET da ${clientAddress} — UA: ${request.headers.get('user-agent')?.slice(0, 40)} origin=${request.headers.get('origin')} referer=${request.headers.get('referer')?.slice(0, 60)}`);
  const masterKey = (import.meta.env.ALTCHA_HMAC_KEY as string) || process.env.ALTCHA_HMAC_KEY || '';

  if (!masterKey) {
    console.error(`${ts()} ${tag} ALTCHA_HMAC_KEY non configurata`);
    return new Response(JSON.stringify({ error: 'configurazione mancante' }), {
      status: 500,
      headers: { 'Content-Type': 'application/json' },
    });
  }

  try {
    const hmacSignatureSecret = await deriveHmacKeySecret(masterKey);
    const hmacKeySignatureSecret = await deriveHmacKeySecret(masterKey + '-key');

    const challenge = await createChallenge({
      algorithm: 'PBKDF2/SHA-256',
      cost: 50_000,
      deriveKey,
      hmacSignatureSecret,
      hmacKeySignatureSecret,
      expiresAt: new Date(Date.now() + 5 * 60 * 1000),
    });

    console.log(`${ts()} ${tag} Challenge emesso — signature: ${challenge.signature} client: ${clientAddress}`);

    return new Response(JSON.stringify(challenge), {
      headers: { 'Content-Type': 'application/json' },
    });
  } catch (err) {
    console.error(`${ts()} ${tag} Errore creazione challenge:`, err);
    return new Response(JSON.stringify({ error: 'errore interno' }), {
      status: 500,
      headers: { 'Content-Type': 'application/json' },
    });
  }
};
