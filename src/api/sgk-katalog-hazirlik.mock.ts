/** Shared S85-C1 SGK katalog hazırlık mock contract (empty catalog, tamlik blocker). */

export const SGK_KATALOG_TAMLIK_BLOCKER = {
  severity: "BLOCKER" as const,
  code: "SGK_KATALOG_TAMLIK_KANITI_EKSIK",
  message: "Resmi kaynak tamlik kaniti eksik; katalog DOGRULANMIS_TAM yapilamaz ve onaylanamaz.",
  domain: "SGK_KATALOG",
  cozum_onerisi:
    "Mali musavir operasyonel kanit paketi + guncel resmi SGK/mevzuat eklerini tamamlayin; ucuncu taraf listeleri kullanmayin."
};

export function buildSgkKatalogTamlikMock() {
  return {
    tamlik_durumu: "TASLAK",
    katalog_surumu: "",
    manifest_set_hash: "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
    kod_sayisi: 0,
    kaynak_sayisi: 0,
    aktif_manifest_sayisi: 0,
    eksik_kanitlar: [
      "GUNCEL_TAM_KOD_LISTESI",
      "KOD_BAZLI_YURURLUK_TARIHI",
      "BIRLESIK_NEDEN_MATRISI",
      "KOD_BELGE_MATRISI",
      "SIFIR_GUN_SIFIR_KAZANC_KISITLARI",
      "KISMI_SURELI_KULLANIM_KURALLARI",
      "KAYNAK_MANIFESTI",
      "EBILDIRGE_GUNCEL_GORUNUM"
    ],
    erisilemeyen_kaynaklar: ["e-Bildirge/e-Beyanname login-gated dropdown"],
    operasyonel_kanitlar: [],
    blocker_kodlari: [SGK_KATALOG_TAMLIK_BLOCKER.code],
    blocker_detaylari: [SGK_KATALOG_TAMLIK_BLOCKER],
    onaylanabilir_mi: false,
    dogrulanmis_tam_secilebilir_mi: false,
    import_yazma_aktif_mi: false,
    approve_aktif_mi: false,
    response_hash: "demo-sgk-katalog-tamlik-hash"
  };
}

export function buildSgkKatalogImportDryRunMock() {
  const tamlik = buildSgkKatalogTamlikMock();
  return {
    mode: "DRY_RUN",
    format: "JSON",
    gecerli_satirlar: [],
    hatali_satirlar: [],
    warnings: ["BOS_PAKET", "TAMLIK_KAPISI_IMPORT_YAZMAYI_ENGELLER"],
    blocker_kodlari: tamlik.blocker_kodlari,
    blocker_detaylari: tamlik.blocker_detaylari,
    canonical_payload: { rows: [] },
    payload_hash: "demo-sgk-katalog-empty-payload-hash",
    manifest_set_hash: tamlik.manifest_set_hash,
    import_yapilabilir_mi: false,
    yazma_endpoint_aktif_mi: false,
    tamlik: {
      tamlik_durumu: tamlik.tamlik_durumu,
      onaylanabilir_mi: false,
      response_hash: tamlik.response_hash
    },
    response_hash: "demo-sgk-katalog-import-hash"
  };
}

export function buildSgkKatalogBlockerRaporuMock() {
  const tamlik = buildSgkKatalogTamlikMock();
  const extras = [
    {
      severity: "BLOCKER" as const,
      code: "SGK_KISMI_SURELI_HESAP_KURALI_EKSIK",
      message: "Kismi sureli prim gunu hesap kurali resmi olarak kanitlanmadi.",
      domain: "SGK_KATALOG",
      cozum_onerisi: "Resmi formul/kanit tamamlanmadan hesap uretilmez."
    },
    {
      severity: "BLOCKER" as const,
      code: "SGK_BILDIRIM_DONEMI_POLITIKASI_EKSIK",
      message: "Bildirim donemi sirket politikasi resmi/yetkili karar olmadan aktif edilemez.",
      domain: "SGK_KATALOG",
      cozum_onerisi: "Onayli politika surumu olmadan 15-14 varsayilmaz."
    },
    {
      severity: "BLOCKER" as const,
      code: "SGK_SUREC_KOD_ESLEMESI_BULUNAMADI",
      message: "Surec→SGK kod eslemesi bulunamadi.",
      domain: "SGK_KATALOG",
      cozum_onerisi: "Resmi katalog onayindan sonra esleme ekleyin."
    },
    {
      severity: "BLOCKER" as const,
      code: "SGK_COKLU_NEDEN_BIRLESIK_KOD_BULUNAMADI",
      message: "Birlesik kod kurali bulunamadi.",
      domain: "SGK_KATALOG",
      cozum_onerisi: "Resmi birlesik neden matrisini ekleyin."
    }
  ];
  const blocker_detaylari = [SGK_KATALOG_TAMLIK_BLOCKER, ...extras];
  const blocker_kodlari = blocker_detaylari.map((b) => b.code).sort();
  return {
    blocker_kodlari,
    blocker_detaylari,
    tamlik,
    approve_disabled_mi: true,
    import_write_disabled_mi: true,
    response_hash: "demo-sgk-katalog-blocker-hash"
  };
}
