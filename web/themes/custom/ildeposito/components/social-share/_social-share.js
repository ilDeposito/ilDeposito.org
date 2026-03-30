((Drupal, once) => {
  'use strict';

  Drupal.behaviors.ilDepositoSocialShare = {
    attach(context, settings) {
      once('ildeposito-social-share', '[data-social-share]', context).forEach((el) => {
        // Dispositivi touch (mobile/tablet) → Web Share API nativa
        const isMobile = window.matchMedia('(pointer: coarse)').matches;

        if (isMobile && navigator.share) {
          el.addEventListener('click', async () => {
            try {
              await navigator.share({
                title: el.dataset.shareTitle || document.title,
                text: el.dataset.shareText || '',
                url: el.dataset.shareUrl || window.location.href,
              });
            } catch {
              // L'utente ha annullato la condivisione — nessuna azione necessaria
            }
          });
        } else {
          // Desktop → Bootstrap Popover
          // bootstrap è disponibile come globale tramite Radix
          new bootstrap.Popover(el, { // eslint-disable-line no-undef
            content: 'social',
            trigger: 'click',
            placement: 'bottom',
            html: false,
          });
        }
      });
    },
  };
})(Drupal, once);
