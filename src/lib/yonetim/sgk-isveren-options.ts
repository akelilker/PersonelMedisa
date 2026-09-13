import type { AppSelectOption } from "../../components/form/AppSelect";
import type { YonetimSgkIsveren } from "../../types/yonetim";

/**
 * Şube formundaki SGK işvereni seçenekleri.
 *
 * İki kural birlikte uygulanır:
 *  1. yalnız seçili şirkete ait SGK işverenleri (başka şirketin kaydı asla
 *     görünmez; backend de aynı invariantı doğrular),
 *  2. yalnız AKTIF kayıtlar — ancak şubenin hâlihazırda bağlı olduğu kayıt PASIF
 *     olsa bile listede kalır. Aksi halde ilgisiz bir düzenleme mevcut eşlemeyi
 *     sessizce düşürürdü.
 *
 * Şirket bilinmiyorsa (legacy/şirketsiz kayıt) liste boştur: fail-closed.
 * Şube adı, ili veya lokasyonu üzerinden hiçbir SGK eşlemesi türetilmez.
 */
export function filterSgkIsverenOptionsForSirket(
  options: YonetimSgkIsveren[],
  sirketId: number | null,
  keepSgkIsverenId: number | null = null
): YonetimSgkIsveren[] {
  if (sirketId == null || sirketId <= 0) {
    return [];
  }

  return options.filter((option) => {
    if (option.sirket?.id !== sirketId) {
      return false;
    }
    if (option.durum === "AKTIF") {
      return true;
    }

    return keepSgkIsverenId != null && option.id === keepSgkIsverenId;
  });
}

/**
 * Şirket bağlamı değiştiğinde artık geçerli olmayan seçim korunmaz; sessizce
 * taşınmak yerine temizlenir (backend yine fail-closed doğrular).
 */
export function resolveSgkIsverenAfterSirketChange(
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
