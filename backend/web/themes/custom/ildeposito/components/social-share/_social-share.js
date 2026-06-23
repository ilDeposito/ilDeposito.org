import Popover from 'bootstrap/js/dist/popover';

Drupal.behaviors.ilDepositoSocialShare = {
	attach(context) {
		document.querySelectorAll('.social-share[data-bs-toggle="popover"]').forEach((el) => {
			// Mobile: usa Web Share API
			const isMobile = window.matchMedia('(pointer: coarse)').matches && typeof navigator.share === 'function';
			if (isMobile) {
				el.addEventListener('click', (e) => {
					e.preventDefault();
					navigator.share({
						title: document.title,
						url: window.location.href,
					});
				}, { once: true });
				// Disabilita popover su mobile
				el.removeAttribute('data-bs-toggle');
			} else {
				// Desktop: popover con contenuto HTML dal template
				if (!el._bsPopover) {
					const tpl = el.querySelector('.social-share__popover-content');
					let content = 'errore';
					if (tpl) {
						// Se <template> nativo, estrai il contenuto vero
						if (tpl.content && tpl.content.cloneNode) {
							const frag = tpl.content.cloneNode(true);
							const div = document.createElement('div');
							div.appendChild(frag);
							content = div.innerHTML;
						} else {
							// Fallback: innerHTML (per compatibilità)
							content = tpl.innerHTML;
						}
					}
					el._bsPopover = new Popover(el, {
						trigger: 'focus', // chiusura automatica quando si clicca fuori
						placement: 'bottom',
						html: true,
						content,
					});
				}
			}
		});
	},
};
