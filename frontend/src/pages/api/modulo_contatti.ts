export const prerender = false;

import type { APIRoute } from 'astro';

export const POST: APIRoute = async ({ request, redirect }) => {
  const data = await request.formData();
  const nome = (data.get('nome') as string | null)?.trim() ?? '';
  const email = (data.get('email') as string | null)?.trim() ?? '';
  const messaggio = (data.get('messaggio') as string | null)?.trim() ?? '';

  if (!nome || !email || !messaggio) {
    return redirect('/contatti?error=campi_mancanti', 302);
  }

  const drupalUrl = process.env.DRUPAL_API_URL ?? '';
  const drupalUser = process.env.DRUPAL_API_USER ?? '';
  const drupalPass = process.env.DRUPAL_API_PASS ?? '';

  const credentials = Buffer.from(`${drupalUser}:${drupalPass}`).toString('base64');

  try {
    const res = await fetch(`${drupalUrl}/jsonapi/ildeposito_contatto/modulo_contatti`, {
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

    if (!res.ok) {
      console.error('[modulo_contatti] Drupal error:', res.status, await res.text());
      return redirect('/contatti?error=invio_fallito', 302);
    }

    return redirect('/contatti?inviato=1', 302);
  } catch (err) {
    console.error('[modulo_contatti] fetch error:', err);
    return redirect('/contatti?error=invio_fallito', 302);
  }
};
