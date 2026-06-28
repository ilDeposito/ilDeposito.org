import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import PDFDocument from 'pdfkit';
import QRCode from 'qrcode';

const SITE_URL = 'https://www.ildeposito.org';
const HEADER_TEXT = 'ilDeposito.org - Canti di protesta politica e sociale';

const PAGE = { width: 595.28, height: 841.89 }; // A4
const MARGIN = { top: 50, bottom: 50, left: 50, right: 50 };
const CONTENT_WIDTH = PAGE.width - MARGIN.left - MARGIN.right;

const COLOR_RED = '#aa0000';
const COLOR_BLACK = '#000000';
const COLOR_GRAY = '#333333';

const __dirname = dirname(fileURLToPath(import.meta.url));
const FONTS_DIR = join(__dirname, '../assets/fonts');

const FONT_FILES = {
  'SourceSans':        join(FONTS_DIR, 'SourceSans3-Medium.ttf'),
  'SourceSans-Italic': join(FONTS_DIR, 'SourceSans3-Italic.ttf'),
  'Bitter':            join(FONTS_DIR, 'Bitter.ttf'),
  'IBMPlexMono':       join(FONTS_DIR, 'IBMPlexMono-Regular.ttf'),
};

function sanitizeText(text) {
  if (!text) return '';
  return text
    .replace(/['']/g, "'")
    .replace(/[""]/g, '"')
    .replace(/—/g, '--')
    .replace(/–/g, '-')
    .replace(/…/g, '...')
    .replace(/ /g, ' ');
}

function stripHtml(html) {
  if (!html) return '';
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/p>\s*<p[^>]*>/gi, '\n\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'")
    .replace(/&nbsp;/g, ' ');
}

function drawHeaderLine(doc) {
  const y = MARGIN.top - 15;
  doc.save()
    .moveTo(MARGIN.left, y)
    .lineTo(PAGE.width - MARGIN.right, y)
    .lineWidth(0.5)
    .strokeColor(COLOR_BLACK)
    .stroke()
    .restore();
}

function drawFooterLine(doc) {
  const y = PAGE.height - MARGIN.bottom + 15;
  doc.save()
    .moveTo(MARGIN.left, y)
    .lineTo(PAGE.width - MARGIN.right, y)
    .lineWidth(0.5)
    .strokeColor(COLOR_BLACK)
    .stroke()
    .restore();
}

function registerFonts(doc) {
  for (const [name, path] of Object.entries(FONT_FILES)) {
    doc.registerFont(name, path);
  }
}

/**
 * @param {object} canto
 * @param {object} options
 * @param {string[]} options.autoriTesto
 * @param {string} options.periodo
 * @param {string[]} options.lingue
 * @param {string[]} options.tags
 * @returns {Promise<Buffer>}
 */
export async function generateCantoPdf(canto, { autoriTesto, periodo, lingue, tags }) {
  const slug = canto.slug;
  const pageUrl = `${SITE_URL}/canti/${slug}`;

  const qrBuffer = await QRCode.toBuffer(pageUrl, {
    width: 200,
    errorCorrectionLevel: 'H',
    margin: 1,
    color: { dark: '#000000', light: '#ffffff' },
  });

  return new Promise((resolve, reject) => {
    const chunks = [];
    const doc = new PDFDocument({
      size: 'A4',
      margins: { top: MARGIN.top, bottom: MARGIN.bottom, left: MARGIN.left, right: MARGIN.right },
      info: {
        Title: `${canto.titolo} - ilDeposito.org`,
        Author: autoriTesto.length > 0 ? autoriTesto.join(', ') : 'ilDeposito.org',
        Subject: 'Canti di protesta politica e sociale',
        Creator: 'ilDeposito.org',
      },
      bufferPages: true,
    });

    registerFonts(doc);

    doc.on('data', (chunk) => chunks.push(chunk));
    doc.on('end', () => resolve(Buffer.concat(chunks)));
    doc.on('error', reject);

    renderCantoPage(doc, canto, { autoriTesto, periodo, lingue, tags, pageUrl, qrBuffer });

    const pageCount = doc.bufferedPageRange().count;
    for (let i = 0; i < pageCount; i++) {
      doc.switchToPage(i);

      doc.save()
        .fontSize(9)
        .font('SourceSans-Italic')
        .fillColor(COLOR_GRAY);

      const headerWidth = doc.widthOfString(HEADER_TEXT);
      doc.text(HEADER_TEXT, (PAGE.width - headerWidth) / 2, MARGIN.top - 30, { lineBreak: false });

      drawHeaderLine(doc);
      drawFooterLine(doc);

      const footerText = `pagina ${i + 1}`;
      const footerWidth = doc.widthOfString(footerText);
      doc.text(footerText, (PAGE.width - footerWidth) / 2, PAGE.height - MARGIN.bottom + 20, { lineBreak: false });

      doc.restore();
    }

    doc.end();
  });
}

function renderCantoPage(doc, canto, { autoriTesto, periodo, lingue, tags, pageUrl, qrBuffer }) {
  const anno = canto.anno ? new Date(canto.anno).getUTCFullYear() : null;
  const testo = sanitizeText(stripHtml(canto.testo || ''));
  const informazioni = canto.informazioni ? sanitizeText(stripHtml(canto.informazioni)) : null;

  const qrSize = 65;
  const qrX = PAGE.width - MARGIN.right - qrSize;
  const qrY = MARGIN.top + 5;
  doc.image(qrBuffer, qrX, qrY, { width: qrSize, height: qrSize });

  const titleMaxWidth = CONTENT_WIDTH - qrSize - 15;

  let y = MARGIN.top + 5;

  doc.font('SourceSans').fontSize(20).fillColor(COLOR_BLACK);
  const titleText = sanitizeText(canto.titolo);
  const titleHeight = doc.heightOfString(titleText, { width: titleMaxWidth, align: 'center' });
  doc.text(titleText, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
  y += titleHeight + 2;

  doc.font('SourceSans').fontSize(11).fillColor(COLOR_BLACK);

  const metaParts = [];
  if (anno) metaParts.push(`(${anno})`);
  if (autoriTesto.length > 0) metaParts.push(`di ${autoriTesto.join(', ')}`);
  if (periodo) metaParts.push(`Periodo: ${sanitizeText(periodo)}`);
  if (lingue.length > 0) metaParts.push(`Lingua: ${lingue.join(', ')}`);
  if (tags.length > 0) metaParts.push(`Tags: ${tags.join(', ')}`);

  for (const part of metaParts) {
    doc.text(part, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
    y = doc.y;
  }

  y += 2;
  const addrFull = `Indirizzo: ${pageUrl}`;
  doc.font('SourceSans').fontSize(10).fillColor(COLOR_RED);
  doc.text(addrFull, MARGIN.left, y, {
    width: titleMaxWidth,
    align: 'center',
    link: pageUrl,
    underline: true,
  });

  const textStartY = Math.max(doc.y + 30, qrY + qrSize + 15);

  // Lyrics — two columns
  doc.font('IBMPlexMono').fontSize(8).fillColor(COLOR_BLACK);

  const colGap = 20;
  const colWidth = (CONTENT_WIDTH - colGap) / 2;
  const rightX = MARGIN.left + colWidth + colGap;
  const pageBottom = PAGE.height - MARGIN.bottom - 10;
  const LINE_GAP = 1;
  const STANZA_GAP = 5;

  const lines = testo.split('\n');
  const linesPerCol = Math.ceil(lines.length / 2);

  let rightStart = linesPerCol;
  while (rightStart < lines.length && lines[rightStart].trim() === '') rightStart++;
  const leftLines = lines.slice(0, linesPerCol);
  const rightLines = lines.slice(rightStart);

  let ly = textStartY;
  let ry = textStartY;
  const maxRows = Math.max(leftLines.length, rightLines.length);

  for (let i = 0; i < maxRows; i++) {
    const lLine = i < leftLines.length ? sanitizeText(leftLines[i]) : null;
    const rLine = i < rightLines.length ? sanitizeText(rightLines[i]) : null;

    const lEmpty = !lLine || lLine.trim() === '';
    const rEmpty = !rLine || rLine.trim() === '';

    if (lEmpty && rEmpty) {
      ly += STANZA_GAP;
      ry += STANZA_GAP;
      continue;
    }

    const lH = lLine && !lEmpty ? doc.heightOfString(lLine, { width: colWidth, lineGap: 0 }) : 0;
    const rH = rLine && !rEmpty ? doc.heightOfString(rLine, { width: colWidth, lineGap: 0 }) : 0;
    const rowH = Math.max(lH, rH);

    if (Math.max(ly, ry) + rowH > pageBottom) {
      doc.addPage();
      ly = MARGIN.top + 10;
      ry = MARGIN.top + 10;
    }

    if (lLine && !lEmpty) {
      doc.text(lLine, MARGIN.left, ly, { width: colWidth, lineGap: 0 });
      ly += lH + LINE_GAP;
    } else {
      ly += STANZA_GAP;
    }

    if (rLine && !rEmpty) {
      doc.text(rLine, rightX, ry, { width: colWidth, lineGap: 0 });
      ry += rH + LINE_GAP;
    } else {
      ry += STANZA_GAP;
    }
  }

  const endY = Math.max(ly, ry);

  if (informazioni) {
    let infoY = endY + 20;

    if (infoY + 60 > PAGE.height - MARGIN.bottom) {
      doc.addPage();
      infoY = MARGIN.top + 10;
    }

    doc.font('SourceSans').fontSize(14).fillColor(COLOR_BLACK);
    doc.text('Informazioni', MARGIN.left, infoY, { width: CONTENT_WIDTH });
    infoY = doc.y + 5;

    doc.font('SourceSans').fontSize(11).fillColor(COLOR_GRAY);
    doc.text(informazioni, MARGIN.left, infoY, { width: CONTENT_WIDTH });
  }
}
