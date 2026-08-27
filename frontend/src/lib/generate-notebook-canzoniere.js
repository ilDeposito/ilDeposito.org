import PDFDocument from 'pdfkit';
import { PDFDocument as PDFLibDocument } from 'pdf-lib';
import {
  PAGE, MARGIN, CONTENT_WIDTH, COLOR_BLACK, COLOR_GRAY,
  sanitizeText, stripHtml, drawHeaderLine, drawFooterLine, registerFonts,
} from './generate-pdf.js';

const HEADER_TEXT = 'ilDeposito.org - Canzoniere per NotebookLM';

function pdfToBuffer(doc) {
  return new Promise((resolve, reject) => {
    const chunks = [];
    doc.on('data', (chunk) => chunks.push(chunk));
    doc.on('end', () => resolve(Buffer.concat(chunks)));
    doc.on('error', reject);
    doc.end();
  });
}

function newDoc(title) {
  const doc = new PDFDocument({
    size: 'A4', margins: MARGIN, bufferPages: true,
    info: { Title: `${title} - ilDeposito.org`, Author: 'ilDeposito.org', Subject: 'Canti di protesta politica e sociale', Creator: 'ilDeposito.org' },
  });
  registerFonts(doc);
  return doc;
}

function applyHeaderFooter(doc, offset) {
  const range = doc.bufferedPageRange();
  for (let i = 0; i < range.count; i++) {
    doc.switchToPage(i);
    doc.save().font('SourceSans-Italic').fontSize(9).fillColor(COLOR_GRAY);
    doc.text(HEADER_TEXT, MARGIN.left, MARGIN.top - 30, { width: CONTENT_WIDTH, align: 'center', lineBreak: false });
    drawHeaderLine(doc); drawFooterLine(doc);
    doc.text(`pagina ${offset + i + 1}`, MARGIN.left, PAGE.height - MARGIN.bottom + 20, { width: CONTENT_WIDTH, align: 'center', lineBreak: false });
    doc.restore();
  }
  return range.count;
}

async function merge(buffers, title) {
  const merged = await PDFLibDocument.create();
  merged.setTitle(`${title} - ilDeposito.org`); merged.setAuthor('ilDeposito.org');
  for (const buffer of buffers) {
    const source = await PDFLibDocument.load(buffer);
    const pages = await merged.copyPages(source, source.getPageIndices());
    pages.forEach((page) => merged.addPage(page));
  }
  return Buffer.from(await merged.save());
}

async function splitFirstPage(buffer) {
  const source = await PDFLibDocument.load(buffer);
  const cover = await PDFLibDocument.create();
  const [coverPage] = await cover.copyPages(source, [0]); cover.addPage(coverPage);
  const rest = await PDFLibDocument.create();
  const indices = Array.from({ length: source.getPageCount() - 1 }, (_, i) => i + 1);
  const pages = await rest.copyPages(source, indices); pages.forEach((page) => rest.addPage(page));
  return { cover: Buffer.from(await cover.save()), rest: Buffer.from(await rest.save()) };
}

function clean(value) { return sanitizeText(stripHtml(value ?? '')).replace(/\s+\n/g, '\n').trim(); }
function formatDate(value) {
  if (!value) return null;
  const date = new Date(value);
  return Number.isNaN(date.valueOf()) ? clean(value) : date.toLocaleDateString('it-IT', { timeZone: 'UTC', year: 'numeric', month: 'long', day: 'numeric' });
}

function label(doc, title, value, y) {
  if (!value) return y;
  doc.font('SourceSans').fontSize(10).fillColor(COLOR_BLACK);
  doc.text(`${title}: `, MARGIN.left, y, { continued: true });
  doc.font('SourceSans').fillColor(COLOR_GRAY).text(clean(value), { width: CONTENT_WIDTH });
  return doc.y + 2;
}

function renderCover(doc, title) {
  let y = MARGIN.top + 150;
  doc.font('Bitter').fontSize(28).fillColor(COLOR_BLACK).text(title, MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
  y = doc.y + 18;
  doc.font('SourceSans').fontSize(14).fillColor(COLOR_GRAY).text('Archivio completo dei testi con dati catalografici ed eventi collegati', MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' });
  doc.font('SourceSans').fontSize(10).text(`Generato il ${new Date().toLocaleDateString('it-IT')} — ilDeposito.org`, MARGIN.left, PAGE.height - MARGIN.bottom - 20, { width: CONTENT_WIDTH, align: 'center' });
}

function renderPeriodo(doc, periodo) {
  doc.font('Bitter').fontSize(22).fillColor(COLOR_BLACK).text(`Periodo: ${clean(periodo)}`, MARGIN.left, MARGIN.top + 80, { width: CONTENT_WIDTH, align: 'center' });
}

function renderCanto(doc, canto) {
  let y = MARGIN.top + 8;
  const bottom = PAGE.height - MARGIN.bottom - 10;
  const ensure = (height) => { if (y + height > bottom) { doc.addPage(); y = MARGIN.top + 10; } };
  const text = (heading, value) => {
    if (!value) return;
    ensure(32); y = label(doc, heading, value, y);
  };

  doc.font('Bitter').fontSize(19).fillColor(COLOR_BLACK);
  const title = `Canto: ${clean(canto.titolo)}`;
  const titleHeight = doc.heightOfString(title, { width: CONTENT_WIDTH }); ensure(titleHeight + 8);
  doc.text(title, MARGIN.left, y, { width: CONTENT_WIDTH }); y = doc.y + 8;
  const year = canto.anno ? new Date(canto.anno).getUTCFullYear() : null;
  text('Anno', year ? String(year) : null);
  text('Autori del testo', canto.autoriTesto.join(', '));
  text('Autori della musica', canto.autoriMusica.join(', '));
  text('Lingue', canto.lingue.join(', '));
  text('Tag', canto.tags.join(', '));
  text('Tematiche', canto.tematiche.join(', '));
  text('Fonte', canto.fonte);
  for (const evento of canto.eventi) {
    text('Evento collegato', evento.titolo);
    text('Data dell\'evento', formatDate(evento.data));
    text('Localizzazione', evento.localizzazioni.join(', '));
    text('Descrizione dell\'evento', evento.descrizione);
  }
  if (canto.informazioni) text('Informazioni sul canto', canto.informazioni);

  ensure(40); y += 8;
  doc.font('SourceSans').fontSize(14).fillColor(COLOR_BLACK).text('Testo', MARGIN.left, y, { width: CONTENT_WIDTH });
  y = doc.y + 6;
  doc.font('IBMPlexMono').fontSize(8).fillColor(COLOR_BLACK);
  for (const raw of clean(canto.testo).split('\n')) {
    if (!raw.trim()) { y += 5; continue; }
    const h = doc.heightOfString(raw, { width: CONTENT_WIDTH });
    ensure(h); doc.text(raw, MARGIN.left, y, { width: CONTENT_WIDTH }); y += h + 1;
  }
}

function groups(canti) {
  const byPeriod = new Map(); const without = [];
  for (const canto of canti) {
    if (!canto.periodo) without.push(canto);
    else { const key = canto.periodo.id; if (!byPeriod.has(key)) byPeriod.set(key, { periodo: canto.periodo, canti: [] }); byPeriod.get(key).canti.push(canto); }
  }
  const sorted = [...byPeriod.values()].sort((a, b) => a.periodo.sort - b.periodo.sort || a.periodo.titolo.localeCompare(b.periodo.titolo, 'it'));
  if (without.length) sorted.push({ periodo: { titolo: 'Senza periodo', sort: Infinity }, canti: without });
  return sorted.map((group) => ({ ...group, canti: group.canti.sort((a, b) => a.titolo.localeCompare(b.titolo, 'it')) }));
}

async function renderIndex(entries, offset) {
  const doc = newDoc('Indice dei canti'); let y = MARGIN.top + 5;
  doc.font('Bitter').fontSize(18).fillColor(COLOR_BLACK).text('Indice dei canti', MARGIN.left, y, { width: CONTENT_WIDTH, align: 'center' }); y = doc.y + 25;
  for (const entry of entries) {
    if (y + 16 > PAGE.height - MARGIN.bottom - 10) { doc.addPage(); y = MARGIN.top + 10; }
    doc.font('SourceSans').fontSize(10).fillColor(COLOR_BLACK).text(clean(entry.titolo), MARGIN.left, y, { width: CONTENT_WIDTH - 50, ellipsis: true, lineBreak: false });
    doc.text(String(entry.pagina), MARGIN.left + CONTENT_WIDTH - 50, y, { width: 50, align: 'right', lineBreak: false }); y += 16;
  }
  const count = applyHeaderFooter(doc, offset);
  return { buffer: await pdfToBuffer(doc), count };
}

export async function generaCanzoniereNotebook(canti) {
  const title = 'ilDeposito — Canzoniere per NotebookLM';
  const doc = newDoc(title); renderCover(doc, title);
  const entries = [];
  for (const group of groups(canti)) {
    doc.addPage(); renderPeriodo(doc, group.periodo.titolo);
    for (const canto of group.canti) {
      doc.addPage(); entries.push({ titolo: canto.titolo, page: doc.bufferedPageRange().count - 1 }); renderCanto(doc, canto);
    }
  }
  const { count: indexPages } = await renderIndex(entries.map((entry) => ({ titolo: entry.titolo, pagina: entry.page + 1 })), 0);
  applyHeaderFooter(doc, indexPages);
  const body = await pdfToBuffer(doc);
  const { cover, rest } = await splitFirstPage(body);
  const { buffer: index } = await renderIndex(entries.map((entry) => ({ titolo: entry.titolo, pagina: indexPages + entry.page + 1 })), 0);
  return merge([cover, index, rest], title);
}
