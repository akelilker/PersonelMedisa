// Fast CI critical-test allowlist.
//
// Fast CI (ci.yml → npm run test:ci-fast) must stay fast: it runs only the
// high-value behavior contracts below, not hundreds of source-shape/duplicate
// tests. The full suite (npm run test / ci-full.yml) still runs everything.
//
// Selection rule (per AGENTS.md "körlemesine silme"): keep a test only when it
// guards a real product risk and no other file in this list already covers it.
export const fastCiCriticalTestFiles = [
  // 1) Personel Kaydetme — temel alan değişikliği gerçekten kalıcı mı?
  "tests/unit/personel-cinsiyet-update.php-runtime.test.ts",
  "tests/unit/personel-create-utils.test.ts",
  "tests/unit/dis-kaynak-minimal-create.test.ts",
  "tests/unit/commit-personel-update-cache.test.ts",

  // 2) Yetki / Kapsam — yetkisi olmayan personel görünmüyor / değiştirilemiyor mu?
  "tests/unit/org-hierarchy-authorization.test.ts",
  "tests/unit/role-permissions.test.ts",
  "tests/unit/data-isolation.test.ts",

  // 3) Puantaj / Bordro / Fazla Çalışma / İzin / Rapor — para/çalışan hakkı hesapları.
  "tests/unit/puantaj-hesap-motoru.test.ts",
  "tests/unit/devam-primi-hesap-motoru.test.ts",
  "tests/unit/izin-hesap-motoru.test.ts",
  "tests/unit/serbest-zaman-event-motoru.test.ts",
  "tests/unit/fazla-mesai-durum.test.ts",

  // 4) Giriş / Çıkış — kayıt oluşuyor / doğru personele-güne yansıyor.
  "tests/unit/gunluk-kayit-presets.contract.test.ts",
  "tests/unit/qr-manager-read-contract.test.ts",
  "tests/unit/gunluk-bildirim-actions.test.ts",

  // 5) Kimlik / oturum kontratı (giriş-çıkış köprüsü).
  "tests/unit/auth.api.test.ts",
  "tests/unit/personeller.api.test.ts",

  // 6) Canlı DB mutasyon kapısı — 099 Kalıcı Sil PREFLIGHT/APPLY workflow gate'leri
  //    meşgul/okunamayan worker, korumasız environment ve eşleşmeyen preflight'ta
  //    sunucuya istek dosyası bırakmadan durmalı.
  "tests/unit/kalici-sil-099-workflows.test.ts"
];
