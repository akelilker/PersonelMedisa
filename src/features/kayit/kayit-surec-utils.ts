import type { KeyOption } from "../../types/referans";
import type { Personel } from "../../types/personel";
import { INITIAL_SUREC_FORM } from "../../hooks/useSurecler";
import { DEVAMSIZLIK_SUB_CARDS, type DevamsizlikSubId } from "./kayit-surec-constants";

export function normalizeEnumKey(value: string) {
  return value.trim().replace(/-/g, "_").toUpperCase();
}

export function formatPersonelAdSoyad(personel: { ad?: string | null; soyad?: string | null }) {
  const ad = String(personel.ad ?? "").trim();
  const soyad = String(personel.soyad ?? "").trim();
  return [ad, soyad].filter(Boolean).join(" ");
}

/**
 * Personel seçim listesi görünür label'ı: yalnız **Ad Soyad**.
 * Bölüm / Birim / Pozisyon liste item'ında gösterilmez; arama kontratı ayrı yürür
 * (bkz. KayitSurecWorkspace searchable alanları).
 */
export function formatPersonelLabel(personel: Personel) {
  return formatPersonelAdSoyad(personel);
}

/**
 * Seçim listesi için personel id → görünür label haritası.
 * Yalnız Ad Soyad gösterilir; aynı Ad Soyad gerçekten birden fazla personelde
 * geçiyorsa (ambiguity) tek ek bilgi olarak "Sicil X" eklenir. Bölüm/birim/pozisyon
 * hiçbir durumda label'a girmez.
 */
export function buildPersonelSelectLabels(personeller: Personel[]) {
  const nameCounts = new Map<string, number>();
  for (const personel of personeller) {
    const key = formatPersonelAdSoyad(personel).toLocaleLowerCase("tr-TR");
    if (!key) {
      continue;
    }
    nameCounts.set(key, (nameCounts.get(key) ?? 0) + 1);
  }

  const labels = new Map<number, string>();
  for (const personel of personeller) {
    const name = formatPersonelAdSoyad(personel);
    const ambiguous = (nameCounts.get(name.toLocaleLowerCase("tr-TR")) ?? 0) > 1;
    const sicil = String(personel.sicil_no ?? "").trim();
    labels.set(personel.id, ambiguous && sicil ? `${name} • Sicil ${sicil}` : name);
  }

  return labels;
}

export function normalizePersonelSearchText(value: string | number | null | undefined) {
  return String(value ?? "").toLocaleLowerCase("tr-TR").trim();
}

export function resetSurecFormKeepingPersonel(personelId: string) {
  return {
    ...INITIAL_SUREC_FORM,
    personelId
  };
}

export function resolveSurecTuruKeyFromOptions(candidateKeys: string[], options: KeyOption[]): string | null {
  if (candidateKeys.length === 0 || options.length === 0) {
    return null;
  }

  const keyByNorm = new Map(options.map((option) => [normalizeEnumKey(option.key), option.key]));

  for (const candidate of candidateKeys) {
    const resolved = keyByNorm.get(normalizeEnumKey(candidate));
    if (resolved) {
      return resolved;
    }
  }

  return null;
}

export function resolveDevamsizlikSurecTuru(id: DevamsizlikSubId, surecTuruOptions: KeyOption[]) {
  const card = DEVAMSIZLIK_SUB_CARDS.find((item) => item.id === id);
  if (!card) {
    return null;
  }

  return resolveSurecTuruKeyFromOptions(card.candidateKeys, surecTuruOptions);
}

export function formatGeneralField(value: string | number | null | undefined) {
  if (value === null || value === undefined) {
    return "-";
  }

  const trimmed = String(value).trim();
  return trimmed.length > 0 ? trimmed : "-";
}

export function formatMoneyField(value: number | null | undefined) {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return "-";
  }

  return new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(value);
}

export function toOptionalIdValue(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "";
}

export function optionLabel(options: Array<{ id: number; label: string }>, value: string, fallback: string) {
  if (!value) {
    return "-";
  }

  const option = options.find((item) => String(item.id) === value);
  return option?.label ?? fallback;
}

export function parsePozisyonId(value: string) {
  return value ? Number.parseInt(value, 10) : null;
}

/** @deprecated Prefer hasOrganizasyonFormDiff from kayit-surec-pozisyon */
export function hasPozisyonOrganizationalDiff(
  form: { departmanId: string; gorevId: string; bagliAmirId: string; personelTipiId: string },
  personel: Personel
) {
  return (
    form.departmanId !== toOptionalIdValue(personel.departman_id) ||
    form.gorevId !== toOptionalIdValue(personel.gorev_id) ||
    form.bagliAmirId !== toOptionalIdValue(personel.bagli_amir_id) ||
    form.personelTipiId !== toOptionalIdValue(personel.personel_tipi_id)
  );
}
