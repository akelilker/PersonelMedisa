import type { AppSelectOption } from "../../components/form/AppSelect";
import type { YonetimSgkIsveren } from "../../types/yonetim";

/**
 * Şube formundaki SGK işvereni seçenekleri.
 *
 * 2026-09-15 business kararı: şube şirketi ile `sgk_isveren.sirket_id` farklı
 * olabilir (ör. Medisa şubesi Karyapı veya Şenay SGK işverenini seçebilir).
 * Bu yüzden seçim listesi şirkete göre filtrelenmez; katalogdaki TÜM AKTIF SGK
 * işverenleri sunulur ve yeni eklenen bir SGK işvereni otomatik olarak listede
 * görünür. Şirketsiz (henüz eşlenmemiş) AKTIF kayıtlar da seçilebilir.
 *
 * Tek kural: yalnız AKTIF kayıtlar — ancak şubenin hâlihazırda bağlı olduğu
 * kayıt PASIF olsa bile listede kalır. Aksi halde ilgisiz bir düzenleme mevcut
 * eşlemeyi sessizce düşürürdü.
 *
 * Şube adı, ili veya lokasyonu üzerinden hiçbir SGK eşlemesi türetilmez.
 */
export function filterActiveSgkIsverenOptions(
  options: YonetimSgkIsveren[],
  keepSgkIsverenId: number | null = null
): YonetimSgkIsveren[] {
  return options.filter((option) => {
    if (option.durum === "AKTIF") {
      return true;
    }

    return keepSgkIsverenId != null && option.id === keepSgkIsverenId;
  });
}

/**
 * Seçim listesi değiştiğinde (katalog yenilendi, kayıt silindi) artık listede
 * olmayan seçim korunmaz; sessizce taşınmak yerine temizlenir (backend kaydı
 * yine fail-closed doğrular).
 */
export function resolveSgkIsverenSelection(
  currentSgkIsverenId: string,
  allowedOptions: YonetimSgkIsveren[]
): string {
  const current = currentSgkIsverenId.trim();
  if (!current) {
    return "";
  }

  return allowedOptions.some((option) => String(option.id) === current) ? current : "";
}

/** Pasif kayıt seçenek listesinde kalırsa kullanıcı bunu etiketten görür. */
export function toSgkIsverenSelectOptions(options: YonetimSgkIsveren[]): AppSelectOption[] {
  return options.map((option) => ({
    value: String(option.id),
    label: option.durum === "AKTIF" ? option.ad : `${option.ad} (pasif)`
  }));
}
