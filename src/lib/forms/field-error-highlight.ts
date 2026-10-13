/**
 * Onaylı karar (t869): ortak alan hata işaretleme mekanizması.
 * Hata bir alana aitse ilgili kontrol kırmızı çerçeve alır, ilk hatalı alana
 * odaklanılır/kaydırılır; kullanıcı alanı düzenleyince işaret kalkar.
 * Formlar tek tek değişmez: alan `name`/`id`/`data-field` veya görünen etiket ile bulunur.
 */

import { fieldLabelOf } from "../display/field-labels";

export const FIELD_ERROR_CLASS = "field-has-error";

/** API alan anahtarı → ekrandaki form kontrolü adı (anahtar ile aynı değilse). */
export const FIELD_INPUT_ALIASES: Record<string, readonly string[]> = {
  tc_kimlik_no: ["create-tc", "edit-tc-kimlik-no", "tc-kimlik-no", "tcKimlikNo"],
  sicil_no: ["edit-sicil-no", "sicil-no", "sicilNo"],
  dogum_tarihi: ["create-dogum", "edit-dogum-tarihi", "dogum-tarihi", "dogumTarihi"],
  telefon: ["create-telefon", "edit-telefon"],
  ad: ["create-ad", "edit-ad"],
  soyad: ["create-soyad", "edit-soyad"],
  ise_giris_tarihi: ["create-ise-giris", "edit-ise-giris-tarihi", "ise-giris-tarihi", "iseGirisTarihi"],
  sube_id: ["create-sube", "kalici-yeni-sube", "sube-id", "subeId"],
  sgk_isveren_id: ["create-sgk-isveren", "pozisyon-sgk", "sgk-isveren-id", "sgkIsverenId"],
  calisma_lokasyonu_id: ["create-calisma-lokasyonu", "pozisyon-lokasyon", "calisma-lokasyonu-id", "calismaLokasyonuId"],
  bagli_amir_id: ["create-bagli-amir", "pozisyon-bagli-amir", "bagli-amir-id", "bagliAmirId"],
  departman_id: ["create-departman", "pozisyon-departman", "departman-id", "departmanId"],
  bolum_id: ["create-bolum", "pozisyon-bolum", "bolum-id", "bolumId"],
  birim_id: ["create-birim", "pozisyon-birim", "birim-id", "birimId"],
  gorev_id: ["create-gorev", "pozisyon-gorev", "gorev-id", "gorevId"],
  pozisyon_id: ["create-pozisyon", "pozisyon-pozisyon", "pozisyon-id", "pozisyonId"],
  maas_tutari: ["create-maas"],
  ucret_tipi_id: ["create-ucret-tipi"],
  kan_grubu: ["create-kan"],
  personel_tipi_id: ["create-personel-tipi", "pozisyon-personel-tipi", "personel-tipi-id", "personelTipiId"]
};

function foldTr(text: string): string {
  return text
    .toLocaleLowerCase("tr-TR")
    .replace(/[ç]/g, "c").replace(/[ğ]/g, "g").replace(/[ı]/g, "i").replace(/[ö]/g, "o")
    .replace(/[ş]/g, "s").replace(/[ü]/g, "u").replace(/[^a-z0-9]+/g, " ").trim();
}

function cssEscape(value: string): string {
  return typeof CSS !== "undefined" && typeof CSS.escape === "function" ? CSS.escape(value) : value.replace(/["\\]/g, "\\$&");
}

function searchRoot(): ParentNode {
  const dialogs = Array.from(document.querySelectorAll<HTMLElement>("[role='dialog'], dialog[open], .modal"));
  return dialogs.length > 0 ? dialogs[dialogs.length - 1]! : document;
}

function isVisible(el: Element): boolean {
  if (!(el instanceof HTMLElement)) return false;
  if (el instanceof HTMLInputElement && el.type === "hidden") return false;
  return el.getClientRects().length > 0;
}

/** Hatalı alanın görünen kontrolünü ve sarmalayıcısını bulur. */
export function findFieldControl(fieldOrLabel: string, root: ParentNode = searchRoot()): { wrapper: HTMLElement; control: HTMLElement } | null {
  const candidates = [fieldOrLabel, ...(FIELD_INPUT_ALIASES[fieldOrLabel] ?? [])];
  let el: Element | null = null;
  for (const key of candidates) {
    const k = cssEscape(key);
    el = root.querySelector(`[name="${k}"], [id="${k}"], [data-field="${k}"]`);
    if (el) break;
  }
  if (!el) {
    // t871: yeni alan anahtarları için ortak etiket haritası (FIELD_LABELS) da takma ad sayılır.
    const wants = new Set([
      foldTr(fieldOrLabel.replace(/_id$/, "").replace(/_/g, " ")),
      foldTr(fieldLabelOf(fieldOrLabel) ?? fieldOrLabel)
    ]);
    const labels = Array.from(root.querySelectorAll<HTMLLabelElement>("label"));
    const label = labels.find((l) => wants.has(foldTr(l.textContent ?? "").replace(/\s*\*$/, "")));
    if (label) {
      el = label.htmlFor ? document.getElementById(label.htmlFor) : null;
      el = el ?? label.closest(".form-section")?.querySelector("input, select, textarea, button") ?? null;
      el = el ?? label;
    }
  }
  if (!el) return null;
  const wrapper = (el.closest(".form-section, .form-field, .app-select-field") as HTMLElement | null) ?? (el as HTMLElement);
  const control = isVisible(el)
    ? (el as HTMLElement)
    : (Array.from(wrapper.querySelectorAll<HTMLElement>("input, select, textarea, button, [role='combobox']")).find(isVisible) ?? wrapper);
  return { wrapper, control };
}

export function clearFieldErrors(root: ParentNode = document): void {
  root.querySelectorAll(`.${FIELD_ERROR_CLASS}`).forEach((el) => el.classList.remove(FIELD_ERROR_CLASS));
  root.querySelectorAll("[data-field-error='true']").forEach((el) => {
    el.removeAttribute("aria-invalid");
    el.removeAttribute("data-field-error");
  });
}

function mark(found: { wrapper: HTMLElement; control: HTMLElement }, focus: boolean): void {
  const { wrapper, control } = found;
  wrapper.classList.add(FIELD_ERROR_CLASS);
  control.setAttribute("aria-invalid", "true");
  control.setAttribute("data-field-error", "true");
  const clear = () => {
    wrapper.classList.remove(FIELD_ERROR_CLASS);
    control.removeAttribute("aria-invalid");
    control.removeAttribute("data-field-error");
    wrapper.removeEventListener("input", clear, true);
    wrapper.removeEventListener("change", clear, true);
  };
  wrapper.addEventListener("input", clear, true);
  wrapper.addEventListener("change", clear, true);
  if (focus) {
    if (typeof control.scrollIntoView === "function") control.scrollIntoView({ block: "center", behavior: "smooth" });
    control.focus({ preventScroll: true });
  }
}

/**
 * Alanı kırmızı çerçeveyle işaretler (ilk alana odaklanır). Alan henüz ekranda değilse
 * (sekme/modal açılıyor) kısa süre bekler. Bulunduysa true döner (Promise).
 */
export function highlightFieldErrors(fields: readonly string[], options: { focus?: boolean; timeoutMs?: number } = {}): Promise<boolean> {
  const { focus = true, timeoutMs = 2500 } = options;
  if (typeof document === "undefined" || fields.length === 0) return Promise.resolve(false);
  const started = Date.now();
  return new Promise((resolve) => {
    const attempt = () => {
      const found = fields.map((f) => findFieldControl(f)).filter((x): x is NonNullable<typeof x> => x !== null);
      if (found.length > 0) {
        found.forEach((f, i) => mark(f, focus && i === 0));
        resolve(true);
        return;
      }
      if (Date.now() - started > timeoutMs) {
        resolve(false);
        return;
      }
      setTimeout(attempt, 60);
    };
    setTimeout(attempt, 0);
  });
}
