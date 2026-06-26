import {
  formatSurecStateLabel,
  formatSurecTuruLabel,
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

function normalizeTimelineText(value: string | null | undefined) {
  const formatted = formatDetailValue(value);
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

  if (altBaslik && altBaslik !== anaBaslik) {
    return `${anaBaslik} / ${altBaslik}`;
  }

  return anaBaslik;
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

function buildSurecDateRangeSummary(surec: Surec): string {
  const bas = normalizeTimelineText(surec.baslangic_tarihi);
  const bit = normalizeTimelineText(surec.bitis_tarihi);
  const eff = normalizeTimelineText(surec.effective_date);
  if (bas && bit && bas !== bit) {
    return `${bas} – ${bit}`;
  }
  if (bas && bit) {
    return bas;
  }
  if (bas) {
    return bas;
  }
  if (bit) {
    return bit;
  }
  if (eff) {
    return eff;
  }
  return "";
}

function buildSurecTimelinePrimaryDate(surec: Surec): string | null {
  const eff = normalizeTimelineText(surec.effective_date);
  const basB = normalizeTimelineText(surec.baslangic_tarihi);
  const bitB = normalizeTimelineText(surec.bitis_tarihi);
  if (eff) {
    return `Geçerlilik: ${eff}`;
  }
  if (basB && bitB) {
    return `Dönem: ${basB} – ${bitB}`;
  }
  if (basB) {
    return `Başlangıç: ${basB}`;
  }
  if (bitB) {
    return `Bitiş: ${bitB}`;
  }
  return null;
}

function buildSurecOzet(surec: Surec) {
  const surecTuru = surec.surec_turu.trim().toUpperCase();
  const dates = buildSurecDateRangeSummary(surec);

  if (YONETIM_TIMELINE_SUREC_TYPES.has(surecTuru)) {
    return "Yönetim panelinden rol ve yetki güncellemesi.";
  }

  if (BAGLI_AMIR_TIMELINE_SUREC_TYPES.has(surecTuru)) {
    return "Bağlı amir bilgisi güncellendi.";
  }

  if (surecTuru === "ISTEN_AYRILMA") {
    return joinTimelineOzetParts([
      dates ? `Son çalışma: ${dates}` : null,
      "İş akdi sonlanır; kart pasife işlenir."
    ]);
  }

  if (surecTuru === "ORG_DEGISIKLIK" || surecTuru === "POZISYON_DEGISTI") {
    return joinTimelineOzetParts([dates ? `Dönem: ${dates}` : null, "Departman veya görev bilgisi güncellendi."]);
  }

  if (surecTuru === "RAPOR") {
    return joinTimelineOzetParts([dates ? `Dönem: ${dates}` : null, "Raporlu dönem kaydı."]);
  }

  if (IZIN_ANA_TUR_SET.has(surecTuru)) {
    const ucret =
      surec.ucretli_mi === true ? "Ücretli" : surec.ucretli_mi === false ? "Ücretsiz" : null;
    const alt = surec.alt_tur ? formatSurecTuruLabel(surec.alt_tur) : null;
    return joinTimelineOzetParts([
      alt,
      ucret,
      dates ? `Dönem: ${dates}` : null,
      "İzin kaydı."
    ]);
  }

  if (
    surecTuru.includes("GEC_GELDI") ||
    surecTuru.includes("ERKEN_CIKTI") ||
    surecTuru.includes("DEVAMSIZ") ||
    surecTuru.includes("GELMEDI")
  ) {
    return joinTimelineOzetParts([dates ? `Tarih: ${dates}` : null, "Devamsızlık veya puantaj sapması."]);
  }

  const parts = [
    surec.baslangic_tarihi ? `Başlangıç: ${surec.baslangic_tarihi}` : null,
    surec.bitis_tarihi ? `Bitiş: ${surec.bitis_tarihi}` : null
  ].filter((part): part is string => part !== null);

  return parts.length > 0 ? parts.join(" · ") : "Süreç kaydı.";
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
      aciklama: normalizeTimelineText(surec.aciklama) ?? undefined,
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
