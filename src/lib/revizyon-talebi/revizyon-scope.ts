import type { UserRole } from "../../types/auth";
import type { RevizyonCorrectionEvent } from "../../types/revizyon-correction";
import type { RevizyonHataKodu, RevizyonTalebi } from "../../types/revizyon-talebi";

export type RevizyonActorContext = {
  userId: number;
  role: UserRole;
  subeIds: readonly number[];
  departmanIds: readonly number[];
  linkedPersonelId?: number | null;
};

export type RevizyonScopePayload = {
  bordro_etki_var_mi?: boolean;
  bordro_etki_notu?: string | null;
};

export function canViewRevizyonTalep(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  personelDepartmanId: number | null | undefined
): boolean {
  switch (actor.role) {
    case "GENEL_YONETICI":
      return true;
    case "BOLUM_YONETICISI":
      return personelDepartmanId != null && actor.departmanIds.includes(personelDepartmanId);
    case "MUHASEBE":
      return talep.bordro_etki_var_mi === true;
    case "BIRIM_AMIRI":
      return actor.linkedPersonelId != null && talep.personel_id === actor.linkedPersonelId;
    default:
      return false;
  }
}

export function canCreateRevizyonForPersonel(
  actor: RevizyonActorContext,
  personelId: number,
  personelDepartmanId: number | null | undefined,
  payload: RevizyonScopePayload
): { ok: true } | { ok: false; code: RevizyonHataKodu } {
  if (actor.role === "GENEL_YONETICI") {
    return { ok: true };
  }

  if (actor.role === "BOLUM_YONETICISI") {
    if (personelDepartmanId != null && actor.departmanIds.includes(personelDepartmanId)) {
      return { ok: true };
    }
    return { ok: false, code: "REVISION_SCOPE_DENIED" };
  }

  if (actor.role === "MUHASEBE") {
    const hasBordroGerekce =
      payload.bordro_etki_var_mi === true ||
      (typeof payload.bordro_etki_notu === "string" && payload.bordro_etki_notu.trim().length > 0);
    if (!hasBordroGerekce) {
      return { ok: false, code: "REVISION_SCOPE_DENIED" };
    }
    return { ok: true };
  }

  if (actor.role === "BIRIM_AMIRI") {
    if (actor.linkedPersonelId != null && personelId === actor.linkedPersonelId) {
      return { ok: true };
    }
    return { ok: false, code: "REVISION_SCOPE_DENIED" };
  }

  return { ok: false, code: "REVISION_SCOPE_DENIED" };
}

export function canSubmitRevizyon(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  personelDepartmanId: number | null | undefined
): boolean {
  if (talep.talep_eden_kullanici_id !== actor.userId) {
    return false;
  }

  return canViewRevizyonTalep(actor, talep, personelDepartmanId);
}

export function canCancelRevizyon(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  personelDepartmanId: number | null | undefined
): boolean {
  if (actor.role === "GENEL_YONETICI") {
    return true;
  }

  if (talep.talep_eden_kullanici_id !== actor.userId) {
    return false;
  }

  return canViewRevizyonTalep(actor, talep, personelDepartmanId);
}

export function canApproveOrRejectRevizyon(actor: RevizyonActorContext): boolean {
  return actor.role === "GENEL_YONETICI";
}

export function maskRevizyonFinanceFields(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi
): RevizyonTalebi {
  if (actor.role === "BIRIM_AMIRI") {
    return {
      ...talep,
      bordro_etki_notu: null
    };
  }

  return talep;
}

export function canViewRevizyonCorrection(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  personelDepartmanId: number | null | undefined
): boolean {
  return canViewRevizyonTalep(actor, talep, personelDepartmanId);
}

export function canViewRevizyonFinanceEffect(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  personelDepartmanId: number | null | undefined
): boolean {
  if (actor.role === "BIRIM_AMIRI") {
    return false;
  }

  return canViewRevizyonTalep(actor, talep, personelDepartmanId);
}

export function maskCorrectionFinanceFields(
  actor: RevizyonActorContext,
  correction: RevizyonCorrectionEvent,
  talep: RevizyonTalebi,
  personelDepartmanId: number | null | undefined
): RevizyonCorrectionEvent {
  if (canViewRevizyonFinanceEffect(actor, talep, personelDepartmanId)) {
    return correction;
  }

  return {
    ...correction,
    bordro_etki_tipi: null,
    aciklama: correction.bordro_etki_var_mi ? null : correction.aciklama
  };
}
