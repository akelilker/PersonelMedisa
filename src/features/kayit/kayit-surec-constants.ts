import { resolveGoreveBaslamaTarihiDefault } from "../../lib/personel/gorev-baslama-tarihi";
import type { Personel } from "../../types/personel";

export const KAYIT_SUREC_PERSONEL_FORM_ID = "kayit-surec-personel-form";
export const KAYIT_SUREC_SUREC_FORM_ID = "kayit-surec-surec-form";
export const KAYIT_SUREC_ZIMMET_FORM_ID = "kayit-surec-zimmet-form";
export const KAYIT_SUREC_MALI_FORM_ID = "kayit-surec-mali-form";
export const KAYIT_SUREC_CEZA_FORM_ID = "kayit-surec-ceza-form";
export const KAYIT_SUREC_BELGELER_FORM_ID = "kayit-surec-belgeler-form";
export const KAYIT_SUREC_POZISYON_FORM_ID = "kayit-surec-pozisyon-form";

/** Personel kartı süreç geçmişi; `usePersonelDetail` ile aynı sayfa boyutu. */
export const KAYIT_SUREC_PERSONEL_HISTORY_LIMIT = 20;
/** `useSurecler` liste sayfa boyutu ile uyumlu. */
export const KAYIT_SUREC_LIST_PAGE_SIZE = 10;

export type DevamsizlikSubId = "izin" | "rapor" | "is_kazasi" | "izinsiz" | "gec" | "erken";

export type PersonelSurecTab =
  | "genel"
  | "puantaj"
  | "haftalik-kapanis"
  | "belge-takip"
  | "pozisyon"
  | "belgeler"
  | "mali"
  | "zimmet"
  | "ceza"
  | "ayrilma"
  /** Legacy deep-link id; normalized to `puantaj` in selected-person navigation. */
  | "izin-devamsizlik";

export type PuantajSubdomainId =
  | "gunluk-hareketler"
  | "puantaj-duzenleme"
  | "gorev"
  | "fazla-mesai"
  | DevamsizlikSubId;

export type OrganizasyonFormState = {
  departmanId: string;
  bolumId: string;
  birimId: string;
  gorevId: string;
  pozisyonId: string;
  bagliAmirId: string;
  personelTipiId: string;
  calismaLokasyonuId: string;
  sgkIsverenId: string;
  degisiklikTarihi: string;
  degisiklikNedeni: "" | "TERFI" | "GOREV_DEGISTI" | "YAPILANDIRMA";
  aciklama: string;
  /** @deprecated Use degisiklikTarihi */
  effectiveDate: string;
};

export type PozisyonFormState = OrganizasyonFormState;

type DevamsizlikSubCard = {
  id: DevamsizlikSubId;
  title: string;
  description: string;
  candidateKeys: string[];
};

type DevamsizlikAltTurConfig = {
  label: string;
  options: Array<{ value: string; label: string }>;
};

export const DEVAMSIZLIK_SUB_CARDS: DevamsizlikSubCard[] = [
  {
    id: "izin",
    title: "İzin",
    description: "Özlük süreç kaydı (Bugün’de İzinli görünür)",
    candidateKeys: ["IZIN"]
  },
  {
    id: "rapor",
    title: "Rapor",
    description: "Özlük süreç kaydı (Bugün’de Raporlu görünür)",
    candidateKeys: ["RAPOR"]
  },
  {
    id: "is_kazasi",
    title: "İş Kazası",
    description: "Özlük süreç kaydı (Bugün’de Raporlu görünür)",
    candidateKeys: ["IS_KAZASI"]
  },
  {
    id: "izinsiz",
    title: "İzinsiz Gelmedi",
    description: "Özlük süreç kaydı (Bugün’de Gelmedi görünür)",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "gec",
    title: "Geç Geldi",
    description: "Saatli günlük durum — Bugünkü Personel Durumu",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "erken",
    title: "Erken Çıktı",
    description: "Saatli günlük durum — Bugünkü Personel Durumu",
    candidateKeys: ["DEVAMSIZLIK"]
  }
];

/** Selected-person process navigation (presentation only; no route/permission duplication). */
export const PERSONEL_SUREC_TABS: Array<{ id: PersonelSurecTab; label: string }> = [
  { id: "genel", label: "Genel" },
  { id: "puantaj", label: "Puantaj" },
  { id: "haftalik-kapanis", label: "Haftalık Kapanış" },
  { id: "belge-takip", label: "Belge Takip" },
  { id: "mali", label: "Finans" },
  { id: "pozisyon", label: "Görev / Organizasyon" },
  { id: "belgeler", label: "Belgeler" },
  { id: "zimmet", label: "Zimmet" },
  { id: "ceza", label: "Ceza" },
  { id: "ayrilma", label: "Ayrılma" }
];

const DIS_KAYNAK_VISIBLE_TAB_IDS: PersonelSurecTab[] = ["genel", "pozisyon", "belgeler"];

const INCOMING_PERSONEL_SUREC_TAB_IDS: PersonelSurecTab[] = [
  ...PERSONEL_SUREC_TABS.map((tab) => tab.id),
  "izin-devamsizlik"
];

export const PUANTAJ_SUBDOMAIN_CARDS: Array<{
  id: PuantajSubdomainId;
  title: string;
  description: string;
  kind: "route" | "inline-devamsizlik" | "puantaj-route" | "bugun-modal";
}> = [
  {
    id: "gunluk-hareketler",
    title: "Günlük Hareketler",
    description: "Birim amiri günlük bildirim / tamamlama listesi",
    kind: "route"
  },
  {
    id: "puantaj-duzenleme",
    title: "Puantaj Düzenleme",
    description: "Günlük puantaj satırı ve hareket kararları",
    kind: "puantaj-route"
  },
  ...DEVAMSIZLIK_SUB_CARDS.map((card) => ({
    id: card.id as PuantajSubdomainId,
    title: card.title,
    description: card.description,
    kind: (card.id === "gec" || card.id === "erken"
      ? "bugun-modal"
      : "inline-devamsizlik") as "inline-devamsizlik" | "bugun-modal"
  })),
  {
    id: "gorev",
    title: "Görevde",
    description: "Bugünkü görev durumu — Bugünkü Personel Durumu",
    kind: "bugun-modal"
  },
  {
    id: "fazla-mesai",
    title: "Fazla Mesai",
    description: "Fazla mesai ve tatil çalışması puantaj kararları",
    kind: "puantaj-route"
  }
];

export function isIncomingPersonelSurecTab(value: unknown): value is PersonelSurecTab {
  return typeof value === "string" && INCOMING_PERSONEL_SUREC_TAB_IDS.includes(value as PersonelSurecTab);
}

/** Maps legacy/deep-link tab ids to canonical selected-person navigation tab. */
export function normalizePersonelSurecTab(tab: PersonelSurecTab): PersonelSurecTab {
  if (tab === "izin-devamsizlik") {
    return "puantaj";
  }
  return tab;
}

export function resolveVisiblePersonelSurecTabs(isDirectoryOnly: boolean) {
  if (isDirectoryOnly) {
    return PERSONEL_SUREC_TABS.filter((tab) => DIS_KAYNAK_VISIBLE_TAB_IDS.includes(tab.id));
  }
  return PERSONEL_SUREC_TABS;
}

export function resolvePersonelSurecTabForSurecTuru(surecTuru: string): PersonelSurecTab {
  const normalized = surecTuru.trim().toUpperCase();

  if (["IZIN", "RAPOR", "IS_KAZASI", "DEVAMSIZLIK"].includes(normalized)) {
    return "puantaj";
  }
  if (["POZISYON_DEGISTI", "GOREV_DEGISTI", "BOLUM_DEGISTI", "DEPARTMAN_DEGISTI"].includes(normalized)) {
    return "pozisyon";
  }
  if (normalized.includes("BELGE") || normalized.includes("SERTIFIKA")) {
    return "belgeler";
  }
  if (normalized.includes("ZIMMET")) {
    return "zimmet";
  }
  if (normalized.includes("CEZA") || normalized.includes("DISIPLIN")) {
    return "ceza";
  }
  if (normalized === "ISTEN_AYRILMA") {
    return "ayrilma";
  }

  return "genel";
}

export const DEVAMSIZLIK_ALT_TUR_CONFIG: Record<DevamsizlikSubId, DevamsizlikAltTurConfig> = {
  izin: {
    label: "İzin Türü",
    options: [
      { value: "YILLIK_IZIN", label: "Yıllık" },
      { value: "MAZERET_IZNI", label: "Mazeret" },
      { value: "UCRETSIZ_IZIN", label: "Ücretsiz" }
    ]
  },
  rapor: {
    label: "Rapor Türü",
    options: [
      { value: "Raporlu_Hastalik", label: "Hastalık" },
      { value: "Raporlu_Meslek_Hastaligi", label: "Meslek hastalığı" },
      { value: "Raporlu_Analik", label: "Analık" }
    ]
  },
  is_kazasi: {
    label: "Kayıt Türü",
    options: [{ value: "IS_KAZASI_BILDIRIMI", label: "İş kazası bildirimi" }]
  },
  izinsiz: {
    label: "Gelmedi Türü",
    options: [{ value: "IZINSIZ_GELMEDI", label: "İzinsiz gelmedi" }]
  },
  gec: {
    label: "Geç Kalma Türü",
    options: [
      { value: "MAZERETLI_GEC_GELDI", label: "Mazeretli geç geldi" },
      { value: "MAZERETSIZ_GEC_GELDI", label: "Mazeretsiz geç geldi" }
    ]
  },
  erken: {
    label: "Erken Çıkış Türü",
    options: [
      { value: "MAZERETLI_ERKEN_CIKTI", label: "Mazeretli erken çıktı" },
      { value: "MAZERETSIZ_ERKEN_CIKTI", label: "Mazeretsiz erken çıktı" }
    ]
  }
};

export function createPozisyonFormFromPersonel(personel: Personel | null): OrganizasyonFormState {
  const degisiklikTarihi = personel ? resolveGoreveBaslamaTarihiDefault(personel) : "";
  return {
    departmanId: toOptionalIdValue(personel?.departman_id),
    bolumId: toOptionalIdValue(personel?.bolum_id),
    birimId: toOptionalIdValue(personel?.birim_id),
    gorevId: toOptionalIdValue(personel?.gorev_id),
    pozisyonId: toOptionalIdValue(personel?.pozisyon_id),
    bagliAmirId: toOptionalIdValue(personel?.bagli_amir_id),
    personelTipiId: toOptionalIdValue(personel?.personel_tipi_id),
    calismaLokasyonuId: toOptionalIdValue(personel?.calisma_lokasyonu_id),
    sgkIsverenId: toOptionalIdValue(personel?.sgk_isveren_id),
    degisiklikTarihi,
    degisiklikNedeni: "",
    aciklama: "",
    effectiveDate: degisiklikTarihi
  };
}

export const createOrganizasyonFormFromPersonel = createPozisyonFormFromPersonel;

function toOptionalIdValue(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "";
}
