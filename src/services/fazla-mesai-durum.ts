import type { YillikFazlaCalismaOzeti } from "../types/haftalik-kapanis";

export type FazlaMesaiUyariSeviyesi = "normal" | "yaklasiyor" | "asildi";

type FazlaMesaiBayraklari = Pick<YillikFazlaCalismaOzeti, "limit_asildi_mi" | "limit_yaklasiyor_mu">;

/** Display-only. Limit math stays on the canonical yearly aggregate. */
export function fazlaMesaiUyariSeviyesi(ozet: FazlaMesaiBayraklari): FazlaMesaiUyariSeviyesi {
  if (ozet.limit_asildi_mi) {
    return "asildi";
  }
  if (ozet.limit_yaklasiyor_mu) {
    return "yaklasiyor";
  }
  return "normal";
}

export function formatFazlaMesaiSure(dakika: number): string {
  const safe = Number.isFinite(dakika) ? Math.max(0, Math.trunc(dakika)) : 0;
  const saat = Math.floor(safe / 60);
  const kalanDakika = safe % 60;
  if (kalanDakika === 0) {
    return `${saat} sa`;
  }
  return `${saat} sa ${kalanDakika} dk`;
}

/**
 * BIRIM_AMIRI sees a person only when that person's birim is inside the
 * actor's assigned birim ids. Empty assignment is fail-closed.
 */
export function birimAmiriPersonelKapsamda(
  actorBirimIds: readonly number[],
  personelBirimId: number | null | undefined
): boolean {
  if (actorBirimIds.length === 0) {
    return false;
  }
  return typeof personelBirimId === "number" && actorBirimIds.includes(personelBirimId);
}
