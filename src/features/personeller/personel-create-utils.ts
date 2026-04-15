import type { CreatePersonelPayload } from "../../api/personeller.api";
import type { CreatePersonelFormState } from "../../hooks/usePersoneller";

export function digitsOnly(value: string) {
  return value.replace(/\D+/g, "");
}

export function parseRequiredPositiveInt(value: string, label: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error(`${label} pozitif sayı olmalıdır.`);
  }
  return number;
}

export function parseOptionalPositiveInt(value: string) {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number <= 0) {
    return undefined;
  }

  return number;
}

export function validateTcKimlikNo(value: string) {
  if (!/^\d{11}$/.test(value)) {
    throw new Error("T.C. Kimlik No 11 hane ve yalnızca rakamlardan oluşmalıdır.");
  }
}

export function validatePhoneNumber(value: string, label: string) {
  if (!/^\d{10,11}$/.test(value)) {
    throw new Error(`${label} yalnızca rakamlardan oluşmalı ve 10-11 hane olmalı.`);
  }
}

export function buildCreatePersonelPayload(form: CreatePersonelFormState): CreatePersonelPayload {
  const tcKimlikNo = digitsOnly(form.tcKimlikNo);
  const telefon = digitsOnly(form.telefon);
  const acilDurumTelefon = digitsOnly(form.acilDurumTelefon);

  validateTcKimlikNo(tcKimlikNo);
  validatePhoneNumber(telefon, "Telefon");
  validatePhoneNumber(acilDurumTelefon, "Acil durum telefonu");

  const bagliAmirId = parseOptionalPositiveInt(form.bagliAmirId);
  const ucretTipiId = parseOptionalPositiveInt(form.ucretTipiId);
  const primKuraliId = parseOptionalPositiveInt(form.primKuraliId);
  const maasRaw = form.maasTutari.trim();
  const maasTutari =
    maasRaw === ""
      ? undefined
      : (() => {
          const parsed = Number.parseFloat(maasRaw.replace(",", "."));
          return Number.isFinite(parsed) ? parsed : undefined;
        })();

  return {
    tc_kimlik_no: tcKimlikNo,
    ad: form.ad.trim(),
    soyad: form.soyad.trim(),
    dogum_tarihi: form.dogumTarihi,
    telefon,
    acil_durum_kisi: form.acilDurumKisi.trim(),
    acil_durum_telefon: acilDurumTelefon,
    sicil_no: form.sicilNo.trim(),
    ise_giris_tarihi: form.iseGirisTarihi,
    departman_id: parseRequiredPositiveInt(form.departmanId, "Departman"),
    gorev_id: parseRequiredPositiveInt(form.gorevId, "Görev"),
    personel_tipi_id: parseRequiredPositiveInt(form.personelTipiId, "Personel Tipi"),
    aktif_durum: "AKTIF",
    ...(form.dogumYeri.trim() ? { dogum_yeri: form.dogumYeri.trim() } : {}),
    ...(form.kanGrubu.trim() ? { kan_grubu: form.kanGrubu.trim() } : {}),
    ...(bagliAmirId !== undefined ? { bagli_amir_id: bagliAmirId } : {}),
    ...(ucretTipiId !== undefined ? { ucret_tipi_id: ucretTipiId } : {}),
    ...(primKuraliId !== undefined ? { prim_kurali_id: primKuraliId } : {}),
    ...(maasTutari !== undefined ? { maas_tutari: maasTutari } : {})
  };
}
