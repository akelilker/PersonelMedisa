/** Demo-only stored OOXML workbook. Production export owner is SimpleXlsxWriter. */

function crc32(bytes: Uint8Array): number {
  let crc = 0xffffffff;
  for (const value of bytes) {
    crc ^= value;
    for (let bit = 0; bit < 8; bit += 1) {
      const mask = -(crc & 1);
      crc = (crc >>> 1) ^ (0xedb88320 & mask);
    }
  }
  return (~crc) >>> 0;
}

function u16(value: number): Uint8Array {
  return Uint8Array.of(value & 0xff, (value >>> 8) & 0xff);
}

function u32(value: number): Uint8Array {
  return Uint8Array.of(value & 0xff, (value >>> 8) & 0xff, (value >>> 16) & 0xff, (value >>> 24) & 0xff);
}

function concatBytes(parts: Uint8Array[]): Uint8Array {
  const total = parts.reduce((sum, part) => sum + part.length, 0);
  const out = new Uint8Array(total);
  let offset = 0;
  for (const part of parts) {
    out.set(part, offset);
    offset += part.length;
  }
  return out;
}

function xmlEscape(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function sanitizeCell(value: string): string {
  if (value !== "" && /^[=+\-@]/.test(value)) {
    return `'${value}`;
  }
  return value;
}

function cellRef(col: number, row: number): string {
  let letters = "";
  let current = col;
  do {
    letters = String.fromCharCode(65 + (current % 26)) + letters;
    current = Math.floor(current / 26) - 1;
  } while (current >= 0);
  return `${letters}${row}`;
}

function sheetXml(headers: string[], rows: string[][]): string {
  const allRows = [headers, ...rows];
  const lines = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'];
  lines.push('<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">');
  lines.push("<sheetData>");
  allRows.forEach((row, rowIndex) => {
    const rowNum = rowIndex + 1;
    lines.push(`<row r="${rowNum}">`);
    row.forEach((cell, col) => {
      const text = xmlEscape(sanitizeCell(cell));
      lines.push(`<c r="${cellRef(col, rowNum)}" t="inlineStr"><is><t>${text}</t></is></c>`);
    });
    lines.push("</row>");
  });
  lines.push("</sheetData></worksheet>");
  return lines.join("");
}

function zipStored(files: Array<{ name: string; data: Uint8Array }>): Uint8Array {
  const locals: Uint8Array[] = [];
  const centrals: Uint8Array[] = [];
  let offset = 0;
  for (const file of files) {
    const name = new TextEncoder().encode(file.name);
    const crc = crc32(file.data);
    const local = concatBytes([
      u32(0x04034b50),
      u16(20),
      u16(0x0800),
      u16(0),
      u16(0),
      u16(0),
      u32(crc),
      u32(file.data.length),
      u32(file.data.length),
      u16(name.length),
      u16(0),
      name,
      file.data
    ]);
    const central = concatBytes([
      u32(0x02014b50),
      u16(20),
      u16(20),
      u16(0x0800),
      u16(0),
      u16(0),
      u16(0),
      u32(crc),
      u32(file.data.length),
      u32(file.data.length),
      u16(name.length),
      u16(0),
      u16(0),
      u16(0),
      u16(0),
      u32(0),
      u32(offset),
      name
    ]);
    locals.push(local);
    centrals.push(central);
    offset += local.length;
  }
  const centralDir = concatBytes(centrals);
  const end = concatBytes([
    u32(0x06054b50),
    u16(0),
    u16(0),
    u16(files.length),
    u16(files.length),
    u32(centralDir.length),
    u32(offset),
    u16(0)
  ]);
  return concatBytes([...locals, centralDir, end]);
}

export function buildStoredXlsx(sheetName: string, headers: string[], rows: string[][]): Uint8Array {
  const safeName = (sheetName.replace(/[[\]*?:/\\]/g, "") || "Sheet").slice(0, 31);
  const encoder = new TextEncoder();
  const sheetCount = 1;
  const files = [
    {
      name: "[Content_Types].xml",
      data: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
          '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' +
          '<Default Extension="xml" ContentType="application/xml"/>' +
          '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
          '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
          `<Override PartName="/xl/worksheets/sheet${sheetCount}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>` +
          "</Types>"
      )
    },
    {
      name: "_rels/.rels",
      data: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
          "</Relationships>"
      )
    },
    {
      name: "xl/workbook.xml",
      data: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
          `<sheets><sheet name="${xmlEscape(safeName)}" sheetId="1" r:id="rId1"/></sheets></workbook>`
      )
    },
    {
      name: "xl/_rels/workbook.xml.rels",
      data: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
          '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' +
          "</Relationships>"
      )
    },
    {
      name: "xl/styles.xml",
      data: encoder.encode(
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
          '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"/>'
      )
    },
    {
      name: "xl/worksheets/sheet1.xml",
      data: encoder.encode(sheetXml(headers, rows))
    }
  ];
  return zipStored(files);
}

export function downloadXlsxBytes(filename: string, bytes: Uint8Array): void {
  const copy = new ArrayBuffer(bytes.byteLength);
  new Uint8Array(copy).set(bytes);
  const blob = new Blob([copy], {
    type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
  });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.click();
  URL.revokeObjectURL(url);
}
