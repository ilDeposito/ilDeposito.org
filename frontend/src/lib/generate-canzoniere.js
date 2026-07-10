import PDFDocument from 'pdfkit';
import { PDFDocument as PDFLibDocument } from 'pdf-lib';
import QRCode from 'qrcode';
import {
  PAGE, MARGIN, CONTENT_WIDTH, COLOR_BLACK, COLOR_GRAY,
  sanitizeText, drawHeaderLine, drawFooterLine, registerFonts, renderCantoPage,
} from './generate-pdf.js';
import { withUtm } from './utm.js';

const SITE_URL = 'https://www.ildeposito.org';
const HEADER_TEXT = 'ilDeposito.org - Canti di protesta politica e sociale';

function pdfToBuffer(doc) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    doc.on('data', (c) => chunks.push(c));
    doc.on('end', () => resolve(Buffer.concat(chunks)));
    doc.on('error', reject);
    doc.end();
  });
}

function newDoc(title) {
  const doc = new PDFDocument({
    size: 'A4',
    margins: MARGIN,
    info: {
      Title: `${title} - ilDeposito.org`,
      Author: 'ilDeposito.org',
      Subject: 'Canti di protesta politica e sociale',
      Creator: 'ilDeposito.org',
    },
    bufferPages: true,
  });
  registerFonts(doc);
  return doc;
}

// Applica banner, linee e numero pagina assoluto (offset + indice locale + 1)
// a tutte le pagine bufferizzate del documento — stessa tecnica di
// generateCantoPdf in generate-pdf.js, ma con un offset perché in un
// canzoniere questo documento non parte per forza da pagina 1 (viene
// anteposto l'indice iniziale, vedi assemblaCanzoniere).
function applyHeaderFooter(doc, offset) {
  const range = doc.bufferedPageRange();
  for (let i = 0; i < range.count; i++) {
    doc.switchToPage(i);

    doc.save().fontSize(9).font('SourceSans-Italic').fillColor(COLOR_GRAY);
    const headerWidth = doc.widthOfString(HEADER_TEXT);
    doc.text(HEADER_TEXT, (PAGE.width - headerWidth) / 2, MARGIN.top - 30, { lineBreak: false });

    drawHeaderLine(doc);
    drawFooterLine(doc);

    const footerText = `pagina ${offset + i + 1}`;
    const footerWidth = doc.widthOfString(footerText);
    doc.text(footerText, (PAGE.width - footerWidth) / 2, PAGE.height - MARGIN.bottom + 20, { lineBreak: false });

    doc.restore();
  }
  return range.count;
}

async function mergePdfBuffers(buffers, { titolo } = {}) {
  const merged = await PDFLibDocument.create();

  // copyPages non porta con sé l'Info dictionary del documento sorgente
  // (Title/Author/...): il merge ne crea uno vuoto, va valorizzato a mano.
  if (titolo) merged.setTitle(`${titolo} - ilDeposito.org`);
  merged.setAuthor('ilDeposito.org');
  merged.setSubject('Canti di protesta politica e sociale');
  merged.setCreator('ilDeposito.org');

  for (const buf of buffers) {
    const src = await PDFLibDocument.load(buf);
    const pages = await merged.copyPages(src, src.getPageIndices());
    for (const page of pages) merged.addPage(page);
  }
  return Buffer.from(await merged.save());
}

// Separa la prima pagina (copertina) dal resto: serve per anteporla
// all'indice iniziale, che nel documento "corpo" (vedi buildBodyDoc) segue
// invece la copertina — i numeri di pagina non cambiano (vedi assemblaCanzoniere),
// solo l'ordine fisico delle pagine nel file finale.
async function splitFirstPage(buf) {
  const src = await PDFLibDocument.load(buf);
  const total = src.getPageCount();

  const coverDoc = await PDFLibDocument.create();
  const [coverPage] = await coverDoc.copyPages(src, [0]);
  coverDoc.addPage(coverPage);

  const restDoc = await PDFLibDocument.create();
  const restIndices = Array.from({ length: total - 1 }, (_, i) => i + 1);
  const restPages = await restDoc.copyPages(src, restIndices);
  for (const page of restPages) restDoc.addPage(page);

  return {
    cover: Buffer.from(await coverDoc.save()),
    rest: Buffer.from(await restDoc.save()),
  };
}

const qrCache = new Map();

async function getQrBuffer(slug) {
  if (qrCache.has(slug)) return qrCache.get(slug);
  const qrUrl = withUtm(`${SITE_URL}/canti/${slug}`, {
    source: 'pdf_canzoniere',
    medium: 'qr_code',
    campaign: 'pdf',
  });
  const buffer = await QRCode.toBuffer(qrUrl, {
    width: 100,
    errorCorrectionLevel: 'M',
    margin: 1,
    color: { dark: '#000000', light: '#ffffff' },
  });
  qrCache.set(slug, buffer);
  return buffer;
}

function renderCopertina(doc, { titolo, sottotitolo, immagine }) {
  let y = MARGIN.top + 40;

  if (immagine) {
    const maxSize = 280;
    try {
      doc.image(immagine, MARGIN.left + (CONTENT_WIDTH - maxSize) / 2, y, { fit: [maxSize, maxSize], align: 'center' });
      y += maxSize + 30;
    } catch {
      // Formato immagine non supportato da pdfkit (es. webp): si prosegue senza.
      y += 60;
    }
  } else {
    y += 60;
  }

  doc.font('Bitter').fontSize(28).fillColor(COLOR_BLACK);
  doc.text(sanitizeText(titolo), MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
  y = doc.y + 15;

  if (sottotitolo) {
    doc.font('SourceSans').fontSize(13).fillColor(COLOR_GRAY);
    doc.text(sanitizeText(sottotitolo), MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
  }

  const dataGenerazione = new Date().toLocaleDateString('it-IT', { year: 'numeric', month: 'long', day: 'numeric' });
  doc.font('SourceSans').fontSize(10).fillColor(COLOR_GRAY);
  doc.text(`Generato il ${dataGenerazione} — ${SITE_URL}`, MARGIN.left, PAGE.height - MARGIN.bottom - 20, {
    width: CONTENT_WIDTH,
    align: 'center',
  });
}

function renderDivisorioPeriodo(doc, periodo) {
  let y = MARGIN.top + 60;

  if (periodo.immagineBuffer) {
    const maxSize = 240;
    try {
      doc.image(periodo.immagineBuffer, MARGIN.left + (CONTENT_WIDTH - maxSize) / 2, y, { fit: [maxSize, maxSize], align: 'center' });
      y += maxSize + 30;
    } catch {
      y += 40;
    }
  } else {
    y += 40;
  }

  doc.font('Bitter').fontSize(22).fillColor(COLOR_BLACK);
  doc.text(sanitizeText(periodo.titolo), MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
}

// Corpo del canzoniere: copertina + (pagine divisorie periodo, se richieste)
// + un canto per pagina (ognuno può occupare più pagine se il testo eccede).
// Ritorna il doc ancora aperto (bufferPages) e, per ogni canto, la pagina
// LOCALE (0-based, relativa a questo documento) in cui inizia — servirà per
// calcolare la pagina assoluta finale una volta noto quante pagine avrà
// l'indice iniziale che verrà anteposto (vedi assemblaCanzoniere).
async function buildBodyDoc({ titolo, sottotitolo, copertinaImmagine, gruppi, renderDividers, columns, textField }) {
  const doc = newDoc(titolo);
  const cantoEntries = [];

  renderCopertina(doc, { titolo, sottotitolo, immagine: copertinaImmagine });

  for (const gruppo of gruppi) {
    if (renderDividers && gruppo.periodo) {
      doc.addPage();
      renderDivisorioPeriodo(doc, gruppo.periodo);
    }

    for (const canto of gruppo.canti) {
      doc.addPage();
      const pageInBody = doc.bufferedPageRange().count - 1;
      const qrBuffer = await getQrBuffer(canto.slug);

      renderCantoPage(doc, canto, {
        autoriTesto: canto.autori.map((a) => a.titolo),
        periodo: gruppo.periodo?.titolo ?? canto.periodo?.titolo ?? '',
        lingue: canto.lingue,
        tags: canto.tags,
        pageUrl: `${SITE_URL}/canti/${canto.slug}`,
        qrBuffer,
        columns,
        textField,
      });

      cantoEntries.push({ titolo: canto.titolo, autori: canto.autori, pageInBody });
    }
  }

  return { doc, cantoEntries };
}

// Indice iniziale (titolo → pagina): righe ad altezza FISSA (titolo troncato
// con ellissi in una colonna a larghezza fissa) apposta per rendere il
// conteggio pagine indipendente dal valore/numero di cifre della pagina
// scritta — così una prima passata "a vuoto" (per sapere quante pagine
// occuperà l'indice) e la passata reale con i numeri corretti producono
// sempre lo stesso numero di pagine, per costruzione.
async function renderIndiceTitoli(rows, offset) {
  const doc = newDoc('Indice');
  let y = MARGIN.top + 5;

  doc.font('Bitter').fontSize(18).fillColor(COLOR_BLACK);
  doc.text('Indice', MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
  y = doc.y + 25;

  const pageBottom = PAGE.height - MARGIN.bottom - 10;
  const rowHeight = 16;
  const numColWidth = 50;
  const titleColWidth = CONTENT_WIDTH - numColWidth;

  doc.font('SourceSans').fontSize(10).fillColor(COLOR_BLACK);

  for (const { titolo, pagina } of rows) {
    if (y + rowHeight > pageBottom) {
      doc.addPage();
      y = MARGIN.top + 10;
      doc.font('SourceSans').fontSize(10).fillColor(COLOR_BLACK);
    }

    doc.text(sanitizeText(titolo), MARGIN.left, y, { width: titleColWidth, ellipsis: true, lineBreak: false });
    doc.text(String(pagina), MARGIN.left + titleColWidth, y, { width: numColWidth, align: 'right', lineBreak: false });
    y += rowHeight;
  }

  const pageCount = doc.bufferedPageRange().count;
  applyHeaderFooter(doc, offset);
  const buffer = await pdfToBuffer(doc);
  return { buffer, pageCount };
}

// Indice analitico finale per autore (autore → elenco pagine). Righe ad
// altezza variabile (un autore con molti canti sparsi elenca molte pagine):
// essendo l'ultima sezione del canzoniere, il suo offset è già noto per
// costruzione (indice iniziale + corpo), quindi basta una sola passata.
async function renderIndiceAutori(rows, offset) {
  const doc = newDoc('Indice per autore');
  let y = MARGIN.top + 5;

  doc.font('Bitter').fontSize(18).fillColor(COLOR_BLACK);
  doc.text('Indice per autore', MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
  y = doc.y + 25;

  const pageBottom = PAGE.height - MARGIN.bottom - 10;

  for (const { titolo, pagine } of rows) {
    const text = `${sanitizeText(titolo)}: ${pagine.join(', ')}`;
    doc.font('SourceSans').fontSize(10).fillColor(COLOR_BLACK);
    const h = doc.heightOfString(text, { width: CONTENT_WIDTH });

    if (y + h > pageBottom) {
      doc.addPage();
      y = MARGIN.top + 10;
    }

    doc.font('SourceSans').fontSize(10).fillColor(COLOR_BLACK);
    doc.text(text, MARGIN.left, y, { width: CONTENT_WIDTH });
    y += h + 6;
  }

  applyHeaderFooter(doc, offset);
  return { buffer: await pdfToBuffer(doc) };
}

// Assembla un canzoniere completo: [copertina] + [indice iniziale] +
// [resto del corpo] + [indice per autore, opzionale]. La numerazione delle
// pagine non dipende dall'ordine fisico (la copertina occupa comunque lo
// stesso "slot" prima delle pagine dei canti, vedi paginaFinale sotto), solo
// l'assemblaggio finale sposta fisicamente la copertina davanti all'indice
// (buildBodyDoc la genera per prima, ma nello stesso documento dell'indice
// dei canti — va quindi separata con splitFirstPage). Vedi i commenti su
// renderIndiceTitoli per il motivo della doppia passata sull'indice iniziale.
async function assemblaCanzoniere({ titolo, sottotitolo, copertinaImmagine, gruppi, renderDividers, columns, textField, indiceAutori }) {
  const { doc, cantoEntries } = await buildBodyDoc({ titolo, sottotitolo, copertinaImmagine, gruppi, renderDividers, columns, textField });

  const tocRowsDry = cantoEntries.map((e) => ({ titolo: e.titolo, pagina: e.pageInBody + 1 }));
  const { pageCount: tocPageCount } = await renderIndiceTitoli(tocRowsDry, 0);

  const bodyPageCount = doc.bufferedPageRange().count;
  applyHeaderFooter(doc, tocPageCount);
  const bodyBuffer = await pdfToBuffer(doc);
  const { cover: coverBuffer, rest: restBuffer } = await splitFirstPage(bodyBuffer);

  const tocRowsFinal = cantoEntries.map((e) => ({ titolo: e.titolo, pagina: tocPageCount + e.pageInBody + 1 }));
  const { buffer: tocBuffer } = await renderIndiceTitoli(tocRowsFinal, 0);

  const parti = [coverBuffer, tocBuffer, restBuffer];

  if (indiceAutori) {
    const perAutore = new Map();
    for (const entry of cantoEntries) {
      const paginaFinale = tocPageCount + entry.pageInBody + 1;
      for (const autore of entry.autori) {
        if (!perAutore.has(autore.id)) perAutore.set(autore.id, { titolo: autore.titolo, pagine: [] });
        perAutore.get(autore.id).pagine.push(paginaFinale);
      }
    }

    const righeAutori = [...perAutore.values()]
      .sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'))
      .map((r) => ({ ...r, pagine: [...new Set(r.pagine)].sort((a, b) => a - b) }));

    const { buffer: autoriBuffer } = await renderIndiceAutori(righeAutori, tocPageCount + bodyPageCount);
    parti.push(autoriBuffer);
  }

  return mergePdfBuffers(parti, { titolo });
}

function raggruppaPerPeriodo(canti, periodi) {
  const bucketByPeriodoId = new Map(periodi.map((p) => [p.id, { periodo: p, canti: [] }]));
  const senzaPeriodo = [];

  for (const canto of canti) {
    const bucket = canto.periodo ? bucketByPeriodoId.get(canto.periodo.id) : null;
    if (bucket) bucket.canti.push(canto);
    else senzaPeriodo.push(canto);
  }

  const gruppi = periodi
    .map((p) => bucketByPeriodoId.get(p.id))
    .filter((g) => g.canti.length > 0)
    .map((g) => ({ ...g, canti: [...g.canti].sort((a, b) => a.titolo.localeCompare(b.titolo, 'it')) }));

  if (senzaPeriodo.length > 0) {
    gruppi.push({
      periodo: { titolo: 'Senza periodo', id: null, immagineBuffer: null },
      canti: [...senzaPeriodo].sort((a, b) => a.titolo.localeCompare(b.titolo, 'it')),
    });
  }

  return gruppi;
}

// I tre canzonieri "generali" (tutti i canti, tutti con accordi, tutti per
// periodo) condividono la stessa struttura: divisi per periodo (peso
// crescente), alfabetico dentro, con pagine divisorie e indice per autore.
// periodi va passato con `immagineBuffer` già valorizzato per ogni voce
// (fetch a carico del chiamante, vedi canzonieri-runner.js).
export async function generaCanzonierePerTutti(canti, periodi, { accordi = false } = {}) {
  const cantiFiltrati = accordi ? canti.filter((c) => c.accordi) : canti;
  const gruppi = raggruppaPerPeriodo(cantiFiltrati, periodi);
  const titolo = accordi ? 'Canzoniere — Tutti i canti con accordi' : 'Canzoniere — Tutti i canti';

  return assemblaCanzoniere({
    titolo,
    sottotitolo: 'ilDeposito.org — Archivio di canti di protesta politica e sociale',
    copertinaImmagine: null,
    gruppi,
    renderDividers: true,
    columns: accordi ? 1 : 2,
    textField: accordi ? 'accordi' : 'testo',
    indiceAutori: true,
  });
}

// periodo va passato con `immagineBuffer` già valorizzato (può essere null).
export async function generaCanzonierePerSingoloPeriodo(cantiPeriodo, periodo, { accordi = false } = {}) {
  const cantiFiltrati = accordi ? cantiPeriodo.filter((c) => c.accordi) : cantiPeriodo;
  const canti = [...cantiFiltrati].sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));

  return assemblaCanzoniere({
    titolo: accordi ? `Canzoniere — ${periodo.titolo} (con accordi)` : `Canzoniere — ${periodo.titolo}`,
    sottotitolo: 'ilDeposito.org',
    copertinaImmagine: periodo.immagineBuffer,
    gruppi: [{ periodo, canti }],
    renderDividers: false,
    columns: accordi ? 1 : 2,
    textField: accordi ? 'accordi' : 'testo',
    indiceAutori: true,
  });
}

// autore va passato con `immagineBuffer` già valorizzato (può essere null).
export async function generaCanzonierePerAutore(cantiAutore, autore) {
  const canti = [...cantiAutore].sort((a, b) => a.titolo.localeCompare(b.titolo, 'it'));

  return assemblaCanzoniere({
    titolo: `Canzoniere — ${autore.titolo}`,
    sottotitolo: 'ilDeposito.org',
    copertinaImmagine: autore.immagineBuffer,
    gruppi: [{ periodo: null, canti }],
    renderDividers: false,
    columns: 2,
    textField: 'testo',
    indiceAutori: false,
  });
}
