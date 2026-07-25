import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const finansCreateCommitSource = readFileSync(
  resolve(process.cwd(), "src/lib/finans/finans-create-commit.ts"),
  "utf8"
);
const personelDetailSource = readFileSync(
  resolve(process.cwd(), "src/hooks/usePersonelDetail.ts"),
  "utf8"
);
const personelZimmetCreateSource = readFileSync(
  resolve(process.cwd(), "src/hooks/usePersonelZimmetCreate.ts"),
  "utf8"
);

const OWNERS = {
  finansCreateCommit: finansCreateCommitSource,
  personelDetail: personelDetailSource,
  personelZimmetCreate: personelZimmetCreateSource
} as const;

describe("S93-E2C remaining visible text hygiene", () => {
  it("eski ASCII Türkçe kullanıcı metinlerini production owner dosyalarında tutmaz", () => {
    const combined = Object.values(OWNERS).join("\n");

    expect(combined).not.toContain("pozitif sayi olmalidir");
    expect(combined).not.toContain("sifirdan buyuk olmali");
    expect(combined).not.toContain("Finans kaydi olusturulamadi");
    expect(combined).not.toContain("Bu islem icin yetkin bulunmuyor");
    expect(combined).not.toContain("Urun turu zorunludur");
    expect(combined).not.toContain("Zimmet kaydi yapilamadi");
  });

  it("finans oluşturma altyapısında düzeltilmiş doğrulama ve fallback metinlerini tutar", () => {
    expect(OWNERS.finansCreateCommit).toContain("pozitif sayı olmalıdır");
    expect(OWNERS.finansCreateCommit).toContain("sıfırdan büyük olmalı");
    expect(OWNERS.finansCreateCommit).toContain("Finans kaydı oluşturulamadı");
  });

  it("personel detayı zimmet modal yetki mesajını doğru Türkçe tutar", () => {
    expect(OWNERS.personelDetail).toContain("Bu işlem için yetkin bulunmuyor");
  });

  it("personel zimmet oluşturma doğrulama, yetki ve fallback metinlerini doğru Türkçe tutar", () => {
    expect(OWNERS.personelZimmetCreate).toContain("Ürün türü zorunludur");
    expect(OWNERS.personelZimmetCreate).toContain("Bu işlem için yetkin bulunmuyor");
    expect(OWNERS.personelZimmetCreate).toContain("Zimmet kaydı yapılamadı");
  });
});
