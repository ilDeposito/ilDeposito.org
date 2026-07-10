import { track } from '../lib/analytics.js';

// data-pdf-download-event/-key opzionali: permettono di riusare lo stesso
// listener su download diversi dal singolo canto (canzonieri, canzonieri
// autore) senza toccare l'evento 'pdf_download'/{canto} già esistente.
document.querySelectorAll('[data-pdf-download]').forEach((link) => {
  link.addEventListener('click', () => {
    const event = link.dataset.pdfDownloadEvent || 'pdf_download';
    const key = link.dataset.pdfDownloadKey || 'canto';
    track(event, { [key]: link.dataset.pdfDownload });
  });
});
