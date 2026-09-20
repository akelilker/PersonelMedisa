/** Mevcut tablo state'ini disa aktarir; yeni ag istegi yok. */

export function toCsvValue(value: unknown): string {
  if (value === null || value === undefined) {
    return "";
  }
  let s = typeof value === "object" ? JSON.stringify(value) : String(value);
  // Excel formula injection guard (mirror api/src/Http/CsvResponse.php).
  if (/^[=+\-@]/.test(s)) {
    s = `'${s}`;
  }
  const needsQuote = /[",\n\r]/.test(s);
  const escaped = s.replace(/"/g, '""');
  return needsQuote ? `"${escaped}"` : escaped;
}

export function buildCsv(columns: string[], rows: Array<Record<string, unknown>>): string {
  const header = columns.map(toCsvValue).join(",");
  const lines = rows.map((row) => columns.map((c) => toCsvValue(row[c])).join(","));
  return [header, ...lines].join("\r\n");
}

export function downloadTextFile(filename: string, content: string, mime: string): void {
  const blob = new Blob([content], { type: mime });
  const url = URL.createObjectURL(blob);
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

export function downloadReportCsv(filename: string, columns: string[], rows: Array<Record<string, unknown>>): void {
  const csv = buildCsv(columns, rows);
  downloadTextFile(filename, csv, "text/csv;charset=utf-8");
}
