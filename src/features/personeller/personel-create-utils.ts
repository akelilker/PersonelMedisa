import type { CreatePersonelPayload } from "../../api/personeller.api";
import type { CreatePersonelFormState } from "../../hooks/usePersoneller";

export function digitsOnly(value: string) {
  return value.replace(/\D+/g, "");
}

function titleCaseWord(value: string) {
  const lower = value.toLocaleLowerCase("tr-TR");
  const firstLetterIndex = lower.search(/\p{L}/u);

  if (firstLetterIndex < 0) {
    return lower;
  }

  return `${lower.slice(0, firstLetterIndex)}${lower
    .charAt(firstLetterIndex)
    .toLocaleUpperCase("tr-TR")}${lower.slice(firstLetterIndex + 1)}`;
}

export function normalizePersonelAd(value: string) {
  return value
    .trim()
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part.split("-").map(titleCaseWord).join("-"))
    .join(" ");
}

export function normalizePersonelSoyad(value: string) {
  return value.trim().toLocaleUpperCase("tr-TR");
}

export function normalizeTurkishMobilePhone(value: string, label: string) {
  const digits = digitsOnly(value);
  let normalized = digits;

  if (digits.length === 12 && digits.startsWith("90")) {
    normalized = `0${digits.slice(2)}`;
  } else if (digits.length === 10 && digits.startsWith("5")) {
    normalized = `0${digits}`;
  }

  if (!/^05\d{9}$/.test(normalized)) {
    throw new Error(`${label} 05xx xxx xx xx formatında olmalıdır.`);
  }

  return `${normalized.slice(0, 4)} ${normalized.slice(4, 7)} ${normalized.slice(7, 9)} ${normalized.slice(9, 11)}`;
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
  if (!/^05\d{9}$/.test(value)) {
    throw new Error(`${label} 05xx xxx xx xx formatında olmalıdır.`);
  }
}

export function isPersonelMaasMissing(maasTutari: number | null | undefined) {
  return maasTutari === null || maasTutari === undefined;
}

export function buildCreatePersonelPayload(form: CreatePersonelFormState): CreatePersonelPayload {
  const tcKimlikNo = digitsOnly(form.tcKimlikNo);
  const telefon = normalizeTurkishMobilePhone(form.telefon, "Telefon");
  const acilDurumTelefon = normalizeTurkishMobilePhone(form.acilDurumTelefon, "Acil durum telefonu");

  validateTcKimlikNo(tcKimlikNo);

  if (!form.subeId.trim()) {
    throw new Error("Şube seçilmelidir.");
  }

  const subeId = parseRequiredPositiveInt(form.subeId, "Şube");
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
    ad: normalizePersonelAd(form.ad),
    soyad: normalizePersonelSoyad(form.soyad),
    dogum_tarihi: form.dogumTarihi,
    telefon,
    acil_durum_kisi: form.acilDurumKisi.trim(),
    acil_durum_telefon: acilDurumTelefon,
    sicil_no: form.sicilNo.trim(),
    ise_giris_tarihi: form.iseGirisTarihi,
    sube_id: subeId,
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
