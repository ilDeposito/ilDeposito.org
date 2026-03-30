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
				// Desktop: popover
				if (!el._bsPopover) {
					el._bsPopover = new Popover(el, {
						trigger: 'click',
						placement: 'bottom',
					});
				}
			}
		});
	},
};
