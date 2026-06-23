class ShareButton extends HTMLElement {
  connectedCallback() {
    const button = this.querySelector('button:not([data-share]):not([data-action])');
    const dialog = this.querySelector('dialog');
    const title = this.dataset.title ?? document.title;

    button.addEventListener('click', () => {
      const url = window.location.href;

      if (navigator.share) {
        navigator.share({ title, url }).catch(() => {});
        return;
      }

      const facebook = dialog.querySelector('[data-share="facebook"]');
      const whatsapp = dialog.querySelector('[data-share="whatsapp"]');
      const telegram = dialog.querySelector('[data-share="telegram"]');
      const email = dialog.querySelector('[data-share="email"]');
      const x = dialog.querySelector('[data-share="x"]');

      if (facebook) facebook.href = `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`;
      if (whatsapp) whatsapp.href = `https://wa.me/?text=${encodeURIComponent(`${title} ${url}`)}`;
      if (telegram) telegram.href = `https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title)}`;
      if (email) email.href = `mailto:?subject=${encodeURIComponent(title)}&body=${encodeURIComponent(url)}`;
      if (x) x.href = `https://twitter.com/intent/tweet?url=${encodeURIComponent(url)}&text=${encodeURIComponent(title)}`;

      dialog.showModal();
    });

    const copyBtn = dialog.querySelector('[data-share="copy"]');
    if (copyBtn) {
      copyBtn.addEventListener('click', () => {
        const label = copyBtn.querySelector('[data-copy-label]');
        navigator.clipboard.writeText(window.location.href).then(() => {
          if (!label) return;
          const original = label.textContent;
          label.style.transition = 'opacity 0.3s';
          label.style.opacity = '0';
          setTimeout(() => {
            label.textContent = 'URL copiato!';
            label.style.opacity = '1';
          }, 300);
          setTimeout(() => {
            label.style.opacity = '0';
            setTimeout(() => {
              label.textContent = original;
              label.style.opacity = '1';
            }, 300);
          }, 5000);
        });
      });
    }

    const downloadBtn = dialog.querySelector('[data-action="download-qr"]');
    if (downloadBtn) {
      downloadBtn.addEventListener('click', () => {
        const svgEl = dialog.querySelector('.qr-code-container svg');
        if (!svgEl) return;

        const svgData = new XMLSerializer().serializeToString(svgEl);
        const canvas = document.createElement('canvas');
        const size = 600;
        canvas.width = size;
        canvas.height = size;
        const ctx = canvas.getContext('2d');
        const img = new Image();
        img.onload = () => {
          ctx.drawImage(img, 0, 0, size, size);
          const link = document.createElement('a');
          link.download = 'qrcode-ildeposito.png';
          link.href = canvas.toDataURL('image/png');
          link.click();
        };
        img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svgData);
      });
    }
  }
}

customElements.define('share-button', ShareButton);
