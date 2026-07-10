import { track } from '../lib/analytics.js';

document.querySelectorAll('[data-pdf-download]').forEach((link) => {
  link.addEventListener('click', () => {
    track('pdf_download', { canto: link.dataset.pdfDownload });
  });
});
