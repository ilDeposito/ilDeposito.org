/******/ (function() { // webpackBootstrap
/*!****************************************************************!*\
  !*** ./components/youtube-mini-player/_youtube-mini-player.js ***!
  \****************************************************************/
(function (Drupal) {
  var extractVideoId = function extractVideoId(urlString) {
    if (!urlString) return null;
    try {
      var url = new URL(urlString, window.location.origin);
      var hostname = url.hostname.replace(/^www\./, '');
      if (hostname === 'youtu.be') return url.pathname.split('/').filter(Boolean)[0] || null;
      if (hostname.endsWith('youtube.com')) {
        if (url.searchParams.get('v')) return url.searchParams.get('v');
        var segments = url.pathname.split('/').filter(Boolean);
        if (segments[0] === 'embed' || segments[0] === 'shorts') return segments[1] || null;
      }
    } catch (_unused) {
      return null;
    }
    return null;
  };
  var buildEmbedUrl = function buildEmbedUrl(videoId) {
    var autoplay = arguments.length > 1 && arguments[1] !== undefined ? arguments[1] : false;
    var params = new URLSearchParams({
      enablejsapi: '1',
      rel: '0',
      playsinline: '1',
      origin: window.location.origin
    });
    if (autoplay) params.set('autoplay', '1');
    return "https://www.youtube-nocookie.com/embed/".concat(videoId, "?").concat(params);
  };

  // Invia un comando postMessage all'iframe; l'origine specifica previene XSS.
  var postCommand = function postCommand(iframe, command) {
    var _iframe$contentWindow;
    (_iframe$contentWindow = iframe.contentWindow) === null || _iframe$contentWindow === void 0 || _iframe$contentWindow.postMessage(JSON.stringify({
      event: 'command',
      func: command,
      args: []
    }), 'https://www.youtube-nocookie.com');
  };
  Drupal.behaviors.ilDepositoYoutubeMiniPlayer = {
    attach: function attach(context) {
      context.querySelectorAll('.yt-mini-player:not([data-yt-mini-player-ready])').forEach(function (player) {
        var youtubeUrl = player.getAttribute('data-youtube-url');
        var iframe = player.querySelector('.yt-mini-player__frame');
        var button = player.querySelector('.yt-mini-player__toggle');
        var useEl = button === null || button === void 0 ? void 0 : button.querySelector('use');
        var videoId = extractVideoId(youtubeUrl);
        player.setAttribute('data-yt-mini-player-ready', 'true');
        if (!videoId || !iframe || !button || !useEl) {
          player.style.display = 'none';
          return;
        }
        var isPlaying = false;
        var hasStarted = false;
        var hrefBase = useEl.getAttribute('href').replace(/#[^#]*$/, '#');
        var syncIcon = function syncIcon() {
          useEl.setAttribute('href', hrefBase + (isPlaying ? 'pause-fill' : 'play-fill'));
          button.setAttribute('aria-pressed', isPlaying ? 'true' : 'false');
        };

        // L'iframe nocookie manda onStateChange via postMessage con enablejsapi=1.
        // event.source filtra messaggi di altri player sulla stessa pagina.
        window.addEventListener('message', function (event) {
          if (event.source !== iframe.contentWindow) return;
          try {
            var data = JSON.parse(event.data);
            if (data.event !== 'onStateChange') return;
            // info: 1 = playing, 2 = paused, 0 = ended
            isPlaying = data.info === 1;
            syncIcon();
          } catch (_unused2) {/* formato non atteso, ignora */}
        });
        button.addEventListener('click', function () {
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
    }
  };
})(Drupal);
/******/ })()
;
//# sourceMappingURL=youtube-mini-player.js.map