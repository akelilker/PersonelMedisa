import type { AppPermission } from "../authorization/role-permissions";
import type { Bildirim } from "../../types/bildirim";

export const GUNLUK_BILDIRIM_EDITABLE_STATES = ["TASLAK", "DUZELTME_ISTENDI"] as const;

export function normalizeGunlukBildirimState(state: string | null | undefined): string {
  return (state ?? "").trim().toUpperCase();
}

export function isGunlukBildirimEditableState(state: string | null | undefined): boolean {
  const normalized = normalizeGunlukBildirimState(state);
  return (GUNLUK_BILDIRIM_EDITABLE_STATES as readonly string[]).includes(normalized);
}

export function isGunlukBildirimOwner(
  item: Pick<Bildirim, "created_by">,
  currentUserId: number | null | undefined
): boolean {
  if (currentUserId == null || currentUserId <= 0) {
    return false;
  }

  if (item.created_by == null) {
    return false;
  }

  return item.created_by === currentUserId;
}

export function canEditGunlukBildirim(
  item: Bildirim,
  hasPermission: (permission: AppPermission) => boolean,
  currentUserId: number | null | undefined
): boolean {
  if (!isGunlukBildirimEditableState(item.state)) {
    return false;
  }

  if (!hasPermission("gunluk_bildirim.update_own_open")) {
    return false;
  }

  return isGunlukBildirimOwner(item, currentUserId);
}

export function canCancelGunlukBildirim(
  item: Bildirim,
  hasPermission: (permission: AppPermission) => boolean,
  currentUserId: number | null | undefined
): boolean {
  return canEditGunlukBildirim(item, hasPermission, currentUserId);
}

export function canSubmitGunlukBildirim(
  item: Bildirim,
  hasPermission: (permission: AppPermission) => boolean,
  currentUserId: number | null | undefined
): boolean {
  if (!isGunlukBildirimEditableState(item.state)) {
    return false;
  }

  if (!hasPermission("gunluk_bildirim.submit")) {
    return false;
  }

  return isGunlukBildirimOwner(item, currentUserId);
}

export function canRequestCorrectionGunlukBildirim(
  item: Bildirim,
  hasPermission: (permission: AppPermission) => boolean
): boolean {
  if (normalizeGunlukBildirimState(item.state) !== "GONDERILDI") {
    return false;
  }

  return hasPermission("gunluk_bildirim.request_correction");
}
