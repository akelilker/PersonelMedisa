import { describe, expect, it } from "vitest";
import {
  buildPersonelUpdatePayload,
  personelToEditForm
} from "../../src/features/personeller/personel-edit-utils";
import type { Personel } from "../../src/types/personel";

const legacyMissingPersonel: Personel = {
  id: 41,
  tc_kimlik_no: "12345678901",
  ad: "Ali",
  soyad: "Veli",
  aktif_durum: "AKTIF",
  calisan_kapsami: "IC_PERSONEL",
  dogum_tarihi: "1990-01-01",
  telefon: "05551234567",
  sicil_no: "",
  ise_giris_tarihi: "",
  departman_id: 2,
  bolum_id: 3,
  birim_id: 4,
  gorev_id: 5,
  personel_tipi_id: 1
};

describe("personel missing-info correction payload", () => {
  it("değişmeyen legacy boş Sicil/İşe Giriş alanlarını update payload'a zorla eklemez", () => {
    const form = personelToEditForm(legacyMissingPersonel);
    const payload = buildPersonelUpdatePayload(form, false, {
      includeWageFields: false,
      currentPersonel: legacyMissingPersonel
    });

    expect(payload).not.toHaveProperty("sicil_no");
    expect(payload).not.toHaveProperty("ise_giris_tarihi");
  });

  it("kullanıcı eksik Sicil/İşe Giriş bilgisini doldurduğunda mevcut update owner'ına ekler", () => {
    const form = {
      ...personelToEditForm(legacyMissingPersonel),
      sicilNo: "P-041",
      iseGirisTarihi: "2026-08-15"
    };
    const payload = buildPersonelUpdatePayload(form, false, {
      includeWageFields: false,
      currentPersonel: legacyMissingPersonel
    });

    expect(payload.sicil_no).toBe("P-041");
    expect(payload.ise_giris_tarihi).toBe("2026-08-15");
  });
});
