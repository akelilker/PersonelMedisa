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
import { OZEL_MESAJ_KURALLARI } from "../../src/lib/display/ozel-mesajlar";
import { toTitleCaseTr, userErrorText } from "../../src/lib/display/user-error";
import { fieldLabelsPhp, ozelMesajlarPhp } from "../../scripts/gen-ozel-mesajlar-php";

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

const userVisibleTexts = (line: string): string[] => {
  const texts = [
    ...Array.from(line.matchAll(/>([^<>{}]+)</g), (m) => m[1]),
    ...Array.from(line.matchAll(/"([^"]* [^"]*)"/g), (m) => m[1]),
    ...Array.from(line.matchAll(/`([^`]* [^`]*)`/g), (m) => m[1])
  ];
  if (/^\s*[A-ZÇĞİÖŞÜa-zçğıöşü][^=;(){}<>]*$/.test(line) && !/^\s*\w+[,:]?$/.test(line)) texts.push(line);
  return texts;
};
const scanTsxVisibleText = (banned: RegExp) =>
  listSources(resolve("src"), /\.tsx$/)
    .filter((file) => !/\.test\./.test(file))
    .flatMap((file) =>
      readFileSync(file, "utf8")
        .split("\n")
        .map((line, i) => ({ file, i: i + 1, line }))
        .filter(({ line }) => !/^\s*(\/\/|\*|\/\*|import)/.test(line) && !/[Ee]rror|catch/.test(line))
        .filter(({ line }) =>
          userVisibleTexts(line).some((t) => banned.test(t) && !/(amadı|emedi)\.?\s*$/.test(t.trim()))
        )
        .map(({ file, i }) => `${file}:${i}`)
    );

describe("N) Kullanıcıya teknik terim gösterilmez (t865 a)", () => {
  it("Immutable / Authoritative / Blocker / Preflight / Hash / Snapshot / Manifest / Projection / read-only yok", () => {
    expect(
      scanTsxVisibleText(
        /\b(Immutable|immutable|Authoritative|AUTHORITATIVE|Blocker|BLOCKER|Preflight|PREFLIGHT|Hash|HASH|Snapshot|read-only|Manifest|Projection)\b/
      )
    ).toEqual([]);
  });
});

describe("O) Kullanıcıya meta not gösterilmez (t865 c)", () => {
  it("'salt okunur' / 'otomatik silme' / 'kendiliğinden silmez' yok", () => {
    expect(scanTsxVisibleText(/salt okunur|otomatik silme|kendiliğinden silmez/i)).toEqual([]);
  });
});

describe("P) Görünen tarihler gg/aa/yyyy (t865 b)", () => {
  it("ortak biçimleyici ISO tarih/dönem/metin içi tarihleri çevirir", async () => {
    const mod = await import("../../src/lib/display/iso-date-format");
    expect(mod.formatDisplayDateOrText("2026-04-10")).toBe("10/04/2026");
    expect(mod.formatDisplayDateOrText("2026-04")).toBe("04/2026");
    expect(mod.formatDisplayDateOrText(null)).toBe("-");
    expect(mod.formatDisplayDateOrText("Serbest")).toBe("Serbest");
    expect(mod.formatIsoDatesInText("2024-01-01 — Puantaj")).toBe("01/01/2024 — Puantaj");
  });
  it("bilinen ekranlarda ham ISO tarih basılmaz", () => {
    const checks: [string, RegExp][] = [
      ["src/features/surecler/pages/SurecTakipPage.tsx", /Tarih: \{surec\.baslangic_tarihi/],
      ["src/features/finans/pages/FinansPage.tsx", /Dönem: \{item\.donem\}/],
      ["src/features/bildirimler/pages/BildirimlerPage.tsx", /Tarih: \{bildirim\.tarih/],
      ["src/features/revizyon/pages/HaftalikKapanisPage.tsx", /<td>\{kaynak\.etkilenen_tarih\}<\/td>/],
      ["src/features/personeller/components/personel-dosya/personel-timeline-utils.ts", /`(Geçerlilik|Teslim|İade|Tarih): \$\{(eff|bas|bit|teslimTarihi|iadeTarihi)\}`/]
    ];
    for (const [file, raw] of checks) expect(readFileSync(resolve(file), "utf8")).not.toMatch(raw);
  });
});

describe("Q) Hata metinleri tek tablodan ve Her Kelime Büyük Harfle (t865 HATA-KARAR)", () => {
  it("tablo metinleri Title Case (Türkçe), teknik terim/API yolu yok", async () => {
    const mod = await import("../../src/lib/display/user-error");
    const rows = Object.values(mod.USER_ERROR_TABLOSU);
    expect(rows.length).toBe(30);
    for (const row of rows) {
      for (const text of [row.mesaj, row.aciklama].filter(Boolean) as string[]) {
        for (const word of text.split(/\s+/)) {
          const first = word.replace(/^[^A-Za-zÇĞİÖŞÜçğıöşü]+/, "").charAt(0);
          if (first) expect(first, `${text} → ${word}`).toBe(first.toLocaleUpperCase("tr-TR"));
        }
        expect(text).not.toMatch(/\/api\/|snapshot|preflight|hash|canonical|[A-Z]{3,}_[A-Z]/i);
      }
    }
    expect(mod.toTitleCaseTr("işlem ılık ve SGK T.C. no")).toBe("İşlem Ilık Ve SGK T.C. No");
  });

  it("ham sunucu/teknik metin daima tablo metnine çevrilir", async () => {
    const mod = await import("../../src/lib/display/user-error");
    for (const raw of ["GET /api/legal-holdlar", "Snapshot bulunamadi.", "SCHEMA_NOT_READY: x", "Gerekce zorunludur.", "Talep onaylanamadi (yaris).", "", "anlamsız"]) {
      const text = mod.userErrorText(raw);
      expect(mod.isUserErrorTableText(text) || mod.isFieldErrorText(text)).toBe(true);
    }
    expect(mod.userErrorText("x", "FORBIDDEN", 403)).toContain("Yetkiniz Yok");
    expect(mod.userErrorText("x", undefined, 401)).toContain("Oturumunuz");
  });

  it("ön yüzde hata setter'ları, ApiRequestError ve ErrorState tablodan geçer", () => {
    const offenders = listSources(resolve("src"), /\.(ts|tsx)$/)
      .filter((file) => !/\.test\.|mock|user-error\.ts$/.test(file))
      .flatMap((file) =>
        readFileSync(file, "utf8")
          .split("\n")
          .map((line, i) => ({ line, at: `${file}:${i + 1}` }))
          .filter(({ line }) => /\bset\w*(Error|Hata|Validation)\w*\(\s*["`]/.test(line))
          .map(({ at }) => at)
      );
    expect(offenders).toEqual([]);
    expect(readSrc("src/api/api-client.ts")).toMatch(/super\(userErrorText\(message/);
    expect(readSrc("src/api/api-client.ts")).toMatch(/message: userErrorText\(detail\.message/);
    expect(readSrc("src/components/states/ErrorState.tsx")).toMatch(/userErrorText\(message\)/);
    expect(readSrc("api/src/Http/JsonResponse.php")).toMatch(/'user_message' => UserErrorMessages::forError/);
  });
});

describe("R) Doğrulama hatası alanı adıyla yazılır ve alan kırmızı işaretlenir (t869)", () => {
  it("alan adlı Title Case metin (alan bilgisi veya ham metinden)", async () => {
    const { userErrorText, toTitleCaseTr } = await import("../../src/lib/display/user-error");
    const { FIELD_LABELS } = await import("../../src/lib/display/field-labels");
    expect(userErrorText("tc_kimlik_no zorunludur.", "VALIDATION_ERROR", 422, "tc_kimlik_no")).toBe(
      "T.C. Kimlik No. Boş Bırakılamaz."
    );
    expect(userErrorText("Gecersiz deger.", "VALIDATION_ERROR", 422, "sicil_no")).toBe(
      "Sicil No. Geçerli Değil. Lütfen Kontrol Edin."
    );
    expect(userErrorText("Gerekce zorunludur.")).toBe("Gerekçe Boş Bırakılamaz.");
    expect(userErrorText("Tarih zorunludur.")).toBe("Tarih Boş Bırakılamaz.");
    // Zaten alan adlı metin ikinci kez değişmez.
    expect(userErrorText("T.C. Kimlik No. Boş Bırakılamaz.")).toBe("T.C. Kimlik No. Boş Bırakılamaz.");
    for (const label of Object.values(FIELD_LABELS)) {
      expect(toTitleCaseTr(label)).toBe(label);
    }
  });

  it("backend user_message aynı alan adlarını kullanır (PHP eşlemesi ön yüzle aynı)", async () => {
    const { FIELD_LABELS } = await import("../../src/lib/display/field-labels");
    const php = readSrc("api/src/Http/FieldLabels.php");
    for (const [key, label] of Object.entries(FIELD_LABELS)) {
      expect(php).toContain(`'${key}' => '${label}',`);
    }
    expect(readSrc("api/src/Http/JsonResponse.php")).toMatch(/UserErrorMessages::forError\(\(int\) \$status, \(string\) \$code, \$field/);
    const msgs = readSrc("api/src/Http/UserErrorMessages.php");
    expect(msgs).toContain("FieldLabels::labelOf($field)");
    expect(msgs).toContain("' Boş Bırakılamaz.'");
    expect(msgs).toContain("' Geçerli Değil. Lütfen Kontrol Edin.'");
    expect(readSrc("src/api/api-client.ts")).toMatch(/userErrorText\(message, detail\?\.code, status, detail\?\.field\)/);
  });

  it("ortak mekanizma: alan kırmızı çerçeve + odak, düzenleyince temizlenir", async () => {
    const { highlightFieldErrors, FIELD_ERROR_CLASS } = await import("../../src/lib/forms/field-error-highlight");
    const host = document.createElement("div");
    host.innerHTML =
      '<div class="form-section"><label for="edit-tc-kimlik-no">T.C. Kimlik No.</label><input id="edit-tc-kimlik-no" name="edit-tc-kimlik-no" /></div>';
    document.body.appendChild(host);
    const input = host.querySelector("input")!;
    input.getClientRects = () => [{} as DOMRect] as unknown as DOMRectList;
    expect(await highlightFieldErrors(["tc_kimlik_no"], { timeoutMs: 200 })).toBe(true);
    expect(host.querySelector(".form-section")!.classList.contains(FIELD_ERROR_CLASS)).toBe(true);
    expect(input.getAttribute("aria-invalid")).toBe("true");
    expect(document.activeElement).toBe(input);
    fireEvent.input(input, { target: { value: "1" } });
    expect(host.querySelector(".form-section")!.classList.contains(FIELD_ERROR_CLASS)).toBe(false);
    expect(input.hasAttribute("aria-invalid")).toBe(false);
    host.remove();
    expect(readSrc("src/styles/components/form.css")).toMatch(/\.field-has-error[\s\S]*border-color: #d93025/);
  });
});

describe("S) Personel Kartı eksik bilgi: alan adları, doğru sekme, alan odağı (t869)", () => {
  it("organizasyon alanları Görev / Organizasyon sekmesine, kimlik alanları Genel'e gider (TS + PHP)", async () => {
    const { PERSONEL_MISSING_FIELD_EDIT_TARGET } = await import("../../src/features/personeller/personel-missing-info");
    const php = readSrc("api/src/Services/Personel/PersonelCompletenessService.php");
    for (const [key, target] of Object.entries(PERSONEL_MISSING_FIELD_EDIT_TARGET)) {
      expect(php).toMatch(new RegExp(`'key' => '${key}',[\\s\\S]*?'edit_target' => '${target}'`));
    }
    expect(PERSONEL_MISSING_FIELD_EDIT_TARGET.bolum_id).toBe("pozisyon");
    expect(PERSONEL_MISSING_FIELD_EDIT_TARGET.tc_kimlik_no).toBe("genel");
    // Genel düzenleme formu org alanlarını içermez → org hedefi Genel olamaz.
    const genelForm = readSrc("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx");
    expect(genelForm).not.toMatch(/name="edit-(bolum|birim|departman|sube)/);
  });

  it("uyarı eksik alanları adıyla listeler; tıklayınca sekme + alan anahtarı iletilir", async () => {
    const { PersonelDosyaMissingInfoGateway } = await import(
      "../../src/features/personeller/components/personel-dosya/PersonelDosyaMissingInfoGateway"
    );
    const onOpen = vi.fn();
    const personel = {
      id: 1, ad: "Ayşe", soyad: "Yılmaz", calisan_kapsami: "IC_PERSONEL",
      completeness: {
        is_complete: false, missing_count: 2, critical_missing_labels: ["Bölüm", "Sicil No."],
        missing_fields: [
          { key: "bolum_id", label: "Bölüm", category: "ISTIHDAM", severity: "CRITICAL", edit_target: "genel" },
          { key: "sicil_no", label: "Sicil No.", category: "ISTIHDAM", severity: "CRITICAL", edit_target: "genel" }
        ]
      }
    } as never;
    const view = render(<PersonelDosyaMissingInfoGateway personel={personel} onOpenMissingInfo={onOpen} />);
    expect(view.getByTestId("personel-eksik-bilgi-ozeti").textContent).toContain("2 Eksik Bilgi:");
    fireEvent.click(view.getByTestId("personel-eksik-bilgi-alan-bolum_id"));
    expect(onOpen).toHaveBeenLastCalledWith("pozisyon", "bolum_id");
    fireEvent.click(view.getByTestId("personel-eksik-bilgi-alan-sicil_no"));
    expect(onOpen).toHaveBeenLastCalledWith("genel", "sicil_no");
  });

  it("alan anahtarı Kayıt modalına taşınır ve ortak işaretleme ile odaklanır", async () => {
    const { resolveKayitModalRouteConfig } = await import("../../src/features/kayit/kayit-modal-contract");
    expect(
      resolveKayitModalRouteConfig({ kayitModal: { tab: "surec", targetTab: "pozisyon", focusField: "bolum_id" } })?.focusField
    ).toBe("bolum_id");
    expect(readSrc("src/features/personeller/hooks/usePersonelKartGatewayReturn.ts")).toContain("focusField");
    expect(readSrc("src/features/kayit/components/KayitSurecWorkspace.tsx")).toMatch(/highlightFieldErrors\(\[initialFocusField\]/);
  });
});

describe("T) Duruma özel hata mesajları (t871, OZEL-MESAJLAR-KISA 39 satır) + alan adı kapsamı", () => {
  const ozelOrnekler = JSON.parse(readSrc("tests/unit/fixtures/ozel-mesajlar-ornek.json")) as {
    raw: string;
    code: string | null;
    status: number | null;
    beklenen: string;
  }[];
  const alanHatalari = JSON.parse(readSrc("tests/unit/fixtures/alan-hatalari-196.json")) as string[];
  const GENEL = /^(İşlem Tamamlanamadı|Bilgiler Yüklenemedi|Bu Alan )/;
  // Bilinçli olarak genel kalan iç/teknik hatalar (onaylı: "iç hatalar genel kalır").
  const IC_HATALAR = new Set([
    "Savunma belgesi BELGE surec turu olmalidir.",
    "Gecersiz istek.",
    "Invalid reference table.",
    "Invalid hierarchical reference table.",
    "Multi-axis plan geçersiz.",
    "En fazla ",
    "Lifecycle apply icin rows dizisi zorunludur.",
    "Gecersiz olay karar payload.",
    "VALIDATION_ERROR: BEKLIYOR manager decision olarak yazilamaz.",
    "VALIDATION_ERROR: ilgili olay icin canonical raw dakika yok.",
    "VALIDATION_ERROR: raw_dakika canonical degerle eslesmiyor.",
    "VALIDATION_ERROR: ERKEN_CIKIS icin TOLERANS_UYGULA gecersiz.",
    "VALIDATION_ERROR: TOLERANS_UYGULA yalniz raw <= ",
    "Kayit olusturulamadi.",
    "ZipArchive extension required for XLSX export.",
    "AUTH_SMOKE_READONLY kullanici adi pm_smoke_ro_ ile baslamalidir."
  ]);
  const YANIT_BICIMI = /beklenen formatta|zorunlu alanlar[ıi] i[cç]ermiyor/i;

  it("39 satırın tüm metinleri Türkçe Title Case", () => {
    const satirlar = new Set(OZEL_MESAJ_KURALLARI.map((k) => k.satir));
    for (let i = 1; i <= 39; i += 1) expect(satirlar.has(i), `satır ${i}`).toBe(true);
    for (const k of OZEL_MESAJ_KURALLARI) expect(toTitleCaseTr(k.metin), k.metin).toBe(k.metin);
  });

  it("her satırın gerçek ham mesajı kendi metnine eşlenir, genel metne düşmez", () => {
    const kapsanan = new Set<number>();
    for (const o of ozelOrnekler) {
      const t = userErrorText(o.raw, o.code, o.status);
      expect(t, o.raw).toBe(o.beklenen);
      expect(t).not.toMatch(GENEL);
      for (const k of OZEL_MESAJ_KURALLARI) if (k.metin === t) kapsanan.add(k.satir);
    }
    expect(kapsanan.size).toBeGreaterThanOrEqual(30);
    // [Kayıt] ekran varlık adıyla değişir
    expect(userErrorText("Sube kaydedilemedi.", null, 500)).toBe("Şube Kaydedilemedi. Bilgileri Kontrol Edip Tekrar Deneyin.");
    expect(userErrorText("Departman silinemedi.", null, 500)).toBe(
      "Departman Silinemedi. Bağlı Kayıt Olup Olmadığını Kontrol Edip Tekrar Deneyin."
    );
    expect(userErrorText("T.C. Kimlik No 11 haneli olmalidir.", "VALIDATION_ERROR", 422)).toBe(
      "T.C. Kimlik No. 11 Haneli Ve Yalnızca Rakam Olmalıdır."
    );
  });

  it("196 alan hatası alan adıyla gösterilir (iç hatalar ve yanıt biçimi hariç)", () => {
    for (const raw of alanHatalari) {
      const t = userErrorText(raw, null, 422);
      if (IC_HATALAR.has(raw) || YANIT_BICIMI.test(raw)) {
        expect(t, raw).toMatch(GENEL);
        continue;
      }
      expect(t, raw).not.toMatch(GENEL);
      expect(toTitleCaseTr(t), raw).toBe(t);
    }
  });

  it("backend aynaları (OzelMesajlar.php, FieldLabels.php) ön yüz kaynağıyla birebir", () => {
    expect(readSrc("api/src/Http/OzelMesajlar.php")).toBe(ozelMesajlarPhp());
    expect(readSrc("api/src/Http/FieldLabels.php")).toBe(fieldLabelsPhp());
    expect(readSrc("api/src/Http/UserErrorMessages.php")).toContain("OzelMesajlar::forMessage($message, $code)");
  });

  it("Görev/Organizasyon sekmesinde teknik 'canonical' metni görünmez", () => {
    expect(readSrc("src/features/kayit/components/KayitSurecPersonelOrganizasyonPanel.tsx")).not.toMatch(
      /canonical|Structured audit/i
    );
  });
});

describe("U) Personel Kartı Puantaj sekmesi, kişi kartında QR yok, Talepler ikon seçimi (t873)", () => {
  const dir = "src/features/personeller/components/personel-dosya/";
  it("Puantaj ayrı sekme; puantaj/bordro blokları orada, Genel'de değil", () => {
    expect(readSrc(dir + "PersonelDosyaTabs.tsx")).toMatch(/\{ id: "puantaj", label: "Puantaj" \}/);
    const puantaj = readSrc(dir + "PersonelKartPanelPuantaj.tsx");
    expect(puantaj).toContain("<PersonelPuantajOzetSection");
    expect(puantaj).toContain("<PersonelBordroKapsamSection");
    const genel = readSrc(dir + "PersonelKartPanelGenelBilgiler.tsx");
    expect(genel).not.toMatch(/PersonelPuantajOzetSection|PersonelBordroKapsamSection/);
    expect(readSrc(dir + "PersonelDosyaTabPanels.tsx")).toMatch(/id="personel-kart-panel-puantaj"/);
  });

  it("kişi kartında QR giriş/çıkış hareketi gösterilmez (veri/backend korunur)", () => {
    const files = readdirSync(dir).filter((f) => f.endsWith(".tsx"));
    for (const f of files) expect(readSrc(dir + f), f).not.toMatch(/QrHistory|qr-hareket|fetchPersonelQr/i);
    expect(readSrc("src/api/qr.api.ts")).toContain("fetchMeQrHareketleri");
  });

  it("Talepler: tür seçimi ikon + etiket, çerçevesiz; seçilince yalnız o form", () => {
    const page = readSrc("src/features/self-service/pages/PersonelSelfServiceTaleplerPage.tsx");
    for (const label of ["Giriş/Çıkış Düzeltme", "İzin", "Sağlık Raporu"]) expect(page).toContain(`label: "${label}"`);
    for (const id of ["duzeltme", "izin", "rapor", "avans", "oneri"]) expect(page).toContain(`{secili === "${id}" ? (`);
    const css = readSrc("src/features/self-service/self-service.css");
    const blok = /\.pm-talep-turu \{([^}]*)\}/.exec(css)?.[1] ?? "";
    expect(blok).toMatch(/border:\s*0/);
    expect(blok).toMatch(/background:\s*none/);
    expect(css).toMatch(/\.pm-talep-turleri \{[^}]*justify-items:\s*center/);
    expect(css).toMatch(/\.pm-talep-turleri \{[^}]*repeat\(2, minmax\(0, 1fr\)\)/);
    expect(css).toMatch(/@media \(min-width: 720px\)[^@]*\.pm-talep-turleri \{[^}]*auto-fit/);
  });
});

describe("V) Boş form: tarayıcı balonu yok, alan adlı Title Case mesaj + kırmızı çerçeve + odak (t879)", () => {
  it("main.tsx guard'ı kurar; mesaj CSS ::after ile gösterilir", () => {
    expect(readSrc("src/main.tsx")).toContain("installFormValidationGuard();");
    expect(readSrc("src/styles/components/form.css")).toMatch(
      /\.field-has-error\[data-field-error-text\]::after \{[^}]*content: attr\(data-field-error-text\)/
    );
  });

  it("boş zorunlu alan: noValidate, gönderim engellenir, 'T.C. Kimlik No. Boş Bırakılamaz.', odak ilk alanda, düzenleyince kalkar", async () => {
    const { installFormValidationGuard, __resetFormValidationGuardForTests } = await import(
      "../../src/lib/forms/empty-form-guard"
    );
    __resetFormValidationGuardForTests();
    installFormValidationGuard();
    const onSubmit = vi.fn((e: Event) => e.preventDefault());
    document.body.innerHTML = `
      <form id="f">
        <div class="form-section"><label for="tc">T.C. Kimlik No.</label><input id="tc" name="edit-tc-kimlik-no" required /></div>
        <div class="form-section"><label for="x">Konu *</label><input id="x" name="konu_serbest" required /></div>
        <button type="submit">Kaydet</button>
      </form>`;
    await new Promise((r) => setTimeout(r, 0));
    const form = document.getElementById("f") as HTMLFormElement;
    expect(form.noValidate).toBe(true);
    form.addEventListener("submit", onSubmit);
    form.dispatchEvent(new Event("submit", { bubbles: true, cancelable: true }));
    expect(onSubmit).not.toHaveBeenCalled();
    const tc = document.getElementById("tc") as HTMLInputElement;
    const w1 = tc.closest(".form-section")!;
    expect(w1.classList.contains("field-has-error")).toBe(true);
    expect(w1.getAttribute("data-field-error-text")).toBe("T.C. Kimlik No. Boş Bırakılamaz.");
    expect(document.getElementById("x")!.closest(".form-section")!.getAttribute("data-field-error-text")).toBe(
      "Konu Boş Bırakılamaz."
    );
    expect(tc.getAttribute("aria-invalid")).toBe("true");
    expect(document.activeElement).toBe(tc);
    tc.value = "1";
    tc.dispatchEvent(new Event("input", { bubbles: true }));
    expect(w1.classList.contains("field-has-error")).toBe(false);
    expect(w1.hasAttribute("data-field-error-text")).toBe(false);
    document.body.innerHTML = "";
  });
});

describe("W) Genel düzenleme: Doğum Yeri, Kan Grubu, Acil Durum Kişisi/Telefonu (t885)", () => {
  it("formda dört alan var; Kan Grubu seçimli ve backend değerleriyle aynı", async () => {
    const form = readSrc("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx");
    for (const n of ["edit-dogum-yeri", "edit-kan-grubu", "edit-acil-durum-kisi", "edit-acil-durum-telefon"]) {
      expect(form).toContain(`name="${n}"`);
    }
    const { KAN_GRUBU_SELECT_OPTIONS } = await import("../../src/features/personeller/personel-edit-utils");
    const php = readSrc("api/src/Services/Personel/PersonelCanonicalValidator.php");
    for (const o of KAN_GRUBU_SELECT_OPTIONS) expect(php).toContain(`'${o.value}'`);
    expect(KAN_GRUBU_SELECT_OPTIONS[0]!.value).toBe("0 Rh+");
    expect(KAN_GRUBU_SELECT_OPTIONS[KAN_GRUBU_SELECT_OPTIONS.length - 1]!.value).toBe("AB Rh-");
    const allow = readSrc("api/src/Services/Personel/PersonelBasicUpdateService.php");
    for (const c of ["dogum_yeri", "kan_grubu", "acil_durum_kisi", "acil_durum_telefon"]) expect(allow).toContain(`'${c}'`);
  });

  it("yalnız değişen alanlar gönderilir; boşaltma null; acil telefon 05XX kuralı", async () => {
    const { buildPersonelUpdatePayload, personelToEditForm } = await import(
      "../../src/features/personeller/personel-edit-utils"
    );
    const personel = {
      id: 1,
      ad: "Ayşe",
      soyad: "Yılmaz",
      calisan_kapsami: "IC_PERSONEL",
      dogum_yeri: "İzmir",
      kan_grubu: "A Rh+",
      acil_durum_kisi: "Ali",
      acil_durum_telefon: "0555 111 22 33"
    } as unknown as Parameters<typeof personelToEditForm>[0];
    const form = personelToEditForm(personel);
    const same = buildPersonelUpdatePayload(form, false, { currentPersonel: personel });
    for (const k of ["dogum_yeri", "kan_grubu", "acil_durum_kisi", "acil_durum_telefon"]) expect(same).not.toHaveProperty(k);
    const changed = buildPersonelUpdatePayload(
      { ...form, dogumYeri: "Ankara", kanGrubu: "0 Rh-", acilDurumKisi: "", acilDurumTelefon: "5321234567" },
      false,
      { currentPersonel: personel }
    );
    expect(changed).toMatchObject({
      dogum_yeri: "Ankara",
      kan_grubu: "0 Rh-",
      acil_durum_kisi: null,
      acil_durum_telefon: "0532 123 45 67"
    });
    expect(() =>
      buildPersonelUpdatePayload({ ...form, acilDurumTelefon: "123" }, false, { currentPersonel: personel })
    ).toThrow(/Acil Durum Telefonu 05xx/);
    expect(userErrorText("Acil Durum Telefonu 05xx xxx xx xx formatında olmalıdır.", "VALIDATION_ERROR", 422)).toBe(
      "Acil Durum Telefonu 05XX XXX XX XX Biçiminde Olmalıdır."
    );
  });

  it("Genel panel istemci doğrulama hatasını yakalar (gönderim kilitlenmez); alan işaretleme takma adları", () => {
    const panel = readSrc("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx");
    expect(panel).toMatch(/try \{\s*body = buildPersonelUpdatePayload/);
    const hl = readSrc("src/lib/forms/field-error-highlight.ts");
    for (const n of ["edit-dogum-yeri", "edit-kan-grubu", "edit-acil-durum-kisi", "edit-acil-durum-telefon"]) {
      expect(hl).toContain(`"${n}"`);
    }
  });
});

describe("X) Personel Kartı ve Belge Takip (t876)", () => {
  it("(a) personel_id görünümünde filtre yok, geri satırı Personel Kartı", () => {
    const page = readSrc("src/features/personeller/pages/BelgeTakipPage.tsx");
    expect(page).toMatch(/scopedPersonelId == null \? \([\s\S]*belge-takip-filter-panel/);
    expect(page).toContain('data-testid="belge-takip-personel-adi"');
    const shell = readSrc("src/app/AppShell.tsx");
    expect(shell).toMatch(/\/personeller\/belge-takip/);
    expect(shell).toContain('label: "Personel Kartı"');
    expect(shell).toContain("?tab=egitim-belgeler");
    const link = readSrc("src/features/personeller/components/personel-dosya/PersonelBelgelerPanel.tsx");
    expect(link).toContain("/personeller/belge-takip?personel_id=");
  });

  it("(b) tek işlemde menü yok; birden fazla işlemde İşlemler menüsü kalır", () => {
    const row = readSrc("src/features/personeller/components/personel-dosya/PersonelDosyaActionRow.tsx");
    expect(row).toMatch(/items\.length === 1/);
    expect(row).toContain('label: "Süreçte İşlem Yap"');
    expect(row).toMatch(/aria-haspopup="menu"/);
    const singleStart = row.indexOf("if (items.length === 1)");
    const menuStart = row.indexOf('aria-haspopup="menu"');
    expect(singleStart).toBeGreaterThan(-1);
    expect(menuStart).toBeGreaterThan(singleStart);
    expect(row.slice(singleStart, menuStart)).not.toMatch(/aria-haspopup/);
  });

  it("(c) eksik bilgi uyarısı tek satır sınıfı", () => {
    const gateway = readSrc("src/features/personeller/components/personel-dosya/PersonelDosyaMissingInfoGateway.tsx");
    expect(gateway).toContain("personel-dosya-missing-gateway--tek-satir");
    expect(gateway).toContain("personel-eksik-bilgi-alan-");
    const css = readSrc("src/styles/modules/personel-missing-info.css");
    expect(css).toMatch(/\.personel-dosya-missing-gateway--tek-satir[\s\S]*white-space:\s*nowrap/);
    expect(css).toMatch(/\.personel-dosya-missing-gateway--tek-satir[\s\S]*max-height:\s*28px/);
    expect(css).not.toMatch(/!important/);
  });

  it("(d) masaüstü hero foto bileşeni; mobil akışta gizli", () => {
    const hero = readSrc("src/features/personeller/components/personel-dosya/PersonelDosyaHero.tsx");
    expect(hero).toContain("<PersonelDosyaHeroPhoto");
    const photo = readSrc("src/features/personeller/components/personel-dosya/PersonelDosyaHeroPhoto.tsx");
    expect(photo).toContain("<PersonelSelfPortrait");
    const css = readSrc("src/styles/modules/personeller.css");
    expect(css).toMatch(/\.personel-dosya-hero-photo\s*\{[^}]*display:\s*none/);
    expect(css).toMatch(/@media \(min-width: 721px\)[\s\S]*\.personel-dosya-hero-photo\s*\{[^}]*display:\s*flex/);
  });
});

describe("Y) Kullanıcı Paneli masaüstü düzenleri", () => {
  it("ev simgesi hover/focus/active durumunda kaymaz ve çerçevesiz kalır", () => {
    const css = readSrc("src/styles/components/modal.css");
    const lane = css.slice(css.indexOf(".modal-header-leading .modal-home-btn,"));
    expect(lane).toMatch(
      /\.modal-header-leading \.modal-home-btn:hover,\s*\.modal-header-leading \.modal-home-btn:focus-visible,\s*\.modal-header-leading \.modal-home-btn:active\s*\{[^}]*transform:\s*translateY\(var\(--modal-header-icon-shift-y\)\)/
    );
    expect(css).toMatch(/\.modal-home-btn\s*\{[^}]*border:\s*none/);
    expect(css).toMatch(/\.modal-home-btn\s*\{[^}]*background:\s*transparent/);
    expect(css).not.toMatch(/!important/);
  });

  it("alt ekranlarda geri satırı Kullanıcı Paneli; QR okutma hariç", () => {
    const shell = readSrc("src/app/AppShell.tsx");
    expect(shell).toMatch(/pathname\.startsWith\("\/self\/"\) && pathname !== "\/self\/qr-okut"/);
    expect(shell).toContain('label: "Kullanıcı Paneli"');
  });

  it("masaüstünde alt simgeler etiketli, GİRİŞ/ÇIKIŞ ortalı ve fotoğraf 88px", () => {
    const css = readSrc("src/features/self-service/self-service.css");
    expect(css).toMatch(/@media \(min-width: 641px\)[\s\S]*\.pm-self-dock__label\s*\{[^}]*opacity:\s*1/);
    const desktop = css.slice(css.indexOf("@media (min-width: 721px)"));
    expect(desktop).toMatch(/\.self-home-main\s*\{[^}]*align-items:\s*center/);
    expect(desktop).toMatch(/\.self-home-main > \.pm-section\s*\{[^}]*width:\s*min\(100%, 400px\)/);
    expect(desktop).toMatch(/\.self-home-page \.pm-box-main-action\s*\{[^}]*min-height:\s*58px/);
    expect(desktop).toMatch(/\.pm-self-identity--home \.pm-self-portrait\s*\{[^}]*width:\s*88px/);
    expect(desktop).toMatch(/\.pm-self-identity--home \.pm-self-portrait\s*\{[^}]*height:\s*88px/);
    expect(css).not.toMatch(/!important/);
  });

  it("masaüstü header 4px kısa, logo dikey ortada, başlık kuralları ayrı", () => {
    const css = readSrc("src/styles/components/hero.css");
    const desktop = css.slice(css.lastIndexOf("@media (min-width: 721px)"));
    expect(desktop).toMatch(/\.hero\.hero--personel-shell\s*\{[^}]*padding-bottom:\s*8px/);
    expect(desktop).toMatch(
      /\.hero\.hero--personel-shell \.hero-logo\s*\{[^}]*position:\s*absolute[^}]*top:\s*50%[^}]*transform:\s*translateY\(-50%\)/
    );
    expect(desktop).not.toMatch(/hero-title-stack|\.hero h1|hero-panel-subtitle/);
    expect(css).not.toMatch(/!important/);
  });
});

describe("Z) Personel Kartı hero fotoğrafı masaüstünde sağda, ~72px; mobilde gizli (t889)", () => {
  it("fotoğraf ad satırının içinde değil, hero'nun son çocuğu; CSS sağ sütun + dikey orta", () => {
    const hero = readSrc("src/features/personeller/components/personel-dosya/PersonelDosyaHero.tsx");
    const identity = hero.slice(hero.indexOf('className="personel-dosya-hero-identity"'), hero.indexOf("personel-dosya-hero-chips"));
    expect(identity).not.toContain("PersonelDosyaHeroPhoto");
    expect(hero.lastIndexOf("<PersonelDosyaHeroPhoto")).toBeGreaterThan(hero.indexOf("personel-dosya-hero-org"));
    const css = readSrc("src/styles/modules/personeller.css");
    const m = /@media \(min-width: 721px\) \{([\s\S]*?)\n\}/.exec(css.slice(css.indexOf("t889")  - 40))?.[1] ?? "";
    expect(m).toMatch(/\.personel-dosya-hero \{[^}]*grid-template-columns: minmax\(0, 1fr\) auto/);
    expect(m).toMatch(/\.personel-dosya-hero-photo \{[^}]*grid-column: 2[^}]*align-self: center[^}]*justify-self: end/);
    expect(m).toMatch(/\.personel-dosya-hero-photo \.pm-self-portrait \{[^}]*width: 72px[^}]*height: 72px/);
    expect(css).toMatch(/\.personel-dosya-hero-photo\s*\{\s*display:\s*none/);
  });
});

describe("AA) Kullanıcı ekranında açıklama/meta/yol notu yok; Cinsiyet Genel düzenlemede (t890)", () => {
  const walk = (dir: string): string[] =>
    readdirSync(resolve(dir), { withFileTypes: true }).flatMap((e) =>
      e.isDirectory() ? walk(`${dir}/${e.name}`) : e.name.endsWith(".tsx") ? [`${dir}/${e.name}`] : []
    );
  const stripComments = (s: string) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  const YASAK = [
    /CRUD/,
    /senkron tutulur/,
    /atomik olarak/,
    /(Süreç|Raporlar|Yönetim) →/,
    /(burada|bu bölümde|buradan) yönetilir/,
    /özlük süreç kaydı/,
    /sunucu modelinden okunur/,
    /ekranından yönet/,
    /sekmesinden yapılır/,
    /üzerinden okunur/,
    /Canonical (takvim|kullanıcı|çalışma)/,
    /Ücret geçmişi ve ücret tipi/,
    /Değişiklik (anında personel kaydına|hemen) uygulanır/
  ];
  it("src/features (self-service hariç) kullanıcıya görünen metinde yasak ifade yok", () => {
    const files = walk("src/features").filter((f) => !f.includes("/self-service/"));
    const hits: string[] = [];
    for (const f of files) {
      const s = stripComments(readFileSync(resolve(f), "utf8"));
      for (const re of YASAK) if (re.test(s)) hits.push(`${f}: ${re}`);
    }
    expect(hits).toEqual([]);
  });
  it("Kayıt ve Süreç sekmelerinde açıklama paragrafları kaldırıldı", () => {
    const puantaj = readFileSync(resolve("src/features/kayit/components/KayitSurecPersonelPuantajPanel.tsx"), "utf8");
    expect(puantaj).not.toContain("kayit-surec-puantaj-owner-hint");
    expect(puantaj).not.toContain("Henüz değerlendirilmedi, geç geldi");
    const form = readFileSync(resolve("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx"), "utf8");
    expect(form).not.toContain("personel-edit-org-yonlendirme");
    expect(form).not.toContain("personel-edit-ucret-yonlendirme");
  });
  it("Cinsiyet: Genel düzenleme formu + değişen alan payload + Kayıt görünümü", () => {
    const form = readFileSync(resolve("src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx"), "utf8");
    expect(form).toContain('name="edit-cinsiyet"');
    const utils = readFileSync(resolve("src/features/personeller/personel-edit-utils.ts"), "utf8");
    expect(utils).toMatch(/CINSIYET_SELECT_OPTIONS[\s\S]*"Erkek"[\s\S]*"Kadın"/);
    expect(utils).toMatch(/changed\(editForm\.cinsiyet, cur\.cinsiyet\)/);
    const genel = readFileSync(resolve("src/features/kayit/components/KayitSurecPersonelGenelPanel.tsx"), "utf8");
    expect(genel).toContain('label: "Cinsiyet"');
  });
});

describe("AB) Kullanıcı Paneli: açıklama/meta notları yok; Giriş / Çıkış Geçmişim kalır (t894)", () => {
  const walk = (dir: string): string[] =>
    readdirSync(resolve(dir), { withFileTypes: true }).flatMap((e) =>
      e.isDirectory() ? walk(`${dir}/${e.name}`) : e.name.endsWith(".tsx") ? [`${dir}/${e.name}`] : []
    );
  const YASAK = [
    /bağladıktan sonra bu ekran açılır/,
    /QR giriş\/çıkış ve kişisel özet bu yüzden/,
    /aktif şubeyi değiştirmez/,
    /Personel QR okutarak giriş\/çıkış kaydı oluşturur/,
    /QR okutma yalnızca uygun personel hesabında/,
    /puantaja işlenir/,
    /Kendi kimlik QR/,
    /QrKioskModelNote|QrPuantajExpectationNote/
  ];
  it("self-service kaynaklarında yasak ifade yok", () => {
    const hits: string[] = [];
    for (const f of walk("src/features/self-service")) {
      const s = readFileSync(resolve(f), "utf8").replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
      for (const re of YASAK) if (re.test(s)) hits.push(`${f}: ${re}`);
    }
    expect(hits).toEqual([]);
  });
  it("Giriş / Çıkış Geçmişim bağlantısı korunur", () => {
    const all = walk("src/features/self-service").map((f) => readFileSync(resolve(f), "utf8")).join("\n");
    expect(all).toContain("Giriş / Çıkış Geçmişim");
  });
});
