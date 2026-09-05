/** Cross-shell event: open Bugünkü Personel Durumu modal (IK daily correction owner). */
export const OPEN_BUGUN_PERSONEL_DURUMU_EVENT = "medisa:open-bugun-personel-durumu";

/** Ask header attention badge / open modal consumers to refetch Bugün. */
export const REFRESH_BUGUN_PERSONEL_DURUMU_EVENT = "medisa:refresh-bugun-personel-durumu";

export function dispatchOpenBugunPersonelDurumu(): void {
  if (typeof window === "undefined") {
    return;
  }
  window.dispatchEvent(new CustomEvent(OPEN_BUGUN_PERSONEL_DURUMU_EVENT));
}

export function dispatchRefreshBugunPersonelDurumu(): void {
  if (typeof window === "undefined") {
    return;
  }
  window.dispatchEvent(new CustomEvent(REFRESH_BUGUN_PERSONEL_DURUMU_EVENT));
}
