/** Shared S85-C1 / S98 SGK katalog hazırlık mock contract (empty catalog, tamlik blocker). */

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
    primary_resmi_manifest_sayisi: 0,
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
    uyarilar: [],
    blocker_kodlari: [SGK_KATALOG_TAMLIK_BLOCKER.code],
    blocker_detaylari: [SGK_KATALOG_TAMLIK_BLOCKER],
    onaylanabilir_mi: false,
    dogrulanmis_tam_secilebilir_mi: false,
    import_yazma_aktif_mi: false,
    approve_aktif_mi: false,
    // Demo must not be more permissive than backend: no selectable TEYITSIZ/historical codes.
    secilebilir_kod_ornekleri: [],
    aktiflik_durumu_etiketleri: {
      AKTIF: "AKTIF",
      TARIHSEL: "TARIHSEL",
      BAGLAMA_OZGUN: "BAĞLAMA ÖZGÜ",
      PORTAL_TEYIT_BEKLIYOR: "PORTAL TEYİDİ BEKLİYOR"
    },
    teyitsiz_secilebilir_mi: false,
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

export function buildSgkSurecEslemeDryRunMock() {
  return {
    mode: "DRY_RUN",
    hatali_satirlar: [],
    uyari_satirlari: [{ row_index: 0, warnings: ["KARAR_BEKLIYOR"] }],
    canonical_rows: [],
    esleme_payload_hash: "demo-sgk-esleme-empty-hash",
    parent_surum: null,
    apply_yapilabilir_mi: false,
    decision_pending_count: 1,
    response_hash: "demo-sgk-esleme-dry-run"
  };
}

export function buildSgkSurecEslemeDryRunReadyMock() {
  return {
    mode: "DRY_RUN",
    hatali_satirlar: [],
    uyari_satirlari: [],
    canonical_rows: [{ surec_turu: "RAPOR", alt_tur: "Raporlu_Hastalik", eksik_gun_kodu: "01" }],
    esleme_payload_hash: "demo-sgk-esleme-ready-hash",
    parent_surum: { surum_kodu: "DEMO-KATALOG-2026", state: "ONAYLANDI", parent_immutable_mi: true },
    apply_yapilabilir_mi: true,
    decision_pending_count: 0,
    response_hash: "demo-sgk-esleme-dry-run-ready"
  };
}

export function buildSgkSurecEslemeImportSuccessMock(surumKodu = "DEMO-KATALOG-2026-ESLEME") {
  return {
    surum_id: 9801,
    surum_kodu: surumKodu,
    state: "TASLAK",
    esleme_payload_hash: "demo-sgk-esleme-ready-hash",
    parent_immutable_mi: true,
    response_hash: "demo-sgk-esleme-import-ok"
  };
}

export function buildSgkKatalogSubmitSuccessMock(surumKodu: string) {
  return {
    surum_id: 9801,
    surum_kodu: surumKodu,
    state: "ONAY_BEKLIYOR",
    tamlik_durumu: "RESMI_KAYNAKLI_KISITLI",
    response_hash: "demo-sgk-katalog-submit-ok"
  };
}

export function buildSgkSirketPolitikasiDryRunMock() {
  return {
    mode: "DRY_RUN",
    hatali_satirlar: [{ row_index: -1, errors: ["SUBE_BULUNAMADI_VEYA_PASIF"] }],
    uyari_satirlari: [],
    canonical_payload: null,
    politika_hash: "demo-sgk-politika-empty-hash",
    import_yapilabilir_mi: false,
    overlap_var_mi: false,
    response_hash: "demo-sgk-politika-dry-run"
  };
}

export function buildSgkSirketPolitikasiDryRunReadyMock() {
  return {
    mode: "DRY_RUN",
    hatali_satirlar: [],
    uyari_satirlari: [],
    canonical_payload: {
      sube_id: 1,
      surum_kodu: "DEMO-SGK-POLITIKA-2026",
      gecerlilik_baslangic: "2026-01-01",
      gecerlilik_bitis: null,
      bildirim_donem_tipi: "AY_15_SONRAKI_AY_14",
      degerler: []
    },
    politika_hash: "demo-sgk-politika-ready-hash",
    import_yapilabilir_mi: true,
    overlap_var_mi: false,
    response_hash: "demo-sgk-politika-dry-run-ready"
  };
}

export function buildSgkSirketPolitikasiImportSuccessMock(surumKodu = "DEMO-SGK-POLITIKA-2026") {
  return {
    surum_id: 9901,
    surum_kodu: surumKodu,
    sube_id: 1,
    state: "TASLAK",
    politika_hash: "demo-sgk-politika-ready-hash",
    response_hash: "demo-sgk-politika-import-ok"
  };
}

export function buildSgkSirketPolitikasiSubmitSuccessMock(surumKodu: string) {
  return {
    surum_id: 9901,
    surum_kodu: surumKodu,
    state: "ONAY_BEKLIYOR",
    politika_hash: "demo-sgk-politika-ready-hash",
    response_hash: "demo-sgk-politika-submit-ok"
  };
}

export const SGK_SUREC_ESLEME_SABLON_CSV =
  "\uFEFFsurec_turu;alt_tur;canonical_surec_turu;karar_kurali;kod_secim_modu;eksik_gun_kodu;kaynak_referansi\r\n";

export const SGK_SIRKET_POLITIKASI_SABLON_CSV =
  "\uFEFFsube;surum_kodu;gecerlilik_baslangic;gecerlilik_bitis;bildirim_donem_tipi;politika_kodu;deger;aciklama\r\n";
