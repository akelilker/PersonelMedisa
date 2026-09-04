import { formatBildirimTuruLabel } from "../../lib/display/enum-display";
import { resolveLateMinutes } from "../../lib/bildirim/gunluk-personel-durum-semantics";

export const BIRIM_AMIRI_DURUM_KEYS = [
  "GELDI",
  "GELMEDI",
  "GEC_GELDI",
  "IZINLI",
  "RAPORLU",
  "ERKEN_CIKTI",
  "GOREVDE",
  "DIGER",
  "HENUZ_DEGERLENDIRILMEDI"
] as const;

export type BirimAmiriPersonelDurum = (typeof BIRIM_AMIRI_DURUM_KEYS)[number];

const EXCEPTION_TURLERI = new Set<string>([
  "GELMEDI",
  "GEC_GELDI",
  "ERKEN_CIKTI",
  "IZINLI",
  "RAPORLU",
  "GOREVDE",
  "DIGER"
]);

/** Canonical evidence gates — shared with IK Bugün dashboard (PR #255). */
export function resolveBirimAmiriPersonelDurum(
  bildirimTuru: string | null | undefined,
  attendanceGiris: string | null | undefined = null,
  unitCompleted = false,
  storedDakika: number | null = null
): BirimAmiriPersonelDurum {
  const tur = (bildirimTuru ?? "").trim().toUpperCase();
  if (EXCEPTION_TURLERI.has(tur)) {
    return tur as BirimAmiriPersonelDurum;
  }
  const giris = (attendanceGiris ?? "").trim();
  if (giris !== "") {
    const late = resolveLateMinutes(giris, storedDakika);
    return late != null && late > 0 ? "GEC_GELDI" : "GELDI";
  }
  if (unitCompleted) {
    return "GELDI";
  }
  return "HENUZ_DEGERLENDIRILMEDI";
}

/** @deprecated Prefer resolveBirimAmiriPersonelDurum with evidence args. */
export function deriveBirimAmiriPersonelDurum(
  bildirimTuru: string | null | undefined
): BirimAmiriPersonelDurum {
  return resolveBirimAmiriPersonelDurum(bildirimTuru, null, false);
}

export type BirimAmiriOzetCounts = {
  toplam_personel: number;
  geldi: number;
  gelmedi: number;
  gec_geldi: number;
  izinli: number;
  raporlu: number;
  izinli_raporlu: number;
  erken_cikti: number;
  gorevde: number;
  henuz_degerlendirilmedi: number;
};

export function buildBirimAmiriOzetCounts(
  rows: Array<{ durum: string }>
): BirimAmiriOzetCounts {
  const ozet: BirimAmiriOzetCounts = {
    toplam_personel: rows.length,
    geldi: 0,
    gelmedi: 0,
    gec_geldi: 0,
    izinli: 0,
    raporlu: 0,
    izinli_raporlu: 0,
    erken_cikti: 0,
    gorevde: 0,
    henuz_degerlendirilmedi: 0
  };

  for (const row of rows) {
    const durum = row.durum.trim().toUpperCase();
    if (durum === "GELMEDI") ozet.gelmedi += 1;
    else if (durum === "GEC_GELDI") ozet.gec_geldi += 1;
    else if (durum === "IZINLI") ozet.izinli += 1;
    else if (durum === "RAPORLU") ozet.raporlu += 1;
    else if (durum === "ERKEN_CIKTI") ozet.erken_cikti += 1;
    else if (durum === "GOREVDE") ozet.gorevde += 1;
    else if (durum === "HENUZ_DEGERLENDIRILMEDI") ozet.henuz_degerlendirilmedi += 1;
    else if (durum === "GELDI" || durum === "DIGER") ozet.geldi += 1;
    else ozet.henuz_degerlendirilmedi += 1;
  }
  ozet.izinli_raporlu = ozet.izinli + ozet.raporlu;

  return ozet;
}

export function birimAmiriCountsSatisfyInvariant(counts: BirimAmiriOzetCounts): boolean {
  const sum =
    counts.geldi +
    counts.gec_geldi +
    counts.gelmedi +
    counts.izinli +
    counts.raporlu +
    counts.gorevde +
    counts.erken_cikti +
    counts.henuz_degerlendirilmedi;
  return sum === counts.toplam_personel;
}

export function formatBirimAmiriDurumLabel(durum: string | null | undefined): string {
  const key = (durum ?? "").trim().toUpperCase();
  if (key === "GELDI") {
    return "Geldi";
  }
  if (key === "HENUZ_DEGERLENDIRILMEDI") {
    return "Henüz Değerlendirilmedi";
  }
  const labeled = formatBildirimTuruLabel(key);
  return labeled === "-" ? key : labeled;
}

function positiveDakika(value: number | null | undefined): number | null {
  if (typeof value !== "number" || !Number.isFinite(value) || value <= 0) {
    return null;
  }
  return Math.trunc(value);
}

export function formatBirimAmiriPersonelStatusLine(input: {
  durum: string;
  gec_kalma_dakika?: number | null;
  erken_cikis_dakika?: number | null;
  giris_saati?: string | null;
  cikis_saati?: string | null;
}): string {
  const durum = input.durum.trim().toUpperCase();
  const label = formatBirimAmiriDurumLabel(durum);
  const parts = [label];

  if (durum === "GEC_GELDI") {
    const dakika = positiveDakika(input.gec_kalma_dakika);
    if (dakika) {
      parts.push(`${dakika} Dakika`);
    }
  } else if (durum === "ERKEN_CIKTI") {
    const dakika = positiveDakika(input.erken_cikis_dakika);
    if (dakika) {
      parts.push(`${dakika} Dakika`);
    }
  }

  const times = [input.giris_saati, input.cikis_saati].filter(
    (value): value is string => typeof value === "string" && value.trim().length > 0
  );
  if (times.length > 0 && durum !== "HENUZ_DEGERLENDIRILMEDI") {
    parts.push(times.join("–"));
  }

  return parts.join(" · ");
}

export function istanbulBusinessDate(now = new Date()): string {
  return new Intl.DateTimeFormat("en-CA", { timeZone: "Europe/Istanbul" }).format(now);
}

export type BirimAmiriGunlukDurumBuildRow = {
  personel_id: number;
  ad_soyad: string;
  aktif_durum?: string | null;
  ise_giris_tarihi?: string | null;
  birim_id?: number | null;
  bildirim_turu?: string | null;
  dakika?: number | null;
  baslangic_saati?: string | null;
  bitis_saati?: string | null;
  giris_saati?: string | null;
  cikis_saati?: string | null;
  gec_kalma_dakika?: number | null;
  erken_cikis_dakika?: number | null;
  unit_completed?: boolean;
};

export function mapBirimAmiriPersonelRow(input: BirimAmiriGunlukDurumBuildRow) {
  const dakika =
    typeof input.dakika === "number" && Number.isFinite(input.dakika) ? Math.trunc(input.dakika) : null;
  const stored = dakika && dakika > 0 ? dakika : positiveDakika(input.gec_kalma_dakika);
  const attendanceGiris = input.giris_saati ?? null;
  const durum = resolveBirimAmiriPersonelDurum(
    input.bildirim_turu,
    attendanceGiris,
    input.unit_completed === true,
    stored
  );
  const gec =
    durum === "GEC_GELDI"
      ? resolveLateMinutes(input.baslangic_saati || attendanceGiris, stored)
      : null;
  const erken =
    durum === "ERKEN_CIKTI"
      ? dakika && dakika > 0
        ? dakika
        : positiveDakika(input.erken_cikis_dakika)
      : null;
  const giris = input.baslangic_saati || input.giris_saati || null;
  const cikis = input.bitis_saati || input.cikis_saati || null;

  return {
    personel_id: input.personel_id,
    ad_soyad: input.ad_soyad,
    durum,
    durum_label: formatBirimAmiriDurumLabel(durum),
    gec_kalma_dakika: gec,
    erken_cikis_dakika: erken,
    giris_saati: giris,
    cikis_saati: cikis
  };
}

export function isAktifBirimPersonelForDate(
  personel: {
    aktif_durum?: string | null;
    ise_giris_tarihi?: string | null;
    birim_id?: number | null;
  },
  allowedBirimIds: number[],
  tarih: string
): boolean {
  if ((personel.aktif_durum ?? "AKTIF").toUpperCase() !== "AKTIF") {
    return false;
  }
  if ((personel.ise_giris_tarihi ?? "1900-01-01") > tarih) {
    return false;
  }
  const birimId = personel.birim_id ?? 0;
  return birimId > 0 && allowedBirimIds.includes(birimId);
}
