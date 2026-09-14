// @vitest-environment jsdom

import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppDatePicker } from "../../src/components/form/AppDatePicker";
import { FormField } from "../../src/components/form/FormField";
import {
  APP_PICKER_BLUR_CLASS,
  APP_PICKER_BODY_ATTR,
  resetAppPickerOwner
} from "../../src/components/form/app-picker-layer";
import {
  buildMonthMatrix,
  buildYearRange,
  formatIsoToDisplay,
  parseDisplayToIso,
  parseIsoDate,
  shiftMonth,
  toIsoDate
} from "../../src/lib/tarih/date-picker-utils";

function datePanel(): HTMLElement {
  const panel = document.querySelector('[data-app-date-panel="1"]');
  if (!(panel instanceof HTMLElement)) {
    throw new Error("Kanonik takvim paneli bulunamadı.");
  }

  return panel;
}

function dateCell(iso: string): HTMLElement {
  const cell = document.querySelector(`[data-date-cell="${iso}"]`);
  if (!(cell instanceof HTMLElement)) {
    throw new Error(`Takvim hücresi bulunamadı: ${iso}`);
  }

  return cell;
}

function renderPicker(props: Partial<React.ComponentProps<typeof AppDatePicker>> = {}) {
  const onChange = props.onChange ?? vi.fn();

  return render(
    <div className="modal-container">
      <div className="modal-header">Kayıt ve Süreç İşlemleri</div>
      <div className="modal-body">
        <div className="form-section">
          <label className="form-label" htmlFor="dogum">
            Doğum Tarihi
          </label>
          <AppDatePicker name="dogum" value="" onChange={onChange} {...props} />
        </div>
      </div>
    </div>
  );
}

afterEach(() => {
  cleanup();
  resetAppPickerOwner();
  document.body.removeAttribute(APP_PICKER_BODY_ATTR);
});

describe("takvim tarih kontratı (parse / serialize)", () => {
  it("ISO değeri gg.aa.yyyy olarak gösterilir, boş değer boş kalır", () => {
    expect(formatIsoToDisplay("1991-04-12")).toBe("12.04.1991");
    expect(formatIsoToDisplay("")).toBe("");
    expect(formatIsoToDisplay(null)).toBe("");
  });

  it("gg.aa.yyyy girişi ISO'ya çevrilir; geçersiz gün reddedilir", () => {
    expect(parseDisplayToIso("12.04.1991")).toBe("1991-04-12");
    expect(parseDisplayToIso("12/04/1991")).toBe("1991-04-12");
    expect(parseDisplayToIso("1991-04-12")).toBe("1991-04-12");
    expect(parseDisplayToIso("31.02.1991")).toBeNull();
    expect(parseDisplayToIso("1991-13-01")).toBeNull();
    expect(parseDisplayToIso("")).toBeNull();
  });

  it("artık yıl ve ay sonu doğrulanır (takvim taşması yok)", () => {
    expect(toIsoDate({ year: 2024, month: 1, day: 29 })).toBe("2024-02-29");
    expect(toIsoDate({ year: 2023, month: 1, day: 29 })).toBeNull();
    expect(parseIsoDate("2024-02-29")?.day).toBe(29);
    expect(parseIsoDate("2024-02-30")).toBeNull();
  });

  it("ay matrisi Pazartesi başlangıçlı 42 hücredir", () => {
    const cells = buildMonthMatrix(2026, 8);
    expect(cells).toHaveLength(42);
    // 1 Eylül 2026 Salı → ilk hücre 31 Ağustos olur.
    expect(cells[0]!.iso).toBe("2026-08-31");
    expect(cells.filter((cell) => cell.inMonth)).toHaveLength(30);
  });

  it("yıl aralığı uzak yıllara tek listede erişim sağlar", () => {
    const years = buildYearRange(2026);
    expect(years[0]).toBe(2046);
    expect(years).toContain(1990);
    expect(years).toContain(1980);
    expect(years.at(-1)).toBe(1916);
  });

  it("ay kaydırma yıl sınırını doğru taşır", () => {
    expect(shiftMonth(2026, 11, 1)).toEqual({ year: 2027, month: 0 });
    expect(shiftMonth(2026, 0, -1)).toEqual({ year: 2025, month: 11 });
  });
});


describe("AppDatePicker kanonik takvim", () => {
  it("native input değer/etiket sahibidir; trigger temalı gg.aa.yyyy gösterir", () => {
    renderPicker({ value: "1991-04-12", dataTestId: "dogum-tarihi" });

    const native = screen.getByLabelText("Doğum Tarihi") as HTMLInputElement;
    expect(native.tagName).toBe("INPUT");
    expect(native.getAttribute("type")).toBe("date");
    expect(native.getAttribute("name")).toBe("dogum");
    expect(native.value).toBe("1991-04-12");
    expect(native.getAttribute("data-testid")).toBe("dogum-tarihi");

    const trigger = document.querySelector('[data-app-date-trigger="1"]');
    expect(trigger).not.toBeNull();
    expect(trigger?.classList.contains("form-input")).toBe(true);
    expect(trigger?.textContent).toContain("12.04.1991");
  });

  it("boş değerde kanonik gg.aa.yyyy placeholder gösterilir", () => {
    renderPicker();

    const trigger = document.querySelector('[data-app-date-trigger="1"]');
    expect(trigger?.textContent).toContain("gg.aa.yyyy");
    expect(trigger?.querySelector(".app-date-picker-value")?.classList.contains("is-placeholder")).toBe(true);
  });

  it("panel kanonik picker ailesinde açılır ve blur kontratını paylaşır", () => {
    renderPicker({ value: "2026-09-14" });

    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);

    const panel = datePanel();
    expect(panel.classList.contains("app-picker-panel")).toBe(true);
    expect(panel.getAttribute("data-view")).toBe("days");
    expect(document.body.getAttribute(APP_PICKER_BODY_ATTR)).toBe("1");
    expect(document.querySelector(".modal-header")?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(true);
    expect(document.querySelector(".app-date-picker")?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(false);
    expect(dateCell("2026-09-14").getAttribute("aria-pressed")).toBe("true");
  });

  it("gün seçimi ISO yayar ve paneli kapatır", () => {
    const onChange = vi.fn();
    renderPicker({ value: "2026-09-14", onChange });

    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);
    fireEvent.click(dateCell("2026-09-23"));

    expect(onChange).toHaveBeenCalledWith("2026-09-23");
    expect(document.querySelector('[data-app-date-panel="1"]')).toBeNull();
    expect(document.body.hasAttribute(APP_PICKER_BODY_ATTR)).toBe(false);
  });

  it("doğum tarihi: yıl modunda uzak yıla tek adımda gidilir (tek tek ay geri gitmek yok)", () => {
    const onChange = vi.fn();
    renderPicker({ value: "2026-09-14", onChange });

    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);
    fireEvent.click(document.querySelector('[data-date-ref="year"]') as HTMLElement);
    expect(datePanel().getAttribute("data-view")).toBe("years");

    fireEvent.click(document.querySelector('[data-date-year="1980"]') as HTMLElement);
    expect(datePanel().getAttribute("data-view")).toBe("months");

    fireEvent.click(document.querySelector('[data-date-month="1980-4"]') as HTMLElement);
    expect(datePanel().getAttribute("data-view")).toBe("days");
    expect(datePanel().textContent).toContain("1980");

    fireEvent.click(dateCell("1980-05-15"));
    expect(onChange).toHaveBeenCalledWith("1980-05-15");
  });

  it("outside click paneli kapatır", async () => {
    renderPicker({ value: "2026-09-14" });

    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);
    await act(async () => {
      document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    });

    expect(document.querySelector('[data-app-date-panel="1"]')).toBeNull();
  });

  it("required alanda Temizle yoktur; opsiyonel alanda Temizle değeri boşaltır", () => {
    const requiredChange = vi.fn();
    renderPicker({ value: "2026-09-14", required: true, onChange: requiredChange });
    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);
    expect(document.querySelector('[data-date-clear="1"]')).toBeNull();

    cleanup();

    const optionalChange = vi.fn();
    renderPicker({ value: "2026-09-14", onChange: optionalChange });
    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);
    fireEvent.click(document.querySelector('[data-date-clear="1"]') as HTMLElement);

    expect(optionalChange).toHaveBeenCalledWith("");
    expect(document.querySelector('[data-app-date-panel="1"]')).toBeNull();
  });

  it("min sınırı dışındaki günler seçilemez", () => {
    renderPicker({ value: "2026-09-14", min: "2026-09-10" });

    fireEvent.click(document.querySelector('[data-app-date-trigger="1"]') as HTMLElement);

    expect(dateCell("2026-09-09").hasAttribute("disabled")).toBe(true);
    expect(dateCell("2026-09-14").hasAttribute("disabled")).toBe(false);
  });

  it("FormField type=date kanonik takvimi kullanır ve etiket→değer sahibi kontratını korur", () => {
    const onChange = vi.fn();
    render(
      <div className="modal-container">
        <div className="modal-body">
          <FormField
            label="Doğum Tarihi"
            name="create-dogum"
            type="date"
            value=""
            onChange={onChange}
            required
          />
        </div>
      </div>
    );

    const native = screen.getByLabelText("Doğum Tarihi") as HTMLInputElement;
    expect(native.getAttribute("name")).toBe("create-dogum");
    expect(native.getAttribute("type")).toBe("date");
    expect(native.hasAttribute("required")).toBe(true);

    fireEvent.change(native, { target: { value: "1991-04-12" } });
    expect(onChange).toHaveBeenCalledWith("1991-04-12");

    // Native beyaz input görünmez değer sahibidir; görünen alan temalı trigger'dır.
    expect(document.querySelector(".app-date-picker-native")).not.toBeNull();
    expect(document.querySelector('[data-app-date-trigger="1"]')?.classList.contains("form-input")).toBe(true);
  });
});

describe("takvim tema kontratı (native beyaz input kalktı)", () => {
  const css = readFileSync(
    resolve(process.cwd(), "src/styles/components/app-date-picker.css"),
    "utf8"
  );

  it("native input gizli değer sahibidir, native popup'a güvenilmez", () => {
    expect(css).toMatch(/\.app-date-picker-native\s*\{[^}]*opacity:\s*0/s);
    expect(css).toMatch(/\.app-date-picker-native\s*\{[^}]*pointer-events:\s*none/s);
  });

  it("takvim kartları koyu kanonik yüzeyi ve marka token'larını kullanır", () => {
    expect(css).toMatch(/\.app-date-cell\s*\{[^}]*background:\s*var\(--bg-surface\)/s);
    expect(css).toMatch(/\.app-date-cell\.is-selected\s*\{[^}]*var\(--theme-color-rgb\)/s);
    expect(css).not.toContain("!important");
    expect(css).not.toMatch(/#[0-9a-fA-F]{3,6}\b/);
  });

  it("takvim paneli kanonik picker panelini ve ölçüm kontratını paylaşır", () => {
    const component = readFileSync(
      resolve(process.cwd(), "src/components/form/AppDatePicker.tsx"),
      "utf8"
    );
    expect(component).toContain('"app-picker-panel app-date-panel"');
    expect(component).toContain("measurePickerPanel");
    expect(component).toContain("applyPickerPanelGeometry");
  });
});
