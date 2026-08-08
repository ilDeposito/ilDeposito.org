// Verifica del token di anteprima generato da ildeposito_preview (Drupal).
// Stesso algoritmo lato Drupal: hash_hmac('sha256', "uuid:expires", secret).
import { createHmac, timingSafeEqual } from 'node:crypto';

export interface PreviewTokenCheck {
  ok: boolean;
  reason?: 'missing_params' | 'expired' | 'invalid_signature' | 'missing_secret';
}

/**
 * Ricalcola l'HMAC sul server e lo confronta in tempo costante col token
 * ricevuto, verificando anche che la scadenza non sia superata.
 */
export function verifyPreviewToken(
  uuid: string,
  expires: string | null,
  token: string | null,
  secret: string,
): PreviewTokenCheck {
  if (!secret) return { ok: false, reason: 'missing_secret' };
  if (!uuid || !expires || !token) return { ok: false, reason: 'missing_params' };

  const expiresAt = Number(expires);
  if (!Number.isFinite(expiresAt) || expiresAt < Math.floor(Date.now() / 1000)) {
    return { ok: false, reason: 'expired' };
  }

  const expected = createHmac('sha256', secret).update(`${uuid}:${expires}`).digest();
  const received = Buffer.from(token, 'hex');

  if (expected.length !== received.length || !timingSafeEqual(expected, received)) {
    return { ok: false, reason: 'invalid_signature' };
  }

  return { ok: true };
}
