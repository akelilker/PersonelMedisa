import type { Personel } from "../../types/personel";

export const KAYIT_SUREC_PERSONEL_FORM_ID = "kayit-surec-personel-form";
export const KAYIT_SUREC_SUREC_FORM_ID = "kayit-surec-surec-form";
export const KAYIT_SUREC_ZIMMET_FORM_ID = "kayit-surec-zimmet-form";
export const KAYIT_SUREC_MALI_FORM_ID = "kayit-surec-mali-form";
export const KAYIT_SUREC_CEZA_FORM_ID = "kayit-surec-ceza-form";
export const KAYIT_SUREC_BELGELER_FORM_ID = "kayit-surec-belgeler-form";

/** Personel kartı süreç geçmişi; `usePersonelDetail` ile aynı sayfa boyutu. */
export const KAYIT_SUREC_PERSONEL_HISTORY_LIMIT = 20;
/** `useSurecler` liste sayfa boyutu ile uyumlu. */
export const KAYIT_SUREC_LIST_PAGE_SIZE = 10;

export type DevamsizlikSubId = "izin" | "rapor" | "is_kazasi" | "izinsiz" | "gec" | "erken";

export type PersonelSurecTab =
  | "genel"
  | "izin-devamsizlik"
  | "pozisyon"
  | "belgeler"
  | "mali"
  | "zimmet"
  | "ceza"
  | "ayrilma";

export type PozisyonFormState = {
  departmanId: string;
  gorevId: string;
  bagliAmirId: string;
  personelTipiId: string;
  effectiveDate: string;
  aciklama: string;
};

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
    description: "Planlı ya da onaylı izin kaydı",
    candidateKeys: ["IZIN"]
  },
  {
    id: "rapor",
    title: "Rapor",
    description: "Hastalık veya istirahat raporu kaydı",
    candidateKeys: ["RAPOR"]
  },
  {
    id: "is_kazasi",
    title: "İş Kazası",
    description: "İş kazasına bağlı devamsızlık kaydı",
    candidateKeys: ["IS_KAZASI"]
  },
  {
    id: "izinsiz",
    title: "İzinsiz Gelmedi",
    description: "Mazeretsiz işe gelmeme kaydı",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "gec",
    title: "Geç Geldi",
    description: "Mesai başlangıcından sonra giriş kaydı",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "erken",
    title: "Erken Çıktı",
    description: "Mesai bitiminden önce çıkış kaydı",
    candidateKeys: ["DEVAMSIZLIK"]
  }
];

export const PERSONEL_SUREC_TABS: Array<{ id: PersonelSurecTab; label: string }> = [
  { id: "genel", label: "Genel" },
  { id: "izin-devamsizlik", label: "İzin / Devamsızlık" },
  { id: "pozisyon", label: "Pozisyon" },
  { id: "belgeler", label: "Belgeler" },
  { id: "mali", label: "Mali İşlemler" },
  { id: "zimmet", label: "Zimmet" },
  { id: "ceza", label: "Ceza" },
  { id: "ayrilma", label: "Ayrılma" }
];

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
    options: [{ value: "Raporlu_Hastalik", label: "Raporlu" }]
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

export function createPozisyonFormFromPersonel(personel: Personel | null): PozisyonFormState {
  return {
    departmanId: toOptionalIdValue(personel?.departman_id),
    gorevId: toOptionalIdValue(personel?.gorev_id),
    bagliAmirId: toOptionalIdValue(personel?.bagli_amir_id),
    personelTipiId: toOptionalIdValue(personel?.personel_tipi_id),
    effectiveDate: "",
    aciklama: ""
  };
}

function toOptionalIdValue(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "";
}
