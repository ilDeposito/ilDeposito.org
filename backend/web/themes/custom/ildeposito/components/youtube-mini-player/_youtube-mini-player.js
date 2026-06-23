((Drupal) => {
  const extractVideoId = (urlString) => {
    if (!urlString) return null;
    try {
      const url = new URL(urlString, window.location.origin);
      const hostname = url.hostname.replace(/^www\./, '');
      if (hostname === 'youtu.be') return url.pathname.split('/').filter(Boolean)[0] || null;
      if (hostname.endsWith('youtube.com')) {
        if (url.searchParams.get('v')) return url.searchParams.get('v');
        const segments = url.pathname.split('/').filter(Boolean);
        if (segments[0] === 'embed' || segments[0] === 'shorts') return segments[1] || null;
      }
    } catch {
      return null;
    }
    return null;
  };

  const buildEmbedUrl = (videoId, autoplay = false) => {
    const params = new URLSearchParams({
      enablejsapi: '1',
      rel: '0',
      playsinline: '1',
      origin: window.location.origin,
    });
    if (autoplay) params.set('autoplay', '1');
    return `https://www.youtube-nocookie.com/embed/${videoId}?${params}`;
  };

  // Invia un comando postMessage all'iframe; l'origine specifica previene XSS.
  const postCommand = (iframe, command) => {
    iframe.contentWindow?.postMessage(
      JSON.stringify({ event: 'command', func: command, args: [] }),
      'https://www.youtube-nocookie.com',
    );
  };

  Drupal.behaviors.ilDepositoYoutubeMiniPlayer = {
    attach(context) {
      context.querySelectorAll('.yt-mini-player:not([data-yt-mini-player-ready])').forEach((player) => {
        const youtubeUrl = player.getAttribute('data-youtube-url');
        const iframe = player.querySelector('.yt-mini-player__frame');
        const button = player.querySelector('.yt-mini-player__toggle');
        const useEl = button?.querySelector('use');
        const videoId = extractVideoId(youtubeUrl);

        player.setAttribute('data-yt-mini-player-ready', 'true');

        if (!videoId || !iframe || !button || !useEl) {
          player.style.display = 'none';
          return;
        }

        let isPlaying = false;
        let hasStarted = false;
        const hrefBase = useEl.getAttribute('href').replace(/#[^#]*$/, '#');

        const syncIcon = () => {
          useEl.setAttribute('href', hrefBase + (isPlaying ? 'pause-fill' : 'play-fill'));
          button.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
        };

        // L'iframe nocookie manda onStateChange via postMessage con enablejsapi=1.
        // event.source filtra messaggi di altri player sulla stessa pagina.
        window.addEventListener('message', (event) => {
          if (event.source !== iframe.contentWindow) return;
          try {
            const data = JSON.parse(event.data);
            if (data.event !== 'onStateChange') return;
            // info: 1 = playing, 2 = paused, 0 = ended
            isPlaying = data.info === 1;
            syncIcon();
          } catch { /* formato non atteso, ignora */ }
        });

        button.addEventListener('click', () => {
          if (!hasStarted) {
            // L'iframe viene caricato solo al primo click: nessun contenuto YouTube prima dell'interazione
            iframe.src = buildEmbedUrl(videoId, true);
            hasStarted = true;
            isPlaying = true;
            syncIcon();
            return;
          }
          if (isPlaying) {
            isPlaying = false;
            syncIcon();
            postCommand(iframe, 'pauseVideo');
            return;
          }

          isPlaying = true;
          syncIcon();
          postCommand(iframe, 'playVideo');
        });
      });
    },
  };
})(Drupal);
