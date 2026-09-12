/**
 * Canonical picker blur owner (PersonelMedisa).
 *
 * Kontrat:
 * - Aynı anda tek kanonik picker açıktır (kayıt AppSelect içinde tutulur).
 * - Picker açıkken `document.body[data-app-picker-open="1"]` set edilir ve picker'ın
 *   ata zincirinde OLMAYAN tüm kardeş elemanlara `.app-picker-blurred` eklenir.
 *   Blur davranışı tek shared CSS kuralında yaşar; sayfa/feature bazlı class yoktur.
 * - Picker'ın kendi alanı (form-section + label + seçenek paneli) net kalır.
 *
 * Taşıt Yönetim Sistemi referansı: `:has(.medisa-owner-select-menu.open)` ile blur edilen
 * modal bölümleri. Burada aynı davranış tek JS owner + tek CSS kontratı ile uygulanır.
 */

export const APP_PICKER_BLUR_CLASS = "app-picker-blurred";
export const APP_PICKER_BODY_ATTR = "data-app-picker-open";

/** Blur kapsamı: modal > uygulama shell'i > uygulama kökü sırasıyla aranır. */
const BLUR_SCOPE_SELECTORS = [".modal-container", ".app-shell", "#root"];

/** Picker'ın kendi etiketi blur edilmez. */
const PICKER_CHROME_SELECTOR = `label, .form-label`;

let activeRoot: HTMLElement | null = null;

function resolveBlurScope(root: HTMLElement): HTMLElement {
  for (const selector of BLUR_SCOPE_SELECTORS) {
    const scope = root.closest<HTMLElement>(selector);
    if (scope) {
      return scope;
    }
  }

  return document.body;
}

export function clearAppPickerBlur(): void {
  if (typeof document === "undefined") {
    return;
  }

  document.querySelectorAll(`.${APP_PICKER_BLUR_CLASS}`).forEach((element) => {
    element.classList.remove(APP_PICKER_BLUR_CLASS);
  });
  document.body.removeAttribute(APP_PICKER_BODY_ATTR);
}

/**
 * Picker açıldığında: picker'ın kendi alanı (form-section + label + panel) net kalır,
 * zincir üzerindeki her seviyede diğer kardeşler bulanıklaşır.
 */
export function activateAppPicker(root: HTMLElement): void {
  if (typeof document === "undefined") {
    return;
  }

  clearAppPickerBlur();
  activeRoot = root;

  const scope = resolveBlurScope(root);
  let node: HTMLElement = root;

  while (node.parentElement) {
    const parent = node.parentElement;
    for (const sibling of Array.from(parent.children)) {
      if (sibling === node) {
        continue;
      }

      if (sibling instanceof HTMLElement && sibling.matches(PICKER_CHROME_SELECTOR)) {
        continue;
      }

      sibling.classList.add(APP_PICKER_BLUR_CLASS);
    }

    if (parent === scope || parent === document.body) {
      break;
    }

    node = parent;
  }

  document.body.setAttribute(APP_PICKER_BODY_ATTR, "1");
}

/** Picker kapandığında blur tamamen kalkar. Sadece aktif owner temizleyebilir. */
export function deactivateAppPicker(root: HTMLElement): void {
  if (activeRoot !== root) {
    return;
  }

  activeRoot = null;
  clearAppPickerBlur();
}

/** Test/teardown yardımcıları. */
export function resetAppPickerOwner(): void {
  activeRoot = null;
  clearAppPickerBlur();
}
