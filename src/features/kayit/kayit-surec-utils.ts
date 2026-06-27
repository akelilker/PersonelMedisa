import type { KeyOption } from "../../types/referans";
import type { Personel } from "../../types/personel";
import { INITIAL_SUREC_FORM } from "../../hooks/useSurecler";
import { DEVAMSIZLIK_SUB_CARDS, type DevamsizlikSubId } from "./kayit-surec-constants";

export function normalizeEnumKey(value: string) {
  return value.trim().replace(/-/g, "_").toUpperCase();
}

export function formatPersonelLabel(personel: Personel) {
  const meta = [personel.departman_adi, personel.gorev_adi].filter(Boolean).join(" • ");
  return meta ? `${personel.ad} ${personel.soyad} • ${meta}` : `${personel.ad} ${personel.soyad}`;
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

export function getPersonelInitials(personel: Personel) {
  const adInitial = personel.ad.trim().charAt(0);
  const soyadInitial = personel.soyad.trim().charAt(0);
  return `${adInitial}${soyadInitial}`.toLocaleUpperCase("tr-TR");
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
