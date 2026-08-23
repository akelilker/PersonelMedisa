import type { SgkKatalogBlocker } from "../../api/sgk-katalog-hazirlik.api";
import { SGK_TAMLIK_DURUMU_LABEL, type SgkTamlikDurumu } from "../../api/sgk-katalog-hazirlik.api";
import { formatBooleanLabel, formatSurecStateLabel } from "./enum-display";

/** SGK sürüm / onay akışı durum kodları (ONAY_BEKLIYOR vb.). */
export function formatSgkSurumDurumLabel(state: string | null | undefined): string {
  return formatSurecStateLabel(state);
}

/** import_yapilabilir_mi / apply_yapilabilir_mi gibi boolean uygunluk alanları. */
export function formatSgkImportUygunlukLabel(value: boolean | null | undefined): string {
  return formatBooleanLabel(value, { trueLabel: "Uygun", falseLabel: "Uygun değil" });
}

export function formatSgkEvetHayirLabel(value: boolean | null | undefined): string {
  return formatBooleanLabel(value);
}

export function formatSgkTamlikDurumuLabel(value: string | null | undefined): string {
  if (!value) {
    return "-";
  }
  const key = value as SgkTamlikDurumu;
  return SGK_TAMLIK_DURUMU_LABEL[key] ?? formatSurecStateLabel(value);
}

export function formatSgkBlockerDisplayText(item: SgkKatalogBlocker): string {
  const message = item.message?.trim();
  if (message) {
    return message;
  }
  return formatSurecStateLabel(item.code);
}

export function formatSgkKanitKoduLabel(value: string | null | undefined): string {
  const trimmed = value?.trim();
  return trimmed || "—";
}
