import type { Personel } from "../types/personel";

export type LifecycleSnapshot = {
  departman_id: number | null;
  gorev_id: number | null;
  bagli_amir_id: number | null;
  ucret_tipi: string | null;
  maas_tutari: number | null;
  prim_kurali_id: number | null;
};

export type LifecycleFormFields = {
  departmanId: string;
  gorevId: string;
  bagliAmirId: string;
  ucretTipi: string;
  maasTutari: string;
  primKuraliId: string;
};

function normalizeOptionalId(value: unknown): number | null {
  if (value === undefined || value === null) {
    return null;
  }

  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (trimmed === "") {
      return null;
    }

    const parsed = Number.parseInt(trimmed, 10);
    return Number.isFinite(parsed) ? parsed : null;
  }

  return null;
}

function normalizeOptionalString(value: unknown): string | null {
  if (value === undefined || value === null) {
    return null;
  }

  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function normalizeOptionalMoney(value: unknown): number | null {
  if (value === undefined || value === null) {
    return null;
  }

  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (trimmed === "") {
      return null;
    }

    const parsed = Number.parseFloat(trimmed.replace(",", "."));
    return Number.isFinite(parsed) ? parsed : null;
  }

  return null;
}

export function snapshotFromPersonel(personel: Personel): LifecycleSnapshot {
  return {
    departman_id: normalizeOptionalId(personel.departman_id),
    gorev_id: normalizeOptionalId(personel.gorev_id),
    bagli_amir_id: normalizeOptionalId(personel.bagli_amir_id),
    ucret_tipi: normalizeOptionalString(personel.ucret_tipi),
    maas_tutari: normalizeOptionalMoney(personel.maas_tutari),
    prim_kurali_id: normalizeOptionalId(personel.prim_kurali_id)
  };
}

export function snapshotFromLifecycleForm(form: LifecycleFormFields): LifecycleSnapshot {
  return {
    departman_id: normalizeOptionalId(form.departmanId),
    gorev_id: normalizeOptionalId(form.gorevId),
    bagli_amir_id: normalizeOptionalId(form.bagliAmirId),
    ucret_tipi: normalizeOptionalString(form.ucretTipi),
    maas_tutari: normalizeOptionalMoney(form.maasTutari),
    prim_kurali_id: normalizeOptionalId(form.primKuraliId)
  };
}

function snapshotsEqual(left: LifecycleSnapshot, right: LifecycleSnapshot): boolean {
  return (
    left.departman_id === right.departman_id &&
    left.gorev_id === right.gorev_id &&
    left.bagli_amir_id === right.bagli_amir_id &&
    left.ucret_tipi === right.ucret_tipi &&
    left.maas_tutari === right.maas_tutari &&
    left.prim_kurali_id === right.prim_kurali_id
  );
}

export function computeHasLifecycleDiff(personel: Personel, form: LifecycleFormFields): boolean {
  return !snapshotsEqual(snapshotFromPersonel(personel), snapshotFromLifecycleForm(form));
}

export function lifecycleSnapshotToPersonelPatch(snap: LifecycleSnapshot): Partial<Personel> {
  return {
    departman_id: snap.departman_id ?? undefined,
    gorev_id: snap.gorev_id ?? undefined,
    bagli_amir_id: snap.bagli_amir_id ?? undefined,
    ucret_tipi: snap.ucret_tipi ?? undefined,
    maas_tutari: snap.maas_tutari ?? undefined,
    prim_kurali_id: snap.prim_kurali_id ?? undefined
  };
}
