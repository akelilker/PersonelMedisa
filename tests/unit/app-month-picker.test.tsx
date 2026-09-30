// @vitest-environment jsdom

import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppMonthPicker } from "../../src/components/form/AppMonthPicker";
import { FormField } from "../../src/components/form/FormField";
import {
  APP_PICKER_BLUR_CLASS,
  APP_PICKER_BODY_ATTR,
  resetAppPickerOwner
} from "../../src/components/form/app-picker-layer";
import {
  formatIsoMonthToDisplay,
  parseIsoMonth,
  toIsoMonth
} from "../../src/lib/tarih/date-picker-utils";

function monthPanel(): HTMLElement {
  const panel = document.querySelector('[data-app-month-panel="1"]');
  if (!(panel instanceof HTMLElement)) {
    throw new Error("Kanonik ay paneli bulunamadı.");
  }

  return panel;
}

function monthCell(year: number, monthIndex: number): HTMLElement {
  const cell = document.querySelector(`[data-month-cell="${year}-${monthIndex}"]`);
  if (!(cell instanceof HTMLElement)) {
    throw new Error(`Ay hücresi bulunamadı: ${year}-${monthIndex}`);
  }

  return cell;
}

function renderPicker(props: Partial<React.ComponentProps<typeof AppMonthPicker>> = {}) {
  const onChange = props.onChange ?? vi.fn();

  return render(
    <div className="modal-container">
      <div className="modal-header">Filtreler</div>
      <div className="modal-body">
        <div className="form-section">
          <label className="form-label" htmlFor="donem">
            Ay
          </label>
          <AppMonthPicker name="donem" value="" onChange={onChange} {...props} />
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

describe("ay kontratı (parse / serialize)", () => {
  it("ISO değeri Eylül 2026 olarak gösterilir, boş değer boş kalır", () => {
    expect(formatIsoMonthToDisplay("2026-09")).toBe("Eylül 2026");
    expect(formatIsoMonthToDisplay("")).toBe("");
    expect(formatIsoMonthToDisplay(null)).toBe("");
  });

  it("yyyy-mm parse ve serialize tutarlıdır", () => {
    expect(parseIsoMonth("2026-09")).toEqual({ year: 2026, month: 8 });
    expect(parseIsoMonth("2026-13")).toBeNull();
    expect(parseIsoMonth("2026-4")).toBeNull();
    expect(toIsoMonth({ year: 2026, month: 8 })).toBe("2026-09");
  });
});

describe("AppMonthPicker kanonik ay seçici", () => {
  it("native input değer sahibidir; trigger temalı Türkçe ay gösterir", () => {
    renderPicker({ value: "2026-09", dataTestId: "donem-ay" });

    const native = screen.getByLabelText("Ay") as HTMLInputElement;
    expect(native.tagName).toBe("INPUT");
    expect(native.getAttribute("type")).toBe("month");
    expect(native.getAttribute("name")).toBe("donem");
    expect(native.value).toBe("2026-09");
    expect(native.getAttribute("data-testid")).toBe("donem-ay");

    const trigger = document.querySelector('[data-app-month-trigger="1"]');
    expect(trigger).not.toBeNull();
    expect(trigger?.classList.contains("form-input")).toBe(true);
    expect(trigger?.textContent).toContain("Eylül 2026");
    expect(trigger?.querySelector(".app-month-picker-icon")).not.toBeNull();
  });

  it("boş değerde kanonik Ay Seçin placeholder gösterilir", () => {
    renderPicker();

    const trigger = document.querySelector('[data-app-month-trigger="1"]');
    expect(trigger?.textContent).toContain("Ay Seçin");
    expect(trigger?.querySelector(".app-month-picker-value")?.classList.contains("is-placeholder")).toBe(true);
  });

  it("panel kanonik picker ailesinde açılır ve blur kontratını paylaşır", () => {
    renderPicker({ value: "2026-09" });

    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);

    const panel = monthPanel();
    expect(panel.classList.contains("app-picker-panel")).toBe(true);
    expect(panel.getAttribute("data-view")).toBe("months");
    expect(document.body.getAttribute(APP_PICKER_BODY_ATTR)).toBe("1");
    expect(document.querySelector(".modal-header")?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(true);
    expect(monthCell(2026, 8).getAttribute("aria-pressed")).toBe("true");
    expect(document.querySelector('[data-app-month-scrim="1"]')).not.toBeNull();
  });

  it("ay seçimi yyyy-mm yayar ve paneli kapatır", () => {
    const onChange = vi.fn();
    renderPicker({ value: "2026-09", onChange });

    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);
    fireEvent.click(monthCell(2026, 3));

    expect(onChange).toHaveBeenCalledWith("2026-04");
    expect(document.querySelector('[data-app-month-panel="1"]')).toBeNull();
    expect(document.body.hasAttribute(APP_PICKER_BODY_ATTR)).toBe(false);
  });

  it("outside click paneli kapatır", async () => {
    renderPicker({ value: "2026-09" });

    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);
    await act(async () => {
      document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    });

    expect(document.querySelector('[data-app-month-panel="1"]')).toBeNull();
  });

  it("scrim tıklaması paneli kapatır", () => {
    renderPicker({ value: "2026-09" });

    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);
    fireEvent.pointerDown(document.querySelector('[data-app-month-scrim="1"]') as HTMLElement);

    expect(document.querySelector('[data-app-month-panel="1"]')).toBeNull();
  });

  it("required alanda Temizle yoktur; opsiyonel alanda Temizle değeri boşaltır", () => {
    const requiredChange = vi.fn();
    renderPicker({ value: "2026-09", required: true, onChange: requiredChange });
    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);
    expect(document.querySelector('[data-month-clear="1"]')).toBeNull();

    cleanup();

    const optionalChange = vi.fn();
    renderPicker({ value: "2026-09", onChange: optionalChange });
    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);
    fireEvent.click(document.querySelector('[data-month-clear="1"]') as HTMLElement);

    expect(optionalChange).toHaveBeenCalledWith("");
    expect(document.querySelector('[data-app-month-panel="1"]')).toBeNull();
  });

  it("disabled trigger panel açmaz", () => {
    renderPicker({ value: "2026-09", disabled: true });

    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);
    expect(document.querySelector('[data-app-month-panel="1"]')).toBeNull();
  });

  it("min sınırı dışındaki aylar seçilemez", () => {
    renderPicker({ value: "2026-09", min: "2026-06" });

    fireEvent.click(document.querySelector('[data-app-month-trigger="1"]') as HTMLElement);

    expect(monthCell(2026, 2).hasAttribute("disabled")).toBe(true);
    expect(monthCell(2026, 8).hasAttribute("disabled")).toBe(false);
  });

  it("FormField type=month kanonik ay seçiciyi kullanır ve etiket→değer sahibi kontratını korur", () => {
    const onChange = vi.fn();
    render(
      <div className="modal-container">
        <div className="modal-body">
          <FormField
            label="Dönem"
            name="finans-create-donem"
            type="month"
            value=""
            onChange={onChange}
            required
          />
        </div>
      </div>
    );

    const native = screen.getByLabelText("Dönem") as HTMLInputElement;
    expect(native.getAttribute("name")).toBe("finans-create-donem");
    expect(native.getAttribute("type")).toBe("month");
    expect(native.hasAttribute("required")).toBe(true);

    fireEvent.change(native, { target: { value: "2026-04" } });
    expect(onChange).toHaveBeenCalledWith("2026-04");

    expect(document.querySelector(".app-month-picker-native")).not.toBeNull();
    expect(document.querySelector('[data-app-month-trigger="1"]')?.classList.contains("form-input")).toBe(true);
  });
});

describe("ay seçici tema kontratı", () => {
  const css = readFileSync(
    resolve(process.cwd(), "src/styles/components/app-month-picker.css"),
    "utf8"
  );

  it("native input gizli değer sahibidir, native popup'a güvenilmez", () => {
    expect(css).toMatch(/\.app-month-picker-native\s*\{[^}]*opacity:\s*0/s);
    expect(css).toMatch(/\.app-month-picker-native\s*\{[^}]*pointer-events:\s*none/s);
  });

  it("ay paneli kanonik picker panelini ve ölçüm kontratını paylaşır", () => {
    const component = readFileSync(
      resolve(process.cwd(), "src/components/form/AppMonthPicker.tsx"),
      "utf8"
    );
    expect(component).toContain('"app-picker-panel app-month-panel"');
    expect(component).toContain("measurePickerPanel");
    expect(component).toContain("applyPickerPanelGeometry");
    expect(css).not.toContain("!important");
  });
});
