/**
 * Bound-user canonical username reconciliation — FE mirror.
 *
 * Expected username is derived from the linked personnel ad/soyad via the same
 * name rule as PersonelAccountOnboardingService. Role is irrelevant: any bound
 * account (PERSONEL / BIRIM_AMIRI / BOLUM_YONETICISI / …) uses this template.
 */

import { buildPersonelUsernameFromNames } from "../../features/yonetim/personelUsernameFromNames";

export type BoundUserCanonicalUsernameView = {
  expectedUsername: string;
  actualUsername: string;
  mismatch: boolean;
  personelActive: boolean;
  canOfferFix: boolean;
};

export function resolveBoundUserCanonicalUsernameView(input: {
  actualUsername: string;
  personelAd: string | null | undefined;
  personelSoyad: string | null | undefined;
  personelAktifDurum: string | null | undefined;
}): BoundUserCanonicalUsernameView | null {
  const expectedUsername = buildPersonelUsernameFromNames(input.personelAd, input.personelSoyad);
  if (!expectedUsername) {
    return null;
  }

  const actualUsername = String(input.actualUsername ?? "").trim();
  const personelActive = String(input.personelAktifDurum ?? "").trim().toUpperCase() === "AKTIF";
  const mismatch = actualUsername !== expectedUsername;

  return {
    expectedUsername,
    actualUsername,
    mismatch,
    personelActive,
    canOfferFix: mismatch && personelActive
  };
}
