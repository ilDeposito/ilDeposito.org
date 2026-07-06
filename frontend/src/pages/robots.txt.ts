export async function GET() {
  const isStage = process.env.ENV === 'stage';

  const body = isStage
    ? 'User-agent: *\nDisallow: /\n'
    : [
        'User-agent: *',
        'Allow: /',
        'Disallow: /cerca',
        'Disallow: /api/',
        '',
        'User-agent: GPTBot',
        'Allow: /',
        'User-agent: ChatGPT-User',
        'Allow: /',
        'User-agent: ClaudeBot',
        'Allow: /',
        'User-agent: anthropic-ai',
        'Allow: /',
        'User-agent: Google-Extended',
        'Allow: /',
        'User-agent: CCBot',
        'Allow: /',
        'User-agent: PerplexityBot',
        'Allow: /',
        '',
        'Sitemap: https://www.ildeposito.org/sitemap-index.xml',
        '',
      ].join('\n');

  return new Response(body, { headers: { 'Content-Type': 'text/plain' } });
}
