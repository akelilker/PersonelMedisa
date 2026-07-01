export type RaporKaynakKodu = "SNAPSHOT" | "LIVE" | "FINANS" | "SUREC";

const RAPOR_KAYNAK_LABELS: Record<RaporKaynakKodu, string> = {
  SNAPSHOT: "Mühürlü snapshot",
  LIVE: "Canlı veri",
  FINANS: "Finans kaydı",
  SUREC: "Süreç kaydı"
};

export const RAPOR_LIVE_KAYNAK_UYARI =
  "Bu rapor canlı veriden oluşturuldu; veriler değişebilir.";

export function getRaporKaynakLabel(kaynak: string | undefined): string | null {
  if (!kaynak) {
    return null;
  }

  if (kaynak in RAPOR_KAYNAK_LABELS) {
    return RAPOR_KAYNAK_LABELS[kaynak as RaporKaynakKodu];
  }

  return null;
}

export function buildRaporKaynakMetaLine(options: {
  kaynak?: string;
  donem?: string | null;
  muhur_id?: number | null;
  kayitSayisi: number;
}): string | null {
  const kaynakLabel = getRaporKaynakLabel(options.kaynak);
  if (!kaynakLabel) {
    return null;
  }

  const parts = [`Kaynak: ${kaynakLabel}`];

  if (options.donem) {
    parts.push(`Dönem: ${options.donem}`);
  }

  if (options.muhur_id !== undefined && options.muhur_id !== null && options.muhur_id > 0) {
    parts.push(`Mühür ID: ${options.muhur_id}`);
  }

  parts.push(`Kayıt: ${options.kayitSayisi}`);

  return parts.join(" · ");
}

export function isRaporLiveKaynak(kaynak: string | undefined): boolean {
  return kaynak === "LIVE";
}
