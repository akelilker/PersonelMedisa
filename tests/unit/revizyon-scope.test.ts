import { describe, expect, it } from "vitest";
import {
  canApproveOrRejectRevizyon,
  canCancelRevizyon,
  canCreateRevizyonForPersonel,
  canSubmitRevizyon,
  canViewRevizyonTalep,
  maskRevizyonFinanceFields,
  type RevizyonActorContext
} from "../../src/lib/revizyon-talebi/revizyon-scope";
import type { RevizyonTalebi } from "../../src/types/revizyon-talebi";

const baseTalep: RevizyonTalebi = {
  id: 1,
  personel_id: 1,
  hafta_baslangic: "2026-05-01",
  hafta_bitis: "2026-05-07",
  etkilenen_tarih: "2026-05-03",
  kaynak_tipi: "PUANTAJ",
  kaynak_id: 100,
  revizyon_tipi: "PUANTAJ_GIRIS_CIKIS_DUZELTME",
  onceki_deger: "08:00",
  talep_edilen_deger: "08:15",
  gerekce: "Test",
  talep_eden_kullanici_id: 3,
  talep_zamani: "2026-06-01T10:00:00.000Z",
  durum: "TASLAK",
  bordro_etki_var_mi: false,
  bordro_etki_notu: "Detay",
  correction_event_id: null
};

describe("revizyon-scope", () => {
  it("GENEL_YONETICI tum talepleri gorebilir ve onaylayabilir", () => {
    const actor: RevizyonActorContext = {
      userId: 1,
      role: "GENEL_YONETICI",
      subeIds: [],
      departmanIds: []
    };

    expect(canViewRevizyonTalep(actor, baseTalep, 3)).toBe(true);
    expect(canApproveOrRejectRevizyon(actor)).toBe(true);
    expect(canCancelRevizyon(actor, baseTalep, 3)).toBe(true);
  });

  it("BOLUM_YONETICISI yalniz kendi bolumundeki personeli gorebilir", () => {
    const actor: RevizyonActorContext = {
      userId: 2,
      role: "BOLUM_YONETICISI",
      subeIds: [2],
      departmanIds: [6]
    };

    expect(canViewRevizyonTalep(actor, { ...baseTalep, personel_id: 2 }, 6)).toBe(true);
    expect(canViewRevizyonTalep(actor, baseTalep, 3)).toBe(false);
    expect(
      canCreateRevizyonForPersonel(actor, 2, 6, { bordro_etki_var_mi: false }).ok
    ).toBe(true);
    expect(canCreateRevizyonForPersonel(actor, 1, 3, { bordro_etki_var_mi: false }).ok).toBe(
      false
    );
    expect(canApproveOrRejectRevizyon(actor)).toBe(false);
  });

  it("MUHASEBE yalniz bordro etkili kayitlari gorebilir", () => {
    const actor: RevizyonActorContext = {
      userId: 4,
      role: "MUHASEBE",
      subeIds: [1, 2],
      departmanIds: []
    };

    expect(canViewRevizyonTalep(actor, baseTalep, 3)).toBe(false);
    expect(canViewRevizyonTalep(actor, { ...baseTalep, bordro_etki_var_mi: true }, 3)).toBe(true);
    expect(
      canCreateRevizyonForPersonel(actor, 1, 3, { bordro_etki_var_mi: true }).ok
    ).toBe(true);
    expect(canCreateRevizyonForPersonel(actor, 1, 3, { bordro_etki_var_mi: false }).ok).toBe(
      false
    );
  });

  it("BIRIM_AMIRI yalniz bagli personeli gorebilir ve finance mask uygular", () => {
    const actor: RevizyonActorContext = {
      userId: 3,
      role: "BIRIM_AMIRI",
      subeIds: [1],
      departmanIds: [3],
      linkedPersonelId: 1
    };

    expect(canViewRevizyonTalep(actor, baseTalep, 3)).toBe(true);
    expect(canViewRevizyonTalep(actor, { ...baseTalep, personel_id: 2 }, 6)).toBe(false);
    expect(
      canCreateRevizyonForPersonel(actor, 1, 3, { bordro_etki_var_mi: false }).ok
    ).toBe(true);

    const masked = maskRevizyonFinanceFields(actor, baseTalep);
    expect(masked.bordro_etki_notu).toBeNull();
    expect(masked.bordro_etki_var_mi).toBe(false);
  });

  it("submit ve cancel ownership kurallarini uygular", () => {
    const owner: RevizyonActorContext = {
      userId: 3,
      role: "BIRIM_AMIRI",
      subeIds: [1],
      departmanIds: [3],
      linkedPersonelId: 1
    };
    const other: RevizyonActorContext = {
      userId: 99,
      role: "BIRIM_AMIRI",
      subeIds: [1],
      departmanIds: [3],
      linkedPersonelId: 1
    };

    expect(canSubmitRevizyon(owner, baseTalep, 3)).toBe(true);
    expect(canSubmitRevizyon(other, baseTalep, 3)).toBe(false);
    expect(canCancelRevizyon(owner, baseTalep, 3)).toBe(true);
    expect(canCancelRevizyon(other, baseTalep, 3)).toBe(false);
  });
});
