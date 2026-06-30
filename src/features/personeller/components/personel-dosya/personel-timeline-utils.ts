import {
  formatSurecStateLabel,
  formatSurecTuruLabel,
  normalizeEnumKey,
  formatZimmetKayitDurumuLabel,
  formatZimmetTeslimDurumuLabel,
  formatZimmetUrunTuruLabel
} from "../../../../lib/display/enum-display";
import { getSurecTimelineSortWeight } from "../../../../lib/surec-history-sort";
import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import type { Zimmet } from "../../../../types/zimmet";
import { formatDetailValue } from "./personel-dosya-format-utils";

export type PersonelTimelineEventTone = "default" | "danger";

export type PersonelTimelineEvent = {
  id: string;
  tarih: string | null;
  zamanIkincil?: string;
  baslik: string;
  kaynak: string;
  ozet: string;
  aciklama?: string;
  etiket?: string;
  tone?: PersonelTimelineEventTone;
  sortValue: number;
  sortRank: number;
};

const YONETIM_TIMELINE_SUREC_TYPES = new Set([
  "BIRIM_AMIRI_ATANDI",
  "BIRIM_AMIRI_ATAMASI_KALDIRILDI",
  "SUBE_YETKISI_DEGISTI"
]);

const BAGLI_AMIR_TIMELINE_SUREC_TYPES = new Set([
  "BAGLI_AMIR_ATANDI",
  "BAGLI_AMIR_DEGISTI",
  "BAGLI_AMIR_ATAMASI_KALDIRILDI"
]);

const IZIN_ANA_TUR_SET = new Set([
  "IZIN",
  "YILLIK_IZIN",
  "MAZERET_IZNI",
  "UCRETSIZ_IZIN",
  "DOGUM_IZNI",
  "EVLILIK_IZNI"
]);

function parseTimelineDate(value: string | null | undefined) {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = Date.parse(`${trimmed}T00:00:00`);
  return Number.isFinite(parsed) ? parsed : null;
}

function looksLikeRawJsonLeak(value: string): boolean {
  const trimmed = value.trim();
  if (!trimmed) {
    return true;
  }

  if (trimmed.includes("[object Object]")) {
    return true;
  }

  if (trimmed.startsWith("{") || trimmed.startsWith("[")) {
    return true;
  }

  if (/"tip"\s*:/.test(trimmed)) {
    return true;
  }

  return false;
}

function sanitizeTimelineDisplayText(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed || looksLikeRawJsonLeak(trimmed)) {
    return null;
  }

  return trimmed;
}

type PersonelBelgeKaydiSurecMetadata = {
  ad: string;
  userAciklama: string | null;
};

function parsePersonelBelgeKaydiSurecMetadata(
  value: string | null | undefined
): PersonelBelgeKaydiSurecMetadata | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed.startsWith("{")) {
    return null;
  }

  let parsed: unknown;
  try {
    parsed = JSON.parse(trimmed);
  } catch {
    return null;
  }

  if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed)) {
    return null;
  }

  const record = parsed as Record<string, unknown>;
  if (record._personel_belge_kaydi !== true) {
    return null;
  }

  const ad = typeof record.ad === "string" ? record.ad.trim() : "";
  if (!ad || looksLikeRawJsonLeak(ad)) {
    return null;
  }

  const userAciklama =
    typeof record.aciklama === "string" ? sanitizeTimelineDisplayText(record.aciklama) : null;

  return { ad, userAciklama };
}

function resolveSurecTimelineAciklama(surec: Surec): string | null {
  if (surec.surec_turu.trim().toUpperCase() === "BELGE") {
    const metadata = parsePersonelBelgeKaydiSurecMetadata(surec.aciklama);
    if (metadata) {
      return metadata.userAciklama;
    }
  }

  return normalizeTimelineText(surec.aciklama);
}

function normalizeTimelineText(value: string | null | undefined) {
  const sanitized = sanitizeTimelineDisplayText(value);
  if (!sanitized) {
    return null;
  }

  const formatted = formatDetailValue(sanitized);
  return formatted === "-" ? null : formatted;
}

function createTimelineSortValue(value: string | null | undefined) {
  const parsed = parseTimelineDate(value);
  return parsed ?? Number.NEGATIVE_INFINITY;
}

function buildSurecTitle(surec: Surec) {
  const anaBaslik = formatSurecTuruLabel(surec.surec_turu);
  const altBaslik = normalizeTimelineText(
    surec.alt_tur ? formatSurecTuruLabel(surec.alt_tur) : null
  );

  if (!altBaslik || altBaslik === anaBaslik) {
    return anaBaslik;
  }

  const anaKey = normalizeEnumKey(surec.surec_turu);
  const altKey = normalizeEnumKey(surec.alt_tur ?? "");
  if (altKey && altKey === anaKey) {
    return anaBaslik;
  }

  return `${anaBaslik} / ${altBaslik}`;
}

function formatSurecKayitZamani(value: string | undefined): string | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  if (!trimmed) {
    return null;
  }

  const parsed = Date.parse(trimmed);
  if (!Number.isFinite(parsed)) {
    return null;
  }

  return new Intl.DateTimeFormat("tr-TR", { dateStyle: "short", timeStyle: "short" }).format(new Date(parsed));
}

function joinTimelineOzetParts(parts: Array<string | null | undefined>) {
  return parts
    .map((p) => (typeof p === "string" ? p.trim() : ""))
    .filter(Boolean)
    .join(" · ");
}

function buildSurecTimelinePrimaryDate(surec: Surec): string | null {
  const eff = normalizeTimelineText(surec.effective_date);
  const bas = normalizeTimelineText(surec.baslangic_tarihi);
  const bit = normalizeTimelineText(surec.bitis_tarihi);

  if (eff) {
    return `Geçerlilik: ${eff}`;
  }

  if (bas && bit) {
    if (bas === bit) {
      return `Tarih: ${bas}`;
    }

    return `Başlangıç: ${bas} · Bitiş: ${bit}`;
  }

  if (bas) {
    return `Tarih: ${bas}`;
  }

  if (bit) {
    return `Tarih: ${bit}`;
  }

  return null;
}

function buildSurecOzet(surec: Surec) {
  const surecTuru = surec.surec_turu.trim().toUpperCase();

  if (YONETIM_TIMELINE_SUREC_TYPES.has(surecTuru)) {
    return "Yönetim panelinden rol ve yetki güncellemesi.";
  }

  if (BAGLI_AMIR_TIMELINE_SUREC_TYPES.has(surecTuru)) {
    return "Bağlı amir bilgisi güncellendi.";
  }

  if (surecTuru === "ISTEN_AYRILMA") {
    return "İş akdi sonlanır; kart pasife işlenir.";
  }

  if (surecTuru === "ORG_DEGISIKLIK" || surecTuru === "POZISYON_DEGISTI") {
    return "Departman veya görev bilgisi güncellendi.";
  }

  if (surecTuru === "RAPOR") {
    return "Raporlu dönem kaydı.";
  }

  if (surecTuru === "BELGE") {
    const metadata = parsePersonelBelgeKaydiSurecMetadata(surec.aciklama);
    if (metadata?.ad) {
      return metadata.ad;
    }

    return "Belge kaydı.";
  }

  if (surecTuru === "TESVIK") {
    return "Teşvik kaydı.";
  }

  if (surecTuru === "IS_KAZASI") {
    return "İş kazası kaydı.";
  }

  if (IZIN_ANA_TUR_SET.has(surecTuru)) {
    const ucret =
      surec.ucretli_mi === true ? "Ücretli" : surec.ucretli_mi === false ? "Ücretsiz" : null;
    return joinTimelineOzetParts([ucret, "İzin kaydı."]);
  }

  if (
    surecTuru.includes("GEC_GELDI") ||
    surecTuru.includes("ERKEN_CIKTI") ||
    surecTuru.includes("DEVAMSIZ") ||
    surecTuru.includes("GELMEDI")
  ) {
    return "Devamsızlık veya puantaj sapması.";
  }

  return "Süreç kaydı.";
}

export function buildPersonelTimeline(
  personel: Personel,
  surecler: Surec[],
  zimmetler: Zimmet[]
): PersonelTimelineEvent[] {
  const events: PersonelTimelineEvent[] = [];
  const iseGirisTarihi = normalizeTimelineText(personel.ise_giris_tarihi);

  if (iseGirisTarihi) {
    const iseGirisOzeti = [
      normalizeTimelineText(personel.sicil_no) ? `Sicil ${personel.sicil_no}` : null,
      normalizeTimelineText(personel.departman_adi),
      normalizeTimelineText(personel.gorev_adi)
    ]
      .filter((part): part is string => part !== null)
      .join(" / ");

    events.push({
      id: `personel-ise-giris-${personel.id}`,
      tarih: iseGirisTarihi,
      baslik: "İşe Giriş",
      kaynak: "Personel kartı",
      ozet: iseGirisOzeti || "Kuruma giriş kaydı.",
      sortValue: createTimelineSortValue(iseGirisTarihi),
      sortRank: 4
    });
  }

  for (const surec of surecler) {
    const tarih = buildSurecTimelinePrimaryDate(surec);
    const kayit = formatSurecKayitZamani(surec.created_at);
    const surecTuru = surec.surec_turu.trim().toUpperCase();
    events.push({
      id: `surec-${surec.id}`,
      tarih,
      zamanIkincil: kayit ? `Kayıt: ${kayit}` : undefined,
      baslik: buildSurecTitle(surec),
      kaynak: YONETIM_TIMELINE_SUREC_TYPES.has(surecTuru) ? "Yönetim" : "Süreç",
      ozet: buildSurecOzet(surec),
      aciklama: resolveSurecTimelineAciklama(surec) ?? undefined,
      etiket: normalizeTimelineText(formatSurecStateLabel(surec.state)) ?? undefined,
      tone: surecTuru === "ISTEN_AYRILMA" ? "danger" : "default",
      sortValue: getSurecTimelineSortWeight(surec),
      sortRank: surecTuru === "ISTEN_AYRILMA" ? 0 : 1
    });
  }

  for (const zimmet of zimmetler) {
    const teslimTarihi = normalizeTimelineText(zimmet.teslim_tarihi);
    const urun = formatZimmetUrunTuruLabel(zimmet.urun_turu);
    const teslimDurumu = formatZimmetTeslimDurumuLabel(zimmet.teslim_durumu);
    const teslimEden = normalizeTimelineText(zimmet.teslim_eden);
    const ortakAciklama = normalizeTimelineText(zimmet.aciklama) ?? undefined;

    if (teslimTarihi) {
      const teslimOzet = [urun, teslimDurumu, teslimEden].filter((part): part is string => Boolean(part)).join(" · ");
      events.push({
        id: `zimmet-teslim-${zimmet.id}`,
        tarih: `Teslim: ${teslimTarihi}`,
        baslik: "Zimmet teslimi",
        kaynak: "Zimmet",
        ozet: teslimOzet || "Teslim edilen ürün kaydı.",
        aciklama: ortakAciklama,
        etiket: normalizeTimelineText(formatZimmetKayitDurumuLabel(zimmet.zimmet_durumu)) ?? undefined,
        sortValue: createTimelineSortValue(teslimTarihi),
        sortRank: 2
      });
    }

    const iadeTarihi = normalizeTimelineText(zimmet.iade_tarihi);
    if (iadeTarihi) {
      const iadeOzet = [urun, teslimEden].filter((part): part is string => Boolean(part)).join(" · ");
      events.push({
        id: `zimmet-iade-${zimmet.id}`,
        tarih: `İade: ${iadeTarihi}`,
        baslik: "Zimmet iadesi",
        kaynak: "Zimmet",
        ozet: iadeOzet || "Ürün iade kaydı.",
        aciklama: ortakAciklama,
        etiket: formatZimmetKayitDurumuLabel("IADE_EDILDI"),
        sortValue: createTimelineSortValue(iadeTarihi),
        sortRank: 3
      });
    }
  }

  return [...events].sort((left, right) => {
    if (right.sortValue !== left.sortValue) {
      return right.sortValue - left.sortValue;
    }

    if (left.sortRank !== right.sortRank) {
      return left.sortRank - right.sortRank;
    }

    return right.id.localeCompare(left.id, "tr");
  });
}
