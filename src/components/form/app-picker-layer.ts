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

/* ==========================================================================
   Panel geometrisi (canonical owner)
   --------------------------------------------------------------------------
   Tüm kanonik picker'lar (AppSelect, AppDatePicker) aynı ölçüm/konumlandırma
   kontratını kullanır. Amaç:
   - dikey: yer yoksa yukarı aç (data-placement), max-height + internal scroll
   - yatay: panel viewport/modal sınırından sağa taşmaz; trigger genişliğinden
     anlamsız fazla genişlemez
   - mobile: ekran dışına çıkmaz
   Pixel workaround ve picker başına CSS hack yoktur; değerler imperative
   yazılır (JSX inline style kullanılmaz).
   ========================================================================== */

export type PickerPanelPlacement = "above" | "below";

export const PICKER_PANEL_GAP = 6;
export const PICKER_PANEL_MARGIN = 8;
export const PICKER_PANEL_MAX_HEIGHT = 320;
export const PICKER_PANEL_MIN_HEIGHT = 120;

/**
 * Ay seçici (3 sütun TR ay adları): dar trigger'da panel trigger genişliğine
 * sıkışmaz; Ağustos/Temmuz/Eylül okunabilir kalır. Viewport clamp üst sınırı
 * `measurePickerPanel` içinde korunur.
 */
export const PICKER_MONTH_PANEL_MIN_WIDTH = 252;

export type PickerPanelMeasureOptions = {
  minPanelWidth?: number;
};

export type PickerVisibleClip = { top: number; bottom: number; left: number; right: number };

function isClippingOverflow(value: string): boolean {
  return /(auto|scroll|hidden)/.test(value);
}

/**
 * Panelin yerleşebileceği görünür kutu: en yakın kırpıcı ata ∩ viewport.
 * Yatay ve dikey aynı yürüyüşte hesaplanır.
 */
export function resolvePickerVisibleClip(root: HTMLElement): PickerVisibleClip {
  const viewportWidth = window.innerWidth || document.documentElement.clientWidth || 1024;
  const viewportHeight = window.innerHeight || document.documentElement.clientHeight || 800;
  const clip: PickerVisibleClip = { top: 0, bottom: viewportHeight, left: 0, right: viewportWidth };
  let node = root.parentElement;

  while (node && node !== document.body) {
    const style = window.getComputedStyle(node);
    if (isClippingOverflow(style.overflowY) || isClippingOverflow(style.overflowX)) {
      const rect = node.getBoundingClientRect();

      return {
        top: Math.max(rect.top, 0),
        bottom: Math.min(rect.bottom, viewportHeight),
        left: Math.max(rect.left, 0),
        right: Math.min(rect.right, viewportWidth)
      };
    }

    node = node.parentElement;
  }

  return clip;
}

export type PickerPanelGeometry = {
  placement: PickerPanelPlacement;
  maxHeight: number;
  maxWidth: number;
  width: number;
  offsetLeft: number;
};

export function clampNumber(value: number, min: number, max: number): number {
  return Math.min(Math.max(value, min), max);
}

/** Panel CSS genişliği trigger'a kilitli olduğunda gerçek içerik genişliğini ölçer. */
function measurePanelContentWidth(panel: HTMLElement): number {
  const previousWidth = panel.style.width;
  const previousRight = panel.style.right;
  const previousMaxWidth = panel.style.maxWidth;

  panel.style.width = "max-content";
  panel.style.right = "auto";
  panel.style.maxWidth = "none";

  const contentWidth = panel.scrollWidth;

  panel.style.width = previousWidth;
  panel.style.right = previousRight;
  panel.style.maxWidth = previousMaxWidth;

  return contentWidth;
}

/**
 * Panel geometrisini ölçer. `root` = picker'ın konumlandırma referansı
 * (`.app-select` / `.app-date-picker` / `.app-month-picker`).
 */
export function measurePickerPanel(
  root: HTMLElement,
  panel: HTMLElement,
  options?: PickerPanelMeasureOptions
): PickerPanelGeometry {
  const rect = root.getBoundingClientRect();
  const clip = resolvePickerVisibleClip(root);
  const naturalHeight = panel.scrollHeight || 0;
  const desiredHeight = clampNumber(naturalHeight || 260, PICKER_PANEL_MIN_HEIGHT, PICKER_PANEL_MAX_HEIGHT);
  const spaceBelow = clip.bottom - rect.bottom - PICKER_PANEL_GAP - PICKER_PANEL_MARGIN;
  const spaceAbove = rect.top - clip.top - PICKER_PANEL_GAP - PICKER_PANEL_MARGIN;
  const placement: PickerPanelPlacement =
    spaceBelow < Math.min(160, desiredHeight) && spaceAbove > spaceBelow ? "above" : "below";
  const maxHeight = Math.max(
    PICKER_PANEL_MIN_HEIGHT,
    Math.min(PICKER_PANEL_MAX_HEIGHT, placement === "above" ? spaceAbove : spaceBelow)
  );

  const availableWidth = Math.max(0, clip.right - clip.left - PICKER_PANEL_MARGIN * 2);
  const minPanelWidth = options?.minPanelWidth ?? 0;
  const contentWidth =
    minPanelWidth > 0 ? measurePanelContentWidth(panel) : panel.scrollWidth || 0;
  const naturalWidth = Math.max(rect.width, contentWidth, minPanelWidth);
  const width = Math.max(0, Math.min(naturalWidth, availableWidth || naturalWidth));
  // Tercih: trigger soluna hizalı. Sağ sınırı aşarsa sola kaydır, sol sınırı aşarsa geri clamp et.
  const preferredLeft = rect.left;
  const overflowRight = preferredLeft + width - (clip.right - PICKER_PANEL_MARGIN);
  let offsetLeft = overflowRight > 0 ? -overflowRight : 0;
  const minOffset = clip.left + PICKER_PANEL_MARGIN - preferredLeft;
  offsetLeft = Math.max(offsetLeft, Math.min(0, minOffset));

  return { placement, maxHeight, maxWidth: availableWidth, width, offsetLeft };
}

/** Geometriyi imperative uygular (JSX inline style yok). */
export function applyPickerPanelGeometry(panel: HTMLElement, geometry: PickerPanelGeometry): void {
  panel.style.maxHeight = `${geometry.maxHeight}px`;
  panel.style.maxWidth = `${geometry.maxWidth}px`;
  panel.style.right = "auto";

  if (geometry.width > 0) {
    panel.style.width = `${geometry.width}px`;
    panel.style.left = `${geometry.offsetLeft}px`;
  }
}
