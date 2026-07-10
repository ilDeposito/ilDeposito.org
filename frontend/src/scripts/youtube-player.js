import { track } from '../lib/analytics.js';

const CONSENT_KEY = 'yt-consent';

class YouTubePlayer extends HTMLElement {
  connectedCallback() {
    this._player = null;
    this._ready = false;
    this._played = false;
    this._consented = localStorage.getItem(CONSENT_KEY) === '1';

    const btn = this.querySelector('[data-yt-toggle]');
    const dialog = this.querySelector('dialog');
    const acceptBtn = this.querySelector('[data-yt-accept]');
    const cancelBtn = this.querySelector('[data-yt-cancel]');

    btn.addEventListener('click', () => {
      if (this._consented) {
        if (this._ready) {
          this._togglePlayback();
        } else {
          this._loadPlayer();
        }
        return;
      }
      dialog.showModal();
    });

    acceptBtn.addEventListener('click', () => {
      this._consented = true;
      localStorage.setItem(CONSENT_KEY, '1');
      dialog.close();
      this._loadPlayer();
    });

    cancelBtn.addEventListener('click', () => dialog.close());
  }

  _extractVideoId() {
    const url = this.dataset.videoUrl;
    const match = url.match(/(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/|v\/|shorts\/))([a-zA-Z0-9_-]{11})/);
    return match?.[1] ?? null;
  }

  _loadPlayer() {
    const videoId = this._extractVideoId();
    if (!videoId) return;

    const containerId = `yt-${videoId}-${Date.now()}`;
    const container = document.createElement('div');
    container.id = containerId;
    this.querySelector('[data-yt-iframe-wrap]').appendChild(container);

    const initPlayer = () => {
      this._player = new window.YT.Player(containerId, {
        videoId,
        playerVars: {
          autoplay: 1,
          controls: 0,
          rel: 0,
          modestbranding: 1,
          playsinline: 1,
        },
        events: {
          onReady: () => {
            this._ready = true;
            this._setPlaying(true);
            if (!this._played) {
              this._played = true;
              track('audio_play', { canto: this.dataset.cantoSlug ?? '' });
            }
          },
          onStateChange: (e) => {
            if (e.data === window.YT.PlayerState.ENDED) {
              this._setPlaying(false);
            }
          },
        },
      });
    };

    if (window.YT?.Player) {
      initPlayer();
    } else {
      window.__ytPlayerCallbacks = window.__ytPlayerCallbacks || [];
      window.__ytPlayerCallbacks.push(initPlayer);

      if (!document.querySelector('script[src*="youtube.com/iframe_api"]')) {
        const tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);

        window.onYouTubeIframeAPIReady = () => {
          window.__ytPlayerCallbacks.forEach((cb) => cb());
          window.__ytPlayerCallbacks = [];
        };
      }
    }
  }

  _togglePlayback() {
    if (!this._ready || !this._player) return;
    const state = this._player.getPlayerState();
    if (state === window.YT.PlayerState.PLAYING) {
      this._player.pauseVideo();
      this._setPlaying(false);
    } else {
      this._player.playVideo();
      this._setPlaying(true);
    }
  }

  _setPlaying(playing) {
    const btn = this.querySelector('[data-yt-toggle]');
    const playIcon = this.querySelector('[data-yt-icon-play]');
    const pauseIcon = this.querySelector('[data-yt-icon-pause]');
    const label = this.querySelector('[data-yt-label]');

    playIcon.classList.toggle('hidden', playing);
    pauseIcon.classList.toggle('hidden', !playing);
    btn.setAttribute('aria-label', playing ? 'Metti in pausa' : 'Ascolta questo canto');
    if (label) label.textContent = playing ? 'Pausa' : 'Ascolta';
  }
}

customElements.define('youtube-player', YouTubePlayer);
