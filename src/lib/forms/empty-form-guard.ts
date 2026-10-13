/**
 * Onaylı karar (t879): uygulama genelinde tarayıcının kendi (İngilizce) doğrulama balonu kapalı.
 * Tüm formlar `noValidate` olur; gönderimde geçersiz alanlar ortak mekanizmayla işaretlenir:
 * kırmızı çerçeve + alan adlı Title Case Türkçe mesaj ("T.C. Kimlik No. Boş Bırakılamaz."),
 * ilk alana odak; kullanıcı alanı düzenleyince işaret kalkar.
 * Mesaj React ağacına düğüm eklemeden `data-field-error-text` + CSS ::after ile gösterilir.
 */
import { fieldLabelOf } from "../display/field-labels";
import { toTitleCaseTr } from "../display/user-error";
import { FIELD_ERROR_CLASS, FIELD_INPUT_ALIASES } from "./field-error-highlight";

export const FIELD_ERROR_TEXT_ATTR = "data-field-error-text";

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

function aliasKey(name: string): string | null {
  for (const [key, aliases] of Object.entries(FIELD_INPUT_ALIASES)) {
    if (key === name || aliases.includes(name)) return key;
  }
  return null;
}

function labelTextOf(el: Control): string | null {
  const own = el.labels?.[0] ?? el.closest("label");
  let text = own?.textContent ?? null;
  if (!text) {
    const section = el.closest(".form-section, .form-field, .app-select-field");
    text = section?.querySelector("label")?.textContent ?? null;
  }
  if (!text) text = el.getAttribute("aria-label") ?? el.getAttribute("placeholder");
  if (!text) return null;
  // Etiket içinde kontrolün kendi metni (ör. select seçenekleri) varsa ilk satırı al.
  const first = text.split("\n").map((s) => s.trim()).find(Boolean) ?? "";
  const clean = first.replace(/\s*\*\s*$/, "").replace(/[:：]\s*$/, "").trim();
  return clean.length > 0 && clean.length <= 60 ? clean : null;
}

/** Kontrolün kullanıcıya görünen alan adı (Title Case). */
export function fieldLabelForControl(el: Control): string {
  const name = el.getAttribute("name") || el.id || "";
  const key = name ? aliasKey(name) ?? name : "";
  const mapped = key ? fieldLabelOf(key) : null;
  const label = mapped ?? labelTextOf(el) ?? "Bu Alan";
  return toTitleCaseTr(label);
}

/** Native geçerlilik durumundan Title Case mesaj. */
export function validityMessage(el: Control): string {
  const label = fieldLabelForControl(el);
  const v = el.validity;
  if (v.valueMissing) return `${label} Boş Bırakılamaz.`;
  if (v.tooShort) return `${label} En Az ${el.getAttribute("minlength")} Karakter Olmalıdır.`;
  if (v.tooLong) return `${label} En Fazla ${el.getAttribute("maxlength")} Karakter Olabilir.`;
  if (v.rangeUnderflow) return `${label} En Az ${el.getAttribute("min")} Olmalıdır.`;
  if (v.rangeOverflow) return `${label} En Fazla ${el.getAttribute("max")} Olabilir.`;
  return `${label} Geçerli Değil. Lütfen Kontrol Edin.`;
}

function wrapperOf(el: HTMLElement): HTMLElement {
  return (
    (el.closest(".form-section, .form-field, .app-select-field, label") as HTMLElement | null) ??
    (el.parentElement as HTMLElement | null) ??
    el
  );
}

function markControl(el: Control, message: string, focus: boolean): void {
  const wrapper = wrapperOf(el);
  wrapper.classList.add(FIELD_ERROR_CLASS);
  wrapper.setAttribute(FIELD_ERROR_TEXT_ATTR, message);
  el.setAttribute("aria-invalid", "true");
  el.setAttribute("data-field-error", "true");
  const clear = () => {
    wrapper.classList.remove(FIELD_ERROR_CLASS);
    wrapper.removeAttribute(FIELD_ERROR_TEXT_ATTR);
    el.removeAttribute("aria-invalid");
    el.removeAttribute("data-field-error");
    wrapper.removeEventListener("input", clear, true);
    wrapper.removeEventListener("change", clear, true);
  };
  wrapper.addEventListener("input", clear, true);
  wrapper.addEventListener("change", clear, true);
  if (focus) {
    if (typeof el.scrollIntoView === "function") el.scrollIntoView({ block: "center", behavior: "smooth" });
    el.focus({ preventScroll: true });
  }
}

function announce(text: string): void {
  let live = document.getElementById("form-hata-duyuru");
  if (!live) {
    live = document.createElement("div");
    live.id = "form-hata-duyuru";
    live.setAttribute("role", "alert");
    live.className = "visually-hidden";
    live.style.cssText = "position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)";
    document.body.appendChild(live);
  }
  live.textContent = text;
}

/** Formdaki geçersiz alanları işaretler; varsa true döner (gönderim engellenmeli). */
export function markInvalidControls(form: HTMLFormElement): boolean {
  const invalid = Array.from(form.elements).filter(
    (el): el is Control =>
      (el instanceof HTMLInputElement || el instanceof HTMLSelectElement || el instanceof HTMLTextAreaElement) &&
      el.willValidate &&
      !el.validity.valid
  );
  if (invalid.length === 0) return false;
  const messages = invalid.map((el, i) => {
    const msg = validityMessage(el);
    markControl(el, msg, i === 0);
    return msg;
  });
  announce(messages.join(" "));
  return true;
}

function applyNoValidate(root: ParentNode): void {
  root.querySelectorAll?.("form:not([novalidate])").forEach((f) => ((f as HTMLFormElement).noValidate = true));
}

let installed = false;

/** Bootstrap'ta bir kez çağrılır (src/main.tsx). */
export function installFormValidationGuard(doc: Document = document): void {
  if (installed || typeof doc === "undefined") return;
  installed = true;
  applyNoValidate(doc);
  new MutationObserver((records) => {
    for (const r of records) {
      r.addedNodes.forEach((n) => {
        if (n instanceof HTMLFormElement) n.noValidate = true;
        else if (n instanceof Element) applyNoValidate(n);
      });
    }
  }).observe(doc.documentElement, { childList: true, subtree: true });
  doc.addEventListener(
    "submit",
    (event) => {
      const form = event.target;
      if (!(form instanceof HTMLFormElement)) return;
      if (markInvalidControls(form)) {
        event.preventDefault();
        event.stopImmediatePropagation();
      }
    },
    true
  );
}

/** Yalnız testler için. */
export function __resetFormValidationGuardForTests(): void {
  installed = false;
}
