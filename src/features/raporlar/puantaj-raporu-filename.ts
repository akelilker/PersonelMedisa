export function puantajRaporuXlsxFilename(baslangic?: string, bitis?: string): string {
  if (
    baslangic &&
    bitis &&
    /^\d{4}-\d{2}-\d{2}$/.test(baslangic) &&
    /^\d{4}-\d{2}-\d{2}$/.test(bitis)
  ) {
    const donem = baslangic.slice(0, 7);
    if (bitis.slice(0, 7) === donem) {
      const [yearText, monthText] = donem.split("-");
      const year = Number(yearText);
      const month = Number(monthText);
      const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
      const last = `${donem}-${String(lastDay).padStart(2, "0")}`;
      if (baslangic === `${donem}-01` && bitis === last) {
        return `puantaj-raporu-${donem}.xlsx`;
      }
    }
    return `puantaj-raporu-${baslangic}_${bitis}.xlsx`;
  }

  return "puantaj-raporu.xlsx";
}
