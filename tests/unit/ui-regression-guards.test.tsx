// @vitest-environment jsdom

/**
 * Regresyon kilitleri (Fast CI):
 * A) iOS çift liste: dokunmatikte gizli native <select> focus/tap almaz (WebKit'te focus()
 *    sistem picker'ını açar → #493'teki trigger pointerdown focus'u kökü). Tek liste kanonik panel.
 * B) Prim Kuralı kullanıcıya gösterilmez (onaylı karar; veri/API değişmez).
 */
import { readdirSync, readFileSync, statSync } from "node:fs";
import { join, resolve } from "node:path";
import { cleanup, fireEvent, render } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppSelect } from "../../src/components/form/AppSelect";
import { resetAppPickerOwner } from "../../src/components/form/app-picker-layer";

const OPTIONS = [
  { value: "1", label: "Ayşe Yılmaz" },
  { value: "2", label: "Mehmet Kaya" }
];

function mockPointer(touch: boolean) {
  vi.stubGlobal(
    "matchMedia",
    vi.fn((query: string) => ({
      matches: touch && query.includes("hover: none"),
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn()
    }))
  );
}

function renderPicker() {
  return render(
    <div className="form-section">
      <label className="form-label" htmlFor="personel">
        Personel
      </label>
      <AppSelect
        name="personel"
        value=""
        onChange={() => undefined}
        options={OPTIONS}
        ariaLabel="Personel listesi"
        searchable
        searchInTrigger
        searchPlaceholder="Ad/Soyad Veya Sicil No. Girin."
        dataTestId="native-select"
      />
    </div>
  );
}

afterEach(() => {
  cleanup();
  resetAppPickerOwner();
  vi.unstubAllGlobals();
});

describe("A) iOS çift liste kilidi (AppSelect)", () => {
  it("dokunmatikte trigger dokunuşu native select'e focus vermez; tek arama alanı + kanonik liste açılır", () => {
    mockPointer(true);
    const { container } = renderPicker();
    const select = container.querySelector("select") as HTMLSelectElement;
    const trigger = container.querySelector("[data-app-select-trigger]") as HTMLElement;

    expect(select.tabIndex).toBe(-1);
    expect(select.getAttribute("aria-hidden")).toBe("true");
    expect(trigger.getAttribute("role")).toBe("combobox");

    fireEvent.pointerDown(trigger);
    fireEvent.click(trigger);

    expect(document.activeElement).not.toBe(select);
    expect(container.querySelector("[data-app-select-panel]")).not.toBeNull();
    expect(container.querySelectorAll("input[type='search']")).toHaveLength(1);
  });

  it("masaüstünde klavye sahipliği native select'te kalır", () => {
    mockPointer(false);
    const { container } = renderPicker();
    const select = container.querySelector("select") as HTMLSelectElement;
    const trigger = container.querySelector("[data-app-select-trigger]") as HTMLElement;
    fireEvent.pointerDown(trigger);
    expect(document.activeElement).toBe(select);
  });

  it("kaynak: native select focus yalnız dokunmatik-dışı guard üzerinden; CSS dokunmatikte görünmez", () => {
    const owner = readFileSync(resolve("src/components/form/AppSelect.tsx"), "utf8");
    const rawFocus = owner.match(/selectRef\.current\?\.focus\(/g) ?? [];
    expect(rawFocus).toHaveLength(1);
    expect(owner).toMatch(/if \(canFocusNativeSelect\(\)\) \{\s*selectRef\.current\?\.focus\(/);
    const css = readFileSync(resolve("src/styles/components/app-select.css"), "utf8");
    expect(css).toMatch(
      /@media \(hover: none\) and \(pointer: coarse\) \{[\s\S]*?\.app-select-native \{[^}]*pointer-events: none;[^}]*visibility: hidden;/
    );
  });
});

function listUiSources(dir: string, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      listUiSources(full, out);
    } else if (/\.tsx$/.test(name)) {
      out.push(full);
    }
  }
  return out;
}

describe("B) Prim Kuralı kullanıcıya gösterilmez", () => {
  it("hiçbir UI bileşeni Prim Kuralı etiketi/değeri render etmez", () => {
    const offenders = listUiSources(resolve("src")).filter((file) => {
      const source = readFileSync(file, "utf8");
      return /["'>]\s*Prim [Kk]ural[ıi]/.test(source) || /prim_kurali_adi/.test(source) || /primKuraliOptions\)/.test(source);
    });
    expect(offenders).toEqual([]);
  });
});

describe("C) Dış uygulama kimliği (sekme / link önizleme / PWA) — 42c4dd5b onaylı ad", () => {
  const APPROVED = "Personel Yönetim Sistemi";

  it("index.html title + og/twitter/apple/application meta onaylı adı taşır", () => {
    const html = readFileSync(resolve("index.html"), "utf8");
    expect(html).toContain(`<title>${APPROVED}</title>`);
    for (const attr of [
      `name="application-name" content="${APPROVED}"`,
      `name="apple-mobile-web-app-title" content="${APPROVED}"`,
      `property="og:site_name" content="${APPROVED}"`,
      `property="og:title" content="${APPROVED}"`,
      `name="twitter:title" content="${APPROVED}"`
    ]) {
      expect(html).toContain(attr);
    }
    expect(html).not.toMatch(/Medisa Personel ve Puantaj/);
  });

  it("site.webmanifest name/short_name onaylı değerdedir", () => {
    const manifest = JSON.parse(readFileSync(resolve("public/site.webmanifest"), "utf8")) as {
      name: string;
      short_name: string;
    };
    expect(manifest.name).toBe(APPROVED);
    expect(manifest.short_name).toBe("Personel Yönetim");
  });
});
