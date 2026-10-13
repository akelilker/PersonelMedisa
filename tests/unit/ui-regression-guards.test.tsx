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

/* ---------------- Onaylı kararlar (docs/guncel/onayli-kararlar.md) — sistemik kilitler ---------------- */

function listSources(dir: string, pattern: RegExp, out: string[] = []): string[] {
  for (const name of readdirSync(dir)) {
    const full = join(dir, name);
    if (statSync(full).isDirectory()) {
      listSources(full, pattern, out);
    } else if (pattern.test(name) && !/\.test\./.test(name)) {
      out.push(full);
    }
  }
  return out;
}

const readSrc = (path: string) => readFileSync(resolve(path), "utf8");

describe("D) Tarih görünümü gg/aa/yyyy (tek biçimleyici)", () => {
  it("UI kodunda ham tr-TR tarih biçimlemesi yok (gg.aa.yyyy üretir)", () => {
    const offenders = listSources(resolve("src"), /\.(ts|tsx)$/)
      .filter((file) => !/mock-demo\.ts$/.test(file))
      .filter((file) => {
        const source = readFileSync(file, "utf8");
        return (
          /toLocale(Date)?String\(\s*["']tr-TR["']/.test(source) ||
          /Intl\.DateTimeFormat\(\s*["']tr-TR["']\s*,\s*\{[^}]*(dateStyle|month:\s*["'](2-digit|numeric)["'])/s.test(source)
        );
      });
    expect(offenders).toEqual([]);
  });

  it("formatDisplayDateTime / formatDisplayDate gg/aa/yyyy döndürür", async () => {
    const mod = await import("../../src/lib/display/iso-date-format");
    expect(mod.formatDisplayDate("1992-03-14")).toBe("14/03/1992");
    expect(mod.formatDisplayDateTime("2026-10-12T07:07:12Z")).toBe("12/10/2026 10:07");
    expect(mod.formatDisplayDateTime("2026-10-12 07:07:12Z", { seconds: true })).toBe("12/10/2026 10:07:12");
  });
});

describe('E) "No" kısaltması noktalı ("T.C. Kimlik No.", "Sicil No.")', () => {
  it("görünür metinlerde noktasız Kimlik No / Sicil No yok", () => {
    const offenders: string[] = [];
    for (const file of listSources(resolve("src"), /\.(ts|tsx)$/)) {
      readFileSync(file, "utf8")
        .split("\n")
        .forEach((line, i) => {
          if (/^\s*(\/\/|\*|\/\*)/.test(line)) return;
          if (/["'>`]\s*[^"'<`]*\b(Kimlik|Sicil) No(?![.\w])/.test(line)) offenders.push(`${file}:${i + 1}`);
        });
    }
    expect(offenders).toEqual([]);
  });
});

describe("F) Personel Kartı > Genel (Özlük / Kayıt Özeti) mobilde 2 kolon", () => {
  it("kayıt aynası mobilde tek kolona zorlanmaz; Kayıt formu 2 kolon sahibi korunur", () => {
    const css = readSrc("src/styles/modules/personeller.css");
    expect(css).not.toMatch(
      /\.personel-dosya-kayit-mirror \.personel-form-columns\s*\{[^}]*grid-template-columns:\s*(minmax\(0,\s*1fr\)|1fr)\s*;/s
    );
    expect(css).not.toMatch(/\.personel-dosya-kayit-mirror \.personel-form-columns::after\s*\{[^}]*display:\s*none/s);
  });
});

describe("G) Kayıt ve Süreç > Genel detay ızgarası mobil 2 / masaüstü 3", () => {
  it("mobil kırılımlarda 2 kolon kalır", () => {
    const css = readSrc("src/styles/modules/kayit-surec.css");
    const mobileBlocks = css
      .split("@media (max-width: 560px)")
      .slice(1)
      .map((chunk) => chunk.slice(0, chunk.indexOf("\n}\n")));
    expect(
      mobileBlocks.some((block) => /\.surec-person-general-columns\s*\{[^}]*grid-template-columns:\s*repeat\(2,/.test(block))
    ).toBe(true);
    expect(readSrc("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx")).not.toMatch(/Organizasyon özeti salt okunur/);
  });
});

describe("H) Aktif durum: yalnız yeşil nokta; kalem ile düzenleme", () => {
  it("görünür Çalışıyor pill'i yok, aktifte etiket render edilmez", () => {
    const hero = readSrc("src/features/personeller/components/personel-dosya/PersonelDosyaHero.tsx");
    expect(hero).toMatch(/isActive \? null :/);
    const offenders = listUiSources(resolve("src")).filter((file) =>
      /^(?!\s*(\/\/|\*|\{\/\*)).*>\s*Çalışıyor\s*</m.test(readFileSync(file, "utf8"))
    );
    expect(offenders).toEqual([]);
  });

  it("Süreç Genel başlığında kalem (Personeli Düzenle) var, ayrı metin düğmesi yok", () => {
    const heading = readSrc("src/features/kayit/components/KayitSurecPersonelNameHeading.tsx");
    expect(heading).toMatch(/aria-label="Personeli Düzenle"/);
    const offenders = listUiSources(resolve("src/features/kayit")).filter((file) =>
      />\s*Personeli Düzenle\s*</.test(readFileSync(file, "utf8"))
    );
    expect(offenders).toEqual([]);
  });
});

describe("I) Türkçe karakter: İşlemler", () => {
  it("görünür 'Islemler' yok", () => {
    const offenders = listUiSources(resolve("src")).filter((file) => />\s*Islemler\s*</.test(readFileSync(file, "utf8")) || /^\s*Islemler\s*$/m.test(readFileSync(file, "utf8")));
    expect(offenders).toEqual([]);
  });
});

describe("J) Ortak modal başlığı ve footer sabitleri", () => {
  it("kırmızı gradyan tam uzunluk + ikon kaydırma 6px", () => {
    expect(readSrc("src/styles/tokens/colors.css")).toContain(
      "linear-gradient(180deg, #790000 0%, #700000 30%, #580000 52%, #420205 72%, #32050a 82%, #20080f 90%, #120b14 96%, var(--modal-bg) 100%)"
    );
    expect(readSrc("src/styles/components/modal.css")).toMatch(/--modal-header-icon-shift-y:\s*6px/);
  });

  it("footer görünür sürüm v78.2", () => {
    expect(readSrc("src/config/app-env.ts")).toMatch(/MEDISA_UI_VERSION = "v78\.2"/);
  });

  it("geri satırı tek sahip: modal.css .modal-body > .universal-back-bar", () => {
    expect(readSrc("src/styles/components/modal.css")).toMatch(/\.modal-body > \.universal-back-bar/);
  });
});

describe("F2) Personel Kartı > Genel kayıt aynası dengeli (satır-öncelikli) 2 kolon", () => {
  it("alanlar tek grid'in doğrudan çocukları; sol/sağ sayısı farkı ≤ 1; ilk satır TC | İşe Giriş", async () => {
    const { PersonelDosyaKayitMirrorFields } = await import(
      "../../src/features/personeller/components/personel-dosya/PersonelDosyaKayitMirrorFields"
    );
    const personel = { id: 1, ad: "Ali", soyad: "Veli", aktif_durum: "AKTIF", calisan_kapsami: "DIS_KAYNAK" } as never;
    const { container } = render(<PersonelDosyaKayitMirrorFields personel={personel} />);
    const grid = container.querySelector(".personel-form-columns");
    expect(grid).not.toBeNull();
    expect(container.querySelector(".personel-form-column")).toBeNull();
    const cells = Array.from(grid!.children).filter((el) => !el.classList.contains("personel-dosya-info-notice"));
    const left = cells.filter((_, i) => i % 2 === 0).length;
    const right = cells.filter((_, i) => i % 2 === 1).length;
    expect(Math.abs(left - right)).toBeLessThanOrEqual(1);
    expect(cells[0].textContent).toContain("T.C. Kimlik No.");
    expect(cells[1].textContent).toContain("İşe Giriş Tarihi");
    const css = readFileSync(resolve("src/styles/modules/personeller.css"), "utf8");
    expect(css).toMatch(/\.personel-dosya-kayit-grid\s*\{[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\)/s);
  });
});

describe("K) Personel Listesi satırlarında kapsam rozeti yok (t862)", () => {
  it("liste satırı Harici Personel rozeti render etmez; filtre korunur", () => {
    const page = readFileSync(resolve("src/features/personeller/pages/PersonellerPage.tsx"), "utf8");
    const tbody = page.slice(page.indexOf("<tbody>"), page.indexOf("</tbody>"));
    expect(tbody).not.toMatch(/status-badge|formatCalisanKapsamiLabel|Harici Personel|DIS_KAYNAK/);
    expect(page).toContain("CALISAN_KAPSAMI_SELECT_OPTIONS");
  });
});

describe("L) Mobil ayarlar (çark) menüsü içerik kadar geniş (t863)", () => {
  it("≤720px'te settings-menu-dropdown max-content + dar üst sınır, sağa hizalı", () => {
    const css = readFileSync(resolve("src/styles/components/dropdown.css"), "utf8");
    const block = css.slice(css.lastIndexOf("@media (max-width: 720px)"));
    expect(block).toMatch(/\.settings-dropdown\.settings-menu-dropdown\s*\{[^}]*width:\s*max-content/s);
    expect(block).toMatch(/max-width:\s*min\(260px/);
    expect(block).toMatch(/right:\s*0/);
  });
});

describe("M) Masaüstü ayarlar menüsü yazıları ortalı, dokunmatik mobil sağa hizalı (t864)", () => {
  it("fine pointer'da center, ≤640 tabanı right kalır", () => {
    const css = readFileSync(resolve("src/styles/components/dropdown.css"), "utf8");
    const fine = css.slice(css.lastIndexOf("@media (hover: hover) and (pointer: fine)"));
    expect(fine).toMatch(/\.settings-dropdown\.settings-menu-dropdown button\s*\{[^}]*text-align:\s*center/s);
    const mobile = css.slice(css.indexOf("@media (max-width: 640px)"));
    expect(mobile).toMatch(/\.settings-dropdown button\s*\{[^}]*text-align:\s*right/s);
    expect(css.lastIndexOf("pointer: fine")).toBeGreaterThan(css.indexOf("@media (max-width: 640px)"));
  });
});
