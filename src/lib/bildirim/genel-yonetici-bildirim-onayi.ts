import type { GenelYoneticiBildirimOnayiOzet } from "../../types/genel-yonetici-bildirim-onayi";

const BLOCK_MESSAGES: Record<string, string> = {
  AYLIK_BILDIRIM_ONAYI_GEREKLI: "Önce aylık bildirim onayı tamamlanmalıdır.",
  AYLIK_BILDIRIM_ONAYI_TAMAMLANMADI: "Aylık bildirim onayı henüz tamamlanmamış.",
  EKSIK_HAFTA_VAR: "Eksik haftalar tamamlanmadan Genel Yönetici onayı verilemez.",
  ZATEN_ONAYLANDI: "Bu dönem Genel Yönetici tarafından onaylanmış."
};

export function resolveGenelYoneticiBildirimOnayiBlockMessage(
  code: string | null
): string | null {
  if (!code) return null;
  return BLOCK_MESSAGES[code] ?? "Genel Yönetici onayı için gerekli koşullar henüz sağlanmıyor.";
}

export function isGenelYoneticiBildirimOnayiApproveEnabled(
  canApprove: boolean,
  contextReady: boolean,
  ozet: GenelYoneticiBildirimOnayiOzet | null
): boolean {
  return Boolean(
    canApprove &&
      contextReady &&
      ozet?.onay_verilebilir_mi &&
      !ozet.genel_yonetici_bildirim_onayi
  );
}

export function formatGenelYoneticiBildirimOnayiState(state: string | null | undefined): string {
  return state === "TAMAMLANDI" ? "TAMAMLANDI" : "Henüz onaylanmadı";
}

export function formatGenelYoneticiBildirimOnayiDate(value: string | null | undefined): string {
  if (!value) return "—";
  const parsed = new Date(value.includes("T") ? value : value.replace(" ", "T"));
  if (Number.isNaN(parsed.getTime())) return "—";
  return new Intl.DateTimeFormat("tr-TR", { dateStyle: "medium", timeStyle: "short" }).format(parsed);
}
