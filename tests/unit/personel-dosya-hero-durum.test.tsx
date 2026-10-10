/** @vitest-environment jsdom */
import { cleanup, render, screen } from "@testing-library/react";
import { readFileSync } from "node:fs";
import { afterEach, describe, expect, it } from "vitest";
import { PersonelDosyaHero } from "../../src/features/personeller/components/personel-dosya/PersonelDosyaHero";
import { formatAktifDurumLabel } from "../../src/lib/display/enum-display";
import type { Personel } from "../../src/types/personel";

/**
 * Personel Kartı hero durum göstergesi (#345 kararı; #392 regresyonu düzeltmesi):
 * - Aktif personel: yalnız yeşil nokta; görünür durum metni/pill yok, erişilebilir ad korunur.
 * - Pasif personel: kırmızı nokta + mevcut pasiflik/ayrılma açıklaması görünür.
 * - Boş/bilinmeyen durum asla aktif (yeşil) sayılmaz.
 */
function personel(overrides: Partial<Personel>): Personel {
  return {
    id: 1,
    ad: "Test",
    soyad: "KİŞİ",
    sicil_no: "S-1",
    aktif_durum: "AKTIF",
    ...overrides
  } as unknown as Personel;
}

afterEach(() => cleanup());

describe("PersonelDosyaHero durum", () => {
  it("aktif: yalnız nokta, görünür metin yok, aria-label korunur", () => {
    render(<PersonelDosyaHero personel={personel({ aktif_durum: "AKTIF" })} />);
    const status = screen.getByTestId("personel-dosya-hero-status");
    const aktifLabel = formatAktifDurumLabel("AKTIF");
    expect(status.getAttribute("aria-label")).toBe(aktifLabel);
    expect(status.getAttribute("title")).toBe(aktifLabel);
    expect(status.className).toBe("personel-dosya-status");
    expect(status.querySelector(".personel-dosya-status-dot")).not.toBeNull();
    expect(status.querySelector(".personel-dosya-status-label")).toBeNull();
    expect(status.textContent).toBe("");
  });

  it("pasif: kırmızı nokta sınıfı + pasiflik açıklaması görünür", () => {
    render(
      <PersonelDosyaHero
        personel={personel({ aktif_durum: "PASIF", pasiflik_durumu_etiketi: "İşten Ayrıldı" })}
      />
    );
    const status = screen.getByTestId("personel-dosya-hero-status");
    expect(status.className).toContain("is-passive");
    expect(status.querySelector(".personel-dosya-status-label")?.textContent).toBe("İşten Ayrıldı");
    expect(status.getAttribute("aria-label")).toBe("İşten Ayrıldı");
  });

  it("pasif, açıklama yoksa durum etiketi görünür", () => {
    render(<PersonelDosyaHero personel={personel({ aktif_durum: "PASIF", pasiflik_durumu_etiketi: null })} />);
    const status = screen.getByTestId("personel-dosya-hero-status");
    expect(status.className).toContain("is-passive");
    expect(status.querySelector(".personel-dosya-status-label")?.textContent).toBe(formatAktifDurumLabel("PASIF"));
  });

  it("bilinmeyen/boş durum aktif sayılmaz", () => {
    render(<PersonelDosyaHero personel={personel({ aktif_durum: null as unknown as Personel["aktif_durum"] })} />);
    const status = screen.getByTestId("personel-dosya-hero-status");
    expect(status.className).toContain("is-unknown");
    expect(status.className).not.toContain("is-passive");
    expect(status.querySelector(".personel-dosya-status-label")?.textContent).toBe("Durum Bilinmiyor");
  });
});

describe("Kayıt ve Süreç personel bağlamı durum (aynı karar)", () => {
  // Özet kartı kaldırıldı (2026-10-10); durum noktası Genel panelindeki Ad SOYAD başlığının sağında.
  const source = readFileSync("src/features/kayit/components/KayitSurecPersonelNameHeading.tsx", "utf8");

  it("yalnız açıkça AKTIF aktif sayılır; aktifte görünür metin yok, aria-label korunur", () => {
    expect(source).toMatch(/const isAktif = personel\.aktif_durum === "AKTIF";/);
    expect(source).toMatch(/isAktif \? null : <span aria-hidden="true">\{durumLabel\}<\/span>/);
    expect(source).toMatch(/aria-label=\{durumLabel\}/);
    expect(source).toMatch(/title=\{durumLabel\}/);
    expect(source).toMatch(/data-testid="kayit-surec-personel-durum"/);
    expect(source).toMatch(/isPasif \? " is-passive" : isAktif \? "" : " is-unknown"/);
  });
});
