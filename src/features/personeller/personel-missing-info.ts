import type { Personel, PersonelCalisanKapsami, PersonelCompleteness } from "../../types/personel";

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

export type PersonelMissingFieldCategory = "KIMLIK" | "ILETISIM" | "ISTIHDAM";
export type PersonelMissingFieldSeverity = "CRITICAL" | "WARNING";

export type PersonelMissingField = {
  key: PersonelMissingFieldKey;
  label: string;
  category: PersonelMissingFieldCategory;
  severity: PersonelMissingFieldSeverity;
  editTarget: "genel" | "pozisyon";
};

type PersonelMissingFieldRule = PersonelMissingField & {
  scopes: readonly PersonelCalisanKapsami[];
  isMissing: (personel: Personel) => boolean;
};

const BOTH_SCOPES: readonly PersonelCalisanKapsami[] = ["IC_PERSONEL", "DIS_KAYNAK"];
const IC_ONLY: readonly PersonelCalisanKapsami[] = ["IC_PERSONEL"];

const VALID_KEYS = new Set<PersonelMissingFieldKey>([
  "tc_kimlik_no",
  "sicil_no",
  "dogum_tarihi",
  "telefon",
  "ise_giris_tarihi",
  "departman_id",
  "bolum_id",
  "birim_id",
  "gorev_id",
  "personel_tipi_id"
]);

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
    category: "KIMLIK",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: IC_ONLY,
    isMissing: (personel) => !hasText(personel.tc_kimlik_no)
  },
  {
    key: "sicil_no",
    label: "Sicil No",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasText(personel.sicil_no)
  },
  {
    key: "dogum_tarihi",
    label: "Doğum Tarihi",
    category: "KIMLIK",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: IC_ONLY,
    isMissing: (personel) => !hasText(personel.dogum_tarihi)
  },
  {
    key: "telefon",
    label: "Telefon",
    category: "ILETISIM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: IC_ONLY,
    isMissing: (personel) => !hasText(personel.telefon)
  },
  {
    key: "ise_giris_tarihi",
    label: "İşe Giriş Tarihi",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasText(personel.ise_giris_tarihi)
  },
  {
    key: "departman_id",
    label: "Departman",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.departman_id)
  },
  {
    key: "bolum_id",
    label: "Bölüm",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.bolum_id)
  },
  {
    key: "birim_id",
    label: "Birim",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.birim_id)
  },
  {
    key: "gorev_id",
    label: "Unvan / Görev",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "genel",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.gorev_id)
  },
  {
    key: "personel_tipi_id",
    label: "Personel Tipi",
    category: "ISTIHDAM",
    severity: "CRITICAL",
    editTarget: "pozisyon",
    scopes: BOTH_SCOPES,
    isMissing: (personel) => !hasPositiveId(personel.personel_tipi_id)
  }
];

function resolvePersonelScope(personel: Personel): PersonelCalisanKapsami {
  return personel.calisan_kapsami === "DIS_KAYNAK" ? "DIS_KAYNAK" : "IC_PERSONEL";
}

/** Local canonical evaluation — must stay parity with PersonelCompletenessService.php */
export function evaluatePersonelCompleteness(personel: Personel): PersonelCompleteness {
  const scope = resolvePersonelScope(personel);
  const missingFields = PERSONEL_MISSING_FIELD_RULES.filter(
    (rule) => rule.scopes.includes(scope) && rule.isMissing(personel)
  ).map(({ key, label, category, severity, editTarget }) => ({
    key,
    label,
    category,
    severity,
    edit_target: editTarget
  }));

  return {
    is_complete: missingFields.length === 0,
    missing_count: missingFields.length,
    critical_missing_labels: missingFields.map((field) => field.label),
    missing_fields: missingFields
  };
}

function normalizeApiCompleteness(raw: PersonelCompleteness | undefined): PersonelCompleteness | null {
  if (!raw || typeof raw !== "object") {
    return null;
  }
  const missingCount = Number(raw.missing_count);
  if (!Number.isFinite(missingCount) || missingCount < 0) {
    return null;
  }
  const fields = Array.isArray(raw.missing_fields)
    ? raw.missing_fields
        .filter((field) => field && VALID_KEYS.has(field.key as PersonelMissingFieldKey))
        .map((field) => ({
          key: field.key as PersonelMissingFieldKey,
          label: String(field.label ?? ""),
          category: (field.category as PersonelMissingFieldCategory) || "ISTIHDAM",
          severity: (field.severity as PersonelMissingFieldSeverity) || "CRITICAL",
          edit_target: field.edit_target === "pozisyon" ? ("pozisyon" as const) : ("genel" as const)
        }))
    : undefined;

  const labels = Array.isArray(raw.critical_missing_labels)
    ? raw.critical_missing_labels.map((label) => String(label))
    : fields?.map((field) => field.label) ?? [];

  return {
    is_complete: Boolean(raw.is_complete) && missingCount === 0,
    missing_count: missingCount,
    critical_missing_labels: labels,
    ...(fields ? { missing_fields: fields } : {})
  };
}

/**
 * Prefer API completeness when present; otherwise evaluate locally (demo / legacy).
 * UI must not invent a second policy.
 */
export function resolvePersonelCompleteness(personel: Personel): PersonelCompleteness {
  return normalizeApiCompleteness(personel.completeness) ?? evaluatePersonelCompleteness(personel);
}

export function getPersonelMissingFields(personel: Personel): PersonelMissingField[] {
  const completeness = resolvePersonelCompleteness(personel);
  const fields = completeness.missing_fields;
  if (fields && fields.length > 0) {
    return fields.map((field) => ({
      key: field.key as PersonelMissingFieldKey,
      label: field.label,
      category: (field.category as PersonelMissingFieldCategory) || "ISTIHDAM",
      severity: (field.severity as PersonelMissingFieldSeverity) || "CRITICAL",
      editTarget: field.edit_target === "pozisyon" ? ("pozisyon" as const) : ("genel" as const)
    }));
  }

  // Labels-only API list summary: rebuild from local rules by key match when possible
  if (completeness.missing_count > 0 && completeness.critical_missing_labels.length > 0) {
    const byLabel = new Map(PERSONEL_MISSING_FIELD_RULES.map((rule) => [rule.label, rule]));
    return completeness.critical_missing_labels
      .map((label) => byLabel.get(label))
      .filter((rule): rule is PersonelMissingFieldRule => rule != null)
      .map(({ key, label, category, severity, editTarget }) => ({
        key,
        label,
        category,
        severity,
        editTarget
      }));
  }

  return (evaluatePersonelCompleteness(personel).missing_fields ?? []).map((field) => ({
    key: field.key as PersonelMissingFieldKey,
    label: field.label,
    category: (field.category as PersonelMissingFieldCategory) || "ISTIHDAM",
    severity: (field.severity as PersonelMissingFieldSeverity) || "CRITICAL",
    editTarget: field.edit_target === "pozisyon" ? ("pozisyon" as const) : ("genel" as const)
  }));
}

export function getPersonelMissingFieldKeys(personel: Personel): Set<PersonelMissingFieldKey> {
  return new Set(getPersonelMissingFields(personel).map((field) => field.key));
}

export function countMissingByEditTarget(
  personel: Personel,
  target: "genel" | "pozisyon"
): number {
  return getPersonelMissingFields(personel).filter((field) => field.editTarget === target).length;
}
