import type { Personel, PersonelCalisanKapsami } from "../../types/personel";

export type PersonelMissingFieldKey =
  | "tc_kimlik_no"
  | "sicil_no"
  | "dogum_tarihi"
  | "telefon"
  | "ise_giris_tarihi"
  | "departman_id"
  | "bolum_id"
  | "birim_id"
  | "gorev_id"
  | "personel_tipi_id";

export type PersonelMissingField = {
  key: PersonelMissingFieldKey;
  label: string;
  editTarget: "genel" | "pozisyon";
};

type PersonelMissingFieldRule = PersonelMissingField & {
  scopes: readonly PersonelCalisanKapsami[];
  isMissing: (personel: Personel) => boolean;
};

const BOTH_SCOPES: readonly PersonelCalisanKapsami[] = ["IC_PERSONEL", "DIS_KAYNAK"];
const IC_ONLY: readonly PersonelCalisanKapsami[] = ["IC_PERSONEL"];

function hasText(value: unknown): boolean {
  return typeof value === "string" && value.trim().length > 0;
}

function hasPositiveId(value: unknown): boolean {
  return typeof value === "number" && Number.isInteger(value) && value > 0;
}

const PERSONEL_MISSING_FIELD_RULES: readonly PersonelMissingFieldRule[] = [
  {
    key: "tc_kimlik_no",
    label: "T.C. Kimlik No",
    editTarget: "genel",
    scopes: IC_ONLY,
    isMissing: (personel) => !hasText(personel.tc_kimlik_no)
  },
  {
    key: "sicil_no",
    label: "Sicil No",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasText(personel.sicil_no)
  },
  {
    key: "dogum_tarihi",
    label: "Doğum Tarihi",
    editTarget: "genel",
    scopes: IC_ONLY,
    isMissing: (personel) => !hasText(personel.dogum_tarihi)
  },
  {
    key: "telefon",
    label: "Telefon",
    editTarget: "genel",
    scopes: IC_ONLY,
    isMissing: (personel) => !hasText(personel.telefon)
  },
  {
    key: "ise_giris_tarihi",
    label: "İşe Giriş Tarihi",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasText(personel.ise_giris_tarihi)
  },
  {
    key: "departman_id",
    label: "Departman",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.departman_id)
  },
  {
    key: "bolum_id",
    label: "Bölüm",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.bolum_id)
  },
  {
    key: "birim_id",
    label: "Birim",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.birim_id)
  },
  {
    key: "gorev_id",
    label: "Unvan / Görev",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.gorev_id)
  },
  {
    key: "personel_tipi_id",
    label: "Personel Tipi",
    editTarget: "pozisyon",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.personel_tipi_id)
  }
];

function resolvePersonelScope(personel: Personel): PersonelCalisanKapsami {
  return personel.calisan_kapsami === "DIS_KAYNAK" ? "DIS_KAYNAK" : "IC_PERSONEL";
}

export function getPersonelMissingFields(personel: Personel): PersonelMissingField[] {
  const scope = resolvePersonelScope(personel);

  return PERSONEL_MISSING_FIELD_RULES.filter(
    (rule) => rule.scopes.includes(scope) && rule.isMissing(personel)
  ).map(({ key, label, editTarget }) => ({ key, label, editTarget }));
}

export function getPersonelMissingFieldKeys(personel: Personel): Set<PersonelMissingFieldKey> {
  return new Set(getPersonelMissingFields(personel).map((field) => field.key));
}
