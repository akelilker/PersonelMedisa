import type { IdOption } from "../../types/referans";

/**
 * SGK işveren options for create: only employers that share the selected
 * şube's company. Unknown company → empty list (fail-closed; BE still validates).
 */
export function filterSgkIsverenOptionsForSube(
  sgkOptions: IdOption[],
  subeOptions: IdOption[],
  subeId: string
): IdOption[] {
  const trimmed = subeId.trim();
  if (!trimmed) {
    return [];
  }
  const sube = subeOptions.find((option) => String(option.id) === trimmed);
  const sirketId = sube?.sirketId ?? null;
  if (sirketId == null || sirketId <= 0) {
    return [];
  }
  return sgkOptions.filter((option) => option.sirketId === sirketId);
}

/** Keep current SGK only when it remains valid for the new şube company. */
export function resolveSgkIsverenAfterSubeChange(
  currentSgkIsverenId: string,
  filteredSgkOptions: IdOption[]
): string {
  const current = currentSgkIsverenId.trim();
  if (!current) {
    return "";
  }
  return filteredSgkOptions.some((option) => String(option.id) === current) ? current : "";
}

/**
 * Kayıt formu SGK işveren seçenekleri — çalışan kapsamına duyarlı.
 *
 * IC_PERSONEL (Dahili Personel): yalnız seçili şubenin şirketine ait SGK
 * işverenleri (aynı şirket invariant'ı; backend aynı kuralı doğrular).
 *
 * DIS_KAYNAK (Harici Personel): SGK/bordro kaynağı fiili organizasyon şubesinden
 * bağımsız bir eksendir. Katalogdaki tüm AKTİF SGK işverenleri seçilebilir
 * (ör. Şenay Mobilya bordrolu fakat Medisa fabrikasında çalışan kişi). Şube
 * adından tahmin yapılmaz, otomatik seçim yapılmaz.
 */
export function filterSgkIsverenOptionsForCreate(
  sgkOptions: IdOption[],
  subeOptions: IdOption[],
  subeId: string,
  calisanKapsami: "IC_PERSONEL" | "DIS_KAYNAK"
): IdOption[] {
  if (calisanKapsami === "DIS_KAYNAK") {
    return sgkOptions;
  }
  return filterSgkIsverenOptionsForSube(sgkOptions, subeOptions, subeId);
}

const STATU_ALLOWLIST = ["mavi yaka", "beyaz yaka"];

function normalizeStatuLabel(value: string): string {
  return value.trim().replace(/\s+/g, " ").toLocaleLowerCase("tr-TR");
}

/**
 * Kayıt formundaki personel_tipi_id alanının kullanıcı-facing "Statü" owner'ı:
 * refs.personelTipiOptions içinden yalnız Mavi Yaka / Beyaz Yaka kayıtlarını
 * canonical label'a göre filtreler. ID eşleşmesi yapılmaz; payload kontratı
 * (personel_tipi_id) aynen korunur.
 */
export function filterStatuOptionsForCreate(personelTipiOptions: IdOption[]): IdOption[] {
  return personelTipiOptions.filter((option) =>
    STATU_ALLOWLIST.includes(normalizeStatuLabel(option.label))
  );
}

/**
 * Çalışma lokasyonu picker/display mapper: generic kod + ad birleşimini
 * ("ANKARA — Ankara") insan-okur ada ("Ankara") indirir. DB/API `kod`
 * değeri korunur; global formatReferenceValue davranışı değişmez.
 */
export function mapCalismaLokasyonuDisplayOptions(calismaLokasyonuOptions: IdOption[]): IdOption[] {
  return calismaLokasyonuOptions.map((option) => {
    const parts = option.label.split(" — ");
    const display = (parts.length > 1 ? parts[parts.length - 1] : option.label).trim();
    return { ...option, label: display || option.label };
  });
}
