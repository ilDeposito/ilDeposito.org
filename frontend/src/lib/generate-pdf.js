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

function sanitizeText(text) {
  if (!text) return '';
  return text
    .replace(/[‘’]/g, "'")
    .replace(/[“”]/g, '"')
    .replace(/—/g, '--')
    .replace(/–/g, '-')
    .replace(/…/g, '...')
    .replace(/ /g, ' ');
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

    doc.on('data', (chunk) => chunks.push(chunk));
    doc.on('end', () => resolve(Buffer.concat(chunks)));
    doc.on('error', reject);

    renderCantoPage(doc, canto, { autoriTesto, periodo, lingue, tags, pageUrl, qrBuffer });

    const pageCount = doc.bufferedPageRange().count;
    for (let i = 0; i < pageCount; i++) {
      doc.switchToPage(i);

      doc.save()
        .fontSize(9)
        .font('Helvetica-Oblique')
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
  const testo = sanitizeText(canto.testo || '');
  const informazioni = canto.informazioni ? sanitizeText(stripHtml(canto.informazioni)) : null;

  const qrSize = 65;
  const qrX = PAGE.width - MARGIN.right - qrSize;
  const qrY = MARGIN.top + 5;
  doc.image(qrBuffer, qrX, qrY, { width: qrSize, height: qrSize });

  const titleMaxWidth = CONTENT_WIDTH - qrSize - 15;

  let y = MARGIN.top + 10;

  doc.font('Times-Bold').fontSize(24).fillColor(COLOR_BLACK);
  const titleText = sanitizeText(canto.titolo);
  const titleHeight = doc.heightOfString(titleText, { width: titleMaxWidth, align: 'center' });
  doc.text(titleText, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
  y += titleHeight + 5;

  doc.font('Helvetica').fontSize(13).fillColor(COLOR_BLACK);

  if (anno) {
    doc.text(`(${anno})`, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
    y += doc.currentLineHeight() + 2;
  }

  if (autoriTesto.length > 0) {
    doc.text(`di ${autoriTesto.join(', ')}`, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
    y += doc.currentLineHeight() + 2;
  }

  if (periodo) {
    doc.text(`Periodo: ${sanitizeText(periodo)}`, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
    y += doc.currentLineHeight() + 2;
  }

  if (lingue.length > 0) {
    doc.text(`Lingua: ${lingue.join(', ')}`, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
    y += doc.currentLineHeight() + 2;
  }

  if (tags.length > 0) {
    doc.text(`Tags: ${tags.join(', ')}`, MARGIN.left, y, { width: titleMaxWidth, align: 'center' });
    y += doc.currentLineHeight() + 2;
  }

  const addrFull = `Indirizzo: ${pageUrl}`;
  doc.font('Helvetica').fontSize(11).fillColor(COLOR_RED);
  doc.text(addrFull, MARGIN.left, y, {
    width: titleMaxWidth,
    align: 'center',
    link: pageUrl,
    underline: true,
  });
  y = doc.y + 15;

  const textStartY = Math.max(y, qrY + qrSize + 15);

  doc.font('Courier').fontSize(10).fillColor(COLOR_BLACK);

  const colGap = 20;
  const colWidth = (CONTENT_WIDTH - colGap) / 2;
  const availableHeight = PAGE.height - MARGIN.bottom - textStartY - 10;

  const lines = testo.split('\n');
  const lineH = doc.currentLineHeight() * 1.15;

  const linesPerPage = Math.floor(availableHeight / lineH);
  const linesPerCol = Math.ceil(lines.length / 2);

  const leftLines = lines.slice(0, linesPerCol);
  const rightLines = lines.slice(linesPerCol);

  let currentY = textStartY;
  let lineIndex = 0;

  function renderColumns(leftL, rightL, startY) {
    let ly = startY;
    for (const line of leftL) {
      if (ly + lineH > PAGE.height - MARGIN.bottom - 10) {
        doc.addPage();
        ly = MARGIN.top + 10;
      }
      doc.text(sanitizeText(line), MARGIN.left, ly, { width: colWidth, lineBreak: false });
      ly += lineH;
    }

    let ry = startY;
    const rightX = MARGIN.left + colWidth + colGap;
    for (const line of rightL) {
      if (ry + lineH > PAGE.height - MARGIN.bottom - 10) {
        break;
      }
      doc.text(sanitizeText(line), rightX, ry, { width: colWidth, lineBreak: false });
      ry += lineH;
    }

    return Math.max(ly, ry);
  }

  const endY = renderColumns(leftLines, rightLines, textStartY);

  if (informazioni) {
    let infoY = endY + 20;

    if (infoY + 60 > PAGE.height - MARGIN.bottom) {
      doc.addPage();
      infoY = MARGIN.top + 10;
    }

    doc.font('Times-Bold').fontSize(14).fillColor(COLOR_BLACK);
    doc.text('Informazioni', MARGIN.left, infoY, { width: CONTENT_WIDTH });
    infoY = doc.y + 5;

    doc.font('Helvetica').fontSize(11).fillColor(COLOR_GRAY);
    doc.text(informazioni, MARGIN.left, infoY, { width: CONTENT_WIDTH });
  }
}
