import { describe, expect, it } from "vitest";
import { matchesKullaniciSearch } from "../../src/lib/yonetim/kullanici-search";
import { matchesPersonelFirstLoginFilter } from "../../src/lib/yonetim/personel-first-login-status";
import type { YonetimKullanici } from "../../src/types/yonetim";

const sampleFields = {
  cardLabel: "Zeki Yılmaz",
  displayName: "Zeki Yılmaz",
  adSoyad: "Zeki Yılmaz",
  username: "zeki.yilmaz",
  roleLabel: "Birim Amiri",
  subeScopeLabel: "Medisa Fabrika",
  kullaniciTipiLabel: "Dahili Personel"
};

describe("kullanici search", () => {
  it("matches display name case-insensitively", () => {
    expect(matchesKullaniciSearch(sampleFields, "zeki")).toBe(true);
    expect(matchesKullaniciSearch(sampleFields, "YILMAZ")).toBe(true);
  });

  it("folds Turkish letters for query and haystack", () => {
    expect(matchesKullaniciSearch({ cardLabel: "İlker Akel" }, "ilker")).toBe(true);
    expect(matchesKullaniciSearch({ cardLabel: "Şule Öztürk" }, "sule ozturk")).toBe(true);
    expect(matchesKullaniciSearch({ username: "gokhan.celik" }, "gökhan")).toBe(true);
  });

  it("matches username and branch scope labels", () => {
    expect(matchesKullaniciSearch(sampleFields, "zeki.yilmaz")).toBe(true);
    expect(matchesKullaniciSearch(sampleFields, "fabrika")).toBe(true);
    expect(matchesKullaniciSearch(sampleFields, "birim amiri")).toBe(true);
  });

  it("returns true for empty query", () => {
    expect(matchesKullaniciSearch(sampleFields, "")).toBe(true);
    expect(matchesKullaniciSearch(sampleFields, "   ")).toBe(true);
  });

  it("returns false when no field matches", () => {
    expect(matchesKullaniciSearch(sampleFields, "zzzz-no-match")).toBe(false);
  });

  it("composes with first-login status filter", () => {
    const pending: Pick<YonetimKullanici, "personel_id" | "must_change_password"> = {
      personel_id: 1,
      must_change_password: true
    };
    const completed: Pick<YonetimKullanici, "personel_id" | "must_change_password"> = {
      personel_id: 2,
      must_change_password: false
    };

    const users = [
      { id: 1, ...pending, fields: { cardLabel: "Zeki Pending" } },
      { id: 2, ...completed, fields: { cardLabel: "Zeki Done" } }
    ];

    const filtered = users.filter(
      (row) =>
        matchesPersonelFirstLoginFilter(row, "pending") &&
        matchesKullaniciSearch(row.fields, "zeki")
    );

    expect(filtered.map((row) => row.id)).toEqual([1]);
  });
});
