/** Mevcut tablo state'ini disa aktarir; yeni ag istegi yok. */

export function toCsvValue(value: unknown): string {
  if (value === null || value === undefined) {
    return "";
  }
  const s = typeof value === "object" ? JSON.stringify(value) : String(value);
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

/** Print dialog — PDF olarak kaydet maksadiyla tarayiciya birakilir */
export function printCurrentReportWindow(title: string, columns: string[], rows: Array<Record<string, unknown>>): void {
  const w = window.open("", "_blank");
  if (!w) {
    return;
  }
  const headCells = columns.map((c) => `<th>${escapeHtml(c)}</th>`).join("");
  const bodyRows = rows
    .map(
      (row) =>
        `<tr>${columns.map((c) => `<td>${escapeHtml(String(row[c] ?? ""))}</td>`).join("")}</tr>`
    )
    .join("");

  w.document.write(`<!DOCTYPE html><html><head><title>${escapeHtml(title)}</title>
    <style>
      body{font-family:system-ui,sans-serif;margin:16px;}
      table{border-collapse:collapse;width:100%;}
      th,td{border:1px solid #ccc;padding:6px;font-size:12px;}
      th{background:#f4f4f4;}
    </style></head><body>
    <h1>${escapeHtml(title)}</h1>
    <table><thead><tr>${headCells}</tr></thead><tbody>${bodyRows}</tbody></table>
    <script>window.onload=function(){window.print();}</script>
    </body></html>`);
  w.document.close();
}

function escapeHtml(s: string): string {
  return s
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}
