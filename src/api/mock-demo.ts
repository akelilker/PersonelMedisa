import type { ApiResponse } from "../types/api";
import type { HaftalikKapanisSnapshotSatir, HaftalikKapanisSonuc } from "../types/haftalik-kapanis";
import type {
  FazlaCalismaOdemeTercihi,
  OdemeTipi
} from "../types/fazla-calisma-odeme-tercihi";
import { DEFAULT_ODEME_TIPI } from "../types/fazla-calisma-odeme-tercihi";
import type { SerbestZamanEvent } from "../types/serbest-zaman";
import type { UserRole } from "../types/auth";
import type { HaftalikBildirimMutabakat } from "../types/haftalik-bildirim-mutabakat";
import type { AylikBildirimOnay } from "../types/aylik-bildirim-onay";
import { hasRolePermission, type AppPermission } from "../lib/authorization/role-permissions";
import { isMondayIsoDate, resolveHaftalikMutabakatApproval } from "../lib/bildirim/haftalik-mutabakat";
import {
  listWeeksIntersectingMonth,
  resolveAylikBildirimOnayApproval,
  resolveAyBounds
} from "../lib/bildirim/aylik-bildirim-onay";
import {
  SUBE_DELETE_BLOCKED_ERROR_CODE,
  SUBE_DELETE_BLOCKED_MESSAGE
} from "../lib/yonetim/sube-delete";
import { assertRevizyonTransition } from "../lib/revizyon-talebi/revizyon-state";
import {
  canApproveOrRejectRevizyon,
  canCancelRevizyon,
  canCreateRevizyonForPersonel,
  canSubmitRevizyon,
  canViewRevizyonCorrection,
  canViewRevizyonTalep,
  maskCorrectionFinanceFields,
  maskRevizyonFinanceFields,
  type RevizyonActorContext
} from "../lib/revizyon-talebi/revizyon-scope";
import { buildCorrectionFromRevizyonTalebi } from "../lib/revizyon-talebi/revizyon-correction-map";
import {
  getCancelCorrectionError,
  getProduceCorrectionError
} from "../lib/revizyon-talebi/revizyon-correction-state";
import type { RevizyonCorrectionEvent } from "../types/revizyon-correction";
import type { RevizyonTalebi, RevizyonTipi } from "../types/revizyon-talebi";
import { REVIZYON_TIPLERI } from "../types/revizyon-talebi";
import { hesaplaAylikSgkPuantajOzetleri } from "../services/dashboard-rapor-servisi";
import { buildHaftalikKapanisSnapshot } from "../services/haftalik-kapanis-snapshot";
import {
  hesaplaSerbestZamanBakiye,
  olusturKullanimEvent,
  olusturOlusumEvent,
  olusturDuzeltmeEvent,
  olusturIptalEvent
} from "../services/serbest-zaman-event-motoru";
import { aggregateYillikFazlaCalisma } from "../services/yillik-fazla-calisma-aggregate";
import {
  computeGecerlilikDurumu,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  type PersonelBelgeKayitDurum,
  type PersonelBelgeKayitTipi
} from "../types/personel-belge-kaydi";

type DemoMethod = "GET" | "POST" | "PUT" | "DELETE";

type DemoPersonel = {
  id: number;
  tc_kimlik_no: string;
  ad: string;
  soyad: string;
  aktif_durum: "AKTIF" | "PASIF";
  sube_id?: number;
  telefon?: string;
  dogum_tarihi?: string;
  sicil_no?: string;
  dogum_yeri?: string;
  kan_grubu?: string;
  ise_giris_tarihi?: string;
  acil_durum_kisi?: string;
  acil_durum_telefon?: string;
  departman_id?: number;
  gorev_id?: number;
  personel_tipi_id?: number;
  bagli_amir_id?: number;
  ucret_tipi_id?: number;
  maas_tutari?: number;
  net_maas_tutari?: number;
};

type DemoSurec = {
  id: number;
  personel_id: number;
  surec_turu: string;
  alt_tur?: string;
  baslangic_tarihi?: string;
  bitis_tarihi?: string;
  ucretli_mi?: boolean;
  ilk_iki_gun_firma_oder_mi?: boolean | null;
  aciklama?: string;
  state?: string;
};

type DemoZimmet = {
  id: number;
  personel_id: number;
  urun_turu: string;
  teslim_tarihi: string;
  teslim_eden?: string;
  aciklama?: string;
  teslim_durumu: string;
  zimmet_durumu: string;
  iade_tarihi?: string;
};

type DemoPersonelBelgeKaydi = {
  id: number;
  personel_id: number;
  kayit_tipi: PersonelBelgeKayitTipi;
  ad: string;
  veren_kurum?: string | null;
  belge_no?: string | null;
  baslangic_tarihi?: string | null;
  bitis_tarihi?: string | null;
  durum: PersonelBelgeKayitDurum;
  ek_ref?: string | null;
  aciklama?: string | null;
  created_at?: string;
  updated_at?: string;
};

type DemoBildirim = {
  id: number;
  tarih?: string;
  departman_id?: number;
  personel_id?: number;
  sube_id?: number;
  bildirim_turu: string;
  aciklama?: string;
  state?: string;
  okundu_mi?: boolean;
  created_by?: number;
  updated_by?: number;
  submitted_at?: string | null;
  correction_requested_by?: number | null;
  correction_reason?: string | null;
  haftalik_mutabakat_id?: number | null;
};

type DemoPersonelUcretKaydi = {
  id: number;
  personel_id: number;
  ucret_tutari: number;
  ucret_turu: "BRUT" | "NET";
  para_birimi: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  state: "AKTIF" | "IPTAL";
  kaynak: "MANUEL" | "PERSONEL_KAYDI_MIGRASYON" | "SISTEM";
  aciklama: string | null;
  created_at: string;
  created_by: number | null;
  updated_at: string;
  updated_by: number | null;
};

type DemoMevzuatParametresi = {
  id: number;
  parametre_kodu: string;
  deger_tipi: "SAYISAL" | "METIN";
  sayisal_deger: number | null;
  metin_deger: string | null;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  birim: string | null;
  aciklama: string | null;
  kaynak_referansi: string | null;
  state: "AKTIF" | "IPTAL";
  created_at: string;
  created_by: number | null;
  updated_at: string;
  updated_by: number | null;
};

type DemoFinansKalem = {
  id: number;
  personel_id: number;
  donem: string;
  kalem_turu: string;
  tutar: number;
  aciklama?: string;
  state?: string;
};

type DemoPuantaj = {
  personel_id: number;
  tarih: string;
  kontrol_durumu?: "BEKLIYOR" | "AMIR_KONTROL_ETTI";
  gun_tipi?: "Normal_Is_Gunu" | "Hafta_Tatili_Pazar" | "UBGT_Resmi_Tatil";
  hareket_durumu?: "Geldi" | "Gelmedi" | "Gec_Geldi" | "Erken_Cikti";
  dayanak?:
    | "Yok_Izinsiz"
    | "Ucretli_Izinli"
    | "Raporlu_Hastalik"
    | "Raporlu_Is_Kazasi"
    | "Yillik_Izin"
    | "Telafi_Calismasi"
    | "Gorevde_Calisma";
  durumu_bildirdi_mi?: boolean;
  durum_bildirim_aciklamasi?: string;
  hesap_etkisi?: "Yevmiye_Kes" | "Tam_Yevmiye_Ver" | "Mesai_Yaz" | "Ucretli_Izin" | "Raporlu" | "Telafi";
  beklenen_giris_saati?: string;
  beklenen_cikis_saati?: string;
  giris_saati?: string;
  cikis_saati?: string;
  gec_kalma_dakika?: number;
  erken_cikis_dakika?: number;
  gercek_mola_dakika?: number;
  hesaplanan_mola_dakika?: number;
  net_calisma_suresi_dakika?: number;
  gunluk_brut_sure_dakika?: number;
  hafta_tatili_hak_kazandi_mi?: boolean;
  state?: string;
  compliance_uyarilari: Array<{ code: string; message: string; level?: string }>;
};

const DEMO_PUANTAJ_KONTROL_DURUMU_MAP: Record<string, NonNullable<DemoPuantaj["kontrol_durumu"]>> = {
  BEKLIYOR: "BEKLIYOR",
  AMIR_KONTROL_ETTI: "AMIR_KONTROL_ETTI",
  AMIR_KONTROL_EDILDI: "AMIR_KONTROL_ETTI"
};

type DemoMakine = {
  id: number;
  ad: string;
  tip: string;
  konum?: string | null;
  durum: "aktif" | "arizali" | "pasif";
  sube_id: number;
  son_bakim?: string | null;
  bakim_periyot_gun?: number | null;
};

type DemoMakineBakimKaydi = {
  id: number;
  makine_id: number;
  bakim_tarihi?: string | null;
  yapan?: string;
  notlar?: string;
};

type DemoYonetimKullanici = {
  id: number;
  ad_soyad: string;
  telefon?: string;
  kullanici_tipi: "IC_PERSONEL" | "HARICI";
  rol: "GENEL_YONETICI" | "BOLUM_YONETICISI" | "MUHASEBE" | "BIRIM_AMIRI";
  personel_id?: number | null;
  sube_ids: number[];
  varsayilan_sube_id: number | null;
  durum: "AKTIF" | "PASIF";
  notlar?: string;
};

type DemoSube = {
  id: number;
  kod: string;
  ad: string;
  departman_ids: number[];
  durum: "AKTIF" | "PASIF";
};

type DemoDepartman = {
  id: number;
  ad: string;
};

/** Åirket birim/departman referans listesi (sabit ID sÄ±rasÄ±). */
const DEMO_DEPARTMANLAR: DemoDepartman[] = [
  { id: 1, ad: "Muhasebe" },
  { id: 2, ad: "Finans" },
  { id: 3, ad: "Döşeme" },
  { id: 4, ad: "Panel" },
  { id: 5, ad: "Hammadde Depo" },
  { id: 6, ad: "Depo" },
  { id: 7, ad: "E-ticaret Depo" },
  { id: 8, ad: "Yönetim Personeli" },
  { id: 9, ad: "Dış Ticaret" },
  { id: 10, ad: "İdari İşler" },
  { id: 11, ad: "Pazarlama" },
  { id: 12, ad: "Ar-Ge" }
];

/** GÃ¶rev/unvan referans listesi (sabit ID sÄ±rasÄ±). */
const DEMO_GOREVLER: Array<{ id: number; ad: string }> = [
  { id: 1, ad: "Genel Müdür" },
  { id: 2, ad: "Üretim Müdürü" },
  { id: 3, ad: "Finans Müdürü" },
  { id: 4, ad: "Fabrika Müdürü" },
  { id: 5, ad: "İdari İşler Müdürü" },
  { id: 6, ad: "Pazarlama Müdürü" },
  { id: 7, ad: "Dış Ticaret Müdürü" },
  { id: 8, ad: "Satış Destek Personeli" },
  { id: 9, ad: "Dış Ticaret Personeli" },
  { id: 10, ad: "Satış Personeli" },
  { id: 11, ad: "Temizlik Personeli" },
  { id: 12, ad: "Güvenlik Personeli" },
  { id: 13, ad: "Satış Sonrası (SSH)" },
  { id: 14, ad: "Şoför" },
  { id: 15, ad: "Aşçı" }
];

const DEMO_GOREV_LABELS: Record<number, string> = DEMO_GOREVLER.reduce(
  (acc, row) => {
    acc[row.id] = row.ad;
    return acc;
  },
  {} as Record<number, string>
);

type DemoAylikDurum = {
  ay: string;
  personel_id: number;
  bolum_onay_durumu: "BOLUM_ONAYINDA" | "BOLUM_ONAYLANDI" | "REVIZE_ISTENDI";
  revize_var_mi: boolean;
  son_islem: string;
  kapanis_durumu: "ACIK" | "KAPANDI";
};

const demoState: {
  personeller: DemoPersonel[];
  surecler: DemoSurec[];
  zimmetler: DemoZimmet[];
  personelBelgeKayitlari: DemoPersonelBelgeKaydi[];
  bildirimler: DemoBildirim[];
  haftalikBildirimMutabakatlari: HaftalikBildirimMutabakat[];
  aylikBildirimOnaylari: AylikBildirimOnay[];
  finansKalemleri: DemoFinansKalem[];
  personelUcretleri: DemoPersonelUcretKaydi[];
  mevzuatParametreleri: DemoMevzuatParametresi[];
  puantajMap: Record<string, DemoPuantaj>;
  makineler: DemoMakine[];
  bakimKayitlari: DemoMakineBakimKaydi[];
  yonetimKullanicilari: DemoYonetimKullanici[];
  departmanlar: DemoDepartman[];
  subeler: DemoSube[];
  aylikDurumMap: Record<string, DemoAylikDurum>;
  belgeDurumByPersonelId: Record<
    number,
    Partial<Record<"KIMLIK" | "ADRES_BEYANI" | "IS_GIRIS_EVRAKLARI" | "BANKA_IBAN", "VAR" | "YOK">>
  >;
  kapanisById: Record<number, HaftalikKapanisSonuc>;
  odemeTercihiBySnapshotId: Record<number, FazlaCalismaOdemeTercihi>;
  /** Append-only FCOT audit trail (demo parity). */
  odemeTercihiAudit: Array<{
    tercih_id: number;
    snapshot_id: number;
    onceki_odeme_tipi: OdemeTipi;
    yeni_odeme_tipi: OdemeTipi;
    secen_kullanici_id: number;
    secim_zamani: string;
    gerekce?: string;
  }>;
  /** Sealed puantaj months: `${subeId}|${yil}|${ay}` → true. Missing = open. */
  sealedPuantajDonemKeys: Record<string, true>;
  serbestZamanEventsById: Record<number, SerbestZamanEvent>;
  /** Active OLUSUM uniqueness guard: odeme_tercihi_id → olusum_event_id */
  serbestZamanAktifOlusumByTercihId: Record<number, number>;
  revizyonTalebiById: Record<number, RevizyonTalebi>;
  revizyonCorrectionById: Record<number, RevizyonCorrectionEvent>;
  nextIds: {
    personel: number;
    surec: number;
    zimmet: number;
    personelBelgeKaydi: number;
    bildirim: number;
    haftalikBildirimMutabakat: number;
    aylikBildirimOnay: number;
    finans: number;
    personelUcret: number;
    mevzuatParametre: number;
    kapanis: number;
    odemeTercihi: number;
    serbestZamanEvent: number;
    revizyonTalebi: number;
    revizyonCorrection: number;
    kullanici: number;
    sube: number;
    departman: number;
  };
} = {
  personeller: [
    {
      id: 1,
      tc_kimlik_no: "12345678901",
      ad: "Ayşe",
      soyad: "Yılmaz",
      aktif_durum: "AKTIF",
      sube_id: 1,
      telefon: "05550000000",
      dogum_tarihi: "1992-03-14",
      sicil_no: "P-001",
      dogum_yeri: "İstanbul",
      kan_grubu: "A Rh+",
      ise_giris_tarihi: "2023-02-01",
      acil_durum_kisi: "Fatma Yılmaz",
      acil_durum_telefon: "05553334455",
      departman_id: 3,
      gorev_id: 1,
      personel_tipi_id: 1,
      bagli_amir_id: 1,
      maas_tutari: 35000
    },
    {
      id: 2,
      tc_kimlik_no: "23456789012",
      ad: "Mehmet",
      soyad: "Kaya",
      aktif_durum: "AKTIF",
      sube_id: 2,
      telefon: "05551111111",
      dogum_tarihi: "1989-11-02",
      sicil_no: "P-002",
      dogum_yeri: "Ankara",
      kan_grubu: "0 Rh+",
      ise_giris_tarihi: "2024-07-15",
      acil_durum_kisi: "Zeynep Kaya",
      acil_durum_telefon: "05556667788",
      departman_id: 6,
      gorev_id: 2,
      personel_tipi_id: 2,
      bagli_amir_id: 1
    }
  ],
  surecler: [
    {
      id: 501,
      personel_id: 1,
      surec_turu: "IZIN",
      alt_tur: "YILLIK_IZIN",
      baslangic_tarihi: "2026-04-10",
      bitis_tarihi: "2026-04-11",
      ucretli_mi: true,
      aciklama: "Demo izin kaydi",
      state: "AKTIF"
    }
  ],
  zimmetler: [
    {
      id: 551,
      personel_id: 1,
      urun_turu: "KASK",
      teslim_tarihi: "2026-03-01",
      teslim_eden: "IK Gorevlisi",
      aciklama: "Seri No: KSK-001",
      teslim_durumu: "YENI",
      zimmet_durumu: "AKTIF"
    },
    {
      id: 552,
      personel_id: 1,
      urun_turu: "KULAKLIK",
      teslim_tarihi: "2026-01-15",
      teslim_eden: "BaÄŸlÄ± Amir",
      aciklama: "Onceki vardiyadan teslim alindi",
      teslim_durumu: "IKINCI_EL",
      zimmet_durumu: "IADE_EDILDI",
      iade_tarihi: "2026-02-20"
    },
    {
      id: 553,
      personel_id: 2,
      urun_turu: "AYAKKABI",
      teslim_tarihi: "2026-02-05",
      teslim_eden: "IK Gorevlisi",
      aciklama: "Seri No: AYK-204",
      teslim_durumu: "YENI",
      zimmet_durumu: "AKTIF"
    }
  ],
  personelBelgeKayitlari: [
    {
      id: 701,
      personel_id: 1,
      kayit_tipi: "SERTIFIKA",
      ad: "Forklift Operatör Belgesi",
      veren_kurum: "Medisa Eğitim Merkezi",
      belge_no: "FRK-2024-001",
      baslangic_tarihi: "2024-03-01",
      bitis_tarihi: "2027-03-01",
      durum: "AKTIF",
      created_at: "2024-03-01T10:00:00.000Z"
    },
    {
      id: 702,
      personel_id: 1,
      kayit_tipi: "EHLIYET",
      ad: "B Sınıfı Ehliyet",
      veren_kurum: "İstanbul İl Emniyet",
      belge_no: "TR-987654",
      baslangic_tarihi: "2018-05-10",
      bitis_tarihi: "2026-07-15",
      durum: "AKTIF",
      created_at: "2018-05-10T10:00:00.000Z"
    }
  ],
  bildirimler: [
    {
      id: 701,
      tarih: "2026-04-09",
      departman_id: 3,
      personel_id: 1,
      sube_id: 1,
      bildirim_turu: "GEC_GELDI",
      aciklama: "Demo bildirim",
      state: "GONDERILDI",
      okundu_mi: false,
      created_by: 3,
      updated_by: 3
    },
    {
      id: 702,
      tarih: "2026-04-10",
      departman_id: 6,
      personel_id: 2,
      sube_id: 2,
      bildirim_turu: "IZINLI",
      aciklama: "Onayli izin nedeniyle bugun yok.",
      state: "GONDERILDI",
      okundu_mi: false,
      created_by: 3,
      updated_by: 3
    }
  ],
  haftalikBildirimMutabakatlari: [],
  aylikBildirimOnaylari: [],
  finansKalemleri: [
    {
      id: 901,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "AVANS",
      tutar: 2500,
      aciklama: "Demo avans",
      state: "AKTIF"
    },
    {
      id: 903,
      personel_id: 1,
      donem: "2026-03",
      kalem_turu: "PRIM",
      tutar: 1800,
      aciklama: "Farkli donem finans kaydi",
      state: "AKTIF"
    },
    {
      id: 904,
      personel_id: 1,
      donem: "2026-04",
      kalem_turu: "PRIM",
      tutar: 900,
      aciklama: "Iptal finans kaydi",
      state: "IPTAL"
    }
  ],
  personelUcretleri: [],
  mevzuatParametreleri: [],
  puantajMap: {
    "1|2026-04-09": buildDemoPuantaj({
      personelId: 1,
      tarih: "2026-04-09",
      gunTipi: "Normal_Is_Gunu",
      hareketDurumu: "Geldi",
      hesapEtkisi: "Tam_Yevmiye_Ver",
      girisSaati: "08:30",
      cikisSaati: "18:00",
      gercekMolaDakika: 60,
      hesaplananMolaDakika: 60,
      netCalismaSuresiDakika: 510,
      gunlukBrutSureDakika: 570,
      haftaTatiliHakKazandiMi: true,
      kontrolDurumu: "AMIR_KONTROL_ETTI"
    }),
    "2|2026-04-09": buildDemoPuantaj({
      personelId: 2,
      tarih: "2026-04-09",
      gunTipi: "Normal_Is_Gunu",
      hareketDurumu: "Gelmedi",
      dayanak: "Yok_Izinsiz",
      hesapEtkisi: "Yevmiye_Kes",
      haftaTatiliHakKazandiMi: false,
      complianceUyarilari: [
        {
          code: "DEVAMSIZLIK",
          message: "Mazeretsiz devamsizlik hafta tatili hakkini bozabilir.",
          level: "KRITIK"
        }
      ]
    }),
    "1|2026-04-10": buildDemoPuantaj({
      personelId: 1,
      tarih: "2026-04-10",
      gunTipi: "Normal_Is_Gunu",
      hareketDurumu: "Gec_Geldi",
      dayanak: "Ucretli_Izinli",
      hesapEtkisi: "Tam_Yevmiye_Ver",
      beklenenGirisSaati: "08:00",
      beklenenCikisSaati: "18:00",
      girisSaati: "09:15",
      cikisSaati: "18:00",
      gercekMolaDakika: 60,
      hesaplananMolaDakika: 60,
      netCalismaSuresiDakika: 465,
      gunlukBrutSureDakika: 525,
      haftaTatiliHakKazandiMi: true,
      complianceUyarilari: [
        {
          code: "MAZERET",
          message: "Gec giris ucretli mazeret kapsaminda degerlendirildi.",
          level: "BILGI"
        }
      ]
    })
  },
  makineler: [
    {
      id: 1101,
      ad: "Kesim Robotu",
      tip: "Kesim",
      konum: "Atolye A",
      durum: "aktif",
      sube_id: 1,
      son_bakim: "2026-04-01",
      bakim_periyot_gun: 30
    },
    {
      id: 1102,
      ad: "Forklift 02",
      tip: "Tasima",
      konum: "Depo Giris",
      durum: "aktif",
      sube_id: 2,
      son_bakim: "2026-02-10",
      bakim_periyot_gun: 30
    },
    {
      id: 1103,
      ad: "Pres Hatti",
      tip: "Pres",
      konum: "Atolye B",
      durum: "aktif",
      sube_id: 1,
      son_bakim: null,
      bakim_periyot_gun: 45
    }
  ],
  bakimKayitlari: [
    {
      id: 2101,
      makine_id: 1101,
      bakim_tarihi: "2026-03-01",
      yapan: "Bakim Ekibi A",
      notlar: "Aylik kontrol"
    },
    {
      id: 2102,
      makine_id: 1101,
      bakim_tarihi: "2026-04-01",
      yapan: "Bakim Ekibi B",
      notlar: "Rutin mekanik bakim"
    },
    {
      id: 2104,
      makine_id: 1102,
      bakim_tarihi: "2026-02-10",
      yapan: "Servis",
      notlar: "Fren ayari"
    },
    {
      id: 2103,
      makine_id: 1102,
      bakim_tarihi: "2026-01-05",
      yapan: "Servis",
      notlar: "Yag degisimi"
    },
    {
      id: 2105,
      makine_id: 1103,
      bakim_tarihi: "gecersiz-tarih",
      yapan: "Kayit Hatasi",
      notlar: "Tarih dogrulanamadi"
    }
  ],
  yonetimKullanicilari: [
    {
      id: 1,
      ad_soyad: "İlker Akel",
      telefon: "05550000001",
      kullanici_tipi: "HARICI",
      rol: "GENEL_YONETICI",
      personel_id: null,
      sube_ids: [],
      varsayilan_sube_id: null,
      durum: "AKTIF",
      notlar: "Tum yapinin yonetim ve son kapanis yetkilisi"
    },
    {
      id: 2,
      ad_soyad: "Adnan Bulut",
      telefon: "05550000002",
      kullanici_tipi: "HARICI",
      rol: "BOLUM_YONETICISI",
      personel_id: null,
      sube_ids: [2],
      varsayilan_sube_id: 2,
      durum: "AKTIF",
      notlar: "Depolama biriminin aylik kontrol sorumlusu"
    },
    {
      id: 3,
      ad_soyad: "Serhan Köse",
      telefon: "05550000003",
      kullanici_tipi: "IC_PERSONEL",
      rol: "BIRIM_AMIRI",
      personel_id: 1,
      sube_ids: [1],
      varsayilan_sube_id: 1,
      durum: "AKTIF",
      notlar: "Gunluk kayit sorumlusu"
    }
  ],
  departmanlar: [...DEMO_DEPARTMANLAR],
  subeler: [
    {
      id: 1,
      kod: "MRK",
      ad: "Merkez",
      departman_ids: [1, 3],
      durum: "AKTIF"
    },
    {
      id: 2,
      kod: "DPL",
      ad: "Depolama",
      departman_ids: [6],
      durum: "AKTIF"
    }
  ],
  aylikDurumMap: {
    "2026-04|1": {
      ay: "2026-04",
      personel_id: 1,
      bolum_onay_durumu: "BOLUM_ONAYINDA",
      revize_var_mi: false,
      son_islem: "Birim amiri gunluk kayitlari hazirladi",
      kapanis_durumu: "ACIK"
    },
    "2026-04|2": {
      ay: "2026-04",
      personel_id: 2,
      bolum_onay_durumu: "REVIZE_ISTENDI",
      revize_var_mi: true,
      son_islem: "Bolum yoneticisi duzeltme istedi",
      kapanis_durumu: "ACIK"
    }
  },
  belgeDurumByPersonelId: {},
  kapanisById: {},
  odemeTercihiBySnapshotId: {},
  odemeTercihiAudit: [],
  sealedPuantajDonemKeys: {},
  serbestZamanEventsById: {},
  serbestZamanAktifOlusumByTercihId: {},
  revizyonTalebiById: {},
  revizyonCorrectionById: {},
  nextIds: {
    personel: 100,
    surec: 600,
    zimmet: 560,
    personelBelgeKaydi: 703,
    bildirim: 800,
    haftalikBildirimMutabakat: 0,
    aylikBildirimOnay: 0,
    finans: 950,
    personelUcret: 0,
    mevzuatParametre: 0,
    kapanis: 1000,
    odemeTercihi: 1,
    serbestZamanEvent: 1,
    revizyonTalebi: 1,
    revizyonCorrection: 1,
    kullanici: 3,
    sube: 2,
    departman: 12
  }
};

const DEMO_BELGE_TURLERI = ["KIMLIK", "ADRES_BEYANI", "IS_GIRIS_EVRAKLARI", "BANKA_IBAN"] as const;

function buildDemoBelgeDurumResponse(personelId: number) {
  const stored = demoState.belgeDurumByPersonelId[personelId] ?? {};
  const items = DEMO_BELGE_TURLERI.map((belge_turu) => ({
    belge_turu,
    durum: stored[belge_turu] ?? "YOK"
  }));
  return ok({ items });
}

function serializeDemoPersonelBelgeKaydi(record: DemoPersonelBelgeKaydi) {
  const bitisTarihi = record.bitis_tarihi ?? null;
  return {
    id: record.id,
    personel_id: record.personel_id,
    kayit_tipi: record.kayit_tipi,
    ad: record.ad,
    veren_kurum: record.veren_kurum ?? null,
    belge_no: record.belge_no ?? null,
    baslangic_tarihi: record.baslangic_tarihi ?? null,
    bitis_tarihi: bitisTarihi,
    durum: record.durum,
    gecerlilik_durumu: computeGecerlilikDurumu(bitisTarihi),
    ek_ref: record.ek_ref ?? null,
    aciklama: record.aciklama ?? null,
    created_at: record.created_at ?? null,
    updated_at: record.updated_at ?? null
  };
}

function isPersonelBelgeKayitTipi(value: unknown): value is PersonelBelgeKayitTipi {
  return typeof value === "string" && (PERSONEL_BELGE_KAYIT_TIPI_KEYS as readonly string[]).includes(value);
}

function applyDemoBelgeDurumPut(personelId: number, body: Record<string, unknown>) {
  const itemsRaw = body.items;
  if (!Array.isArray(itemsRaw)) {
    return buildDemoBelgeDurumResponse(personelId);
  }

  const incoming: Partial<Record<(typeof DEMO_BELGE_TURLERI)[number], "VAR" | "YOK">> = {};
  for (const row of itemsRaw) {
    if (typeof row !== "object" || row === null) {
      continue;
    }
    const r = row as Record<string, unknown>;
    const tur = toStringValue(r.belge_turu);
    const durum = toStringValue(r.durum);
    if (!tur || (durum !== "VAR" && durum !== "YOK")) {
      continue;
    }
    if (!(DEMO_BELGE_TURLERI as readonly string[]).includes(tur)) {
      continue;
    }
    incoming[tur as (typeof DEMO_BELGE_TURLERI)[number]] = durum;
  }

  const prior = demoState.belgeDurumByPersonelId[personelId] ?? {};
  const next: (typeof demoState.belgeDurumByPersonelId)[number] = { ...prior };
  for (const belge_turu of DEMO_BELGE_TURLERI) {
    next[belge_turu] = incoming[belge_turu] ?? prior[belge_turu] ?? "YOK";
  }

  demoState.belgeDurumByPersonelId[personelId] = next;
  return buildDemoBelgeDurumResponse(personelId);
}

const DEMO_PERSONEL_TIPI_LABELS: Record<number, string> = {
  1: "Tam Zamanlı",
  2: "Yarı Zamanlı"
};

const DEMO_BAGLI_AMIR_LABELS: Record<number, string> = {
  1: "Demo Amir",
  2: "İkinci Amir"
};

function toRecord(value: unknown): Record<string, unknown> | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  return value as Record<string, unknown>;
}

function toNumber(value: unknown): number | null {
  if (typeof value === "number" && Number.isFinite(value)) {
    return value;
  }

  if (typeof value === "string" && value.trim()) {
    const parsed = Number(value);
    if (Number.isFinite(parsed)) {
      return parsed;
    }
  }

  return null;
}

function toStringValue(value: unknown): string | null {
  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();
  return trimmed || null;
}

function toBooleanValue(value: unknown): boolean | null {
  if (typeof value === "boolean") {
    return value;
  }

  if (typeof value === "number") {
    if (value === 1) return true;
    if (value === 0) return false;
  }

  if (typeof value === "string") {
    const normalized = value.trim().toLowerCase();
    if (normalized === "true" || normalized === "1" || normalized === "evet") return true;
    if (normalized === "false" || normalized === "0" || normalized === "hayir" || normalized === "hayır") return false;
  }

  return null;
}

function readBody(init?: RequestInit): Record<string, unknown> {
  if (!init?.body || typeof init.body !== "string") {
    return {};
  }

  try {
    const parsed = JSON.parse(init.body) as unknown;
    return toRecord(parsed) ?? {};
  } catch {
    return {};
  }
}

function ok<T>(data: T, meta: Record<string, unknown> = {}): ApiResponse<T> {
  return {
    data,
    meta,
    errors: []
  };
}

function demoRevizyonError(code: string, message: string): ApiResponse<unknown> {
  return {
    data: null,
    meta: {},
    errors: [{ code, message }]
  };
}

type MaasDemoSnapshot = {
  id: number;
  snapshot_id: number;
  sube_id: number;
  yil: number;
  ay: number;
  donem: string;
  donem_baslangic: string;
  donem_bitis: string;
  muhur_id: number;
  revision_no: number;
  parent_snapshot_id: number | null;
  state: string;
  contract_version: string;
  cutoff_at: string;
  preflight_hash: string;
  source_hash: string;
  snapshot_hash: string;
  personel_sayisi: number;
  girdi_sayisi: number;
  blocker_count: number;
  warning_count: number;
  created_by: number | null;
  created_at: string;
  iptal_edildi_by: number | null;
  iptal_edildi_at: string | null;
  iptal_nedeni: string | null;
};

type MaasDemoAudit = {
  id: number;
  donem_snapshot_id: number | null;
  sube_id: number;
  yil: number;
  ay: number;
  muhur_id: number | null;
  aksiyon: string;
  sonuc: string;
  actor_id: number | null;
  actor_rol: string | null;
  request_hash: string;
  preflight_hash: string | null;
  source_hash: string | null;
  result_hash: string | null;
  blocker_count: number;
  warning_count: number;
  created_at: string;
};

type MaasDemoCalistirma = {
  id: number;
  donem_snapshot_id: number;
  sube_id: number;
  yil: number;
  ay: number;
  donem: string;
  revision_no: number;
  state: string;
  engine_version: string;
  contract_version: string;
  calculation_input_hash: string;
  source_hash: string;
  result_hash: string;
  personel_sayisi: number;
  aday_sayisi: number;
  toplam_net: number;
  toplam_brut: number;
  toplam_gelir_vergisi: number;
  toplam_sgk: number;
  created_at: string;
  iptal_edildi_at: string | null;
  iptal_nedeni: string | null;
};

type MaasDemoAday = {
  id: number;
  calistirma_id: number;
  personel_id: number;
  personel_ad_soyad: string;
  sicil_no: string;
  state: string;
  net_ucret: number;
  brut_ucret: number;
  gelir_vergisi: number;
  sgk_primi: number;
  result_hash: string;
};

type MaasDemoKalem = {
  id: number;
  aday_id: number;
  kalem_kodu: string;
  kalem_adi: string;
  kategori: string;
  tutar: number;
  matrah: number | null;
  metadata: Record<string, unknown>;
};

type MaasDemoDevir = {
  id: number;
  personel_id: number;
  personel_ad_soyad: string | null;
  sube_id: number;
  yil: number;
  ay: number;
  onceki_kumulatif_gelir_vergisi_matrahi: number;
  onceki_kumulatif_gelir_vergisi: number;
  onceki_kumulatif_sgk_matrahi: number | null;
  kaynak: string | null;
  aciklama: string | null;
  created_at: string;
  updated_at: string;
};

const maasHesaplamaDemoState: {
  snapshots: MaasDemoSnapshot[];
  audits: MaasDemoAudit[];
  calistirmalar: MaasDemoCalistirma[];
  adaylar: MaasDemoAday[];
  kalemler: MaasDemoKalem[];
  devirler: MaasDemoDevir[];
  nextId: number;
  nextAuditId: number;
  nextCalistirmaId: number;
  nextAdayId: number;
  nextKalemId: number;
  nextDevirId: number;
  sealedKeys: Set<string>;
} = {
  snapshots: [],
  audits: [],
  calistirmalar: [],
  adaylar: [],
  kalemler: [],
  devirler: [],
  nextId: 1,
  nextAuditId: 1,
  nextCalistirmaId: 1,
  nextAdayId: 1,
  nextKalemId: 1,
  nextDevirId: 1,
  sealedKeys: new Set(["1:2026:3"])
};

function ensureMaasHesaplamaDemoState() {
  return maasHesaplamaDemoState;
}

function buildMaasHesaplamaCalculationInputHash(snapshot: MaasDemoSnapshot): string {
  return snapshot.source_hash.replace(/^a/, "e");
}

function buildMaasHesaplamaCalculationPreflight(state: typeof maasHesaplamaDemoState, snapshot: MaasDemoSnapshot) {
  const existing = state.calistirmalar.find(
    (item) => item.donem_snapshot_id === snapshot.id && item.state !== "IPTAL"
  );
  const items = [
    {
      severity: "BLOCKER",
      code: "LEGAL_PARAMETER_REQUIRED_MISSING",
      message: "Demo modda hesaplama için zorunlu mevzuat kataloğu bulunamadı.",
      record_type: "mevzuat",
      record_id: null,
      personel_id: null,
      personel_adi: null,
      metadata: { demo: true }
    }
  ];

  return {
    snapshot_id: snapshot.id,
    sube_id: snapshot.sube_id,
    yil: snapshot.yil,
    ay: snapshot.ay,
    donem: snapshot.donem,
    hesaplanabilir_mi: false,
    blocker_count: items.length,
    warning_count: 0,
    info_count: 0,
    items,
    personel_summary: [
      { personel_id: 7, ad_soyad: "Ali Yilmaz", hesaplanabilir_mi: false },
      { personel_id: 8, ad_soyad: "Ayse Demir", hesaplanabilir_mi: false }
    ],
    parameter_summary: { mevzuat_parametre_sayisi: 0 },
    engine_version: "S77_D_DEMO_ENGINE_V2",
    contract_version: "S77_D_CALCULATION_V2",
    calculation_input_hash: buildMaasHesaplamaCalculationInputHash(snapshot),
    source_hash: snapshot.source_hash,
    parameter_set_hash: "0000000000000000000000000000000000000000000000000000000000000000",
    carryover_set_hash: "1111111111111111111111111111111111111111111111111111111111111111",
    snapshot_hash: snapshot.snapshot_hash,
    existing_calculation: existing
      ? {
          id: existing.id,
          revision_no: existing.revision_no,
          state: existing.state,
          source_hash: existing.source_hash,
          result_hash: existing.result_hash
        }
      : null
  };
}

function buildMaasHesaplamaPreflight(
  state: typeof maasHesaplamaDemoState,
  subeId: number,
  yil: number,
  ay: number
) {
  const donem = `${yil}-${String(ay).padStart(2, "0")}`;
  const lastDay = new Date(Date.UTC(yil, ay, 0)).getUTCDate();
  const sealed = state.sealedKeys.has(`${subeId}:${yil}:${ay}`);
  const existing = state.snapshots.find(
    (item) => item.sube_id === subeId && item.yil === yil && item.ay === ay && item.state === "OLUSTURULDU"
  );
  const items = [];
  if (!sealed) {
    items.push({
      severity: "BLOCKER",
      code: "PERIOD_NOT_SEALED",
      message: "Donem muhurlenmemis; snapshot olusturulamaz.",
      record_type: "muhur",
      record_id: null,
      personel_id: null,
      personel_adi: null,
      metadata: {}
    });
  } else {
    items.push({
      severity: "INFO",
      code: "PERIOD_SEALED",
      message: "Donem muhurlu.",
      record_type: "muhur",
      record_id: 1,
      personel_id: null,
      personel_adi: null,
      metadata: { muhurlenen_kayit_sayisi: 2 }
    });
    items.push({
      severity: "WARNING",
      code: "LEGAL_PARAMETER_SET_EMPTY",
      message: "Donemle kesisen mevzuat parametresi yok.",
      record_type: "mevzuat",
      record_id: null,
      personel_id: null,
      personel_adi: null,
      metadata: {}
    });
  }

  const sourceHash = sealed
    ? "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    : "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb";
  const preflightHash = sealed
    ? "cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc"
    : "dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd";

  if (existing && existing.source_hash !== sourceHash) {
    items.push({
      severity: "BLOCKER",
      code: "EXISTING_ACTIVE_SNAPSHOT_SOURCE_CHANGED",
      message: "Aktif snapshot sonrasi kaynaklar degisti.",
      record_type: "snapshot",
      record_id: existing.id,
      personel_id: null,
      personel_adi: null,
      metadata: {}
    });
  }

  const blockerCount = items.filter((item) => item.severity === "BLOCKER").length;
  const warningCount = items.filter((item) => item.severity === "WARNING").length;
  const infoCount = items.filter((item) => item.severity === "INFO").length;

  return {
    sube: { id: subeId, ad: subeId === 1 ? "Merkez" : `Sube ${subeId}`, kod: subeId === 1 ? "MRK" : `S${subeId}` },
    yil,
    ay,
    donem,
    donem_baslangic: `${donem}-01`,
    donem_bitis: `${donem}-${String(lastDay).padStart(2, "0")}`,
    muhur: sealed
      ? {
          id: 1,
          durum: "MUHURLENDI",
          muhurlenen_kayit_sayisi: 2,
          created_at: "2026-03-31 23:59:00"
        }
      : null,
    snapshot_olusturulabilir_mi: sealed && blockerCount === 0 && !existing,
    blocker_count: blockerCount,
    warning_count: warningCount,
    info_count: infoCount,
    items,
    personel_summary: sealed
      ? [
          {
            personel_id: 7,
            ad_soyad: "Ali Yilmaz",
            istihdam_baslangic: `${donem}-01`,
            istihdam_bitis: `${donem}-${String(lastDay).padStart(2, "0")}`,
            ucret_segment_sayisi: 2,
            puantaj_kayit_sayisi: 1,
            finans_kalem_sayisi: 0,
            hazir_mi: true,
            blocker_count: 0,
            warning_count: 0
          },
          {
            personel_id: 8,
            ad_soyad: "Ayse Demir",
            istihdam_baslangic: `${donem}-01`,
            istihdam_bitis: `${donem}-${String(lastDay).padStart(2, "0")}`,
            ucret_segment_sayisi: 1,
            puantaj_kayit_sayisi: 1,
            finans_kalem_sayisi: 0,
            hazir_mi: true,
            blocker_count: 0,
            warning_count: 0
          }
        ]
      : [],
    source_summary: {
      personel_sayisi: sealed ? 2 : 0,
      ucret_segment_sayisi: sealed ? 3 : 0,
      puantaj_kayit_sayisi: sealed ? 2 : 0,
      izin_kayit_sayisi: 0,
      etki_aday_sayisi: 0,
      finans_kalem_sayisi: 0,
      mevzuat_parametre_sayisi: 0
    },
    existing_snapshot: existing
      ? {
          id: existing.id,
          state: existing.state,
          revision_no: existing.revision_no,
          source_hash: existing.source_hash,
          snapshot_hash: existing.snapshot_hash,
          created_at: existing.created_at,
          source_changed: existing.source_hash !== sourceHash
        }
      : null,
    preflight_hash: preflightHash,
    source_hash: sourceHash,
    hashes: { source_hash: sourceHash },
    schema_version: "S77_C_SNAPSHOT_V1",
    contract_version: "S77_C_SNAPSHOT_V1",
    generated_at: new Date().toISOString()
  };
}

const DEMO_USER_ROLES: readonly UserRole[] = [
  "GENEL_YONETICI",
  "BOLUM_YONETICISI",
  "MUHASEBE",
  "BIRIM_AMIRI"
];

function isDemoUserRole(value: string): value is UserRole {
  return (DEMO_USER_ROLES as readonly string[]).includes(value);
}

function readDemoRequestHeader(init: RequestInit | undefined, name: string): string | undefined {
  const headers = new Headers(init?.headers ?? {});
  const direct = headers.get(name);
  if (direct) {
    return direct.trim() || undefined;
  }

  return headers.get(name.toLowerCase())?.trim() || undefined;
}

function resolveDemoDepartmanIdsForSubeler(subeIds: readonly number[]): number[] {
  const departmanIds = new Set<number>();
  for (const subeId of subeIds) {
    const sube = demoState.subeler.find((item) => item.id === subeId);
    for (const departmanId of sube?.departman_ids ?? []) {
      departmanIds.add(departmanId);
    }
  }

  return [...departmanIds];
}

function resolveDemoRequestSubeScope(init: RequestInit | undefined, requestUrl: URL): number | null {
  const querySubeId = toNumber(requestUrl.searchParams.get("sube_id"));
  const headerSubeId = toNumber(readDemoRequestHeader(init, "X-Active-Sube-Id"));
  return querySubeId ?? headerSubeId;
}

function demoFinansItemMatchesScope(
  personelId: number,
  subeScope: number | null,
  allowedSubeIds: readonly number[]
): boolean {
  const linkedPersonel = demoState.personeller.find((personel) => personel.id === personelId);
  if (!linkedPersonel) {
    return allowedSubeIds.length === 0 && subeScope === null;
  }

  if (subeScope !== null) {
    return linkedPersonel.sube_id === subeScope;
  }

  if (allowedSubeIds.length > 0) {
    return typeof linkedPersonel.sube_id === "number" && allowedSubeIds.includes(linkedPersonel.sube_id);
  }

  return true;
}

function readDemoApiActor(init?: RequestInit): RevizyonActorContext {
  const roleHeader = readDemoRequestHeader(init, "X-Demo-Role");
  if (roleHeader && isDemoUserRole(roleHeader)) {
    return readDemoRevizyonActor(init);
  }

  if (typeof window !== "undefined") {
    try {
      const raw =
        window.sessionStorage.getItem("medisa_auth_session") ??
        window.localStorage.getItem("medisa_auth_session");
      if (raw) {
        const session = JSON.parse(raw) as {
          user?: { id?: number; rol?: string; sube_ids?: number[]; personel_id?: number | null };
        };
        const role = session.user?.rol;
        if (role && isDemoUserRole(role)) {
          const subeIds = Array.isArray(session.user?.sube_ids)
            ? session.user.sube_ids.filter((id): id is number => typeof id === "number" && id > 0)
            : role === "BIRIM_AMIRI"
              ? [1]
              : role === "MUHASEBE"
                ? [1, 2]
                : role === "BOLUM_YONETICISI"
                  ? [2]
                  : [];
          return {
            userId: session.user?.id ?? 1,
            role,
            subeIds,
            departmanIds: resolveDemoDepartmanIdsForSubeler(subeIds),
            linkedPersonelId: session.user?.personel_id ?? (role === "BIRIM_AMIRI" ? 1 : null)
          };
        }
      }
    } catch {
      // ignore invalid demo session payloads
    }
  }

  return readDemoRevizyonActor(init);
}

function readDemoRevizyonActor(init?: RequestInit): RevizyonActorContext {
  const roleHeader = readDemoRequestHeader(init, "X-Demo-Role");
  const userIdRaw = readDemoRequestHeader(init, "X-Demo-User-Id");
  const parsedUserId = userIdRaw ? Number.parseInt(userIdRaw, 10) : Number.NaN;
  const userId = Number.isFinite(parsedUserId) && parsedUserId > 0 ? parsedUserId : 1;
  const user = demoState.yonetimKullanicilari.find((item) => item.id === userId);
  const roleFromUser = user?.rol ?? "GENEL_YONETICI";
  const role = roleHeader && isDemoUserRole(roleHeader) ? roleHeader : roleFromUser;
  const subeIds =
    user?.sube_ids ??
    (role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [2] : []);
  const linkedPersonelId = user?.personel_id ?? (role === "BIRIM_AMIRI" ? 1 : null);

  return {
    userId: user?.id ?? userId,
    role,
    subeIds,
    departmanIds: resolveDemoDepartmanIdsForSubeler(subeIds),
    linkedPersonelId
  };
}

function enforceDemoRevizyonPermission(
  actor: RevizyonActorContext,
  permission: AppPermission,
  errorCode: string,
  message: string
): ApiResponse<unknown> | null {
  if (!hasRolePermission(actor.role, permission)) {
    return demoRevizyonError(errorCode, message);
  }

  return null;
}

function enforceDemoPermission(
  actor: RevizyonActorContext,
  permission: AppPermission,
  message = "Bu islem icin yetkiniz yok."
): ApiResponse<unknown> | null {
  return enforceDemoRevizyonPermission(actor, permission, "FORBIDDEN", message);
}

function enforceDemoAnyPermission(
  actor: RevizyonActorContext,
  permissions: readonly AppPermission[],
  message = "Bu islem icin yetkiniz yok."
): ApiResponse<unknown> | null {
  if (permissions.some((permission) => hasRolePermission(actor.role, permission))) {
    return null;
  }

  return demoRevizyonError("FORBIDDEN", message);
}

const DEMO_BILDIRIM_ALLOWED_TURLER = [
  "GELMEDI",
  "GEC_GELDI",
  "ERKEN_CIKTI",
  "IZINLI",
  "RAPORLU",
  "GOREVDE",
  "DIGER"
] as const;

const DEMO_BILDIRIM_EDITABLE_STATES = ["TASLAK", "DUZELTME_ISTENDI"] as const;

const DEMO_BILDIRIM_LEGACY_TUR_MAP: Record<string, string> = {
  DEVAMSIZLIK: "GELMEDI",
  IZINLI_GELMEDI: "IZINLI",
  IZINSIZ_GELMEDI: "GELMEDI",
  GEC_CIKTI: "ERKEN_CIKTI"
};

function normalizeDemoBildirimTuru(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }

  const upper = value.toUpperCase();
  const mapped = DEMO_BILDIRIM_LEGACY_TUR_MAP[upper] ?? upper;
  return DEMO_BILDIRIM_ALLOWED_TURLER.includes(mapped as (typeof DEMO_BILDIRIM_ALLOWED_TURLER)[number])
    ? mapped
    : null;
}

function demoBildirimConflict(message: string): ApiResponse<unknown> {
  return demoRevizyonError("CONFLICT", message);
}

function assertDemoBildirimOwnership(
  actor: RevizyonActorContext,
  bildirim: DemoBildirim
): ApiResponse<unknown> | null {
  const createdBy = bildirim.created_by ?? 0;
  if (createdBy <= 0 || createdBy !== actor.userId) {
    return demoRevizyonError("FORBIDDEN", "Bu islem icin yetkiniz yok.");
  }

  return null;
}

function assertDemoBildirimEditableState(bildirim: DemoBildirim): ApiResponse<unknown> | null {
  const state = (bildirim.state ?? "").toUpperCase();
  if (["GONDERILDI", "HAFTALIK_MUTABAKATA_ALINDI", "IPTAL"].includes(state)) {
    return demoBildirimConflict("Bu durumdaki bildirim guncellenemez.");
  }
  if (!(DEMO_BILDIRIM_EDITABLE_STATES as readonly string[]).includes(state)) {
    return demoBildirimConflict("Bu durumdaki bildirim guncellenemez.");
  }

  return null;
}

function resolveDemoBildirimSubeId(personelId: number | null | undefined): number | undefined {
  if (personelId === null || personelId === undefined) {
    return undefined;
  }

  const personel = demoState.personeller.find((item) => item.id === personelId);
  return typeof personel?.sube_id === "number" ? personel.sube_id : undefined;
}

function assertDemoAylikWriteSubeScope(
  actor: RevizyonActorContext,
  subeId: number | null
): ApiResponse<unknown> | null {
  if (actor.subeIds.length > 0 && (subeId === null || subeId <= 0)) {
    return demoRevizyonError("VALIDATION_ERROR", "Sube secimi zorunludur.");
  }

  return null;
}

function assertDemoAylikSubeAccess(
  actor: RevizyonActorContext,
  subeId: number | null
): ApiResponse<unknown> | null {
  if (
    subeId !== null &&
    subeId > 0 &&
    actor.subeIds.length > 0 &&
    !actor.subeIds.includes(subeId)
  ) {
    return demoRevizyonError("FORBIDDEN", "Bu islem icin yetkiniz yok.");
  }

  return null;
}

function hasDemoPendingBolumOnay(
  ay: string,
  subeId: number | null,
  departmanId: number | null
): boolean {
  return demoState.personeller.some((personel) => {
    if (personel.aktif_durum !== "AKTIF") {
      return false;
    }
    if (subeId !== null && personel.sube_id !== subeId) {
      return false;
    }
    if (departmanId !== null && personel.departman_id !== departmanId) {
      return false;
    }

    const durum = ensureAylikDurum(ay, personel.id);
    return durum.kapanis_durumu !== "KAPANDI" && durum.bolum_onay_durumu === "BOLUM_ONAYINDA";
  });
}

function isDemoAmirKontrolOnlyPayload(body: Record<string, unknown>): boolean {
  const keys = Object.keys(body);
  return keys.length === 1 && keys[0] === "kontrol_durumu" && body.kontrol_durumu === "AMIR_KONTROL_ETTI";
}

function enforceDemoPuantajUpsertPermission(
  actor: RevizyonActorContext,
  body: Record<string, unknown>
): ApiResponse<unknown> | null {
  if (isDemoAmirKontrolOnlyPayload(body)) {
    return enforceDemoAnyPermission(actor, ["puantaj.amir_kontrol", "puantaj.update"]);
  }

  return enforceDemoPermission(actor, "puantaj.update");
}

function findDemoPersonelDepartmanId(personelId: number): number | null {
  const personel = demoState.personeller.find((item) => item.id === personelId);
  return personel?.departman_id ?? null;
}

function presentDemoRevizyonTalep(actor: RevizyonActorContext, talep: RevizyonTalebi): RevizyonTalebi {
  return maskRevizyonFinanceFields(actor, talep);
}

function isDemoRevizyonTipi(value: unknown): value is RevizyonTipi {
  return typeof value === "string" && (REVIZYON_TIPLERI as readonly string[]).includes(value);
}

function findDemoClosedKapanis(haftaBaslangic: string, haftaBitis: string): HaftalikKapanisSonuc | null {
  for (const kapanis of Object.values(demoState.kapanisById)) {
    if (kapanis.hafta_baslangic === haftaBaslangic && kapanis.hafta_bitis === haftaBitis) {
      return kapanis;
    }
  }

  return null;
}

/** Test helper: seeds TAMAMLANDI mutabakat required before POST /haftalik-kapanis. */
export function seedDemoHaftalikMutabakatForClose(params: {
  haftaBaslangic: string;
  haftaBitis: string;
  subeId?: number;
  birimAmiriUserId?: number;
}): void {
  const subeId = params.subeId ?? 1;
  const birimAmiriUserId = params.birimAmiriUserId ?? 1;
  const exists = demoState.haftalikBildirimMutabakatlari.some(
    (item) => item.sube_id === subeId && item.hafta_baslangic === params.haftaBaslangic
  );
  if (exists) {
    return;
  }
  demoState.haftalikBildirimMutabakatlari.push({
    id: ++demoState.nextIds.haftalikBildirimMutabakat,
    sube_id: subeId,
    birim_amiri_user_id: birimAmiriUserId,
    hafta_baslangic: params.haftaBaslangic,
    hafta_bitis: params.haftaBitis,
    state: "TAMAMLANDI",
    onaylayan_user_id: birimAmiriUserId,
    onaylandi_at: new Date().toISOString(),
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString()
  });
}

function resolveDemoKapanisSubeId(actor: RevizyonActorContext, init?: RequestInit): number | null {
  const headerRaw = readDemoRequestHeader(init, "X-Active-Sube-Id");
  const headerSube = headerRaw ? Number.parseInt(headerRaw, 10) : Number.NaN;
  if (Number.isFinite(headerSube) && headerSube > 0) {
    if (actor.subeIds.length > 0 && !actor.subeIds.includes(headerSube)) {
      return null;
    }
    return headerSube;
  }
  if (actor.subeIds.length === 1) {
    return actor.subeIds[0];
  }
  if (actor.subeIds.length === 0) {
    return 1;
  }
  return null;
}

function resolveDemoHaftaPair(
  baslangicRaw: unknown,
  bitisRaw: unknown
): { start: string; end: string } | ApiResponse<unknown> {
  const baslangic =
    typeof baslangicRaw === "string" && baslangicRaw.trim() !== ""
      ? baslangicRaw.trim()
      : "2026-04-06";
  if (!/^\d{4}-\d{2}-\d{2}$/.test(baslangic)) {
    return demoRevizyonError("VALIDATION_ERROR", "Hafta baslangici YYYY-MM-DD formatinda olmalidir.");
  }
  const startDate = new Date(`${baslangic}T12:00:00`);
  if (Number.isNaN(startDate.getTime())) {
    return demoRevizyonError("VALIDATION_ERROR", "Hafta baslangici YYYY-MM-DD formatinda olmalidir.");
  }
  const endDate = new Date(startDate);
  endDate.setDate(startDate.getDate() + 6);
  const expectedEnd = [
    endDate.getFullYear(),
    String(endDate.getMonth() + 1).padStart(2, "0"),
    String(endDate.getDate()).padStart(2, "0")
  ].join("-");
  const bitis = typeof bitisRaw === "string" ? bitisRaw.trim() : "";
  if (bitis !== "" && bitis !== expectedEnd) {
    return demoRevizyonError(
      "VALIDATION_ERROR",
      `hafta_bitis hafta_baslangic + 6 gun olmalidir (${expectedEnd}).`
    );
  }
  return { start: baslangic, end: expectedEnd };
}

function hasDemoOpenRevizyonTalebi(params: {
  personel_id: number;
  kaynak_tipi: string;
  kaynak_id: number;
  etkilenen_tarih: string;
}): boolean {
  return Object.values(demoState.revizyonTalebiById).some(
    (talep) =>
      (talep.durum === "TASLAK" || talep.durum === "ONAY_BEKLIYOR") &&
      talep.personel_id === params.personel_id &&
      talep.kaynak_tipi === params.kaynak_tipi &&
      talep.kaynak_id === params.kaynak_id &&
      talep.etkilenen_tarih === params.etkilenen_tarih
  );
}

function demoCorrectionError(code: string, message: string): ApiResponse<unknown> {
  return {
    data: null,
    meta: {},
    errors: [{ code, message }]
  };
}

function findDemoRevizyonTalebi(id: number): RevizyonTalebi | null {
  return demoState.revizyonTalebiById[id] ?? null;
}

function findDemoRevizyonCorrection(id: number): RevizyonCorrectionEvent | null {
  return demoState.revizyonCorrectionById[id] ?? null;
}

function findCorrectionByRevizyonTalebiId(talepId: number): RevizyonCorrectionEvent | null {
  for (const correction of Object.values(demoState.revizyonCorrectionById)) {
    if (correction.revizyon_talebi_id === talepId) {
      return correction;
    }
  }

  return null;
}

function findDemoSnapshotRefForTalep(talep: RevizyonTalebi): string | null {
  const kapanis = findDemoClosedKapanis(talep.hafta_baslangic, talep.hafta_bitis);
  if (!kapanis) {
    return null;
  }

  const satir = kapanis.snapshot_satirlari?.find((row) => row.personel_id === talep.personel_id);
  if (satir?.snapshot_id === undefined) {
    return null;
  }

  return `snapshot:${satir.snapshot_id}`;
}

function presentDemoRevizyonCorrection(
  actor: RevizyonActorContext,
  correction: RevizyonCorrectionEvent
): RevizyonCorrectionEvent {
  const talep = findDemoRevizyonTalebi(correction.revizyon_talebi_id);
  if (!talep) {
    return correction;
  }

  const personelDepartmanId = findDemoPersonelDepartmanId(talep.personel_id);
  return maskCorrectionFinanceFields(actor, correction, talep, personelDepartmanId);
}

function persistDemoCorrectionForTalep(
  talep: RevizyonTalebi,
  actor: RevizyonActorContext,
  nowIso: string
): ApiResponse<RevizyonCorrectionEvent> | ApiResponse<unknown> {
  const produceError = getProduceCorrectionError(talep);
  if (produceError) {
    return demoCorrectionError(produceError, "Revizyon correction uretilemedi.");
  }

  if (talep.correction_event_id != null || findCorrectionByRevizyonTalebiId(talep.id)) {
    return demoCorrectionError(
      "CORRECTION_ALREADY_EXISTS",
      "Bu revizyon talebi icin correction zaten mevcut."
    );
  }

  const id = ++demoState.nextIds.revizyonCorrection;
  const buildResult = buildCorrectionFromRevizyonTalebi({
    talep,
    id,
    actorUserId: actor.userId,
    nowIso,
    snapshotRef: findDemoSnapshotRefForTalep(talep)
  });

  if (!("id" in buildResult)) {
    return demoCorrectionError(buildResult.code, "Revizyon correction uretilemedi.");
  }

  demoState.revizyonCorrectionById[id] = buildResult;
  talep.correction_event_id = id;
  demoState.revizyonTalebiById[talep.id] = talep;

  return ok(presentDemoRevizyonCorrection(actor, buildResult));
}

function approveDemoRevizyonTalebi(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  kararNotu: string | null
): ApiResponse<unknown> {
  // S79-E: onay correction uretmez; correction_event_id null kalir (S79-F).
  return applyDemoRevizyonTransition(actor, talep, "ONAYLANDI", {
    karar_veren_kullanici_id: actor.userId,
    karar_notu: kararNotu
  });
}

function applyDemoRevizyonTransition(
  actor: RevizyonActorContext,
  talep: RevizyonTalebi,
  nextDurum: RevizyonTalebi["durum"],
  karar?: { karar_veren_kullanici_id?: number; karar_notu?: string | null }
): ApiResponse<unknown> {
  const transition = assertRevizyonTransition(talep.durum, nextDurum);
  if (!transition.ok) {
    return demoRevizyonError("STATE_CONFLICT", "Gecersiz revizyon durum gecisi.");
  }

  talep.durum = nextDurum;

  if (karar !== undefined) {
    talep.karar_veren_kullanici_id = karar.karar_veren_kullanici_id ?? actor.userId;
    talep.karar_zamani = new Date().toISOString();
    talep.karar_notu = karar.karar_notu ?? null;
  }

  if (nextDurum === "ONAYLANDI") {
    talep.correction_event_id = null;
  }

  demoState.revizyonTalebiById[talep.id] = talep;
  return ok(presentDemoRevizyonTalep(actor, talep));
}

function buildDemoRevizyonTalebiListResponse(
  searchUrl: URL,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.view",
    "UNAUTHORIZED_REVISION_REQUEST",
    "Revizyon taleplerini goruntuleme yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  const personelId = toNumber(searchUrl.searchParams.get("personel_id"));
  const durum = toStringValue(searchUrl.searchParams.get("durum"));
  const haftaBaslangic = toStringValue(searchUrl.searchParams.get("hafta_baslangic"));
  const haftaBitis = toStringValue(searchUrl.searchParams.get("hafta_bitis"));

  const items = Object.values(demoState.revizyonTalebiById)
    .filter((talep) => {
      if (personelId !== null && talep.personel_id !== personelId) {
        return false;
      }
      if (durum && talep.durum !== durum) {
        return false;
      }
      if (haftaBaslangic && talep.hafta_baslangic !== haftaBaslangic) {
        return false;
      }
      if (haftaBitis && talep.hafta_bitis !== haftaBitis) {
        return false;
      }
      return true;
    })
    .filter((talep) =>
      canViewRevizyonTalep(actor, talep, findDemoPersonelDepartmanId(talep.personel_id))
    )
    .map((talep) => presentDemoRevizyonTalep(actor, talep));

  return ok({ items });
}

function buildDemoRevizyonTalebiDetailResponse(
  talepId: number,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.view",
    "UNAUTHORIZED_REVISION_REQUEST",
    "Revizyon talebi goruntuleme yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  const talep = findDemoRevizyonTalebi(talepId);
  if (!talep) {
    return demoRevizyonError("NOT_FOUND", "Revizyon talebi bulunamadi.");
  }

  const personelDepartmanId = findDemoPersonelDepartmanId(talep.personel_id);
  if (!canViewRevizyonTalep(actor, talep, personelDepartmanId)) {
    return demoRevizyonError("REVISION_SCOPE_DENIED", "Revizyon talebi kapsam disi.");
  }

  return ok(presentDemoRevizyonTalep(actor, talep));
}

function createDemoRevizyonTalebi(
  body: Record<string, unknown>,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.create",
    "UNAUTHORIZED_REVISION_REQUEST",
    "Revizyon talebi olusturma yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  const personel_id = toNumber(body.personel_id);
  const kaynak_id = toNumber(body.kaynak_id);
  const hafta_baslangic = toStringValue(body.hafta_baslangic);
  const hafta_bitis = toStringValue(body.hafta_bitis);
  const etkilenen_tarih = toStringValue(body.etkilenen_tarih);
  const kaynak_tipi = toStringValue(body.kaynak_tipi);
  const revizyon_tipi = body.revizyon_tipi;
  const gerekce = toStringValue(body.gerekce);

  if (
    personel_id === null ||
    personel_id < 1 ||
    kaynak_id === null ||
    kaynak_id < 1 ||
    !hafta_baslangic ||
    !hafta_bitis ||
    !etkilenen_tarih ||
    !kaynak_tipi ||
    !isDemoRevizyonTipi(revizyon_tipi) ||
    !gerekce
  ) {
    return demoRevizyonError("INVALID_BODY", "Revizyon talebi payload gecersiz.");
  }

  const serverOwnedFields = [
    "id",
    "sube_id",
    "kapanis_id",
    "snapshot_id",
    "durum",
    "talep_eden_kullanici_id",
    "talep_eden_rol",
    "talep_zamani",
    "karar_veren_kullanici_id",
    "karar_zamani",
    "karar_aciklamasi",
    "karar_notu",
    "correction_event_id",
    "created_at",
    "updated_at",
    "acik_talep_slot"
  ] as const;
  for (const field of serverOwnedFields) {
    if (Object.prototype.hasOwnProperty.call(body, field)) {
      return demoRevizyonError("VALIDATION_ERROR", `${field} istemci tarafindan belirlenemez.`);
    }
  }

  const personelDepartmanId = findDemoPersonelDepartmanId(personel_id);
  const scopeDecision = canCreateRevizyonForPersonel(actor, personel_id, personelDepartmanId, {
    bordro_etki_var_mi: body.bordro_etki_var_mi === true,
    bordro_etki_notu: toStringValue(body.bordro_etki_notu) ?? null
  });
  if (!scopeDecision.ok) {
    return demoRevizyonError(scopeDecision.code, "Revizyon talebi kapsam disi.");
  }

  if (!findDemoClosedKapanis(hafta_baslangic, hafta_bitis)) {
    return demoRevizyonError("PERIOD_NOT_CLOSED", "Revizyon talebi yalniz kapali donem icin acilabilir.");
  }

  if (
    hasDemoOpenRevizyonTalebi({
      personel_id,
      kaynak_tipi,
      kaynak_id,
      etkilenen_tarih
    })
  ) {
    return demoRevizyonError(
      "ALREADY_EXISTS",
      "Ayni kaynak icin acik revizyon talebi zaten mevcut."
    );
  }

  const id = ++demoState.nextIds.revizyonTalebi;
  const talep: RevizyonTalebi = {
    id,
    personel_id,
    hafta_baslangic,
    hafta_bitis,
    etkilenen_tarih,
    kaynak_tipi,
    kaynak_id,
    revizyon_tipi,
    onceki_deger:
      body.onceki_deger === undefined
        ? null
        : (body.onceki_deger as RevizyonTalebi["onceki_deger"]),
    talep_edilen_deger:
      body.talep_edilen_deger === undefined
        ? null
        : (body.talep_edilen_deger as RevizyonTalebi["talep_edilen_deger"]),
    gerekce,
    talep_eden_kullanici_id: actor.userId,
    talep_zamani: new Date().toISOString(),
    durum: "TASLAK",
    karar_veren_kullanici_id: null,
    karar_zamani: null,
    karar_notu: null,
    bordro_etki_var_mi: body.bordro_etki_var_mi === true,
    bordro_etki_notu: toStringValue(body.bordro_etki_notu) ?? null,
    correction_event_id: null
  };

  demoState.revizyonTalebiById[id] = talep;
  return ok(presentDemoRevizyonTalep(actor, talep));
}

function handleDemoRevizyonAction(
  talepId: number,
  action: "gonder" | "onay" | "red" | "iptal",
  body: Record<string, unknown>,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const talep = findDemoRevizyonTalebi(talepId);
  if (!talep) {
    return demoRevizyonError("NOT_FOUND", "Revizyon talebi bulunamadi.");
  }

  const personelDepartmanId = findDemoPersonelDepartmanId(talep.personel_id);
  const kararNotu = toStringValue(body.karar_notu) ?? null;

  if (action === "gonder") {
    const permissionError = enforceDemoRevizyonPermission(
      actor,
      "revizyon.submit",
      "UNAUTHORIZED_REVISION_REQUEST",
      "Revizyon talebi gonderme yetkisi yok."
    );
    if (permissionError) {
      return permissionError;
    }

    if (!canSubmitRevizyon(actor, talep, personelDepartmanId)) {
      if (talep.talep_eden_kullanici_id !== actor.userId && actor.role !== "GENEL_YONETICI") {
        return demoRevizyonError("REVISION_OWNER_DENIED", "Bu revizyon talebi size ait degil.");
      }
      return demoRevizyonError("REVISION_SCOPE_DENIED", "Revizyon talebi gonderilemez.");
    }

    return applyDemoRevizyonTransition(actor, talep, "ONAY_BEKLIYOR");
  }

  if (action === "onay" || action === "red") {
    const permission = action === "onay" ? "revizyon.approve" : "revizyon.reject";
    const permissionError = enforceDemoRevizyonPermission(
      actor,
      permission,
      "UNAUTHORIZED_REVISION_APPROVAL",
      "Revizyon talebi onay/red yetkisi yok."
    );
    if (permissionError) {
      return permissionError;
    }

    if (!canApproveOrRejectRevizyon(actor)) {
      return demoRevizyonError(
        "UNAUTHORIZED_REVISION_APPROVAL",
        "Revizyon talebi onay/red yetkisi yok."
      );
    }

    if (action === "onay") {
      return approveDemoRevizyonTalebi(actor, talep, kararNotu);
    }

    if (!kararNotu || !kararNotu.trim()) {
      return demoRevizyonError("VALIDATION_ERROR", "Red aciklamasi zorunludur.");
    }

    return applyDemoRevizyonTransition(
      actor,
      talep,
      "REDDEDILDI",
      {
        karar_veren_kullanici_id: actor.userId,
        karar_notu: kararNotu
      }
    );
  }

  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.cancel",
    "UNAUTHORIZED_REVISION_REQUEST",
    "Revizyon talebi iptal yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  if (!canCancelRevizyon(actor, talep, personelDepartmanId)) {
    if (talep.talep_eden_kullanici_id !== actor.userId && actor.role !== "GENEL_YONETICI") {
      return demoRevizyonError("REVISION_OWNER_DENIED", "Bu revizyon talebi size ait degil.");
    }
    return demoRevizyonError("REVISION_SCOPE_DENIED", "Revizyon talebi iptal edilemez.");
  }

  return applyDemoRevizyonTransition(actor, talep, "IPTAL", {
    karar_veren_kullanici_id: actor.userId,
    karar_notu: kararNotu
  });
}

function buildDemoRevizyonCorrectionListResponse(
  searchUrl: URL,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.view",
    "UNAUTHORIZED_REVISION_REQUEST",
    "Revizyon correction listesi goruntuleme yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  const revizyonTalebiId = toNumber(searchUrl.searchParams.get("revizyon_talebi_id"));
  const personelId = toNumber(searchUrl.searchParams.get("personel_id"));
  const haftaBaslangic = toStringValue(searchUrl.searchParams.get("hafta_baslangic"));
  const haftaBitis = toStringValue(searchUrl.searchParams.get("hafta_bitis"));

  const items = Object.values(demoState.revizyonCorrectionById)
    .filter((correction) => {
      if (revizyonTalebiId !== null && correction.revizyon_talebi_id !== revizyonTalebiId) {
        return false;
      }
      if (personelId !== null && correction.personel_id !== personelId) {
        return false;
      }
      if (haftaBaslangic && correction.hafta_baslangic !== haftaBaslangic) {
        return false;
      }
      if (haftaBitis && correction.hafta_bitis !== haftaBitis) {
        return false;
      }
      return true;
    })
    .filter((correction) => {
      const talep = findDemoRevizyonTalebi(correction.revizyon_talebi_id);
      if (!talep) {
        return false;
      }

      return canViewRevizyonCorrection(
        actor,
        talep,
        findDemoPersonelDepartmanId(talep.personel_id)
      );
    })
    .map((correction) => presentDemoRevizyonCorrection(actor, correction));

  return ok({ items });
}

function buildDemoRevizyonCorrectionDetailResponse(
  correctionId: number,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.view",
    "UNAUTHORIZED_REVISION_REQUEST",
    "Revizyon correction goruntuleme yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  const correction = findDemoRevizyonCorrection(correctionId);
  if (!correction) {
    return demoCorrectionError("CORRECTION_NOT_FOUND", "Revizyon correction bulunamadi.");
  }

  const talep = findDemoRevizyonTalebi(correction.revizyon_talebi_id);
  if (!talep) {
    return demoCorrectionError("CORRECTION_TARGET_NOT_FOUND", "Revizyon talebi bulunamadi.");
  }

  const personelDepartmanId = findDemoPersonelDepartmanId(talep.personel_id);
  if (!canViewRevizyonCorrection(actor, talep, personelDepartmanId)) {
    return demoCorrectionError("CORRECTION_SCOPE_DENIED", "Revizyon correction kapsam disi.");
  }

  return ok(presentDemoRevizyonCorrection(actor, correction));
}

function produceDemoRevizyonCorrection(
  talepId: number,
  actor: RevizyonActorContext
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.approve",
    "UNAUTHORIZED_REVISION_APPROVAL",
    "Revizyon correction uretme yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  if (!canApproveOrRejectRevizyon(actor)) {
    return demoCorrectionError(
      "CORRECTION_SCOPE_DENIED",
      "Revizyon correction uretme yetkisi yok."
    );
  }

  const talep = findDemoRevizyonTalebi(talepId);
  if (!talep) {
    return demoCorrectionError("CORRECTION_TARGET_NOT_FOUND", "Revizyon talebi bulunamadi.");
  }

  const nowIso = new Date().toISOString();
  return persistDemoCorrectionForTalep(talep, actor, nowIso);
}

function cancelDemoRevizyonCorrection(
  correctionId: number,
  actor: RevizyonActorContext,
  body: Record<string, unknown>
): ApiResponse<unknown> {
  const permissionError = enforceDemoRevizyonPermission(
    actor,
    "revizyon.approve",
    "UNAUTHORIZED_REVISION_APPROVAL",
    "Revizyon correction iptal yetkisi yok."
  );
  if (permissionError) {
    return permissionError;
  }

  if (!canApproveOrRejectRevizyon(actor)) {
    return demoCorrectionError(
      "CORRECTION_SCOPE_DENIED",
      "Revizyon correction iptal yetkisi yok."
    );
  }

  const correction = findDemoRevizyonCorrection(correctionId);
  if (!correction) {
    return demoCorrectionError("CORRECTION_NOT_FOUND", "Revizyon correction bulunamadi.");
  }

  const cancelError = getCancelCorrectionError(correction);
  if (cancelError) {
    return demoCorrectionError(cancelError, "Revizyon correction iptal edilemedi.");
  }

  const talep = findDemoRevizyonTalebi(correction.revizyon_talebi_id);
  if (!talep) {
    return demoCorrectionError("CORRECTION_TARGET_NOT_FOUND", "Revizyon talebi bulunamadi.");
  }

  correction.iptal_edildi_mi = true;
  correction.iptal_zamani = new Date().toISOString();
  correction.iptal_eden_kullanici_id = actor.userId;

  const iptalAciklama = toStringValue(body.aciklama);
  if (iptalAciklama) {
    correction.aciklama = iptalAciklama;
  }

  demoState.revizyonCorrectionById[correctionId] = correction;

  return ok(presentDemoRevizyonCorrection(actor, correction));
}

function isDemoOdemeTipi(value: unknown): value is OdemeTipi {
  return value === "KARAR_BEKLIYOR" || value === "UCRET" || value === "SERBEST_ZAMAN";
}

function guvenliFazlaCalismaDakika(value: number | undefined): number {
  if (value === undefined || !Number.isFinite(value) || value < 0) {
    return 0;
  }

  return value;
}

function findDemoSnapshotSatir(snapshotId: number): HaftalikKapanisSnapshotSatir | null {
  for (const kapanis of Object.values(demoState.kapanisById)) {
    for (const satir of kapanis.snapshot_satirlari ?? []) {
      if (satir.snapshot_id === snapshotId) {
        return satir;
      }
    }
  }

  return null;
}

function buildSyntheticOdemeTercihi(satir: HaftalikKapanisSnapshotSatir): FazlaCalismaOdemeTercihi {
  const snapshot_id = satir.snapshot_id;
  const kapanis_id = satir.kapanis_id;

  if (snapshot_id === undefined || kapanis_id === undefined) {
    throw new Error("Snapshot satiri snapshot_id veya kapanis_id icermiyor.");
  }

  return {
    snapshot_id,
    kapanis_id,
    personel_id: satir.personel_id,
    hafta_baslangic: satir.hafta_baslangic,
    hafta_bitis: satir.hafta_bitis,
    fazla_calisma_dakika: guvenliFazlaCalismaDakika(satir.fazla_calisma_dakika),
    odeme_tipi: DEFAULT_ODEME_TIPI,
    id: undefined,
    secim_zamani: undefined,
    secen_kullanici_id: undefined,
    onceki_odeme_tipi: undefined,
    gerekce: undefined
  };
}

function demoOdemeTercihiNotFound(snapshotId: number): ApiResponse<unknown> {
  return {
    data: null,
    meta: {},
    errors: [
      {
        code: "NOT_FOUND",
        message: `snapshot_id ${snapshotId} icin odeme tercihi veya kapanis satiri bulunamadi.`
      }
    ]
  };
}

const FCOT_SERVER_OWNED_FIELDS = [
  "id",
  "kapanis_id",
  "personel_id",
  "hafta_baslangic",
  "hafta_bitis",
  "fazla_calisma_dakika",
  "secen_kullanici_id",
  "secim_zamani",
  "onceki_odeme_tipi",
  "created_at",
  "updated_at",
  "sube_id"
] as const;

function demoFcotValidationError(field: string, message: string): ApiResponse<unknown> {
  return {
    data: null,
    meta: {},
    errors: [{ code: "VALIDATION_ERROR", message, field }]
  };
}

function findDemoKapanisSubeIdForSnapshot(snapshotId: number): number | null {
  for (const kapanis of Object.values(demoState.kapanisById)) {
    for (const satir of kapanis.snapshot_satirlari ?? []) {
      if (satir.snapshot_id === snapshotId) {
        return (kapanis as HaftalikKapanisSonuc & { sube_id?: number }).sube_id ?? 1;
      }
    }
  }

  return null;
}

function monthsCoveredByDemoWeek(haftaBaslangic: string, haftaBitis: string): Array<{ yil: number; ay: number }> {
  const start = new Date(`${haftaBaslangic}T00:00:00Z`);
  const end = new Date(`${haftaBitis}T00:00:00Z`);
  if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime()) || end < start) {
    return [];
  }

  const seen = new Map<string, { yil: number; ay: number }>();
  const cursor = new Date(start);
  while (cursor <= end) {
    const yil = cursor.getUTCFullYear();
    const ay = cursor.getUTCMonth() + 1;
    seen.set(`${yil}-${ay}`, { yil, ay });
    cursor.setUTCDate(cursor.getUTCDate() + 1);
  }

  return Array.from(seen.values());
}

function assertDemoWeekPeriodsOpen(
  subeId: number,
  haftaBaslangic: string,
  haftaBitis: string
): ApiResponse<unknown> | null {
  const months = monthsCoveredByDemoWeek(haftaBaslangic, haftaBitis);
  if (months.length === 0) {
    return demoRevizyonError("PERIOD_STATE_UNKNOWN", "Puantaj donem durumu belirlenemedi.");
  }

  for (const month of months) {
    const key = `${subeId}|${month.yil}|${month.ay}`;
    if (demoState.sealedPuantajDonemKeys[key]) {
      return demoRevizyonError(
        "PERIOD_LOCKED",
        "Bu donem muhurlenmis, odeme tercihi guncellenemez."
      );
    }
  }

  return null;
}

function hasActiveDemoSerbestZamanOlusum(tercihId: number): boolean {
  return demoState.serbestZamanAktifOlusumByTercihId[tercihId] !== undefined;
}

function listDemoSerbestZamanEvents(): SerbestZamanEvent[] {
  return Object.values(demoState.serbestZamanEventsById).sort((a, b) => {
    const dateCmp = a.event_tarihi.localeCompare(b.event_tarihi);
    if (dateCmp !== 0) {
      return dateCmp;
    }
    return (a.id ?? 0) - (b.id ?? 0);
  });
}

function findDemoPersonelById(personelId: number): DemoPersonel | undefined {
  return demoState.personeller.find((item) => item.id === personelId);
}

function findDemoSerbestZamanByIslemAnahtari(
  personelId: number,
  islemAnahtari: string
): SerbestZamanEvent | null {
  for (const event of listDemoSerbestZamanEvents()) {
    if (
      event.personel_id === personelId &&
      "islem_anahtari" in event &&
      event.islem_anahtari === islemAnahtari
    ) {
      return event;
    }
  }
  return null;
}

function resolveDemoDonemMeta(
  subeId: number,
  eventTarihi: string
): { donem_yil: number; donem_ay: number; donem_kilitli_miydi: boolean } {
  const yil = Number.parseInt(eventTarihi.slice(0, 4), 10);
  const ay = Number.parseInt(eventTarihi.slice(5, 7), 10);
  const key = `${subeId}|${yil}|${ay}`;
  return {
    donem_yil: yil,
    donem_ay: ay,
    donem_kilitli_miydi: demoState.sealedPuantajDonemKeys[key] === true
  };
}

function assertDemoSzPersonelScope(
  actor: RevizyonActorContext,
  personel: DemoPersonel
): ApiResponse<unknown> | null {
  if (actor.subeIds.length === 0 && !hasRolePermission(actor.role, "personeller.view")) {
    return demoRevizyonError("FORBIDDEN", "Sube baglami olmadan serbest zaman erisilemez.");
  }
  return demoPersonelSubeScopeError(actor, personel);
}

function findDemoOdemeTercihiById(odemeTercihiId: number): FazlaCalismaOdemeTercihi | null {
  for (const tercih of Object.values(demoState.odemeTercihiBySnapshotId)) {
    if (tercih.id === odemeTercihiId) {
      return tercih;
    }
  }

  return null;
}

function resolvePersistedOdemeTercihi(params: {
  odeme_tercihi_id?: number;
  snapshot_id?: number;
}): FazlaCalismaOdemeTercihi | null {
  if (params.odeme_tercihi_id !== undefined) {
    return findDemoOdemeTercihiById(params.odeme_tercihi_id);
  }

  if (params.snapshot_id !== undefined) {
    return demoState.odemeTercihiBySnapshotId[params.snapshot_id] ?? null;
  }

  return null;
}

function demoSerbestZamanOlusumError(
  code: string,
  message: string
): ApiResponse<unknown> {
  return {
    data: null,
    meta: {},
    errors: [{ code, message }]
  };
}

const DEMO_UCRET_OVERLAP_MESAJI = "Ucret gecerlilik tarihleri mevcut kayitla cakisiyor.";
const DEMO_MEVZUAT_OVERLAP_MESAJI = "Mevzuat parametresi tarih araligi mevcut kayitla cakisiyor.";

function isDemoIsoDate(value: unknown): value is string {
  return typeof value === "string" && /^\d{4}-\d{2}-\d{2}$/.test(value);
}

function demoTodayIsoDate(): string {
  return new Date().toISOString().slice(0, 10);
}

function addDaysToIsoDate(value: string, days: number): string {
  const date = new Date(`${value}T00:00:00Z`);
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

function demoPersonelSubeScopeError(
  actor: RevizyonActorContext,
  personel: DemoPersonel
): ApiResponse<unknown> | null {
  if (
    actor.subeIds.length > 0 &&
    typeof personel.sube_id === "number" &&
    !actor.subeIds.includes(personel.sube_id)
  ) {
    return demoRevizyonError("FORBIDDEN", "Secili sube icin yetkiniz yok.");
  }
  return null;
}

type DemoUcretNormalizedBody = {
  ucret_tutari: number;
  ucret_turu: "BRUT" | "NET";
  para_birimi: string;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  aciklama: string | null;
};

function normalizeDemoUcretBody(
  source: Record<string, unknown>
): { error: ApiResponse<unknown> } | { value: DemoUcretNormalizedBody } {
  const tutar = toNumber(source.ucret_tutari);
  if (tutar === null || tutar <= 0) {
    return { error: demoRevizyonError("SALARY_AMOUNT_INVALID", "Ucret tutari sifirdan buyuk olmalidir.") };
  }

  const turu = (toStringValue(source.ucret_turu) ?? "").toUpperCase();
  if (turu !== "BRUT" && turu !== "NET") {
    return { error: demoRevizyonError("SALARY_TYPE_INVALID", "Ucret turu BRUT veya NET olmalidir.") };
  }

  const paraBirimi = (toStringValue(source.para_birimi) ?? "TRY").toUpperCase();
  if (!/^[A-Z]{3}$/.test(paraBirimi)) {
    return { error: demoRevizyonError("SALARY_CURRENCY_INVALID", "Para birimi uc harfli ISO kodu olmalidir.") };
  }

  const baslangic = source.gecerlilik_baslangic;
  if (!isDemoIsoDate(baslangic)) {
    return { error: demoRevizyonError("DATE_INVALID", "gecerlilik_baslangic gecerli bir tarih olmalidir.") };
  }

  const bitisRaw = source.gecerlilik_bitis;
  let bitis: string | null = null;
  if (bitisRaw !== null && bitisRaw !== undefined && bitisRaw !== "") {
    if (!isDemoIsoDate(bitisRaw)) {
      return { error: demoRevizyonError("DATE_INVALID", "gecerlilik_bitis gecerli bir tarih olmalidir.") };
    }
    bitis = bitisRaw;
  }

  if (bitis !== null && bitis < baslangic) {
    return { error: demoRevizyonError("DATE_RANGE_INVALID", "Bitis tarihi baslangic tarihinden once olamaz.") };
  }

  return {
    value: {
      ucret_tutari: tutar,
      ucret_turu: turu,
      para_birimi: paraBirimi,
      gecerlilik_baslangic: baslangic,
      gecerlilik_bitis: bitis,
      aciklama: toStringValue(source.aciklama) ?? null
    }
  };
}

/** Tarih dahil (inclusive) cakisma kontrolu: start <= yeniBitis && (bitis yok || bitis >= yeniBaslangic). */
function demoUcretHasOverlap(
  personelId: number,
  start: string,
  end: string | null,
  excludeId?: number
): boolean {
  const yeniBitis = end ?? "9999-12-31";
  return demoState.personelUcretleri.some(
    (item) =>
      item.personel_id === personelId &&
      item.state === "AKTIF" &&
      (excludeId === undefined || item.id !== excludeId) &&
      item.gecerlilik_baslangic <= yeniBitis &&
      (item.gecerlilik_bitis === null || item.gecerlilik_bitis >= start)
  );
}

function sortDemoUcretKayitlari(items: DemoPersonelUcretKaydi[]): DemoPersonelUcretKaydi[] {
  return [...items].sort((left, right) => {
    if (left.gecerlilik_baslangic !== right.gecerlilik_baslangic) {
      return left.gecerlilik_baslangic < right.gecerlilik_baslangic ? 1 : -1;
    }
    return right.id - left.id;
  });
}

function findDemoGuncelUcret(personelId: number, tarih: string): DemoPersonelUcretKaydi | null {
  const matches = sortDemoUcretKayitlari(
    demoState.personelUcretleri.filter(
      (item) =>
        item.personel_id === personelId &&
        item.state === "AKTIF" &&
        item.gecerlilik_baslangic <= tarih &&
        (item.gecerlilik_bitis === null || tarih <= item.gecerlilik_bitis)
    )
  );
  return matches[0] ?? null;
}

/** Backend gibi legacy personeller.maas_tutari alanini guncel ucretle senkron tutar. */
function syncDemoLegacyMaas(personelId: number): void {
  const personel = demoState.personeller.find((item) => item.id === personelId);
  if (!personel) {
    return;
  }
  if (!demoState.personelUcretleri.some((item) => item.personel_id === personelId)) {
    return;
  }
  const guncel = findDemoGuncelUcret(personelId, demoTodayIsoDate());
  personel.maas_tutari = guncel ? guncel.ucret_tutari : undefined;
}

type DemoMevzuatNormalizedBody = {
  parametre_kodu: string;
  deger_tipi: "SAYISAL" | "METIN";
  sayisal_deger: number | null;
  metin_deger: string | null;
  gecerlilik_baslangic: string;
  gecerlilik_bitis: string | null;
  birim: string | null;
  aciklama: string | null;
  kaynak_referansi: string | null;
};

function normalizeDemoMevzuatBody(
  source: Record<string, unknown>
): { error: ApiResponse<unknown> } | { value: DemoMevzuatNormalizedBody } {
  const kod = (toStringValue(source.parametre_kodu) ?? "").toUpperCase();
  if (!kod || kod.length > 80 || !/^[A-Z0-9_.-]+$/.test(kod)) {
    return { error: demoRevizyonError("VALIDATION_ERROR", "Gecersiz parametre kodu.") };
  }

  const tip = (toStringValue(source.deger_tipi) ?? "").toUpperCase();
  if (tip !== "SAYISAL" && tip !== "METIN") {
    return { error: demoRevizyonError("VALIDATION_ERROR", "Deger tipi SAYISAL veya METIN olmalidir.") };
  }

  const sayisal = toNumber(source.sayisal_deger);
  const metin = toStringValue(source.metin_deger) ?? null;
  if (tip === "SAYISAL" && (sayisal === null || metin !== null)) {
    return { error: demoRevizyonError("VALIDATION_ERROR", "Sayisal parametre yalniz sayisal deger icermelidir.") };
  }
  if (tip === "METIN" && (metin === null || sayisal !== null)) {
    return { error: demoRevizyonError("VALIDATION_ERROR", "Metin parametresi yalniz metin degeri icermelidir.") };
  }

  const baslangic = source.gecerlilik_baslangic;
  if (!isDemoIsoDate(baslangic)) {
    return { error: demoRevizyonError("DATE_INVALID", "Gecerli bir tarih zorunludur.") };
  }

  const bitisRaw = source.gecerlilik_bitis;
  let bitis: string | null = null;
  if (bitisRaw !== null && bitisRaw !== undefined && bitisRaw !== "") {
    if (!isDemoIsoDate(bitisRaw)) {
      return { error: demoRevizyonError("DATE_INVALID", "Gecerli bir tarih zorunludur.") };
    }
    bitis = bitisRaw;
  }

  if (bitis !== null && bitis < baslangic) {
    return { error: demoRevizyonError("DATE_RANGE_INVALID", "Bitis tarihi baslangic tarihinden once olamaz.") };
  }

  return {
    value: {
      parametre_kodu: kod,
      deger_tipi: tip,
      sayisal_deger: tip === "SAYISAL" ? sayisal : null,
      metin_deger: tip === "METIN" ? metin : null,
      gecerlilik_baslangic: baslangic,
      gecerlilik_bitis: bitis,
      birim: toStringValue(source.birim) ?? null,
      aciklama: toStringValue(source.aciklama) ?? null,
      kaynak_referansi: toStringValue(source.kaynak_referansi) ?? null
    }
  };
}

function demoMevzuatHasOverlap(
  kod: string,
  start: string,
  end: string | null,
  excludeId?: number
): boolean {
  const yeniBitis = end ?? "9999-12-31";
  return demoState.mevzuatParametreleri.some(
    (item) =>
      item.parametre_kodu === kod &&
      item.state === "AKTIF" &&
      (excludeId === undefined || item.id !== excludeId) &&
      item.gecerlilik_baslangic <= yeniBitis &&
      (item.gecerlilik_bitis === null || item.gecerlilik_bitis >= start)
  );
}

function parsePath(path: string): URL {
  const normalized = path.startsWith("/") ? path : `/${path}`;
  return new URL(normalized, "https://demo.local");
}

function getMethod(init?: RequestInit): DemoMethod {
  const method = (init?.method ?? "GET").toUpperCase();
  if (method === "POST" || method === "PUT" || method === "DELETE") {
    return method;
  }

  return "GET";
}

function resolveDemoRole(username: string) {
  const normalized = username.toLowerCase();
  if (normalized.includes("birim")) {
    return "BIRIM_AMIRI";
  }
  if (normalized.includes("muhasebe")) {
    return "MUHASEBE";
  }
  if (normalized.includes("bolum") || normalized.includes("bÃ¶lÃ¼m")) {
    return "BOLUM_YONETICISI";
  }

  return "GENEL_YONETICI";
}

type DemoPuantajBuildParams = {
  personelId: number;
  tarih: string;
  gunTipi: NonNullable<DemoPuantaj["gun_tipi"]>;
  hareketDurumu: NonNullable<DemoPuantaj["hareket_durumu"]>;
  dayanak?: DemoPuantaj["dayanak"];
  durumuBildirdiMi?: boolean;
  durumBildirimAciklamasi?: string;
  hesapEtkisi?: DemoPuantaj["hesap_etkisi"];
  beklenenGirisSaati?: string;
  beklenenCikisSaati?: string;
  girisSaati?: string;
  cikisSaati?: string;
  gercekMolaDakika?: number;
  hesaplananMolaDakika?: number;
  netCalismaSuresiDakika?: number;
  gunlukBrutSureDakika?: number;
  haftaTatiliHakKazandiMi?: boolean;
  state?: string;
  complianceUyarilari?: DemoPuantaj["compliance_uyarilari"];
  kontrolDurumu?: DemoPuantaj["kontrol_durumu"];
};

const DEMO_PUANTAJ_GUN_TIPI_MAP: Record<string, NonNullable<DemoPuantaj["gun_tipi"]>> = {
  NORMAL_IS_GUNU: "Normal_Is_Gunu",
  HAFTA_TATILI_PAZAR: "Hafta_Tatili_Pazar",
  UBGT_RESMI_TATIL: "UBGT_Resmi_Tatil"
};

const DEMO_PUANTAJ_HAREKET_DURUMU_MAP: Record<string, NonNullable<DemoPuantaj["hareket_durumu"]>> = {
  GELDI: "Geldi",
  GELMEDI: "Gelmedi",
  GEC_GELDI: "Gec_Geldi",
  ERKEN_CIKTI: "Erken_Cikti"
};

const DEMO_PUANTAJ_DAYANAK_MAP: Record<string, NonNullable<DemoPuantaj["dayanak"]>> = {
  YOK_IZINSIZ: "Yok_Izinsiz",
  UCRETLI_IZINLI: "Ucretli_Izinli",
  RAPORLU_HASTALIK: "Raporlu_Hastalik",
  RAPORLU_IS_KAZASI: "Raporlu_Is_Kazasi",
  YILLIK_IZIN: "Yillik_Izin",
  TELAFI_CALISMASI: "Telafi_Calismasi",
  GOREVDE_CALISMA: "Gorevde_Calisma"
};

const DEMO_PUANTAJ_HESAP_ETKISI_MAP: Record<string, NonNullable<DemoPuantaj["hesap_etkisi"]>> = {
  KESINTI_YAP: "Yevmiye_Kes",
  YEVMIYE_KES: "Yevmiye_Kes",
  TAM_YEVMIYE_VER: "Tam_Yevmiye_Ver",
  UCRETLI_IZIN: "Ucretli_Izin",
  RAPORLU: "Raporlu",
  MESAI_YAZ: "Mesai_Yaz",
  TELAFI: "Telafi"
};

function normalizeDemoLiteralToken(value: unknown) {
  const stringValue = toStringValue(value);
  if (!stringValue) {
    return undefined;
  }

  return stringValue.replace(/[\s-]+/g, "_").toUpperCase();
}

function readDemoPuantajGunTipi(value: unknown): DemoPuantaj["gun_tipi"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_GUN_TIPI_MAP[token] : undefined;
}

function readDemoPuantajHareketDurumu(value: unknown): DemoPuantaj["hareket_durumu"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_HAREKET_DURUMU_MAP[token] : undefined;
}

function readDemoPuantajDayanak(value: unknown): DemoPuantaj["dayanak"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_DAYANAK_MAP[token] : undefined;
}

function readDemoPuantajHesapEtkisi(value: unknown): DemoPuantaj["hesap_etkisi"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_HESAP_ETKISI_MAP[token] : undefined;
}

function readDemoPuantajKontrolDurumu(value: unknown): DemoPuantaj["kontrol_durumu"] | undefined {
  const token = normalizeDemoLiteralToken(value);
  return token ? DEMO_PUANTAJ_KONTROL_DURUMU_MAP[token] : undefined;
}

function buildDemoPuantaj(params: DemoPuantajBuildParams): DemoPuantaj {
  return {
    personel_id: params.personelId,
    tarih: params.tarih,
    gun_tipi: params.gunTipi,
    hareket_durumu: params.hareketDurumu,
    dayanak: params.dayanak,
    durumu_bildirdi_mi: params.durumuBildirdiMi,
    durum_bildirim_aciklamasi: params.durumBildirimAciklamasi,
    hesap_etkisi: params.hesapEtkisi,
    beklenen_giris_saati: params.beklenenGirisSaati,
    beklenen_cikis_saati: params.beklenenCikisSaati,
    giris_saati: params.girisSaati,
    cikis_saati: params.cikisSaati,
    gercek_mola_dakika: params.gercekMolaDakika,
    hesaplanan_mola_dakika: params.hesaplananMolaDakika,
    net_calisma_suresi_dakika: params.netCalismaSuresiDakika,
    gunluk_brut_sure_dakika: params.gunlukBrutSureDakika,
    hafta_tatili_hak_kazandi_mi: params.haftaTatiliHakKazandiMi,
    state: params.state ?? "HESAPLANDI",
    kontrol_durumu: params.kontrolDurumu ?? "BEKLIYOR",
    compliance_uyarilari: params.complianceUyarilari ?? []
  };
}

function defaultPuantaj(personelId: number, tarih: string): DemoPuantaj {
  return buildDemoPuantaj({
    personelId,
    tarih,
    gunTipi: "Normal_Is_Gunu",
    hareketDurumu: "Geldi",
    hesapEtkisi: "Tam_Yevmiye_Ver",
    girisSaati: "08:30",
    cikisSaati: "18:00",
    gercekMolaDakika: 60,
    hesaplananMolaDakika: 60,
    netCalismaSuresiDakika: 510,
    gunlukBrutSureDakika: 570,
    haftaTatiliHakKazandiMi: true
  });
}

function getLabel(map: Record<number, string>, id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return map[id] ?? `#${id}`;
}

function getSubeLabel(id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return demoState.subeler.find((item) => item.id === id)?.ad;
}

function getDepartmanLabel(id: number | undefined) {
  if (typeof id !== "number") {
    return undefined;
  }

  return demoState.departmanlar.find((item) => item.id === id)?.ad;
}

function monthKey(ay: string, personelId: number) {
  return `${ay}|${personelId}`;
}

function ensureAylikDurum(ay: string, personelId: number): DemoAylikDurum {
  const key = monthKey(ay, personelId);
  const existing = demoState.aylikDurumMap[key];
  if (existing) {
    return existing;
  }

  const created: DemoAylikDurum = {
    ay,
    personel_id: personelId,
    bolum_onay_durumu: "BOLUM_ONAYINDA",
    revize_var_mi: false,
    son_islem: "Birim amiri gunluk kayitlari hazirladi",
    kapanis_durumu: "ACIK"
  };
  demoState.aylikDurumMap[key] = created;
  return created;
}

function formatAylikIslemTimestamp() {
  return new Intl.DateTimeFormat("tr-TR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit"
  }).format(new Date());
}

function isTesvikKalemi(kalemTuru: string | undefined) {
  return ["PRIM", "BONUS", "IKRAMIYE", "TESVIK"].includes((kalemTuru ?? "").toUpperCase());
}

function isCezaKalemi(kalemTuru: string | undefined) {
  return (kalemTuru ?? "").toUpperCase() === "CEZA";
}

function summarizeBildirimForPersonel(ay: string, personelId: number) {
  const matching = demoState.bildirimler.filter(
    (item) => item.personel_id === personelId && (item.tarih ?? "").startsWith(`${ay}-`) && item.state !== "IPTAL"
  );

  let devamsizlikGun = 0;
  let gecKalmaAdet = 0;
  let izinliGelmedi = 0;
  let izinsizGelmedi = 0;
  let raporlu = 0;

  for (const item of matching) {
    switch ((item.bildirim_turu ?? "").toUpperCase()) {
      case "GEC_GELDI":
        gecKalmaAdet += 1;
        break;
      case "IZINLI":
      case "IZINLI_GELMEDI":
        izinliGelmedi += 1;
        break;
      case "IZINSIZ_GELMEDI":
        izinsizGelmedi += 1;
        devamsizlikGun += 1;
        break;
      case "DEVAMSIZLIK":
      case "GELMEDI":
        devamsizlikGun += 1;
        break;
      case "RAPORLU":
        raporlu += 1;
        break;
      default:
        break;
    }
  }

  return {
    devamsizlikGun,
    gecKalmaAdet,
    izinliGelmedi,
    izinsizGelmedi,
    raporlu
  };
}

function summarizeFinansForPersonel(ay: string, personelId: number) {
  const matching = demoState.finansKalemleri.filter(
    (item) => item.personel_id === personelId && item.donem === ay && item.state !== "IPTAL"
  );

  return matching.reduce(
    (acc, item) => {
      if (isTesvikKalemi(item.kalem_turu)) {
        acc.tesvikTutari += item.tutar;
      }
      if (isCezaKalemi(item.kalem_turu)) {
        acc.cezaKesintiTutari += item.tutar;
      }
      return acc;
    },
    { tesvikTutari: 0, cezaKesintiTutari: 0 }
  );
}

function buildAylikOzetResponse(ay: string, subeId?: number | null, departmanId?: number | null, sadeceRevizeli = false) {
  const items = demoState.personeller
    .filter((personel) => {
      if (subeId !== null && subeId !== undefined && personel.sube_id !== subeId) {
        return false;
      }
      if (departmanId !== null && departmanId !== undefined && personel.departman_id !== departmanId) {
        return false;
      }
      return personel.aktif_durum === "AKTIF";
    })
    .map((personel) => {
      const bildirimOzet = summarizeBildirimForPersonel(ay, personel.id);
      const finansOzet = summarizeFinansForPersonel(ay, personel.id);
      const durum = ensureAylikDurum(ay, personel.id);

      return {
        personel_id: personel.id,
        ad_soyad: `${personel.ad} ${personel.soyad}`,
        sicil_no: personel.sicil_no,
        sube: getSubeLabel(personel.sube_id) ?? "-",
        bolum: getDepartmanLabel(personel.departman_id) ?? "-",
        bagli_amir_adi: getLabel(DEMO_BAGLI_AMIR_LABELS, personel.bagli_amir_id) ?? "-",
        devamsizlik_gun: bildirimOzet.devamsizlikGun,
        gec_kalma_adet: bildirimOzet.gecKalmaAdet,
        izinli_gelmedi: bildirimOzet.izinliGelmedi,
        izinsiz_gelmedi: bildirimOzet.izinsizGelmedi,
        raporlu: bildirimOzet.raporlu,
        tesvik_tutari: finansOzet.tesvikTutari,
        ceza_kesinti_tutari: finansOzet.cezaKesintiTutari,
        bolum_onay_durumu: durum.bolum_onay_durumu,
        revize_var_mi: durum.revize_var_mi,
        son_islem: durum.son_islem,
        kapanis_durumu: durum.kapanis_durumu
      };
    })
    .filter((item) => (sadeceRevizeli ? item.revize_var_mi : true));

  const pendingBolumOnayi = items.filter((item) => item.bolum_onay_durumu === "BOLUM_ONAYINDA").length;

  const state = (() => {
    if (items.length === 0) {
      return "BOLUM_ONAYINDA";
    }
    if (items.every((item) => item.kapanis_durumu === "KAPANDI")) {
      return "KAPANDI";
    }
    if (items.some((item) => item.bolum_onay_durumu === "REVIZE_ISTENDI")) {
      return "REVIZE_ISTENDI";
    }
    if (pendingBolumOnayi === 0) {
      return "BOLUM_ONAYLANDI";
    }
    return "BOLUM_ONAYINDA";
  })();

  return {
    ay,
    state,
    summary: {
      toplam_personel: items.length,
      toplam_devamsizlik_gun: items.reduce((acc, item) => acc + item.devamsizlik_gun, 0),
      toplam_gec_kalma: items.reduce((acc, item) => acc + item.gec_kalma_adet, 0),
      toplam_izinli_gelmedi: items.reduce((acc, item) => acc + item.izinli_gelmedi, 0),
      toplam_izinsiz_gelmedi: items.reduce((acc, item) => acc + item.izinsiz_gelmedi, 0),
      toplam_raporlu: items.reduce((acc, item) => acc + item.raporlu, 0),
      toplam_tesvik_tutari: items.reduce((acc, item) => acc + item.tesvik_tutari, 0),
      toplam_ceza_kesinti_tutari: items.reduce((acc, item) => acc + item.ceza_kesinti_tutari, 0)
    },
    items,
    pending_bolum_onayi: pendingBolumOnayi
  };
}

function getDemoPuantajRowsForPersonel(personelId: number) {
  return Object.values(demoState.puantajMap).filter((kayit) => kayit.personel_id === personelId);
}

function buildDemoPersonelDetail(personel: DemoPersonel) {
  const sgkOzeti = hesaplaAylikSgkPuantajOzetleri(getDemoPuantajRowsForPersonel(personel.id))[0] ?? null;

  return {
    ana_kart: { ...personel },
    sistem_ozeti: {
      hizmet_suresi: personel.id === 1 ? "3 yil 2 ay" : "1 yil 8 ay",
      toplam_izin_hakki: personel.id === 1 ? 14 : 10,
      kullanilan_izin: personel.id === 1 ? 4 : 2,
      kalan_izin: personel.id === 1 ? 10 : 8,
      sgk_donem: sgkOzeti?.donem,
      sgk_prim_gun: sgkOzeti?.sgk_prim_gun,
      sgk_eksik_gun_sayisi: sgkOzeti?.eksik_gun_sayisi,
      sgk_eksik_gun_nedeni_kodu: sgkOzeti?.eksik_gun_nedeni_kodu ?? null,
      sgk_ayin_takvim_gun_sayisi: sgkOzeti?.ayin_takvim_gun_sayisi,
      sgk_hesaplama_modu: sgkOzeti?.hesaplama_modu
    },
    pasiflik_durumu: {
      aktif_durum: personel.aktif_durum,
      etiket: personel.aktif_durum === "PASIF" ? "Isten Ayrildi" : null
    },
    referans_adlari: {
      sube: getSubeLabel(personel.sube_id),
      departman: getDepartmanLabel(personel.departman_id),
      gorev: getLabel(DEMO_GOREV_LABELS, personel.gorev_id),
      personel_tipi: getLabel(DEMO_PERSONEL_TIPI_LABELS, personel.personel_tipi_id),
      bagli_amir: getLabel(DEMO_BAGLI_AMIR_LABELS, personel.bagli_amir_id)
    }
  };
}

function buildDemoIsgMakineListResponse(searchUrl: URL) {
  const page = toNumber(searchUrl.searchParams.get("page")) ?? 1;
  const limit = toNumber(searchUrl.searchParams.get("limit")) ?? 10;
  const search = (toStringValue(searchUrl.searchParams.get("search")) ?? "").toLowerCase();
  const durum = (toStringValue(searchUrl.searchParams.get("durum")) ?? "tum").toLowerCase();
  const tip = (toStringValue(searchUrl.searchParams.get("tip")) ?? "").toLowerCase();
  const subeId = toNumber(searchUrl.searchParams.get("sube_id"));

  const filtered = demoState.makineler.filter((item) => {
    if (subeId !== null && item.sube_id !== subeId) {
      return false;
    }
    if (durum !== "tum" && item.durum !== durum) {
      return false;
    }
    if (tip && !item.tip.toLowerCase().includes(tip)) {
      return false;
    }
    if (!search) {
      return true;
    }

    const fullText = `${item.ad} ${item.tip} ${item.konum ?? ""}`.toLowerCase();
    return fullText.includes(search);
  });

  const start = (page - 1) * limit;
  const items = filtered.slice(start, start + limit).map((item) => ({
    ...item,
    referans_adlari: {
      sube: getSubeLabel(item.sube_id) ?? "-"
    }
  }));
  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / limit));

  return ok(
    {
      items
    },
    {
      page,
      limit,
      total,
      total_pages: totalPages
    }
  );
}

function findDemoMakineByScope(makineId: number, subeId: number | null) {
  const makine = demoState.makineler.find((item) => item.id === makineId);
  if (!makine) {
    return null;
  }
  if (subeId !== null && makine.sube_id !== subeId) {
    return null;
  }
  return makine;
}

function buildDemoIsgMakineDetailResponse(searchUrl: URL, makineId: number) {
  const subeId = toNumber(searchUrl.searchParams.get("sube_id"));
  const makine = findDemoMakineByScope(makineId, subeId);
  if (!makine) {
    return null;
  }

  return ok({
    ...makine,
    referans_adlari: {
      sube: getSubeLabel(makine.sube_id) ?? "-"
    }
  });
}

function buildDemoIsgMakineBakimResponse(searchUrl: URL, makineId: number) {
  const subeId = toNumber(searchUrl.searchParams.get("sube_id"));
  const page = toNumber(searchUrl.searchParams.get("page")) ?? 1;
  const limit = toNumber(searchUrl.searchParams.get("limit")) ?? 10;
  const makine = findDemoMakineByScope(makineId, subeId);
  if (!makine) {
    return null;
  }

  const filtered = demoState.bakimKayitlari.filter((item) => item.makine_id === makineId);
  const start = (page - 1) * limit;
  const items = filtered.slice(start, start + limit);
  const total = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / limit));

  return ok(
    {
      items
    },
    {
      page,
      limit,
      total,
      total_pages: totalPages
    }
  );
}

export function resolveDemoApiResponse(
  path: string,
  init?: RequestInit
): ApiResponse<unknown> | null {
  const requestUrl = parsePath(path);
  const pathname = requestUrl.pathname;
  const method = getMethod(init);
  const body = readBody(init);

  const mutabakatWeek = (value: unknown) => {
    if (typeof value !== "string" || !isMondayIsoDate(value)) return null;
    const end = new Date(`${value}T00:00:00Z`);
    end.setUTCDate(end.getUTCDate() + 6);
    return { start: value, end: end.toISOString().slice(0, 10) };
  };

  const mutabakatScope = (actor: RevizyonActorContext) => {
    const query = toNumber(requestUrl.searchParams.get("sube_id"));
    if (query !== null) return actor.subeIds.length === 0 || actor.subeIds.includes(query) ? query : null;
    return actor.subeIds.length === 1 ? actor.subeIds[0] : null;
  };

  const mutabakatCounts = (subeId: number, userId: number, start: string, end: string) => {
    const rows = demoState.bildirimler.filter(
      (item) => item.sube_id === subeId && item.created_by === userId &&
        (item.tarih ?? "") >= start && (item.tarih ?? "") <= end
    );
    const count = (state: string) => rows.filter((item) => (item.state ?? "").toUpperCase() === state).length;
    return {
      toplam: rows.length, taslak: count("TASLAK"), gonderildi: count("GONDERILDI"),
      duzeltme_istendi: count("DUZELTME_ISTENDI"),
      haftalik_mutabakata_alindi: count("HAFTALIK_MUTABAKATA_ALINDI"), iptal: count("IPTAL")
    };
  };

  if (pathname === "/auth/login" && method === "POST") {
    const username = toStringValue(body.username) ?? "demo";
    const role = resolveDemoRole(username);
    const profile = role === "BIRIM_AMIRI" ? "birim_amiri" : "yonetim";
    const sube_ids =
      role === "BIRIM_AMIRI" ? [1] : role === "MUHASEBE" ? [1, 2] : role === "BOLUM_YONETICISI" ? [2] : [];
    const sube_list =
      sube_ids.length > 0
      ? sube_ids.map((id) => ({ id, ad: id === 1 ? "Merkez" : `Åube ${id}` }))
        : undefined;

    return ok({
      token: "demo-token",
      ui_profile: profile,
      sube_list,
      user: {
        id: username.length + 1,
        ad_soyad: username,
        rol: role,
        sube_ids
      }
    });
  }

  if (pathname === "/haftalik-bildirim-mutabakatlari/ozet" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "haftalik_mutabakat.view");
    if (permissionError) return permissionError;
    const week = mutabakatWeek(requestUrl.searchParams.get("hafta_baslangic"));
    if (!week) return demoRevizyonError("VALIDATION_ERROR", "Hafta baslangici Pazartesi olmalidir.");
    const subeId = mutabakatScope(actor);
    if (!subeId) return demoRevizyonError("VALIDATION_ERROR", "Haftalik mutabakat icin aktif sube secilmelidir.");
    const existing = demoState.haftalikBildirimMutabakatlari.find(
      (item) => item.sube_id === subeId && item.birim_amiri_user_id === actor.userId && item.hafta_baslangic === week.start
    );
    const counts = mutabakatCounts(subeId, actor.userId, week.start, week.end);
    const approval = resolveHaftalikMutabakatApproval(counts, existing?.id ?? null);
    return ok({
      hafta_baslangic: week.start, hafta_bitis: week.end, sube_id: subeId,
      birim_amiri_user_id: actor.userId, counts, ...approval,
      mevcut_mutabakat_id: existing?.id ?? null
    });
  }

  if (pathname === "/haftalik-bildirim-mutabakatlari" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "haftalik_mutabakat.approve");
    if (permissionError) return permissionError;
    if (actor.role !== "BIRIM_AMIRI") return demoRevizyonError("FORBIDDEN", "Yalnizca birim amiri kendi haftasini onaylayabilir.");
    const week = mutabakatWeek(body.hafta_baslangic);
    if (!week) return demoRevizyonError("VALIDATION_ERROR", "Hafta baslangici Pazartesi olmalidir.");
    const subeId = mutabakatScope(actor);
    if (!subeId) return demoRevizyonError("FORBIDDEN", "Haftalik mutabakat icin aktif sube secilmelidir.");
    const existing = demoState.haftalikBildirimMutabakatlari.find(
      (item) => item.sube_id === subeId && item.birim_amiri_user_id === actor.userId && item.hafta_baslangic === week.start
    );
    const counts = mutabakatCounts(subeId, actor.userId, week.start, week.end);
    const approval = resolveHaftalikMutabakatApproval(counts, existing?.id ?? null);
    if (!approval.onaylanabilir_mi) return demoBildirimConflict(approval.blok_nedeni ?? "Hafta onaylanamaz.");
    const now = new Date().toISOString();
    const mutabakat: HaftalikBildirimMutabakat = {
      id: ++demoState.nextIds.haftalikBildirimMutabakat, sube_id: subeId,
      birim_amiri_user_id: actor.userId, hafta_baslangic: week.start, hafta_bitis: week.end,
      state: "TAMAMLANDI", onaylayan_user_id: actor.userId,
      onaylandi_at: now, created_at: now, updated_at: now
    };
    demoState.haftalikBildirimMutabakatlari.push(mutabakat);
    const linked = demoState.bildirimler.filter(
      (item) => item.sube_id === subeId && item.created_by === actor.userId &&
        (item.tarih ?? "") >= week.start && (item.tarih ?? "") <= week.end && item.state === "GONDERILDI"
    );
    linked.forEach((item) => {
      item.state = "HAFTALIK_MUTABAKATA_ALINDI";
      item.haftalik_mutabakat_id = mutabakat.id;
      item.updated_by = actor.userId;
    });
    return ok({ mutabakat, gunluk_bildirimler: linked, counts: { toplam: linked.length, baglanan: linked.length }, baglanan_kayit_sayisi: linked.length });
  }

  const mutabakatDetailMatch = pathname.match(/^\/haftalik-bildirim-mutabakatlari\/(\d+)$/);
  if (mutabakatDetailMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "haftalik_mutabakat.view");
    if (permissionError) return permissionError;
    const id = Number.parseInt(mutabakatDetailMatch[1], 10);
    const mutabakat = demoState.haftalikBildirimMutabakatlari.find((item) => item.id === id);
    if (!mutabakat) return demoRevizyonError("NOT_FOUND", "Haftalik mutabakat bulunamadi.");
    const scopeAllowed = actor.subeIds.length === 0 || actor.subeIds.includes(mutabakat.sube_id);
    if (!scopeAllowed || (actor.role === "BIRIM_AMIRI" && mutabakat.birim_amiri_user_id !== actor.userId)) {
      return demoRevizyonError("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.");
    }
    const linked = demoState.bildirimler.filter((item) => item.haftalik_mutabakat_id === id);
    return ok({ mutabakat, gunluk_bildirimler: linked, counts: { toplam: linked.length, baglanan: linked.length } });
  }

  const buildAylikOnayContext = (subeId: number, amirId: number, ay: string) => {
    const bounds = resolveAyBounds(ay);
    if (!bounds) {
      return null;
    }
    const { ay_baslangic: ayBaslangic, ay_bitis: ayBitis } = bounds;
    const rows = demoState.bildirimler.filter(
      (item) =>
        item.sube_id === subeId &&
        item.created_by === amirId &&
        (item.tarih ?? "") >= ayBaslangic &&
        (item.tarih ?? "") <= ayBitis
    );
    const weeks = listWeeksIntersectingMonth(ayBaslangic, ayBitis);
    const counts = {
      toplam_bildirim: 0,
      mutabakata_alinan: 0,
      mutabakatli_hafta: 0,
      eksik_hafta: 0,
      taslak: 0,
      duzeltme_istendi: 0,
      gonderildi: 0
    };
    const stateMap: Record<string, keyof typeof counts> = {
      TASLAK: "taslak",
      GONDERILDI: "gonderildi",
      DUZELTME_ISTENDI: "duzeltme_istendi",
      HAFTALIK_MUTABAKATA_ALINDI: "mutabakata_alinan"
    };
    rows.forEach((row) => {
      const state = (row.state ?? "").toUpperCase();
      if (state === "IPTAL") {
        return;
      }
      counts.toplam_bildirim += 1;
      const key = stateMap[state];
      if (key) {
        counts[key] += 1;
      }
    });

    const haftalar = weeks.map((week) => {
      const weekRows = rows.filter(
        (row) =>
          (row.tarih ?? "") >= week.hafta_baslangic &&
          (row.tarih ?? "") <= week.hafta_bitis &&
          (row.state ?? "").toUpperCase() !== "IPTAL"
      );
      const mutabakat = demoState.haftalikBildirimMutabakatlari.find(
        (item) =>
          item.sube_id === subeId &&
          item.birim_amiri_user_id === amirId &&
          item.hafta_baslangic === week.hafta_baslangic
      );
      const bildirimSayisi = weekRows.length;
      const mutabakataAlinan = weekRows.filter(
        (row) => (row.state ?? "").toUpperCase() === "HAFTALIK_MUTABAKATA_ALINDI"
      ).length;
      const eksikMi = bildirimSayisi > 0 && !mutabakat;
      if (eksikMi) {
        counts.eksik_hafta += 1;
      } else if (mutabakat) {
        counts.mutabakatli_hafta += 1;
      }
      return {
        hafta_baslangic: week.hafta_baslangic,
        hafta_bitis: week.hafta_bitis,
        mutabakat_id: mutabakat?.id ?? null,
        state: mutabakat?.state ?? null,
        bildirim_sayisi: bildirimSayisi,
        mutabakata_alinan_sayisi: mutabakataAlinan,
        eksik_mi: eksikMi,
        blok_nedeni: eksikMi ? "Haftalik mutabakat eksik." : null
      };
    });

    return { ayBaslangic, ayBitis, counts, haftalar };
  };

  if (pathname === "/aylik-bildirim-onaylari/ozet" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "aylik_bildirim_onayi.view");
    if (permissionError) return permissionError;
    const ay = toStringValue(requestUrl.searchParams.get("ay")) ?? "";
    if (!resolveAyBounds(ay)) {
      return demoRevizyonError("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.");
    }
    const subeId = mutabakatScope(actor);
    if (!subeId) {
      return demoRevizyonError("VALIDATION_ERROR", "Aylik bildirim onayi icin aktif sube secilmelidir.");
    }
    const amirId =
      actor.role === "BIRIM_AMIRI"
        ? actor.userId
        : toNumber(requestUrl.searchParams.get("birim_amiri_user_id"));
    const existing =
      amirId !== null
        ? demoState.aylikBildirimOnaylari.find(
            (item) => item.sube_id === subeId && item.birim_amiri_user_id === amirId && item.ay === ay
          )
        : undefined;
    const context = amirId !== null ? buildAylikOnayContext(subeId, amirId, ay) : null;
    const approval =
      context !== null
        ? resolveAylikBildirimOnayApproval({
            counts: context.counts,
            mevcutOnayId: existing?.id ?? null,
            eksikHaftaSayisi: context.counts.eksik_hafta
          })
        : { onaylanabilir_mi: false, blok_nedeni: "Birim amiri secimi zorunludur." };
    return ok({
      ay,
      ay_baslangic: context?.ayBaslangic ?? resolveAyBounds(ay)?.ay_baslangic,
      ay_bitis: context?.ayBitis ?? resolveAyBounds(ay)?.ay_bitis,
      sube_id: subeId,
      birim_amiri_user_id: amirId,
      haftalar: context?.haftalar ?? [],
      counts: context?.counts ?? {
        toplam_bildirim: 0,
        mutabakata_alinan: 0,
        mutabakatli_hafta: 0,
        eksik_hafta: 0,
        taslak: 0,
        duzeltme_istendi: 0,
        gonderildi: 0
      },
      ...approval,
      mevcut_onay_id: existing?.id ?? null
    });
  }

  if (pathname === "/aylik-bildirim-onaylari" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "aylik_bildirim_onayi.approve");
    if (permissionError) return permissionError;
    if (actor.role !== "BIRIM_AMIRI") {
      return demoRevizyonError("FORBIDDEN", "Yalnizca birim amiri kendi ayini onaylayabilir.");
    }
    const ay = toStringValue(body.ay) ?? "";
    if (!resolveAyBounds(ay)) {
      return demoRevizyonError("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.");
    }
    const subeId = mutabakatScope(actor);
    if (!subeId) {
      return demoRevizyonError("VALIDATION_ERROR", "Aylik bildirim onayi icin aktif sube secilmelidir.");
    }
    const existing = demoState.aylikBildirimOnaylari.find(
      (item) => item.sube_id === subeId && item.birim_amiri_user_id === actor.userId && item.ay === ay
    );
    const context = buildAylikOnayContext(subeId, actor.userId, ay);
    if (!context) {
      return demoRevizyonError("VALIDATION_ERROR", "Ay parametresi YYYY-MM formatinda olmalidir.");
    }
    const approval = resolveAylikBildirimOnayApproval({
      counts: context.counts,
      mevcutOnayId: existing?.id ?? null,
      eksikHaftaSayisi: context.counts.eksik_hafta
    });
    if (!approval.onaylanabilir_mi) {
      return demoRevizyonError("CONFLICT", approval.blok_nedeni ?? "Aylik bildirim onayi olusturulamadi.");
    }
    const now = new Date().toISOString();
    const onay: AylikBildirimOnay = {
      id: ++demoState.nextIds.aylikBildirimOnay,
      sube_id: subeId,
      birim_amiri_user_id: actor.userId,
      ay,
      ay_baslangic: context.ayBaslangic,
      ay_bitis: context.ayBitis,
      state: "TAMAMLANDI",
      onaylayan_user_id: actor.userId,
      onaylandi_at: now,
      aciklama: toStringValue(body.aciklama) ?? null,
      created_at: now,
      updated_at: now
    };
    demoState.aylikBildirimOnaylari.push(onay);
    const mutabakatlar = context.haftalar
      .map((week) =>
        demoState.haftalikBildirimMutabakatlari.find(
          (item) =>
            item.sube_id === subeId &&
            item.birim_amiri_user_id === actor.userId &&
            item.hafta_baslangic === week.hafta_baslangic
        )
      )
      .filter((item): item is HaftalikBildirimMutabakat => item !== undefined);
    return ok({ onay, haftalar: context.haftalar, haftalik_mutabakatlar: mutabakatlar, counts: context.counts });
  }

  const aylikOnayDetailMatch = pathname.match(/^\/aylik-bildirim-onaylari\/(\d+)$/);
  if (aylikOnayDetailMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "aylik_bildirim_onayi.view");
    if (permissionError) return permissionError;
    const id = Number.parseInt(aylikOnayDetailMatch[1], 10);
    const onay = demoState.aylikBildirimOnaylari.find((item) => item.id === id);
    if (!onay) {
      return demoRevizyonError("NOT_FOUND", "Aylik bildirim onayi bulunamadi.");
    }
    const scopeAllowed = actor.subeIds.length === 0 || actor.subeIds.includes(onay.sube_id);
    if (!scopeAllowed || (actor.role === "BIRIM_AMIRI" && onay.birim_amiri_user_id !== actor.userId)) {
      return demoRevizyonError("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.");
    }
    const context = buildAylikOnayContext(onay.sube_id, onay.birim_amiri_user_id, onay.ay);
    const mutabakatlar = (context?.haftalar ?? [])
      .map((week) =>
        demoState.haftalikBildirimMutabakatlari.find(
          (item) =>
            item.sube_id === onay.sube_id &&
            item.birim_amiri_user_id === onay.birim_amiri_user_id &&
            item.hafta_baslangic === week.hafta_baslangic
        )
      )
      .filter((item): item is HaftalikBildirimMutabakat => item !== undefined);
    return ok({
      onay,
      haftalar: context?.haftalar ?? [],
      haftalik_mutabakatlar: mutabakatlar,
      counts: context?.counts ?? {
        toplam_bildirim: 0,
        mutabakata_alinan: 0,
        mutabakatli_hafta: 0,
        eksik_hafta: 0,
        taslak: 0,
        duzeltme_istendi: 0,
        gonderildi: 0
      }
    });
  }

  if (pathname === "/isg/makineler" && method === "GET") {
    return buildDemoIsgMakineListResponse(requestUrl);
  }

  const isgMakineDetailMatch = pathname.match(/^\/isg\/makineler\/(\d+)$/);
  if (isgMakineDetailMatch && method === "GET") {
    return buildDemoIsgMakineDetailResponse(requestUrl, Number.parseInt(isgMakineDetailMatch[1], 10));
  }

  const isgMakineBakimMatch = pathname.match(/^\/isg\/makineler\/(\d+)\/bakimlar$/);
  if (isgMakineBakimMatch && method === "GET") {
    return buildDemoIsgMakineBakimResponse(requestUrl, Number.parseInt(isgMakineBakimMatch[1], 10));
  }

  if (pathname === "/personeller" && method === "GET") {
    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 10;
    const aktiflik = toStringValue(requestUrl.searchParams.get("aktiflik")) ?? "tum";
    const search = (toStringValue(requestUrl.searchParams.get("search")) ?? "").toLowerCase();
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const personelTipiId = toNumber(requestUrl.searchParams.get("personel_tipi_id"));

    const filtered = demoState.personeller.filter((item) => {
      if (aktiflik === "aktif" && item.aktif_durum !== "AKTIF") {
        return false;
      }
      if (aktiflik === "pasif" && item.aktif_durum !== "PASIF") {
        return false;
      }
      if (subeId !== null && item.sube_id !== subeId) {
        return false;
      }
      if (departmanId !== null && item.departman_id !== departmanId) {
        return false;
      }
      if (personelTipiId !== null && item.personel_tipi_id !== personelTipiId) {
        return false;
      }
      if (!search) {
        return true;
      }

      const fullText = `${item.ad} ${item.soyad} ${item.tc_kimlik_no}`.toLowerCase();
      return fullText.includes(search);
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit).map((item) => ({
      ...item,
      sube_adi: getSubeLabel(item.sube_id),
      departman_adi: getDepartmanLabel(item.departman_id),
      gorev_adi: getLabel(DEMO_GOREV_LABELS, item.gorev_id),
      personel_tipi_adi: getLabel(DEMO_PERSONEL_TIPI_LABELS, item.personel_tipi_id),
      bagli_amir_adi: getLabel(DEMO_BAGLI_AMIR_LABELS, item.bagli_amir_id)
    }));
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok(
      {
        items
      },
      {
        page,
        limit,
        total,
        total_pages: totalPages
      }
    );
  }

  if (pathname === "/personeller" && method === "POST") {
    const subeId = toNumber(body.sube_id);
    if (subeId === null) {
      return demoRevizyonError("VALIDATION_ERROR", "Şube seçilmelidir.");
    }

    const next: DemoPersonel = {
      id: ++demoState.nextIds.personel,
      tc_kimlik_no: toStringValue(body.tc_kimlik_no) ?? "00000000000",
      ad: toStringValue(body.ad) ?? "Yeni",
      soyad: toStringValue(body.soyad) ?? "Personel",
      aktif_durum: (toStringValue(body.aktif_durum) as "AKTIF" | "PASIF") ?? "AKTIF",
      sube_id: subeId,
      telefon: toStringValue(body.telefon) ?? undefined,
      dogum_tarihi: toStringValue(body.dogum_tarihi) ?? undefined,
      sicil_no: toStringValue(body.sicil_no) ?? undefined,
      dogum_yeri: toStringValue(body.dogum_yeri) ?? undefined,
      kan_grubu: toStringValue(body.kan_grubu) ?? undefined,
      ise_giris_tarihi: toStringValue(body.ise_giris_tarihi) ?? undefined,
      acil_durum_kisi: toStringValue(body.acil_durum_kisi) ?? undefined,
      acil_durum_telefon: toStringValue(body.acil_durum_telefon) ?? undefined,
      departman_id: toNumber(body.departman_id) ?? undefined,
      gorev_id: toNumber(body.gorev_id) ?? undefined,
      personel_tipi_id: toNumber(body.personel_tipi_id) ?? undefined,
      bagli_amir_id: toNumber(body.bagli_amir_id) ?? undefined,
      ucret_tipi_id: toNumber(body.ucret_tipi_id) ?? undefined,
      net_maas_tutari: toNumber(body.net_maas_tutari) ?? toNumber(body.maas_tutari) ?? undefined,
      maas_tutari: toNumber(body.maas_tutari) ?? toNumber(body.net_maas_tutari) ?? undefined
    };
    demoState.personeller.unshift(next);
    return ok({
      ...next,
      sube_adi: getSubeLabel(next.sube_id),
      departman_adi: getDepartmanLabel(next.departman_id),
      gorev_adi: getLabel(DEMO_GOREV_LABELS, next.gorev_id),
      personel_tipi_adi: getLabel(DEMO_PERSONEL_TIPI_LABELS, next.personel_tipi_id),
      bagli_amir_adi: getLabel(DEMO_BAGLI_AMIR_LABELS, next.bagli_amir_id)
    });
  }

  const personelDetailMatch = pathname.match(/^\/personeller\/(\d+)$/);
  if (personelDetailMatch) {
    const id = Number.parseInt(personelDetailMatch[1], 10);
    const personel = demoState.personeller.find((item) => item.id === id);
    if (!personel) {
      return null;
    }

    if (method === "GET") {
      return ok(buildDemoPersonelDetail(personel));
    }

    if (method === "PUT") {
      Object.assign(personel, body);
      return ok(buildDemoPersonelDetail(personel));
    }
  }

  const personelUcretAktifMatch = pathname.match(/^\/personeller\/(\d+)\/ucret(?:ler)?\/aktif$/);
  if (personelUcretAktifMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "personeller.ucret.view",
      "Ucret bilgisine erisim yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const personelId = Number.parseInt(personelUcretAktifMatch[1], 10);
    const personel = demoState.personeller.find((item) => item.id === personelId);
    if (!personel) {
      return demoRevizyonError("SALARY_RECORD_NOT_FOUND", "Personel bulunamadi.");
    }
    const scopeError = demoPersonelSubeScopeError(actor, personel);
    if (scopeError) return scopeError;

    const tarihParam = toStringValue(requestUrl.searchParams.get("tarih"));
    const tarih = tarihParam && isDemoIsoDate(tarihParam) ? tarihParam : demoTodayIsoDate();
    const guncel = findDemoGuncelUcret(personelId, tarih);
    if (guncel) {
      return ok(guncel);
    }

    const hasHistory = demoState.personelUcretleri.some((item) => item.personel_id === personelId);
    if (!hasHistory && typeof personel.maas_tutari === "number" && personel.maas_tutari > 0) {
      const legacyStart = personel.ise_giris_tarihi ?? "1900-01-01";
      if (legacyStart <= tarih) {
        return ok({
          id: null,
          personel_id: personelId,
          ucret_tutari: personel.maas_tutari,
          ucret_turu: "NET",
          para_birimi: "TRY",
          gecerlilik_baslangic: legacyStart,
          gecerlilik_bitis: null,
          state: "AKTIF",
          kaynak: "PERSONEL_KAYDI_MIGRASYON",
          virtual: true
        });
      }
    }

    return demoRevizyonError("SALARY_MISSING", "Belirtilen tarihte gecerli ucret kaydi yok.");
  }

  const personelUcretListMatch = pathname.match(/^\/personeller\/(\d+)\/ucretler$/);
  if (personelUcretListMatch && (method === "GET" || method === "POST")) {
    const actor = readDemoApiActor(init);
    const personelId = Number.parseInt(personelUcretListMatch[1], 10);
    const personel = demoState.personeller.find((item) => item.id === personelId);
    if (!personel) {
      return demoRevizyonError("SALARY_RECORD_NOT_FOUND", "Personel bulunamadi.");
    }

    if (method === "GET") {
      const permissionError = enforceDemoPermission(
        actor,
        "personeller.ucret.view",
        "Ucret bilgisine erisim yetkiniz yok."
      );
      if (permissionError) return permissionError;
      const scopeError = demoPersonelSubeScopeError(actor, personel);
      if (scopeError) return scopeError;

      return ok({
        items: sortDemoUcretKayitlari(
          demoState.personelUcretleri.filter((item) => item.personel_id === personelId)
        )
      });
    }

    const permissionError = enforceDemoPermission(
      actor,
      "personeller.ucret.manage",
      "Ucret bilgisine erisim yetkiniz yok."
    );
    if (permissionError) return permissionError;
    const scopeError = demoPersonelSubeScopeError(actor, personel);
    if (scopeError) return scopeError;

    const normalized = normalizeDemoUcretBody(body);
    if ("error" in normalized) {
      return normalized.error;
    }
    const data = normalized.value;
    const now = new Date().toISOString();

    const hasHistory = demoState.personelUcretleri.some((item) => item.personel_id === personelId);
    if (!hasHistory && typeof personel.maas_tutari === "number" && personel.maas_tutari > 0) {
      const legacyStart = personel.ise_giris_tarihi ?? "1900-01-01";
      if (legacyStart < data.gecerlilik_baslangic) {
        demoState.personelUcretleri.push({
          id: ++demoState.nextIds.personelUcret,
          personel_id: personelId,
          ucret_tutari: personel.maas_tutari,
          ucret_turu: "NET",
          para_birimi: "TRY",
          gecerlilik_baslangic: legacyStart,
          gecerlilik_bitis: addDaysToIsoDate(data.gecerlilik_baslangic, -1),
          state: "AKTIF",
          kaynak: "PERSONEL_KAYDI_MIGRASYON",
          aciklama: "Legacy personel maasindan tarihce gecisi",
          created_at: now,
          created_by: actor.userId,
          updated_at: now,
          updated_by: actor.userId
        });
      }
    }

    const openKayit = sortDemoUcretKayitlari(
      demoState.personelUcretleri.filter(
        (item) =>
          item.personel_id === personelId && item.state === "AKTIF" && item.gecerlilik_bitis === null
      )
    )[0];
    if (openKayit && openKayit.gecerlilik_baslangic <= data.gecerlilik_baslangic) {
      if (openKayit.gecerlilik_baslangic === data.gecerlilik_baslangic) {
        return demoRevizyonError(
          "DATE_RANGE_INVALID",
          "Ayni baslangic tarihli acik ucret kaydi kapatilamaz."
        );
      }
      openKayit.gecerlilik_bitis = addDaysToIsoDate(data.gecerlilik_baslangic, -1);
      openKayit.updated_at = now;
      openKayit.updated_by = actor.userId;
    }

    if (demoUcretHasOverlap(personelId, data.gecerlilik_baslangic, data.gecerlilik_bitis)) {
      return demoRevizyonError("SALARY_DATE_OVERLAP", DEMO_UCRET_OVERLAP_MESAJI);
    }

    const next: DemoPersonelUcretKaydi = {
      id: ++demoState.nextIds.personelUcret,
      personel_id: personelId,
      ...data,
      state: "AKTIF",
      kaynak: "MANUEL",
      created_at: now,
      created_by: actor.userId,
      updated_at: now,
      updated_by: actor.userId
    };
    demoState.personelUcretleri.push(next);
    syncDemoLegacyMaas(personelId);
    return ok(next);
  }

  const personelUcretDetailMatch = pathname.match(/^\/personeller\/(\d+)\/ucretler\/(\d+)$/);
  if (personelUcretDetailMatch && method === "PUT") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "personeller.ucret.manage",
      "Ucret bilgisine erisim yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const personelId = Number.parseInt(personelUcretDetailMatch[1], 10);
    const ucretId = Number.parseInt(personelUcretDetailMatch[2], 10);
    const record = demoState.personelUcretleri.find(
      (item) => item.id === ucretId && item.personel_id === personelId
    );
    if (!record) {
      return demoRevizyonError("SALARY_RECORD_NOT_FOUND", "Ucret kaydi bulunamadi.");
    }
    if (record.state !== "AKTIF" || record.gecerlilik_baslangic <= demoTodayIsoDate()) {
      return demoRevizyonError(
        "SALARY_CHANGE_FORBIDDEN",
        "Baslamis veya iptal edilmis ucret kaydi degistirilemez."
      );
    }

    const normalized = normalizeDemoUcretBody({
      ucret_tutari: record.ucret_tutari,
      ucret_turu: record.ucret_turu,
      para_birimi: record.para_birimi,
      gecerlilik_baslangic: record.gecerlilik_baslangic,
      gecerlilik_bitis: record.gecerlilik_bitis,
      aciklama: record.aciklama,
      ...body
    });
    if ("error" in normalized) {
      return normalized.error;
    }
    if (
      demoUcretHasOverlap(
        personelId,
        normalized.value.gecerlilik_baslangic,
        normalized.value.gecerlilik_bitis,
        record.id
      )
    ) {
      return demoRevizyonError("SALARY_DATE_OVERLAP", DEMO_UCRET_OVERLAP_MESAJI);
    }

    Object.assign(record, normalized.value, {
      updated_at: new Date().toISOString(),
      updated_by: actor.userId
    });
    syncDemoLegacyMaas(personelId);
    return ok(record);
  }

  const personelUcretCancelMatch = pathname.match(/^\/personeller\/(\d+)\/ucretler\/(\d+)\/iptal$/);
  if (personelUcretCancelMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "personeller.ucret.manage",
      "Ucret bilgisine erisim yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const personelId = Number.parseInt(personelUcretCancelMatch[1], 10);
    const ucretId = Number.parseInt(personelUcretCancelMatch[2], 10);
    const record = demoState.personelUcretleri.find(
      (item) => item.id === ucretId && item.personel_id === personelId
    );
    if (!record) {
      return demoRevizyonError("SALARY_RECORD_NOT_FOUND", "Ucret kaydi bulunamadi.");
    }
    if (record.state !== "AKTIF") {
      return demoRevizyonError("SALARY_CHANGE_FORBIDDEN", "Ucret kaydi zaten iptal.");
    }

    record.state = "IPTAL";
    record.updated_at = new Date().toISOString();
    record.updated_by = actor.userId;
    syncDemoLegacyMaas(personelId);
    return ok(record);
  }

  if (pathname === "/surecler" && method === "GET") {
    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const surecTuru = toStringValue(requestUrl.searchParams.get("surec_turu"));
    const state = toStringValue(requestUrl.searchParams.get("state"));
    const baslangicTarihi = toStringValue(requestUrl.searchParams.get("baslangic_tarihi"));
    const bitisTarihi = toStringValue(requestUrl.searchParams.get("bitis_tarihi"));
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));

    const filtered = demoState.surecler.filter((item) => {
      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }

      if (surecTuru && item.surec_turu !== surecTuru) {
        return false;
      }

      if (state && item.state !== state) {
        return false;
      }

      if (baslangicTarihi && item.baslangic_tarihi !== baslangicTarihi) {
        return false;
      }

      if (bitisTarihi && item.bitis_tarihi !== bitisTarihi) {
        return false;
      }

      if (subeId !== null) {
        const linkedPersonel = demoState.personeller.find((personel) => personel.id === item.personel_id);
        if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
          return false;
        }
      }

      return true;
    });

    return ok({ items: filtered });
  }

  if (pathname === "/surecler" && method === "POST") {
    const surecTuru = toStringValue(body.surec_turu) ?? "IZIN";
    const altTur = toStringValue(body.alt_tur) ?? undefined;
    const next: DemoSurec = {
      id: ++demoState.nextIds.surec,
      personel_id: toNumber(body.personel_id) ?? 1,
      surec_turu: surecTuru,
      alt_tur: altTur,
      baslangic_tarihi: toStringValue(body.baslangic_tarihi) ?? undefined,
      bitis_tarihi: toStringValue(body.bitis_tarihi) ?? undefined,
      ucretli_mi: body.ucretli_mi === undefined ? true : Boolean(body.ucretli_mi),
      ilk_iki_gun_firma_oder_mi:
        surecTuru === "RAPOR" && altTur === "Raporlu_Hastalik"
          ? body.ilk_iki_gun_firma_oder_mi !== undefined && body.ilk_iki_gun_firma_oder_mi !== null
            ? Boolean(body.ilk_iki_gun_firma_oder_mi)
            : false
          : null,
      aciklama: toStringValue(body.aciklama) ?? undefined,
      state: "AKTIF"
    };
    demoState.surecler.unshift(next);

    if (next.surec_turu === "ISTEN_AYRILMA") {
      const targetPersonel = demoState.personeller.find((personel) => personel.id === next.personel_id);
      if (targetPersonel) {
        targetPersonel.aktif_durum = "PASIF";
      }
    }

    return ok(next);
  }

  if (pathname === "/zimmetler" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "personeller.detail.view");
    if (permissionError) {
      return permissionError;
    }

    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const zimmetDurumu = toStringValue(requestUrl.searchParams.get("zimmet_durumu"));
    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 10;

    const filtered = demoState.zimmetler.filter((item) => {
      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }
      if (zimmetDurumu && item.zimmet_durumu !== zimmetDurumu) {
        return false;
      }
      if (subeId !== null) {
        const linkedPersonel = demoState.personeller.find((personel) => personel.id === item.personel_id);
        if (!linkedPersonel || linkedPersonel.sube_id !== subeId) {
          return false;
        }
      }
      return true;
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit);
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok(
      { items },
      {
        page,
        limit,
        total,
        total_pages: totalPages
      }
    );
  }

  if (pathname === "/zimmetler" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "personeller.update");
    if (permissionError) {
      return permissionError;
    }

    if (typeof body.personel_id !== "number" && typeof body.personel_id !== "string") {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Personel secilmelidir.", field: "personel_id" }]
      };
    }
    const personelId = toNumber(body.personel_id);
    if (personelId === null || personelId <= 0) {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Personel secilmelidir.", field: "personel_id" }]
      };
    }

    const targetPersonel = demoState.personeller.find((personel) => personel.id === personelId);
    if (!targetPersonel) {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Personel bulunamadi.", field: "personel_id" }]
      };
    }
    if (targetPersonel.aktif_durum === "PASIF") {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Pasif personele zimmet kaydi eklenemez.", field: "personel_id" }]
      };
    }

    const requireString = (field: string, message: string) => {
      if (typeof body[field] !== "string" || body[field].trim() === "") {
        return {
          data: null,
          meta: {},
          errors: [{ code: "VALIDATION_ERROR", message, field }]
        };
      }
      return null;
    };

    for (const check of [
      requireString("urun_turu", "Urun turu zorunludur."),
      requireString("teslim_tarihi", "Teslim tarihi zorunludur."),
      requireString("teslim_eden", "Teslim eden bilgisi zorunludur."),
      requireString("teslim_durumu", "Teslim durumu zorunludur.")
    ]) {
      if (check) {
        return check;
      }
    }

    const urunTuru = String(body.urun_turu).trim().toUpperCase();
    const teslimDurumu = String(body.teslim_durumu).trim().toUpperCase();
    const validUrun = ["AYAKKABI", "KASK", "KULAKLIK", "MASKE", "TELEFON", "DIGER"];
    const validTeslim = ["YENI", "IKINCI_EL", "ARIZALI"];
    if (!validUrun.includes(urunTuru)) {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Urun turu gecerli degil.", field: "urun_turu" }]
      };
    }
    if (!validTeslim.includes(teslimDurumu)) {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Teslim durumu gecerli degil.", field: "teslim_durumu" }]
      };
    }

    const teslimTarihi = String(body.teslim_tarihi).trim();
    if (!/^\d{4}-\d{2}-\d{2}$/.test(teslimTarihi)) {
      return {
        data: null,
        meta: {},
        errors: [{ code: "VALIDATION_ERROR", message: "Teslim tarihi gecerli olmalidir.", field: "teslim_tarihi" }]
      };
    }

    const next: DemoZimmet = {
      id: ++demoState.nextIds.zimmet,
      personel_id: personelId,
      urun_turu: urunTuru,
      teslim_tarihi: teslimTarihi,
      teslim_eden: String(body.teslim_eden).trim(),
      aciklama: typeof body.aciklama === "string" && body.aciklama.trim() ? body.aciklama.trim() : undefined,
      teslim_durumu: teslimDurumu,
      zimmet_durumu: "AKTIF"
    };
    demoState.zimmetler.unshift(next);
    return ok(next);
  }

  const surecDetailMatch = pathname.match(/^\/surecler\/(\d+)$/);
  if (surecDetailMatch) {
    const id = Number.parseInt(surecDetailMatch[1], 10);
    const actor = readDemoApiActor(init);
    const surec = demoState.surecler.find((item) => item.id === id);

    if (method === "GET") {
      const permissionError = enforceDemoPermission(actor, "surecler.detail.view");
      if (permissionError) {
        return permissionError;
      }
      if (!surec) {
        return demoRevizyonError("NOT_FOUND", "Surec bulunamadi.");
      }
      return ok(surec);
    }

    if (method === "PUT") {
      const permissionError = enforceDemoPermission(actor, "surecler.update");
      if (permissionError) {
        return permissionError;
      }
      if (!surec) {
        return demoRevizyonError("NOT_FOUND", "Surec bulunamadi.");
      }
      const state = String(surec.state ?? "").toUpperCase();
      if (state === "IPTAL" || state === "TAMAMLANDI") {
        return demoRevizyonError("CONFLICT", "Iptal veya tamamlanmis surec guncellenemez.");
      }
      if (body.personel_id !== undefined && body.personel_id !== null && body.personel_id !== "") {
        const incoming = toNumber(body.personel_id);
        if (incoming !== null && incoming !== surec.personel_id) {
          return {
            data: null,
            meta: {},
            errors: [{ code: "VALIDATION_ERROR", message: "Surec personeli degistirilemez.", field: "personel_id" }]
          };
        }
      }
      const mutableKeys = [
        "surec_turu",
        "alt_tur",
        "baslangic_tarihi",
        "bitis_tarihi",
        "ucretli_mi",
        "ilk_iki_gun_firma_oder_mi",
        "aciklama"
      ] as const;
      const patch: Record<string, unknown> = {};
      for (const key of mutableKeys) {
        if (Object.prototype.hasOwnProperty.call(body, key)) {
          patch[key] = body[key];
        }
      }
      if (Object.keys(patch).length === 0) {
        return {
          data: null,
          meta: {},
          errors: [{ code: "VALIDATION_ERROR", message: "Guncellenecek alan bulunamadi.", field: "body" }]
        };
      }
      Object.assign(surec, patch);
      return ok(surec);
    }
  }

  const surecCancelMatch = pathname.match(/^\/surecler\/(\d+)\/iptal$/);
  if (surecCancelMatch && method === "POST") {
    const id = Number.parseInt(surecCancelMatch[1], 10);
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "surecler.cancel");
    if (permissionError) {
      return permissionError;
    }
    const surec = demoState.surecler.find((item) => item.id === id);
    if (!surec) {
      return demoRevizyonError("NOT_FOUND", "Surec bulunamadi.");
    }
    const state = String(surec.state ?? "").toUpperCase();
    if (state === "TAMAMLANDI") {
      return demoRevizyonError("CONFLICT", "Tamamlanmis surec iptal edilemez.");
    }
    surec.state = "IPTAL";
    return ok({ id: surec.id, state: surec.state });
  }

  if (pathname === "/bildirimler" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "bildirimler.view");
    if (permissionError) {
      return permissionError;
    }

    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 10;
    const tarih = toStringValue(requestUrl.searchParams.get("tarih"));
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const bildirimTuru = normalizeDemoBildirimTuru(requestUrl.searchParams.get("bildirim_turu"));
    const stateFilter = toStringValue(requestUrl.searchParams.get("state"))?.toUpperCase();

    const filtered = demoState.bildirimler.filter((item) => {
      if (tarih && item.tarih !== tarih) {
        return false;
      }
      if (departmanId !== null && item.departman_id !== departmanId) {
        return false;
      }
      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }
      if (bildirimTuru && item.bildirim_turu !== bildirimTuru) {
        return false;
      }
      if (stateFilter && (item.state ?? "").toUpperCase() !== stateFilter) {
        return false;
      }
      return true;
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit);
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok(
      { items },
      {
        page,
        limit,
        total,
        total_pages: totalPages
      }
    );
  }

  if (pathname === "/bildirimler" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "gunluk_bildirim.create");
    if (permissionError) {
      return permissionError;
    }

    const bildirimTuru = normalizeDemoBildirimTuru(toStringValue(body.bildirim_turu));
    if (!bildirimTuru) {
      return demoRevizyonError("VALIDATION_ERROR", "Bildirim turu gecerli degil.");
    }

    const aciklama = toStringValue(body.aciklama) ?? undefined;
    if (bildirimTuru === "DIGER" && !aciklama) {
      return demoRevizyonError("VALIDATION_ERROR", "DIGER turu icin aciklama zorunludur.");
    }

    const personelId = toNumber(body.personel_id) ?? undefined;
    const next: DemoBildirim = {
      id: ++demoState.nextIds.bildirim,
      tarih: toStringValue(body.tarih) ?? undefined,
      departman_id: toNumber(body.departman_id) ?? undefined,
      personel_id: personelId,
      sube_id: resolveDemoBildirimSubeId(personelId),
      bildirim_turu: bildirimTuru,
      aciklama,
      state: "TASLAK",
      okundu_mi: false,
      created_by: actor.userId,
      updated_by: actor.userId
    };
    demoState.bildirimler.unshift(next);
    return ok(next);
  }

  const bildirimDetailMatch = pathname.match(/^\/bildirimler\/(\d+)$/);
  if (bildirimDetailMatch) {
    const actor = readDemoApiActor(init);
    const id = Number.parseInt(bildirimDetailMatch[1], 10);
    const bildirim = demoState.bildirimler.find((item) => item.id === id);
    if (!bildirim) {
      return null;
    }

    if (method === "GET") {
      const permissionError = enforceDemoPermission(actor, "bildirimler.view");
      if (permissionError) {
        return permissionError;
      }

      return ok(bildirim);
    }

    if (method === "PUT") {
      const permissionError = enforceDemoPermission(actor, "gunluk_bildirim.update_own_open");
      if (permissionError) {
        return permissionError;
      }

      const ownershipError = assertDemoBildirimOwnership(actor, bildirim);
      if (ownershipError) {
        return ownershipError;
      }

      const stateError = assertDemoBildirimEditableState(bildirim);
      if (stateError) {
        return stateError;
      }

      if (body.bildirim_turu !== undefined) {
        const nextTur = normalizeDemoBildirimTuru(toStringValue(body.bildirim_turu));
        if (!nextTur) {
          return demoRevizyonError("VALIDATION_ERROR", "Bildirim turu gecerli degil.");
        }
        bildirim.bildirim_turu = nextTur;
      }

      if (body.aciklama !== undefined) {
        bildirim.aciklama = toStringValue(body.aciklama) ?? undefined;
      }

      const nextTur = bildirim.bildirim_turu;
      if (nextTur === "DIGER" && !bildirim.aciklama) {
        return demoRevizyonError("VALIDATION_ERROR", "DIGER turu icin aciklama zorunludur.");
      }

      bildirim.updated_by = actor.userId;
      return ok(bildirim);
    }
  }

  const bildirimSubmitMatch = pathname.match(/^\/bildirimler\/(\d+)\/submit$/);
  if (bildirimSubmitMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "gunluk_bildirim.submit");
    if (permissionError) {
      return permissionError;
    }

    const id = Number.parseInt(bildirimSubmitMatch[1], 10);
    const bildirim = demoState.bildirimler.find((item) => item.id === id);
    if (!bildirim) {
      return null;
    }

    const ownershipError = assertDemoBildirimOwnership(actor, bildirim);
    if (ownershipError) {
      return ownershipError;
    }

    const state = (bildirim.state ?? "").toUpperCase();
    if (state === "GONDERILDI") {
      return ok(bildirim);
    }
    if (state === "IPTAL") {
      return demoBildirimConflict("Iptal edilmis bildirim gonderilemez.");
    }
    if (!(DEMO_BILDIRIM_EDITABLE_STATES as readonly string[]).includes(state)) {
      return demoBildirimConflict("Bu durumdaki bildirim gonderilemez.");
    }

    bildirim.state = "GONDERILDI";
    bildirim.submitted_at = new Date().toISOString();
    bildirim.updated_by = actor.userId;
    return ok(bildirim);
  }

  const bildirimCorrectionMatch = pathname.match(/^\/bildirimler\/(\d+)\/request-correction$/);
  if (bildirimCorrectionMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "gunluk_bildirim.request_correction");
    if (permissionError) {
      return permissionError;
    }

    const id = Number.parseInt(bildirimCorrectionMatch[1], 10);
    const bildirim = demoState.bildirimler.find((item) => item.id === id);
    if (!bildirim) {
      return null;
    }

    const reason = toStringValue(body.correction_reason)?.trim() ?? "";
    if (!reason) {
      return demoRevizyonError("VALIDATION_ERROR", "Duzeltme nedeni zorunludur.");
    }

    const state = (bildirim.state ?? "").toUpperCase();
    if (state !== "GONDERILDI") {
      return demoBildirimConflict("Yalnizca gonderilmis bildirimler icin duzeltme istenebilir.");
    }

    bildirim.state = "DUZELTME_ISTENDI";
    bildirim.correction_requested_by = actor.userId;
    bildirim.correction_reason = reason;
    bildirim.updated_by = actor.userId;
    return ok(bildirim);
  }

  const bildirimCancelMatch = pathname.match(/^\/bildirimler\/(\d+)\/iptal$/);
  if (bildirimCancelMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "gunluk_bildirim.update_own_open");
    if (permissionError) {
      return permissionError;
    }

    const id = Number.parseInt(bildirimCancelMatch[1], 10);
    const bildirim = demoState.bildirimler.find((item) => item.id === id);
    if (!bildirim) {
      return null;
    }

    const ownershipError = assertDemoBildirimOwnership(actor, bildirim);
    if (ownershipError) {
      return ownershipError;
    }

    const state = (bildirim.state ?? "").toUpperCase();
    if (state === "IPTAL") {
      return ok(bildirim);
    }
    if (!(DEMO_BILDIRIM_EDITABLE_STATES as readonly string[]).includes(state)) {
      return demoBildirimConflict("Bu durumdaki bildirim iptal edilemez.");
    }

    bildirim.state = "IPTAL";
    bildirim.updated_by = actor.userId;
    return ok(bildirim);
  }

  const puantajMatch = pathname.match(/^\/gunluk-puantaj\/(\d+)\/([^/]+)$/);
  if (puantajMatch) {
    const actor = readDemoApiActor(init);
    const personelId = Number.parseInt(puantajMatch[1], 10);
    const tarih = decodeURIComponent(puantajMatch[2]);
    const key = `${personelId}|${tarih}`;
    const existing = demoState.puantajMap[key] ?? defaultPuantaj(personelId, tarih);

    if (method === "GET") {
      const permissionError = enforceDemoPermission(actor, "puantaj.view");
      if (permissionError) {
        return permissionError;
      }

      demoState.puantajMap[key] = existing;
      return ok(existing);
    }

    if (method === "PUT") {
      const permissionError = enforceDemoPuantajUpsertPermission(actor, body);
      if (permissionError) {
        return permissionError;
      }

      const hasDurumuBildirdiMi = Object.prototype.hasOwnProperty.call(body, "durumu_bildirdi_mi");
      const hasDurumBildirimAciklamasi = Object.prototype.hasOwnProperty.call(
        body,
        "durum_bildirim_aciklamasi"
      );
      const nextDurumuBildirdiMi = toBooleanValue(body.durumu_bildirdi_mi);
      const nextDurumBildirimAciklamasi =
        nextDurumuBildirdiMi === true
          ? hasDurumBildirimAciklamasi
            ? toStringValue(body.durum_bildirim_aciklamasi) ?? undefined
            : existing.durum_bildirim_aciklamasi
          : hasDurumuBildirdiMi
            ? undefined
            : existing.durum_bildirim_aciklamasi;
      const updated: DemoPuantaj = {
        ...existing,
        gun_tipi: readDemoPuantajGunTipi(body.gun_tipi) ?? existing.gun_tipi,
        hareket_durumu: readDemoPuantajHareketDurumu(body.hareket_durumu) ?? existing.hareket_durumu,
        dayanak: readDemoPuantajDayanak(body.dayanak) ?? existing.dayanak,
        durumu_bildirdi_mi: hasDurumuBildirdiMi
          ? nextDurumuBildirdiMi ?? undefined
          : existing.durumu_bildirdi_mi,
        durum_bildirim_aciklamasi: nextDurumBildirimAciklamasi,
        hesap_etkisi: readDemoPuantajHesapEtkisi(body.hesap_etkisi) ?? existing.hesap_etkisi,
        beklenen_giris_saati: toStringValue(body.beklenen_giris_saati) ?? existing.beklenen_giris_saati,
        beklenen_cikis_saati: toStringValue(body.beklenen_cikis_saati) ?? existing.beklenen_cikis_saati,
        giris_saati: toStringValue(body.giris_saati) ?? existing.giris_saati,
        cikis_saati: toStringValue(body.cikis_saati) ?? existing.cikis_saati,
        gercek_mola_dakika: toNumber(body.gercek_mola_dakika) ?? existing.gercek_mola_dakika,
        kontrol_durumu: readDemoPuantajKontrolDurumu(body.kontrol_durumu) ?? existing.kontrol_durumu ?? "BEKLIYOR"
      };
      demoState.puantajMap[key] = updated;
      return ok(updated);
    }
  }

  if (pathname === "/puantaj/muhurle" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) {
      return permissionError;
    }

    const yil = toNumber(body.yil) ?? new Date().getFullYear();
    const ay = toNumber(body.ay) ?? new Date().getMonth() + 1;
    const donemPrefix = `${yil}-${String(ay).padStart(2, "0")}`;
    let count = 0;
    for (const [key, entry] of Object.entries(demoState.puantajMap)) {
      if (key.includes(`|${donemPrefix}`) && entry.state !== "MUHURLENDI") {
        entry.state = "MUHURLENDI";
        count++;
      }
    }
    return ok({ muhurlenen_kayit_sayisi: count, donem: donemPrefix });
  }

  if (pathname === "/haftalik-kapanis/revizyon-talepleri" && method === "GET") {
    return buildDemoRevizyonTalebiListResponse(requestUrl, readDemoRevizyonActor(init));
  }

  if (pathname === "/haftalik-kapanis/revizyon-talepleri" && method === "POST") {
    return createDemoRevizyonTalebi(body, readDemoRevizyonActor(init));
  }

  const revizyonTalebiActionMatch = pathname.match(
    /^\/haftalik-kapanis\/revizyon-talepleri\/(\d+)\/(gonder|onay|red|iptal)$/
  );
  if (revizyonTalebiActionMatch && method === "POST") {
    const talepId = Number.parseInt(revizyonTalebiActionMatch[1], 10);
    const action = revizyonTalebiActionMatch[2] as "gonder" | "onay" | "red" | "iptal";
    return handleDemoRevizyonAction(talepId, action, body, readDemoRevizyonActor(init));
  }

  const revizyonTalebiDetailMatch = pathname.match(/^\/haftalik-kapanis\/revizyon-talepleri\/(\d+)$/);
  if (revizyonTalebiDetailMatch && method === "GET") {
    const talepId = Number.parseInt(revizyonTalebiDetailMatch[1], 10);
    return buildDemoRevizyonTalebiDetailResponse(talepId, readDemoRevizyonActor(init));
  }

  const revizyonCorrectionProduceMatch = pathname.match(
    /^\/haftalik-kapanis\/revizyon-talepleri\/(\d+)\/correction-uret$/
  );
  if (revizyonCorrectionProduceMatch && method === "POST") {
    const talepId = Number.parseInt(revizyonCorrectionProduceMatch[1], 10);
    return produceDemoRevizyonCorrection(talepId, readDemoRevizyonActor(init));
  }

  if (pathname === "/haftalik-kapanis/revizyon-corrections" && method === "GET") {
    return buildDemoRevizyonCorrectionListResponse(requestUrl, readDemoRevizyonActor(init));
  }

  const revizyonCorrectionCancelMatch = pathname.match(
    /^\/haftalik-kapanis\/revizyon-corrections\/(\d+)\/iptal$/
  );
  if (revizyonCorrectionCancelMatch && method === "POST") {
    const correctionId = Number.parseInt(revizyonCorrectionCancelMatch[1], 10);
    return cancelDemoRevizyonCorrection(
      correctionId,
      readDemoRevizyonActor(init),
      body
    );
  }

  const revizyonCorrectionDetailMatch = pathname.match(
    /^\/haftalik-kapanis\/revizyon-corrections\/(\d+)$/
  );
  if (revizyonCorrectionDetailMatch && method === "GET") {
    const correctionId = Number.parseInt(revizyonCorrectionDetailMatch[1], 10);
    return buildDemoRevizyonCorrectionDetailResponse(
      correctionId,
      readDemoRevizyonActor(init)
    );
  }

  if (pathname === "/haftalik-kapanis/yillik-fazla-calisma" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.view");
    if (permissionError) {
      return permissionError;
    }

    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil"));

    if (personelId === null || personelId < 1 || yil === null || yil < 1) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "INVALID_QUERY",
            message: "personel_id ve yil zorunludur ve pozitif tam sayi olmalidir."
          }
        ]
      };
    }

    const personel = demoState.personeller.find((item) => item.id === personelId);
    if (!personel) {
      return demoRevizyonError("NOT_FOUND", "Personel bulunamadi.");
    }
    if (
      actor.subeIds.length > 0 &&
      (typeof personel.sube_id !== "number" || !actor.subeIds.includes(personel.sube_id))
    ) {
      return demoRevizyonError("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.");
    }

    const scopedKapanislar = Object.values(demoState.kapanisById).filter((kapanis) => {
      const subeId = (kapanis as HaftalikKapanisSonuc & { sube_id?: number }).sube_id ?? 1;
      if (actor.subeIds.length > 0 && !actor.subeIds.includes(subeId)) {
        return false;
      }
      return true;
    });

    const ozet = aggregateYillikFazlaCalisma({
      kapanislar: scopedKapanislar,
      personel_id: personelId,
      yil
    });

    return ok(ozet);
  }

  const haftalikKapanisDetailMatch = pathname.match(/^\/haftalik-kapanis\/(\d+)$/);
  if (haftalikKapanisDetailMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.view");
    if (permissionError) {
      return permissionError;
    }

    const kapanisId = Number.parseInt(haftalikKapanisDetailMatch[1], 10);
    const kayit = demoState.kapanisById[kapanisId];
    if (!kayit) {
      // Fall through to HTTP layer so 404 status is preserved (apiRequest demo short-circuit).
      return null;
    }
    const subeId = (kayit as HaftalikKapanisSonuc & { sube_id?: number }).sube_id ?? 1;
    if (actor.subeIds.length > 0 && !actor.subeIds.includes(subeId)) {
      return demoRevizyonError("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.");
    }

    return ok(kayit);
  }

  if (pathname === "/haftalik-kapanis" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) {
      return permissionError;
    }

    const subeId = resolveDemoKapanisSubeId(actor, init);
    if (subeId === null) {
      return demoRevizyonError("VALIDATION_ERROR", "Haftalik kapanis icin aktif sube secilmelidir.");
    }

    const week = resolveDemoHaftaPair(body.hafta_baslangic, body.hafta_bitis);
    if ("errors" in week) {
      return week;
    }
    const { start: hafta_baslangic, end: hafta_bitis } = week;

    const departmanIdFromBody = toNumber(body.departman_id);
    const departman_id =
      body.departman_id === undefined || body.departman_id === null ? undefined : departmanIdFromBody;
    if (body.departman_id !== undefined && body.departman_id !== null && departman_id === null) {
      return demoRevizyonError("VALIDATION_ERROR", "departman_id pozitif tam sayi olmalidir.");
    }

    const mutabakatlar = demoState.haftalikBildirimMutabakatlari.filter(
      (item) => item.sube_id === subeId && item.hafta_baslangic === hafta_baslangic
    );
    if (mutabakatlar.length < 1) {
      return demoRevizyonError("STATE_CONFLICT", "Haftalik mutabakat bulunamadi.");
    }
    if (mutabakatlar.some((item) => item.state !== "TAMAMLANDI")) {
      return demoRevizyonError("STATE_CONFLICT", "Haftalik mutabakat tamamlanmamis.");
    }

    const duplicate = Object.values(demoState.kapanisById).find((item) => {
      const itemSube = (item as HaftalikKapanisSonuc & { sube_id?: number }).sube_id ?? 1;
      const itemDepartman = item.departman_id ?? null;
      const requestDepartman = departman_id ?? null;
      return (
        itemSube === subeId &&
        item.hafta_baslangic === hafta_baslangic &&
        itemDepartman === requestDepartman
      );
    });
    if (duplicate) {
      return demoRevizyonError(
        "STATE_CONFLICT",
        "Bu sube, hafta ve departman kapsami icin haftalik kapanis zaten olusturulmus."
      );
    }

    const kapanisId = ++demoState.nextIds.kapanis;
    const kapsamPersoneller =
      departman_id != null
        ? demoState.personeller.filter(
            (personel) => personel.departman_id === departman_id && personel.sube_id === subeId
          )
        : demoState.personeller.filter((personel) => personel.sube_id === subeId);

    const snapshot = buildHaftalikKapanisSnapshot({
      kapanis_id: kapanisId,
      hafta_baslangic,
      hafta_bitis,
      departman_id: departman_id ?? undefined,
      personeller: kapsamPersoneller.map((personel) => ({
        id: personel.id,
        departman_id: personel.departman_id,
        dogum_tarihi: personel.dogum_tarihi ?? null
      })),
      resolvePuantaj: (personelId, tarih) => {
        const kayit = demoState.puantajMap[`${personelId}|${tarih}`];
        return kayit ?? null;
      }
    });

    const response: HaftalikKapanisSonuc & { sube_id: number } = {
      id: kapanisId,
      kapanis_id: kapanisId,
      hafta_baslangic,
      hafta_bitis,
      departman_id: departman_id ?? undefined,
      state: "KAPANDI",
      personel_sayisi: snapshot.personel_sayisi,
      snapshot_satir_sayisi: snapshot.snapshot_satir_sayisi,
      snapshot_satirlari: snapshot.snapshot_satirlari,
      sube_id: subeId
    };
    demoState.kapanisById[kapanisId] = response;

    return ok(response);
  }

  if (pathname === "/fazla-calisma-odeme-tercihi" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.view");
    if (permissionError) {
      return permissionError;
    }

    const snapshotId = toNumber(requestUrl.searchParams.get("snapshot_id"));
    if (snapshotId === null || snapshotId < 1) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "INVALID_QUERY",
            message: "snapshot_id zorunludur ve pozitif tam sayi olmalidir."
          }
        ]
      };
    }

    const satir = findDemoSnapshotSatir(snapshotId);
    if (!satir) {
      return demoOdemeTercihiNotFound(snapshotId);
    }

    const subeId = findDemoKapanisSubeIdForSnapshot(snapshotId);
    if (subeId !== null && actor.subeIds.length > 0 && !actor.subeIds.includes(subeId)) {
      return demoRevizyonError("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.");
    }

    const stored = demoState.odemeTercihiBySnapshotId[snapshotId];
    if (stored) {
      return ok(stored);
    }

    // Synthetic default — no DB/demo write.
    return ok(buildSyntheticOdemeTercihi(satir));
  }

  if (pathname === "/fazla-calisma-odeme-tercihi" && method === "PUT") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) {
      return permissionError;
    }

    for (const field of FCOT_SERVER_OWNED_FIELDS) {
      if (Object.prototype.hasOwnProperty.call(body, field)) {
        return demoFcotValidationError(field, `${field} istemci tarafindan belirlenemez.`);
      }
    }

    const snapshotId = toNumber(body.snapshot_id);
    if (snapshotId === null || snapshotId < 1) {
      return demoFcotValidationError(
        "snapshot_id",
        "snapshot_id zorunludur ve pozitif tam sayi olmalidir."
      );
    }

    const odemeTipi = body.odeme_tipi;
    if (!isDemoOdemeTipi(odemeTipi)) {
      return demoFcotValidationError("odeme_tipi", "odeme_tipi gecersiz.");
    }

    const satir = findDemoSnapshotSatir(snapshotId);
    if (!satir) {
      return demoOdemeTercihiNotFound(snapshotId);
    }

    const kapanis_id = satir.kapanis_id;
    if (kapanis_id === undefined) {
      return demoOdemeTercihiNotFound(snapshotId);
    }

    const subeId = findDemoKapanisSubeIdForSnapshot(snapshotId) ?? 1;
    if (actor.subeIds.length > 0 && !actor.subeIds.includes(subeId)) {
      return demoRevizyonError("FORBIDDEN", "Bu kayit aktif sube baglaminda goruntulenemiyor.");
    }

    const periodError = assertDemoWeekPeriodsOpen(subeId, satir.hafta_baslangic, satir.hafta_bitis);
    if (periodError) {
      return periodError;
    }

    const existing = demoState.odemeTercihiBySnapshotId[snapshotId];
    if (existing && existing.odeme_tipi === odemeTipi) {
      // Idempotent: no updated_at / audit side effects.
      return ok(existing);
    }

    const onceki_odeme_tipi = existing?.odeme_tipi ?? DEFAULT_ODEME_TIPI;
    if (
      existing?.id !== undefined &&
      onceki_odeme_tipi === "SERBEST_ZAMAN" &&
      (odemeTipi === "UCRET" || odemeTipi === "KARAR_BEKLIYOR") &&
      hasActiveDemoSerbestZamanOlusum(existing.id)
    ) {
      return demoRevizyonError(
        "STATE_CONFLICT",
        "Aktif serbest zaman olusumu varken odeme tipi degistirilemez."
      );
    }

    const now = new Date().toISOString();
    const tercihId = existing?.id ?? ++demoState.nextIds.odemeTercihi;
    const next: FazlaCalismaOdemeTercihi = {
      id: tercihId,
      snapshot_id: snapshotId,
      kapanis_id,
      personel_id: satir.personel_id,
      hafta_baslangic: satir.hafta_baslangic,
      hafta_bitis: satir.hafta_bitis,
      fazla_calisma_dakika: guvenliFazlaCalismaDakika(satir.fazla_calisma_dakika),
      odeme_tipi: odemeTipi,
      secim_zamani: now,
      secen_kullanici_id: actor.userId,
      onceki_odeme_tipi,
      gerekce: toStringValue(body.gerekce) ?? undefined
    };

    demoState.odemeTercihiBySnapshotId[snapshotId] = next;
    demoState.odemeTercihiAudit.push({
      tercih_id: tercihId,
      snapshot_id: snapshotId,
      onceki_odeme_tipi,
      yeni_odeme_tipi: odemeTipi,
      secen_kullanici_id: actor.userId,
      secim_zamani: now,
      gerekce: next.gerekce
    });

    return ok(next);
  }

  if (pathname === "/serbest-zaman/events" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.view");
    if (permissionError) return permissionError;

    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    if (personelId === null || personelId < 1) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "INVALID_QUERY",
            message: "personel_id zorunludur ve pozitif tam sayi olmalidir."
          }
        ]
      };
    }

    const personel = findDemoPersonelById(personelId);
    if (!personel) {
      return demoSerbestZamanOlusumError("NOT_FOUND", "personel bulunamadi.");
    }
    const scopeError = assertDemoSzPersonelScope(actor, personel);
    if (scopeError) return scopeError;

    const items = listDemoSerbestZamanEvents().filter((event) => event.personel_id === personelId);
    return ok({ items });
  }

  if (pathname === "/serbest-zaman/bakiye" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.view");
    if (permissionError) return permissionError;

    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    if (personelId === null || personelId < 1) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "INVALID_QUERY",
            message: "personel_id zorunludur ve pozitif tam sayi olmalidir."
          }
        ]
      };
    }

    const personel = findDemoPersonelById(personelId);
    if (!personel) {
      return demoSerbestZamanOlusumError("NOT_FOUND", "personel bulunamadi.");
    }
    const scopeError = assertDemoSzPersonelScope(actor, personel);
    if (scopeError) return scopeError;

    const referans_tarih = toStringValue(requestUrl.searchParams.get("referans_tarih"));
    const bakiye = hesaplaSerbestZamanBakiye({
      personel_id: personelId,
      events: listDemoSerbestZamanEvents(),
      referans_tarih: referans_tarih ?? undefined
    });

    return ok(bakiye);
  }

  if (pathname === "/serbest-zaman/olusum" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) return permissionError;

    for (const field of [
      "personel_id",
      "dakika",
      "event_tarihi",
      "son_kullanim_tarihi",
      "created_by",
      "created_at",
      "sube_id",
      "islem_anahtari"
    ]) {
      if (Object.prototype.hasOwnProperty.call(body, field)) {
        return demoFcotValidationError(field, `${field} istemci tarafindan belirlenemez.`);
      }
    }

    const odemeTercihiId = toNumber(body.odeme_tercihi_id);
    const snapshotId = toNumber(body.snapshot_id);

    if (
      (odemeTercihiId === null || odemeTercihiId < 1) &&
      (snapshotId === null || snapshotId < 1)
    ) {
      return demoFcotValidationError(
        "odeme_tercihi_id",
        "odeme_tercihi_id veya snapshot_id zorunludur."
      );
    }

    const tercih = resolvePersistedOdemeTercihi({
      odeme_tercihi_id: odemeTercihiId !== null && odemeTercihiId >= 1 ? odemeTercihiId : undefined,
      snapshot_id: snapshotId !== null && snapshotId >= 1 ? snapshotId : undefined
    });

    if (!tercih || tercih.id === undefined) {
      return demoSerbestZamanOlusumError(
        "NOT_PERSISTED",
        "Odeme tercihi persist edilmemis; olusum eventi uretilemez."
      );
    }

    const personel = findDemoPersonelById(tercih.personel_id);
    if (!personel) {
      return demoSerbestZamanOlusumError("NOT_FOUND", "personel bulunamadi.");
    }
    const scopeError = assertDemoSzPersonelScope(actor, personel);
    if (scopeError) return scopeError;

    if (hasActiveDemoSerbestZamanOlusum(tercih.id)) {
      return demoSerbestZamanOlusumError(
        "ALREADY_EXISTS",
        "Bu odeme tercihi icin aktif serbest zaman olusumu zaten mevcut."
      );
    }

    const sonuc = olusturOlusumEvent({
      tercih,
      mevcutEvents: listDemoSerbestZamanEvents()
    });

    if (!sonuc.ok) {
      const messages: Record<string, string> = {
        ALREADY_EXISTS: "Bu odeme tercihi icin serbest zaman olusum eventi zaten mevcut.",
        NOT_ELIGIBLE: "Odeme tercihi SERBEST_ZAMAN degil; olusum eventi uretilemez.",
        ZERO_DAKIKA: "Fazla calisma dakikasi sifir; olusum eventi uretilemez.",
        NOT_PERSISTED: "Odeme tercihi persist edilmemis; olusum eventi uretilemez."
      };

      return demoSerbestZamanOlusumError(sonuc.code, messages[sonuc.code] ?? sonuc.code);
    }

    const eventId = ++demoState.nextIds.serbestZamanEvent;
    const subeId = typeof personel.sube_id === "number" ? personel.sube_id : 1;
    const donem = resolveDemoDonemMeta(subeId, sonuc.event.event_tarihi);
    const persisted: SerbestZamanEvent = {
      ...sonuc.event,
      id: eventId,
      ...donem
    };
    demoState.serbestZamanEventsById[eventId] = persisted;
    demoState.serbestZamanAktifOlusumByTercihId[tercih.id] = eventId;

    return ok(persisted);
  }

  if (pathname === "/serbest-zaman/kullanim" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) return permissionError;

    if (Object.prototype.hasOwnProperty.call(body, "sube_id")) {
      return demoFcotValidationError("sube_id", "sube_id istemci tarafindan belirlenemez.");
    }

    const personelId = toNumber(body.personel_id);
    const dakika = toNumber(body.dakika);
    const eventTarihi = toStringValue(body.event_tarihi);
    const islemAnahtari = toStringValue(body.islem_anahtari);

    if (personelId === null || personelId < 1) {
      return demoFcotValidationError(
        "personel_id",
        "personel_id zorunludur ve pozitif tam sayi olmalidir."
      );
    }

    if (dakika === null || dakika <= 0) {
      return demoSerbestZamanOlusumError("ZERO_DAKIKA", "Kullanim dakikasi pozitif olmalidir.");
    }

    if (!eventTarihi || !/^\d{4}-\d{2}-\d{2}$/.test(eventTarihi.trim())) {
      return demoFcotValidationError("event_tarihi", "event_tarihi YYYY-MM-DD formatinda olmalidir.");
    }

    if (!islemAnahtari) {
      return demoFcotValidationError("islem_anahtari", "islem_anahtari zorunludur.");
    }

    const personel = findDemoPersonelById(personelId);
    if (!personel) {
      return demoSerbestZamanOlusumError("NOT_FOUND", "personel bulunamadi.");
    }
    const scopeError = assertDemoSzPersonelScope(actor, personel);
    if (scopeError) return scopeError;

    const existing = findDemoSerbestZamanByIslemAnahtari(personelId, islemAnahtari);
    if (existing) {
      if (
        existing.event_tipi !== "SERBEST_ZAMAN_KULLANIM" ||
        existing.dakika !== dakika ||
        existing.event_tarihi !== eventTarihi.trim().slice(0, 10)
      ) {
        return demoSerbestZamanOlusumError(
          "IDEMPOTENCY_CONFLICT",
          "Ayni islem_anahtari farkli payload ile kullanilmis."
        );
      }
      return ok(existing);
    }

    const sonuc = olusturKullanimEvent({
      personel_id: personelId,
      dakika,
      event_tarihi: eventTarihi.trim().slice(0, 10),
      islem_anahtari: islemAnahtari,
      mevcutEvents: listDemoSerbestZamanEvents(),
      aciklama: toStringValue(body.aciklama) ?? undefined
    });

    if (!sonuc.ok) {
      const messages: Record<string, string> = {
        ZERO_DAKIKA: "Kullanim dakikasi pozitif olmalidir.",
        NO_ELIGIBLE_BALANCE: "Kullanilabilir serbest zaman bakiyesi yok.",
        INSUFFICIENT_BALANCE: "Kullanim miktari mevcut bakiyeyi asiyor."
      };

      return demoSerbestZamanOlusumError(sonuc.code, messages[sonuc.code] ?? sonuc.code);
    }

    const eventId = ++demoState.nextIds.serbestZamanEvent;
    const subeId = typeof personel.sube_id === "number" ? personel.sube_id : 1;
    const donem = resolveDemoDonemMeta(subeId, sonuc.event.event_tarihi);
    const persisted: SerbestZamanEvent = {
      ...sonuc.event,
      id: eventId,
      ...donem
    };
    demoState.serbestZamanEventsById[eventId] = persisted;

    return ok(persisted);
  }

  if (pathname === "/serbest-zaman/iptal" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) return permissionError;

    if (Object.prototype.hasOwnProperty.call(body, "sube_id")) {
      return demoFcotValidationError("sube_id", "sube_id istemci tarafindan belirlenemez.");
    }

    const personelId = toNumber(body.personel_id);
    const hedefEventId = toNumber(body.hedef_event_id);
    const hedefEventTipi = toStringValue(body.hedef_event_tipi);
    const eventTarihi = toStringValue(body.event_tarihi);
    const islemAnahtari = toStringValue(body.islem_anahtari);

    if (personelId === null || personelId < 1) {
      return demoFcotValidationError(
        "personel_id",
        "personel_id zorunludur ve pozitif tam sayi olmalidir."
      );
    }

    if (hedefEventId === null || hedefEventId < 1) {
      return demoSerbestZamanOlusumError(
        "TARGET_NOT_FOUND",
        "hedef_event_id gecerli bir event id olmalidir."
      );
    }

    if (
      hedefEventTipi !== "SERBEST_ZAMAN_OLUSUM" &&
      hedefEventTipi !== "SERBEST_ZAMAN_KULLANIM"
    ) {
      return demoSerbestZamanOlusumError(
        "UNSUPPORTED_TARGET_EVENT",
        "hedef_event_tipi OLUSUM veya KULLANIM olmalidir."
      );
    }

    if (!eventTarihi || !/^\d{4}-\d{2}-\d{2}$/.test(eventTarihi.trim())) {
      return demoFcotValidationError("event_tarihi", "event_tarihi YYYY-MM-DD formatinda olmalidir.");
    }

    if (!islemAnahtari) {
      return demoFcotValidationError("islem_anahtari", "islem_anahtari zorunludur.");
    }

    const personel = findDemoPersonelById(personelId);
    if (!personel) {
      return demoSerbestZamanOlusumError("NOT_FOUND", "personel bulunamadi.");
    }
    const scopeError = assertDemoSzPersonelScope(actor, personel);
    if (scopeError) return scopeError;

    const existing = findDemoSerbestZamanByIslemAnahtari(personelId, islemAnahtari);
    if (existing) {
      if (
        existing.event_tipi !== "SERBEST_ZAMAN_IPTAL" ||
        existing.hedef_event_id !== hedefEventId ||
        existing.hedef_event_tipi !== hedefEventTipi ||
        existing.event_tarihi !== eventTarihi.trim().slice(0, 10)
      ) {
        return demoSerbestZamanOlusumError(
          "IDEMPOTENCY_CONFLICT",
          "Ayni islem_anahtari farkli payload ile kullanilmis."
        );
      }
      return ok(existing);
    }

    const sonuc = olusturIptalEvent({
      personel_id: personelId,
      hedef_event_id: hedefEventId,
      hedef_event_tipi: hedefEventTipi,
      event_tarihi: eventTarihi.trim().slice(0, 10),
      islem_anahtari: islemAnahtari,
      mevcutEvents: listDemoSerbestZamanEvents(),
      aciklama: toStringValue(body.aciklama) ?? undefined
    });

    if (!sonuc.ok) {
      const messages: Record<string, string> = {
        TARGET_NOT_FOUND: "Hedef event bulunamadi.",
        TARGET_PERSONEL_MISMATCH: "Hedef event bu personele ait degil.",
        TARGET_ALREADY_CANCELLED: "Hedef event zaten iptal edilmis.",
        ALREADY_CANCELLED: "Bu hedef event icin iptal zaten mevcut.",
        UNSUPPORTED_TARGET_EVENT: "Hedef event tipi desteklenmiyor."
      };

      return demoSerbestZamanOlusumError(sonuc.code, messages[sonuc.code] ?? sonuc.code);
    }

    const eventId = ++demoState.nextIds.serbestZamanEvent;
    const subeId = typeof personel.sube_id === "number" ? personel.sube_id : 1;
    const donem = resolveDemoDonemMeta(subeId, sonuc.event.event_tarihi);
    const persisted = {
      ...sonuc.event,
      id: eventId,
      ...donem
    };
    demoState.serbestZamanEventsById[eventId] = persisted;

    if (hedefEventTipi === "SERBEST_ZAMAN_OLUSUM") {
      for (const [tercihId, olusumId] of Object.entries(demoState.serbestZamanAktifOlusumByTercihId)) {
        if (olusumId === hedefEventId) {
          delete demoState.serbestZamanAktifOlusumByTercihId[Number(tercihId)];
        }
      }
    }

    return ok(persisted);
  }

  if (pathname === "/serbest-zaman/duzeltme" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.muhurle");
    if (permissionError) return permissionError;

    if (Object.prototype.hasOwnProperty.call(body, "sube_id")) {
      return demoFcotValidationError("sube_id", "sube_id istemci tarafindan belirlenemez.");
    }

    const personelId = toNumber(body.personel_id);
    const hedefEventId = toNumber(body.hedef_event_id);
    const hedefEventTipi = toStringValue(body.hedef_event_tipi);
    const yeniDakika = toNumber(body.yeni_dakika);
    const eventTarihi = toStringValue(body.event_tarihi);
    const islemAnahtari = toStringValue(body.islem_anahtari);
    const aciklama = toStringValue(body.aciklama);

    if (personelId === null || personelId < 1) {
      return demoFcotValidationError(
        "personel_id",
        "personel_id zorunludur ve pozitif tam sayi olmalidir."
      );
    }

    if (hedefEventId === null || hedefEventId < 1) {
      return demoSerbestZamanOlusumError(
        "TARGET_NOT_FOUND",
        "hedef_event_id gecerli bir event id olmalidir."
      );
    }

    if (
      hedefEventTipi !== "SERBEST_ZAMAN_OLUSUM" &&
      hedefEventTipi !== "SERBEST_ZAMAN_KULLANIM"
    ) {
      return demoSerbestZamanOlusumError(
        "UNSUPPORTED_TARGET_EVENT",
        "hedef_event_tipi OLUSUM veya KULLANIM olmalidir."
      );
    }

    if (yeniDakika === null || yeniDakika <= 0) {
      return demoSerbestZamanOlusumError("ZERO_DAKIKA", "yeni_dakika pozitif olmalidir.");
    }

    if (!eventTarihi || !/^\d{4}-\d{2}-\d{2}$/.test(eventTarihi.trim())) {
      return demoFcotValidationError("event_tarihi", "event_tarihi YYYY-MM-DD formatinda olmalidir.");
    }

    if (!islemAnahtari) {
      return demoFcotValidationError("islem_anahtari", "islem_anahtari zorunludur.");
    }

    if (!aciklama) {
      return demoFcotValidationError("aciklama", "aciklama zorunludur.");
    }

    const personel = findDemoPersonelById(personelId);
    if (!personel) {
      return demoSerbestZamanOlusumError("NOT_FOUND", "personel bulunamadi.");
    }
    const scopeError = assertDemoSzPersonelScope(actor, personel);
    if (scopeError) return scopeError;

    const existing = findDemoSerbestZamanByIslemAnahtari(personelId, islemAnahtari);
    if (existing) {
      if (
        existing.event_tipi !== "SERBEST_ZAMAN_DUZELTME" ||
        existing.hedef_event_id !== hedefEventId ||
        existing.hedef_event_tipi !== hedefEventTipi ||
        existing.yeni_dakika !== yeniDakika ||
        existing.event_tarihi !== eventTarihi.trim().slice(0, 10) ||
        (existing.aciklama ?? "") !== aciklama
      ) {
        return demoSerbestZamanOlusumError(
          "IDEMPOTENCY_CONFLICT",
          "Ayni islem_anahtari farkli payload ile kullanilmis."
        );
      }
      return ok(existing);
    }

    const sonuc = olusturDuzeltmeEvent({
      personel_id: personelId,
      hedef_event_id: hedefEventId,
      hedef_event_tipi: hedefEventTipi,
      yeni_dakika: yeniDakika,
      event_tarihi: eventTarihi.trim().slice(0, 10),
      islem_anahtari: islemAnahtari,
      mevcutEvents: listDemoSerbestZamanEvents(),
      aciklama
    });

    if (!sonuc.ok) {
      const messages: Record<string, string> = {
        TARGET_NOT_FOUND: "Hedef event bulunamadi.",
        TARGET_PERSONEL_MISMATCH: "Hedef event bu personele ait degil.",
        TARGET_ALREADY_CANCELLED: "Hedef event iptal edilmis; duzeltme yapilamaz.",
        ZERO_DAKIKA: "yeni_dakika pozitif olmalidir.",
        INSUFFICIENT_BALANCE: "Duzeltme sonrasi kullanim bakiyeyi asiyor.",
        UNSUPPORTED_TARGET_EVENT: "Hedef event tipi desteklenmiyor."
      };

      return demoSerbestZamanOlusumError(sonuc.code, messages[sonuc.code] ?? sonuc.code);
    }

    const eventId = ++demoState.nextIds.serbestZamanEvent;
    const subeId = typeof personel.sube_id === "number" ? personel.sube_id : 1;
    const donem = resolveDemoDonemMeta(subeId, sonuc.event.event_tarihi);
    const persisted = {
      ...sonuc.event,
      id: eventId,
      ...donem
    };
    demoState.serbestZamanEventsById[eventId] = persisted;

    return ok(persisted);
  }

  if (pathname === "/yonetim/kullanicilar" && method === "GET") {
    return ok({
      items: demoState.yonetimKullanicilari.map((item) => ({
        ...item,
        personel_ad_soyad:
          item.personel_id != null
            ? demoState.personeller
                .filter((personel) => personel.id === item.personel_id)
                .map((personel) => `${personel.ad} ${personel.soyad}`)[0] ?? null
            : null
      }))
    });
  }

  if (pathname === "/yonetim/kullanicilar" && method === "POST") {
    const personelId = toNumber(body.personel_id);
    const linkedPersonel =
      personelId !== null ? demoState.personeller.find((personel) => personel.id === personelId) ?? null : null;
    const next: DemoYonetimKullanici = {
      id: ++demoState.nextIds.kullanici,
      ad_soyad:
        toStringValue(body.ad_soyad) ??
        (linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : "Yeni Kullanici"),
      telefon: toStringValue(body.telefon) ?? linkedPersonel?.telefon ?? undefined,
      kullanici_tipi: body.kullanici_tipi === "HARICI" ? "HARICI" : "IC_PERSONEL",
      rol:
        body.rol === "GENEL_YONETICI" ||
        body.rol === "BOLUM_YONETICISI" ||
        body.rol === "MUHASEBE" ||
        body.rol === "BIRIM_AMIRI"
          ? body.rol
          : "BIRIM_AMIRI",
      personel_id: personelId,
      sube_ids: Array.isArray(body.sube_ids)
        ? body.sube_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : [],
      varsayilan_sube_id: toNumber(body.varsayilan_sube_id),
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF",
      notlar: toStringValue(body.notlar) ?? undefined
    };
    demoState.yonetimKullanicilari.unshift(next);
    return ok({
      ...next,
      personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
    });
  }

  const yonetimKullaniciMatch = pathname.match(/^\/yonetim\/kullanicilar\/(\d+)$/);
  if (yonetimKullaniciMatch && method === "PUT") {
    const id = Number.parseInt(yonetimKullaniciMatch[1], 10);
    const target = demoState.yonetimKullanicilari.find((item) => item.id === id);
    if (!target) {
      return null;
    }

    const personelId = toNumber(body.personel_id);
    const linkedPersonel =
      personelId !== null ? demoState.personeller.find((personel) => personel.id === personelId) ?? null : null;
    Object.assign(target, {
      ad_soyad:
        toStringValue(body.ad_soyad) ??
        (linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : target.ad_soyad),
      telefon: toStringValue(body.telefon) ?? linkedPersonel?.telefon ?? target.telefon,
      kullanici_tipi: body.kullanici_tipi === "HARICI" ? "HARICI" : "IC_PERSONEL",
      rol:
        body.rol === "GENEL_YONETICI" ||
        body.rol === "BOLUM_YONETICISI" ||
        body.rol === "MUHASEBE" ||
        body.rol === "BIRIM_AMIRI"
          ? body.rol
          : target.rol,
      personel_id: personelId,
      sube_ids: Array.isArray(body.sube_ids)
        ? body.sube_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : target.sube_ids,
      varsayilan_sube_id: toNumber(body.varsayilan_sube_id),
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF",
      notlar: toStringValue(body.notlar) ?? undefined
    });
    return ok({
      ...target,
      personel_ad_soyad: linkedPersonel ? `${linkedPersonel.ad} ${linkedPersonel.soyad}` : null
    });
  }

  if (pathname === "/yonetim/subeler" && method === "GET") {
    return ok({
      items: demoState.subeler.map((item) => ({
        ...item,
        departman_adlari: item.departman_ids
          .map((departmanId) => getDepartmanLabel(departmanId))
          .filter((label): label is string => typeof label === "string")
      }))
    });
  }

  if (pathname === "/yonetim/subeler" && method === "POST") {
    const next: DemoSube = {
      id: ++demoState.nextIds.sube,
      kod: toStringValue(body.kod) ?? `SBE-${demoState.nextIds.sube}`,
      ad: toStringValue(body.ad) ?? "Yeni Sube",
      departman_ids: Array.isArray(body.departman_ids)
        ? body.departman_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : [],
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF"
    };
    demoState.subeler.unshift(next);
    return ok({
      ...next,
      departman_adlari: next.departman_ids
        .map((departmanId) => getDepartmanLabel(departmanId))
        .filter((label): label is string => typeof label === "string")
    });
  }

  const yonetimSubeMatch = pathname.match(/^\/yonetim\/subeler\/(\d+)$/);
  if (yonetimSubeMatch && method === "PUT") {
    const id = Number.parseInt(yonetimSubeMatch[1], 10);
    const target = demoState.subeler.find((item) => item.id === id);
    if (!target) {
      return null;
    }

    Object.assign(target, {
      kod: toStringValue(body.kod) ?? target.kod,
      ad: toStringValue(body.ad) ?? target.ad,
      departman_ids: Array.isArray(body.departman_ids)
        ? body.departman_ids
            .map((item) => toNumber(item))
            .filter((item): item is number => item !== null)
        : target.departman_ids,
      durum: body.durum === "PASIF" ? "PASIF" : "AKTIF"
    });
    return ok({
      ...target,
      departman_adlari: target.departman_ids
        .map((departmanId) => getDepartmanLabel(departmanId))
        .filter((label): label is string => typeof label === "string")
    });
  }

  if (yonetimSubeMatch && method === "DELETE") {
    const id = Number.parseInt(yonetimSubeMatch[1], 10);
    const targetIndex = demoState.subeler.findIndex((item) => item.id === id);
    if (targetIndex === -1) {
      return demoRevizyonError("NOT_FOUND", "Sube bulunamadi.");
    }

    const hasLinkedPersonel = demoState.personeller.some((personel) => personel.sube_id === id);
    if (hasLinkedPersonel) {
      return demoRevizyonError(SUBE_DELETE_BLOCKED_ERROR_CODE, SUBE_DELETE_BLOCKED_MESSAGE);
    }

    demoState.subeler.splice(targetIndex, 1);
    demoState.yonetimKullanicilari.forEach((kullanici) => {
      if (!kullanici.sube_ids.includes(id)) {
        return;
      }

      kullanici.sube_ids = kullanici.sube_ids.filter((subeId) => subeId !== id);
      if (kullanici.varsayilan_sube_id === id) {
        kullanici.varsayilan_sube_id = kullanici.sube_ids[0] ?? null;
      }
    });

    return ok({ id, deleted: true });
  }

  if (pathname === "/yonetim/aylik-ozet" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "aylik-ozet.view");
    if (permissionError) {
      return permissionError;
    }

    const ay = toStringValue(requestUrl.searchParams.get("ay")) ?? "2026-04";
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const departmanId = toNumber(requestUrl.searchParams.get("departman_id"));
    const sadeceRevizeli = toStringValue(requestUrl.searchParams.get("sadece_revizeli")) === "true";
    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname === "/yonetim/aylik-ozet/bolum-onay" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoAnyPermission(actor, [
      "aylik_bolum_onayi.approve",
      "aylik-ozet.review"
    ]);
    if (permissionError) {
      return permissionError;
    }

    const ay = toStringValue(body.ay) ?? "2026-04";
    const subeId = toNumber(body.sube_id);
    const departmanId = toNumber(body.departman_id);
    const sadeceRevizeli = Boolean(body.sadece_revizeli);

    const subeScopeError =
      assertDemoAylikWriteSubeScope(actor, subeId) ?? assertDemoAylikSubeAccess(actor, subeId);
    if (subeScopeError) {
      return subeScopeError;
    }

    demoState.personeller.forEach((personel) => {
      if (personel.aktif_durum !== "AKTIF") {
        return;
      }
      if (subeId !== null && personel.sube_id !== subeId) {
        return;
      }
      if (departmanId !== null && personel.departman_id !== departmanId) {
        return;
      }

      const durum = ensureAylikDurum(ay, personel.id);
      if (durum.kapanis_durumu === "KAPANDI") {
        return;
      }
      durum.bolum_onay_durumu = "BOLUM_ONAYLANDI";
      durum.revize_var_mi = false;
      durum.son_islem = `Bolum yoneticisi onay verdi (${formatAylikIslemTimestamp()})`;
    });

    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname === "/yonetim/aylik-ozet/ay-kapat" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoAnyPermission(actor, [
      "genel_yonetici_onayi.approve",
      "aylik-ozet.executive_ack"
    ]);
    if (permissionError) {
      return permissionError;
    }

    const ay = toStringValue(body.ay) ?? "2026-04";
    const subeId = toNumber(body.sube_id);
    const departmanId = toNumber(body.departman_id);
    const sadeceRevizeli = Boolean(body.sadece_revizeli);

    const subeScopeError =
      assertDemoAylikWriteSubeScope(actor, subeId) ?? assertDemoAylikSubeAccess(actor, subeId);
    if (subeScopeError) {
      return subeScopeError;
    }

    if (hasDemoPendingBolumOnay(ay, subeId, departmanId)) {
      return demoRevizyonError(
        "PENDING_BOLUM_ONAY",
        "Bekleyen bölüm onayları tamamlanmadan genel yönetici onayı verilemez."
      );
    }

    demoState.personeller.forEach((personel) => {
      if (personel.aktif_durum !== "AKTIF") {
        return;
      }
      if (subeId !== null && personel.sube_id !== subeId) {
        return;
      }
      if (departmanId !== null && personel.departman_id !== departmanId) {
        return;
      }

      const durum = ensureAylikDurum(ay, personel.id);
      durum.kapanis_durumu = "KAPANDI";
      durum.son_islem = `Genel yonetici ust onay verdi (${formatAylikIslemTimestamp()})`;
    });

    return ok(buildAylikOzetResponse(ay, subeId, departmanId, sadeceRevizeli));
  }

  if (pathname === "/referans/departmanlar" && method === "POST") {
    if (!Object.prototype.hasOwnProperty.call(body, "ad")) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "DEPARTMAN_NAME_REQUIRED",
            message: "Departman adı zorunludur.",
            field: "ad"
          }
        ]
      };
    }

    if (typeof body.ad !== "string") {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "VALIDATION_ERROR",
            message: "Departman adı metin olmalıdır.",
            field: "ad"
          }
        ]
      };
    }

    const ad = body.ad.trim();
    if (!ad) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "DEPARTMAN_NAME_REQUIRED",
            message: "Departman adı zorunludur.",
            field: "ad"
          }
        ]
      };
    }

    if (ad.length > 120) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "VALIDATION_ERROR",
            message: "Departman adı en fazla 120 karakter olabilir.",
            field: "ad"
          }
        ]
      };
    }

    // Approximation of utf8mb4_unicode_ci equality for known ASCII/case pairs.
    const existing = demoState.departmanlar.find(
      (item) => item.ad.localeCompare(ad, "en", { sensitivity: "accent" }) === 0
    );
    if (existing) {
      return {
        data: null,
        meta: {},
        errors: [
          {
            code: "DEPARTMAN_ZATEN_VAR",
            message: "Bu departman adı zaten kayıtlı.",
            field: "ad"
          }
        ]
      };
    }

    const created: DemoDepartman = {
      id: ++demoState.nextIds.departman,
      ad
    };
    demoState.departmanlar.push(created);
    return ok(created);
  }

  if (pathname.startsWith("/referans/") && method === "GET") {
    if (pathname === "/referans/departmanlar") {
      return ok(demoState.departmanlar);
    }

    if (pathname === "/referans/gorevler") {
      return ok(DEMO_GOREVLER);
    }

    if (pathname === "/referans/personel-tipleri") {
      return ok([
        { id: 1, ad: "Tam Zamanlı" },
        { id: 2, ad: "Yarı Zamanlı" }
      ]);
    }

    if (pathname === "/referans/surec-turleri") {
      return ok([
        { key: "IZIN", label: "İzin" },
        { key: "RAPOR", label: "Rapor" },
        { key: "IS_KAZASI", label: "İş Kazası" },
        { key: "DEVAMSIZLIK", label: "Devamsızlık" },
        { key: "ISTEN_AYRILMA", label: "İşten Ayrılma" }
      ]);
    }

    if (pathname === "/referans/bildirim-turleri") {
      return ok([
        { key: "GEC_GELDI", label: "Geç Geldi" },
        { key: "GELMEDI", label: "Gelmedi" },
        { key: "IZINLI_GELMEDI", label: "İzinli Gelmedi" },
        { key: "IZINSIZ_GELMEDI", label: "İzinsiz Gelmedi" },
        { key: "DEVAMSIZLIK", label: "Devamsızlık" },
        { key: "RAPORLU", label: "Raporlu" }
      ]);
    }

    if (pathname === "/referans/bagli-amirler") {
      return ok([
        { id: 1, ad: "Demo Amir" },
        { id: 2, ad: "İkinci Amir" }
      ]);
    }

    if (pathname === "/referans/ucret-tipleri") {
      return ok([
        { id: 1, ad: "Aylık" },
        { id: 2, ad: "Günlük" }
      ]);
    }

    if (pathname === "/referans/prim-kurallari") {
      return ok([
        { id: 7, ad: "7 No'lu Prim Kuralı" },
        { id: 8, ad: "8 No'lu Prim Kuralı" }
      ]);
    }

    return ok([]);
  }

  if (pathname.startsWith("/raporlar/") && method === "GET") {
    const personel = demoState.personeller[0];
    const sgkOzeti =
      personel != null ? hesaplaAylikSgkPuantajOzetleri(getDemoPuantajRowsForPersonel(personel.id))[0] ?? null : null;

    return ok(
      {
        items: [
          {
            personel_id: personel?.id ?? 1,
            ad_soyad: personel != null ? `${personel.ad} ${personel.soyad}` : "Ayşe Yılmaz",
            rapor_tipi: pathname.replace("/raporlar/", ""),
            net_calisma_dakika: 510,
            sgk_donem: sgkOzeti?.donem ?? "2026-04",
            sgk_prim_gun: sgkOzeti?.sgk_prim_gun ?? 30,
            eksik_gun_nedeni_kodu: sgkOzeti?.eksik_gun_nedeni_kodu ?? "-"
          }
        ]
      },
      { total: 1 }
    );
  }

  const belgeKayitlariListMatch = pathname.match(/^\/personeller\/(\d+)\/belge-kayitlari$/);
  if (belgeKayitlariListMatch && method === "GET") {
    const personelId = Number.parseInt(belgeKayitlariListMatch[1] ?? "0", 10);
    const exists = demoState.personeller.some((item) => item.id === personelId);
    if (!exists) {
      return null;
    }

    const stateFilter = toStringValue(requestUrl.searchParams.get("state"));
    const page = toNumber(requestUrl.searchParams.get("page")) ?? 1;
    const limit = toNumber(requestUrl.searchParams.get("limit")) ?? 50;

    const filtered = demoState.personelBelgeKayitlari.filter((item) => {
      if (item.personel_id !== personelId) {
        return false;
      }
      if (stateFilter && stateFilter !== "tum" && item.durum !== stateFilter) {
        return false;
      }
      return true;
    });

    const start = (page - 1) * limit;
    const items = filtered.slice(start, start + limit).map(serializeDemoPersonelBelgeKaydi);
    const total = filtered.length;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    return ok({ items }, { page, limit, total, total_pages: totalPages });
  }

  if (belgeKayitlariListMatch && method === "POST") {
    const personelId = Number.parseInt(belgeKayitlariListMatch[1] ?? "0", 10);
    const exists = demoState.personeller.some((item) => item.id === personelId);
    if (!exists) {
      return null;
    }

    const kayitTipi = body.kayit_tipi;
    const ad = toStringValue(body.ad);
    if (!isPersonelBelgeKayitTipi(kayitTipi) || !ad) {
      return demoRevizyonError("VALIDATION_ERROR", "kayit_tipi ve ad zorunludur.");
    }

    const now = new Date().toISOString();
    const next: DemoPersonelBelgeKaydi = {
      id: ++demoState.nextIds.personelBelgeKaydi,
      personel_id: personelId,
      kayit_tipi: kayitTipi,
      ad,
      veren_kurum: toStringValue(body.veren_kurum) ?? null,
      belge_no: toStringValue(body.belge_no) ?? null,
      baslangic_tarihi: toStringValue(body.baslangic_tarihi) ?? null,
      bitis_tarihi: toStringValue(body.bitis_tarihi) ?? null,
      durum: "AKTIF",
      ek_ref: toStringValue(body.ek_ref) ?? null,
      aciklama: toStringValue(body.aciklama) ?? null,
      created_at: now,
      updated_at: now
    };
    demoState.personelBelgeKayitlari.unshift(next);
    return ok(serializeDemoPersonelBelgeKaydi(next));
  }

  const belgeKayitDetailMatch = pathname.match(/^\/belge-kayitlari\/(\d+)$/);
  if (belgeKayitDetailMatch && method === "PUT") {
    const id = Number.parseInt(belgeKayitDetailMatch[1], 10);
    const kayit = demoState.personelBelgeKayitlari.find((item) => item.id === id);
    if (!kayit) {
      return null;
    }
    if (kayit.durum === "IPTAL") {
      return demoRevizyonError("INVALID_STATE", "Iptal edilmis kayit guncellenemez.");
    }

    const kayitTipi = body.kayit_tipi;
    if (kayitTipi !== undefined && isPersonelBelgeKayitTipi(kayitTipi)) {
      kayit.kayit_tipi = kayitTipi;
    }
    const ad = toStringValue(body.ad);
    if (ad) {
      kayit.ad = ad;
    }
    if ("veren_kurum" in body) {
      kayit.veren_kurum = toStringValue(body.veren_kurum) ?? null;
    }
    if ("belge_no" in body) {
      kayit.belge_no = toStringValue(body.belge_no) ?? null;
    }
    if ("baslangic_tarihi" in body) {
      kayit.baslangic_tarihi = toStringValue(body.baslangic_tarihi) ?? null;
    }
    if ("bitis_tarihi" in body) {
      kayit.bitis_tarihi = toStringValue(body.bitis_tarihi) ?? null;
    }
    if ("ek_ref" in body) {
      kayit.ek_ref = toStringValue(body.ek_ref) ?? null;
    }
    if ("aciklama" in body) {
      kayit.aciklama = toStringValue(body.aciklama) ?? null;
    }
    kayit.updated_at = new Date().toISOString();
    return ok(serializeDemoPersonelBelgeKaydi(kayit));
  }

  const belgeKayitCancelMatch = pathname.match(/^\/belge-kayitlari\/(\d+)\/iptal$/);
  if (belgeKayitCancelMatch && method === "POST") {
    const id = Number.parseInt(belgeKayitCancelMatch[1], 10);
    const kayit = demoState.personelBelgeKayitlari.find((item) => item.id === id);
    if (!kayit) {
      return null;
    }

    kayit.durum = "IPTAL";
    kayit.updated_at = new Date().toISOString();
    return ok(serializeDemoPersonelBelgeKaydi(kayit));
  }

  const belgeDurumMatch = pathname.match(/^\/personeller\/(\d+)\/belge-durumu$/);
  if (belgeDurumMatch && method === "GET") {
    const personelId = Number.parseInt(belgeDurumMatch[1] ?? "0", 10);
    const exists = demoState.personeller.some((item) => item.id === personelId);
    if (!exists) {
      return null;
    }
    return buildDemoBelgeDurumResponse(personelId);
  }

  if (belgeDurumMatch && method === "PUT") {
    const personelId = Number.parseInt(belgeDurumMatch[1] ?? "0", 10);
    const exists = demoState.personeller.some((item) => item.id === personelId);
    if (!exists) {
      return null;
    }
    return applyDemoBelgeDurumPut(personelId, body);
  }

  if (pathname === "/ek-odeme-kesinti" && method === "GET") {
    const actor = readDemoApiActor(init);
    if (!hasRolePermission(actor.role, "finans.view")) {
      return demoRevizyonError("FORBIDDEN", "Bu islem icin yetkiniz yok.");
    }

    const subeScope = resolveDemoRequestSubeScope(init, requestUrl);
    if (subeScope !== null && actor.subeIds.length > 0 && !actor.subeIds.includes(subeScope)) {
      return demoRevizyonError("FORBIDDEN", "Secili sube icin yetkiniz yok.");
    }

    const personelId = toNumber(requestUrl.searchParams.get("personel_id"));
    const donem = toStringValue(requestUrl.searchParams.get("donem"));
    const state = toStringValue(requestUrl.searchParams.get("state"));
    const kalemTuru = toStringValue(requestUrl.searchParams.get("kalem_turu"));

    const filtered = demoState.finansKalemleri.filter((item) => {
      if (!demoFinansItemMatchesScope(item.personel_id, subeScope, actor.subeIds)) {
        return false;
      }

      if (personelId !== null && item.personel_id !== personelId) {
        return false;
      }

      if (donem && item.donem !== donem) {
        return false;
      }

      if (state && item.state !== state) {
        return false;
      }

      if (kalemTuru && item.kalem_turu !== kalemTuru) {
        return false;
      }

      return true;
    });

    return ok({ items: filtered });
  }

  if (pathname === "/ek-odeme-kesinti" && method === "POST") {
    const next: DemoFinansKalem = {
      id: ++demoState.nextIds.finans,
      personel_id: toNumber(body.personel_id) ?? 1,
      donem: toStringValue(body.donem) ?? "2026-04",
      kalem_turu: toStringValue(body.kalem_turu) ?? "AVANS",
      tutar: toNumber(body.tutar) ?? 0,
      aciklama: toStringValue(body.aciklama) ?? undefined,
      state: "AKTIF"
    };
    demoState.finansKalemleri.unshift(next);
    return ok(next);
  }

  const finansDetailMatch = pathname.match(/^\/ek-odeme-kesinti\/(\d+)$/);
  if (finansDetailMatch && method === "PUT") {
    const id = Number.parseInt(finansDetailMatch[1], 10);
    const finans = demoState.finansKalemleri.find((item) => item.id === id);
    if (!finans) {
      return null;
    }

    Object.assign(finans, body);
    return ok(finans);
  }

  const finansCancelMatch = pathname.match(/^\/ek-odeme-kesinti\/(\d+)\/iptal$/);
  if (finansCancelMatch && method === "POST") {
    const id = Number.parseInt(finansCancelMatch[1], 10);
    const finans = demoState.finansKalemleri.find((item) => item.id === id);
    if (!finans) {
      return null;
    }

    finans.state = "IPTAL";
    return ok({ id: finans.id, state: finans.state });
  }

  if (pathname === "/mevzuat-parametreleri" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "mevzuat_parametreleri.view",
      "Mevzuat parametrelerine erisim yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const kodFilter = (toStringValue(requestUrl.searchParams.get("parametre_kodu")) ?? "").toUpperCase();
    const items = demoState.mevzuatParametreleri
      .filter((item) => !kodFilter || item.parametre_kodu === kodFilter)
      .sort((left, right) => {
        if (left.parametre_kodu !== right.parametre_kodu) {
          return left.parametre_kodu < right.parametre_kodu ? -1 : 1;
        }
        if (left.gecerlilik_baslangic !== right.gecerlilik_baslangic) {
          return left.gecerlilik_baslangic < right.gecerlilik_baslangic ? 1 : -1;
        }
        return right.id - left.id;
      });

    return ok({ items });
  }

  if (pathname === "/mevzuat-parametreleri" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "mevzuat_parametreleri.manage",
      "Mevzuat parametrelerini yonetme yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const normalized = normalizeDemoMevzuatBody(body);
    if ("error" in normalized) {
      return normalized.error;
    }
    const data = normalized.value;
    const now = new Date().toISOString();

    const openKayit = demoState.mevzuatParametreleri
      .filter(
        (item) =>
          item.parametre_kodu === data.parametre_kodu &&
          item.state === "AKTIF" &&
          item.gecerlilik_bitis === null
      )
      .sort((left, right) =>
        left.gecerlilik_baslangic === right.gecerlilik_baslangic
          ? right.id - left.id
          : left.gecerlilik_baslangic < right.gecerlilik_baslangic
            ? 1
            : -1
      )[0];
    if (openKayit && openKayit.gecerlilik_baslangic <= data.gecerlilik_baslangic) {
      if (openKayit.gecerlilik_baslangic === data.gecerlilik_baslangic) {
        return demoRevizyonError(
          "DATE_RANGE_INVALID",
          "Ayni baslangic tarihli acik parametre kapatilamaz."
        );
      }
      openKayit.gecerlilik_bitis = addDaysToIsoDate(data.gecerlilik_baslangic, -1);
      openKayit.updated_at = now;
      openKayit.updated_by = actor.userId;
    }

    if (demoMevzuatHasOverlap(data.parametre_kodu, data.gecerlilik_baslangic, data.gecerlilik_bitis)) {
      return demoRevizyonError("LEGAL_PARAMETER_OVERLAP", DEMO_MEVZUAT_OVERLAP_MESAJI);
    }

    const next: DemoMevzuatParametresi = {
      id: ++demoState.nextIds.mevzuatParametre,
      ...data,
      state: "AKTIF",
      created_at: now,
      created_by: actor.userId,
      updated_at: now,
      updated_by: actor.userId
    };
    demoState.mevzuatParametreleri.push(next);
    return ok(next);
  }

  const mevzuatDetailMatch = pathname.match(/^\/mevzuat-parametreleri\/(\d+)$/);
  if (mevzuatDetailMatch && method === "PUT") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "mevzuat_parametreleri.manage",
      "Mevzuat parametrelerini yonetme yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const id = Number.parseInt(mevzuatDetailMatch[1], 10);
    const record = demoState.mevzuatParametreleri.find((item) => item.id === id);
    if (!record) {
      return demoRevizyonError("NOT_FOUND", "Mevzuat parametresi bulunamadi.");
    }
    if (record.state !== "AKTIF" || record.gecerlilik_baslangic <= demoTodayIsoDate()) {
      return demoRevizyonError(
        "LEGAL_PARAMETER_CHANGE_FORBIDDEN",
        "Baslamis veya iptal edilmis parametre degistirilemez."
      );
    }

    const normalized = normalizeDemoMevzuatBody({
      parametre_kodu: record.parametre_kodu,
      deger_tipi: record.deger_tipi,
      sayisal_deger: record.sayisal_deger,
      metin_deger: record.metin_deger,
      gecerlilik_baslangic: record.gecerlilik_baslangic,
      gecerlilik_bitis: record.gecerlilik_bitis,
      birim: record.birim,
      aciklama: record.aciklama,
      kaynak_referansi: record.kaynak_referansi,
      ...body
    });
    if ("error" in normalized) {
      return normalized.error;
    }
    if (normalized.value.parametre_kodu !== record.parametre_kodu) {
      return demoRevizyonError("LEGAL_PARAMETER_CHANGE_FORBIDDEN", "Parametre kodu degistirilemez.");
    }
    if (
      demoMevzuatHasOverlap(
        normalized.value.parametre_kodu,
        normalized.value.gecerlilik_baslangic,
        normalized.value.gecerlilik_bitis,
        record.id
      )
    ) {
      return demoRevizyonError("LEGAL_PARAMETER_OVERLAP", DEMO_MEVZUAT_OVERLAP_MESAJI);
    }

    Object.assign(record, normalized.value, {
      updated_at: new Date().toISOString(),
      updated_by: actor.userId
    });
    return ok(record);
  }

  const mevzuatCancelMatch = pathname.match(/^\/mevzuat-parametreleri\/(\d+)\/iptal$/);
  if (mevzuatCancelMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "mevzuat_parametreleri.manage",
      "Mevzuat parametrelerini yonetme yetkiniz yok."
    );
    if (permissionError) return permissionError;

    const id = Number.parseInt(mevzuatCancelMatch[1], 10);
    const record = demoState.mevzuatParametreleri.find((item) => item.id === id);
    if (!record) {
      return demoRevizyonError("NOT_FOUND", "Mevzuat parametresi bulunamadi.");
    }
    if (record.state !== "AKTIF") {
      return demoRevizyonError("LEGAL_PARAMETER_CHANGE_FORBIDDEN", "Parametre zaten iptal.");
    }

    record.state = "IPTAL";
    record.updated_at = new Date().toISOString();
    record.updated_by = actor.userId;
    return ok(record);
  }

  if (pathname === "/puantaj/donem-kapanis-preflight" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.donem_kapanis.view");
    if (permissionError) {
      return permissionError;
    }

    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil")) ?? 2026;
    const ay = toNumber(requestUrl.searchParams.get("ay")) ?? 6;
    if (!subeId) {
      return demoRevizyonError("VALIDATION_ERROR", "sube_id zorunludur.");
    }

    const donem = `${yil}-${String(ay).padStart(2, "0")}`;
    const sube = demoState.subeler.find((item) => item.id === subeId);
    const blockers = [
      {
        code: "CANDIDATE_HAZIR_PENDING",
        severity: "BLOCKER",
        domain: "etki_adayi",
        title: "HAZIR etki adayi",
        message: "Uygulanmayi bekleyen HAZIR etki adayi var.",
        count: 1,
        owner_role: "MUHASEBE",
        action_route: "/puantaj",
        action_permission: "puantaj.bildirim_etki.view",
        record_ids: [1],
        metadata: {}
      }
    ];
    const warnings = [
      {
        code: "PUANTAJ_MANUAL_NO_NOTE",
        severity: "WARNING",
        domain: "puantaj",
        title: "Aciklamasiz manuel puantaj",
        message: "Manuel puantaj kaydinda aciklama eksik.",
        count: 1,
        owner_role: "MUHASEBE",
        action_route: "/puantaj",
        action_permission: "puantaj.view",
        record_ids: [12],
        metadata: {}
      }
    ];
    const infos = [
      {
        code: "CANDIDATE_APPLIED_COUNT",
        severity: "INFO",
        domain: "etki_adayi",
        title: "Uygulanan aday",
        message: "Uygulanan etki adayi sayisi.",
        count: 2,
        owner_role: "MUHASEBE",
        action_route: "/puantaj",
        action_permission: "puantaj.bildirim_etki.view",
        record_ids: [],
        metadata: {}
      }
    ];

    return ok({
      sube: sube ? { id: sube.id, ad: sube.ad } : { id: subeId, ad: `Sube ${subeId}` },
      yil,
      ay,
      donem,
      donem_state: "ACIK",
      muhur_state: "ACIK",
      muhur_id: null,
      kapanabilir_mi: false,
      blocker_count: blockers.length,
      warning_count: warnings.length,
      info_count: infos.length,
      kategori_sayaclari: { etki_adayi: 1, puantaj: 1 },
      blockers,
      warnings,
      infos,
      candidate_state_counts: { HAZIR: 1, INCELEME_GEREKLI: 1, UYGULANDI: 2, YOK_SAYILDI: 0 },
      notification_chain_counts: { taslak: 0, haftalik_eksik: 0 },
      puantaj_counts: { kontrol_bekleyen: 1 },
      finance_readiness: { salary_missing: 0 },
      preflight_hash: "demo-preflight-hash",
      schema_version: "S76_PERIOD_CLOSE_PREFLIGHT_V1",
      generated_at: new Date().toISOString()
    });
  }

  if (pathname === "/puantaj/donem-kapanis-preflight/items" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.donem_kapanis.view");
    if (permissionError) {
      return permissionError;
    }

    const code = toStringValue(requestUrl.searchParams.get("code")) ?? "";
    const items =
      code === "CANDIDATE_HAZIR_PENDING"
        ? [
            {
              record_id: 1,
              personel_id: 1,
              tarih: "2026-06-03",
              state: "HAZIR",
              detail: "Gec kalma etkisi",
              severity: "BLOCKER"
            }
          ]
        : [
            {
              record_id: 12,
              personel_id: 2,
              tarih: "2026-06-04",
              state: "BEKLIYOR",
              detail: "Kontrol bekliyor",
              severity: code.includes("WARNING") ? "WARNING" : "BLOCKER"
            }
          ];

    return ok(
      { items },
      { page: 1, limit: 20, total: items.length, total_pages: 1, has_next_page: false, has_prev_page: false }
    );
  }

  if (pathname === "/puantaj/donem-kapanis-preflight/export.csv" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.donem_kapanis.export");
    if (permissionError) {
      return permissionError;
    }

    return ok("code,severity,domain,title\nCANDIDATE_HAZIR_PENDING,BLOCKER,etki_adayi,HAZIR etki adayi\n");
  }

  if (pathname === "/puantaj/donem-kapanis-auditleri" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.donem_kapanis.view");
    if (permissionError) {
      return permissionError;
    }

    const subeId = toNumber(requestUrl.searchParams.get("sube_id")) ?? 1;
    const yil = toNumber(requestUrl.searchParams.get("yil")) ?? 2026;
    const ay = toNumber(requestUrl.searchParams.get("ay")) ?? 6;
    const items = [
      {
        id: 1,
        sube_id: subeId,
        yil,
        ay,
        action: "CLOSE_ATTEMPT_BLOCKED",
        result_state: "BLOCKED",
        muhur_id: null,
        blocker_count: 1,
        warning_count: 1,
        preflight_hash: "demo-preflight-hash",
        request_hash: "demo-request-hash",
        result_hash: "demo-result-hash",
        actor_user_id: actor.userId,
        created_at: new Date().toISOString()
      }
    ];

    return ok({ items }, { page: 1, limit: 20, total: items.length, total_pages: 1 });
  }

  // --- S77-C Maaş Hesaplama Merkezi (demo parity) ---
  const maasDemo = ensureMaasHesaplamaDemoState();

  if (pathname === "/maas-hesaplama/preflight" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "maas_hesaplama.view",
      "Maas hesaplama merkezine erisim yetkiniz yok."
    );
    if (permissionError) {
      return permissionError;
    }

    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil")) ?? 2026;
    const ay = toNumber(requestUrl.searchParams.get("ay")) ?? 3;
    if (!subeId) {
      return demoRevizyonError("VALIDATION_ERROR", "sube_id zorunludur.");
    }

    return ok(buildMaasHesaplamaPreflight(maasDemo, subeId, yil, ay));
  }

  if (pathname === "/maas-hesaplama/snapshotlar" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama.view");
    if (permissionError) {
      return permissionError;
    }
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil"));
    const ay = toNumber(requestUrl.searchParams.get("ay"));
    if (!subeId) {
      return demoRevizyonError("VALIDATION_ERROR", "sube_id zorunludur.");
    }
    const items = maasDemo.snapshots.filter(
      (item) =>
        item.sube_id === subeId &&
        (yil === null || item.yil === yil) &&
        (ay === null || item.ay === ay)
    );
    return ok({ items });
  }

  if (pathname === "/maas-hesaplama/snapshotlar" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(
      actor,
      "maas_hesaplama.manage",
      "Maas hesaplama snapshot yonetme yetkiniz yok."
    );
    if (permissionError) {
      return permissionError;
    }
    const body = readBody(init) as {
      sube_id?: number;
      yil?: number;
      ay?: number;
      expected_preflight_hash?: string;
    };
    const subeId = toNumber(body.sube_id);
    const yil = toNumber(body.yil);
    const ay = toNumber(body.ay);
    const expected = String(body.expected_preflight_hash ?? "");
    if (!subeId || !yil || !ay || !/^[a-f0-9]{64}$/.test(expected)) {
      return demoRevizyonError("VALIDATION_ERROR", "sube_id/yil/ay/expected_preflight_hash zorunludur.");
    }
    const preflight = buildMaasHesaplamaPreflight(maasDemo, subeId, yil, ay);
    const active = maasDemo.snapshots.find(
      (item) => item.sube_id === subeId && item.yil === yil && item.ay === ay && item.state === "OLUSTURULDU"
    );
    if (active) {
      if (active.source_hash === preflight.source_hash) {
        return ok({ snapshot: active, idempotent: true, audit: null });
      }
      return demoRevizyonError("PAYROLL_SNAPSHOT_SOURCE_CHANGED", "Kaynaklar degisti.");
    }
    if (preflight.blocker_count > 0 || !preflight.muhur) {
      return demoRevizyonError("PAYROLL_PREFLIGHT_BLOCKED", "Preflight blocker iceriyor.");
    }
    if (expected !== preflight.preflight_hash) {
      return demoRevizyonError("PAYROLL_PREFLIGHT_STALE", "Preflight sonucu guncel degil.");
    }
    const cancelled = [...maasDemo.snapshots]
      .filter((item) => item.sube_id === subeId && item.yil === yil && item.ay === ay)
      .sort((a, b) => b.revision_no - a.revision_no)[0];
    const snapshotId = ++maasDemo.nextId;
    const snapshot = {
      id: snapshotId,
      snapshot_id: snapshotId,
      sube_id: subeId,
      yil,
      ay,
      donem: preflight.donem,
      donem_baslangic: preflight.donem_baslangic,
      donem_bitis: preflight.donem_bitis,
      muhur_id: preflight.muhur?.id ?? 1,
      revision_no: cancelled ? cancelled.revision_no + 1 : 1,
      parent_snapshot_id: cancelled?.id ?? null,
      state: "OLUSTURULDU",
      contract_version: "S77_C_SNAPSHOT_V1",
      cutoff_at: new Date().toISOString().slice(0, 19).replace("T", " "),
      preflight_hash: preflight.preflight_hash,
      source_hash: preflight.source_hash,
      snapshot_hash: preflight.source_hash,
      personel_sayisi: preflight.personel_summary.length,
      girdi_sayisi: 8,
      blocker_count: 0,
      warning_count: preflight.warning_count,
      created_by: actor.userId,
      created_at: new Date().toISOString(),
      iptal_edildi_by: null,
      iptal_edildi_at: null,
      iptal_nedeni: null
    };
    maasDemo.snapshots.push(snapshot);
    maasDemo.audits.push({
      id: ++maasDemo.nextAuditId,
      donem_snapshot_id: snapshot.id,
      sube_id: subeId,
      yil,
      ay,
      muhur_id: snapshot.muhur_id,
      aksiyon: "SNAPSHOT_CREATE",
      sonuc: "CREATED",
      actor_id: actor.userId,
      actor_rol: actor.role,
      request_hash: "demo-request-hash",
      preflight_hash: snapshot.preflight_hash,
      source_hash: snapshot.source_hash,
      result_hash: snapshot.snapshot_hash,
      blocker_count: 0,
      warning_count: snapshot.warning_count,
      created_at: snapshot.created_at
    });
    return ok({ snapshot, idempotent: false, audit: maasDemo.audits[maasDemo.audits.length - 1] });
  }

  const maasCalculationPreflightMatch = pathname.match(
    /^\/maas-hesaplama\/snapshotlar\/(\d+)\/hesaplama-preflight$/
  );
  if (maasCalculationPreflightMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasCalculationPreflightMatch[1], 10);
    const snapshot = maasDemo.snapshots.find((item) => item.id === id);
    if (!snapshot) {
      return demoRevizyonError("PAYROLL_SNAPSHOT_NOT_FOUND", "Snapshot bulunamadi.");
    }
    return ok(buildMaasHesaplamaCalculationPreflight(maasDemo, snapshot));
  }

  const maasCalculateMatch = pathname.match(/^\/maas-hesaplama\/snapshotlar\/(\d+)\/hesapla$/);
  if (maasCalculateMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.manage");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasCalculateMatch[1], 10);
    const snapshot = maasDemo.snapshots.find((item) => item.id === id);
    if (!snapshot) {
      return demoRevizyonError("PAYROLL_SNAPSHOT_NOT_FOUND", "Snapshot bulunamadi.");
    }
    const preflight = buildMaasHesaplamaCalculationPreflight(maasDemo, snapshot);
    const body = readBody(init) as { expected_calculation_input_hash?: string };
    if (String(body.expected_calculation_input_hash ?? "") !== preflight.calculation_input_hash) {
      return demoRevizyonError("PAYROLL_CALCULATION_PREFLIGHT_STALE", "Hesaplama girdisi guncel degil.");
    }
    if (!preflight.hesaplanabilir_mi) {
      return demoRevizyonError("PAYROLL_CALCULATION_BLOCKED", "Hesaplama preflight blocker iceriyor.");
    }
    return ok({ calistirma: preflight.existing_calculation ?? undefined, idempotent: true, audit: null });
  }

  if (pathname === "/maas-hesaplama/calistirmalar" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil"));
    const ay = toNumber(requestUrl.searchParams.get("ay"));
    const items = maasDemo.calistirmalar.filter(
      (item) =>
        (subeId === null || item.sube_id === subeId) &&
        (yil === null || item.yil === yil) &&
        (ay === null || item.ay === ay)
    );
    return ok({ items });
  }

  const maasCalistirmaDetailMatch = pathname.match(/^\/maas-hesaplama\/calistirmalar\/(\d+)$/);
  if (maasCalistirmaDetailMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasCalistirmaDetailMatch[1], 10);
    const calistirma = maasDemo.calistirmalar.find((item) => item.id === id);
    return calistirma ? ok(calistirma) : demoRevizyonError("PAYROLL_CALCULATION_NOT_FOUND", "Calistirma bulunamadi.");
  }

  const maasCalistirmaAdayMatch = pathname.match(/^\/maas-hesaplama\/calistirmalar\/(\d+)\/adaylar$/);
  if (maasCalistirmaAdayMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const calistirmaId = Number.parseInt(maasCalistirmaAdayMatch[1], 10);
    return ok({ items: maasDemo.adaylar.filter((item) => item.calistirma_id === calistirmaId) });
  }

  const maasCalistirmaAuditMatch = pathname.match(/^\/maas-hesaplama\/calistirmalar\/(\d+)\/audit$/);
  if (maasCalistirmaAuditMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    return ok({ items: [] });
  }

  const maasCalistirmaCancelMatch = pathname.match(/^\/maas-hesaplama\/calistirmalar\/(\d+)\/iptal$/);
  if (maasCalistirmaCancelMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.manage");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasCalistirmaCancelMatch[1], 10);
    const calistirma = maasDemo.calistirmalar.find((item) => item.id === id);
    if (!calistirma) {
      return demoRevizyonError("PAYROLL_CALCULATION_NOT_FOUND", "Calistirma bulunamadi.");
    }
    const neden = String(readBody(init).neden ?? "").trim();
    if (!neden) {
      return demoRevizyonError("VALIDATION_ERROR", "Iptal nedeni zorunludur.");
    }
    calistirma.state = "IPTAL";
    calistirma.iptal_edildi_at = new Date().toISOString();
    calistirma.iptal_nedeni = neden;
    return ok({ calistirma, idempotent: false, audit: null });
  }

  const maasAdayDetailMatch = pathname.match(/^\/maas-hesaplama\/adaylar\/(\d+)$/);
  if (maasAdayDetailMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasAdayDetailMatch[1], 10);
    const aday = maasDemo.adaylar.find((item) => item.id === id);
    return aday ? ok(aday) : demoRevizyonError("PAYROLL_CANDIDATE_NOT_FOUND", "Aday bulunamadi.");
  }

  const maasAdayKalemMatch = pathname.match(/^\/maas-hesaplama\/adaylar\/(\d+)\/kalemler$/);
  if (maasAdayKalemMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const adayId = Number.parseInt(maasAdayKalemMatch[1], 10);
    return ok({ items: maasDemo.kalemler.filter((item) => item.aday_id === adayId) });
  }

  if (pathname === "/maas-hesaplama/yasal-katalog" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    return ok({
      engine_version: "S77_D_DEMO_ENGINE_V2",
      contract_version: "S77_D_CALCULATION_V2",
      items: []
    });
  }

  if (pathname === "/maas-hesaplama/devirler" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.view");
    if (permissionError) {
      return permissionError;
    }
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil"));
    const ay = toNumber(requestUrl.searchParams.get("ay"));
    const items = maasDemo.devirler.filter(
      (item) =>
        (subeId === null || item.sube_id === subeId) &&
        (yil === null || item.yil === yil) &&
        (ay === null || item.ay === ay)
    );
    return ok({ items });
  }

  if (pathname === "/maas-hesaplama/devirler" && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama_adaylari.manage");
    if (permissionError) {
      return permissionError;
    }
    const payload = readBody(init);
    const personelId = toNumber(payload.personel_id);
    const subeId = toNumber(payload.sube_id);
    const yil = toNumber(payload.yil);
    const ay = toNumber(payload.ay);
    const matrah = toNumber(payload.onceki_kumulatif_gelir_vergisi_matrahi);
    const vergi = toNumber(payload.onceki_kumulatif_gelir_vergisi);
    if (!personelId || !subeId || !yil || !ay || matrah === null || vergi === null) {
      return demoRevizyonError("VALIDATION_ERROR", "personel_id/sube_id/yil/ay/matrah/vergi zorunludur.");
    }
    const now = new Date().toISOString();
    const existing = maasDemo.devirler.find(
      (item) => item.personel_id === personelId && item.sube_id === subeId && item.yil === yil && item.ay === ay
    );
    const personel = demoState.personeller.find((item) => item.id === personelId);
    const next = existing ?? {
      id: ++maasDemo.nextDevirId,
      personel_id: personelId,
      personel_ad_soyad: personel ? `${personel.ad} ${personel.soyad}` : null,
      sube_id: subeId,
      yil,
      ay,
      onceki_kumulatif_gelir_vergisi_matrahi: matrah,
      onceki_kumulatif_gelir_vergisi: vergi,
      onceki_kumulatif_sgk_matrahi: null,
      kaynak: null,
      aciklama: null,
      created_at: now,
      updated_at: now
    };
    Object.assign(next, {
      onceki_kumulatif_gelir_vergisi_matrahi: matrah,
      onceki_kumulatif_gelir_vergisi: vergi,
      onceki_kumulatif_sgk_matrahi: toNumber(payload.onceki_kumulatif_sgk_matrahi),
      kaynak: toStringValue(payload.kaynak) ?? "MANUEL",
      aciklama: toStringValue(payload.aciklama),
      updated_at: now
    });
    if (!existing) {
      maasDemo.devirler.push(next);
    }
    return ok(next);
  }

  const maasDetailMatch = pathname.match(/^\/maas-hesaplama\/snapshotlar\/(\d+)$/);
  if (maasDetailMatch && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama.view");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasDetailMatch[1], 10);
    const snapshot = maasDemo.snapshots.find((item) => item.id === id);
    if (!snapshot) {
      return demoRevizyonError("PAYROLL_SNAPSHOT_NOT_FOUND", "Snapshot bulunamadi.");
    }
    return ok({
      ...snapshot,
      girdi_ozet: { PERSONEL: 2, UCRET: 2, PUANTAJ: 2, FINANS: 1, MEVZUAT: 0, MUHUR: 1 },
      hash_dogrulama: { dogrulandi: true, hesaplanan_snapshot_hash: snapshot.snapshot_hash }
    });
  }

  const maasCancelMatch = pathname.match(/^\/maas-hesaplama\/snapshotlar\/(\d+)\/iptal$/);
  if (maasCancelMatch && method === "POST") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama.manage");
    if (permissionError) {
      return permissionError;
    }
    const id = Number.parseInt(maasCancelMatch[1], 10);
    const snapshot = maasDemo.snapshots.find((item) => item.id === id);
    if (!snapshot) {
      return demoRevizyonError("PAYROLL_SNAPSHOT_NOT_FOUND", "Snapshot bulunamadi.");
    }
    const body = readBody(init) as { neden?: string };
    const neden = String(body.neden ?? "").trim();
    if (!neden) {
      return demoRevizyonError("VALIDATION_ERROR", "Iptal nedeni zorunludur.");
    }
    if (snapshot.state === "IPTAL") {
      return ok({ snapshot, idempotent: true, audit: null });
    }
    snapshot.state = "IPTAL";
    snapshot.iptal_edildi_by = actor.userId;
    snapshot.iptal_edildi_at = new Date().toISOString();
    snapshot.iptal_nedeni = neden;
    return ok({ snapshot, idempotent: false, audit: null });
  }

  if (pathname === "/maas-hesaplama/auditler" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "maas_hesaplama.view");
    if (permissionError) {
      return permissionError;
    }
    const subeId = toNumber(requestUrl.searchParams.get("sube_id"));
    const yil = toNumber(requestUrl.searchParams.get("yil")) ?? 2026;
    const ay = toNumber(requestUrl.searchParams.get("ay")) ?? 3;
    if (!subeId) {
      return demoRevizyonError("VALIDATION_ERROR", "sube_id zorunludur.");
    }
    const items = maasDemo.audits.filter(
      (item) => item.sube_id === subeId && item.yil === yil && item.ay === ay
    );
    return ok({ items });
  }

  if (pathname === "/puantaj/bildirim-etki-adaylari/rapor" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.bildirim_etki.rapor.view");
    if (permissionError) {
      return permissionError;
    }

    const ay = toStringValue(requestUrl.searchParams.get("ay")) ?? "2026-06";
    const subeId = toNumber(requestUrl.searchParams.get("sube_id")) ?? 1;
    const items = [
      {
        id: 1,
        personel_id: 1,
        personel_ad_soyad: "Ali Demir",
        sicil_no: "1001",
        sube_ad: "Merkez",
        departman_ad: "Uretim",
        tarih: `${ay}-03`,
        bildirim_turu: "GEC_KALMA",
        etki_turu: "GEC_KALMA_DK",
        effective_miktar: 15,
        effective_birim: "DK",
        state: "HAZIR",
        conflict_code: null,
        mevcut_puantaj_ozet: null,
        uygulanan_puantaj_ozet: null,
        karar_turu: null,
        karar_veren: null,
        karar_zamani: null,
        uygulama_modu: "OTOMATIK",
        projection_version: 1,
        source_integrity: "OK",
        audit_integrity: "OK"
      },
      {
        id: 2,
        personel_id: 2,
        personel_ad_soyad: "Ayse Yilmaz",
        sicil_no: "1002",
        sube_ad: "Sube 2",
        departman_ad: "Lojistik",
        tarih: `${ay}-04`,
        bildirim_turu: "GELMEDI",
        etki_turu: "DEVAMSIZLIK_GUN",
        effective_miktar: 1,
        effective_birim: "GUN",
        state: "UYGULANDI",
        conflict_code: "MEVCUT_PUANTAJ_VAR",
        mevcut_puantaj_ozet: "Geldi / Normal",
        uygulanan_puantaj_ozet: "Gelmedi",
        karar_turu: "ADAY_ETKISIYLE_REVIZE_ET",
        karar_veren: "Muhasebe Demo",
        karar_zamani: "2026-06-12T10:00:00Z",
        uygulama_modu: "CAKISMA_COZUM",
        projection_version: 1,
        source_integrity: "OK",
        audit_integrity: "OK"
      }
    ];

    const summary = {
      toplam_aday: items.length,
      otomatik_uygulanan: 0,
      manuel_uygulanan: 1,
      koru: 0,
      revize: 1,
      yok_sayilan: 0,
      bekleyen: 1,
      conflict_dagilimi: { MEVCUT_PUANTAJ_VAR: 1 },
      toplam_gec_kalma_dakika: 15,
      toplam_erken_cikis_dakika: 0,
      toplam_devamsizlik_gun: 1
    };

    return ok(
      { items, summary, sube_id: subeId, ay },
      { page: 1, limit: 20, total: items.length, total_pages: 1, has_next_page: false, has_prev_page: false }
    );
  }

  if (pathname === "/puantaj/bildirim-etki-adaylari/rapor/export.csv" && method === "GET") {
    const actor = readDemoApiActor(init);
    const permissionError = enforceDemoPermission(actor, "puantaj.bildirim_etki.rapor.export");
    if (permissionError) {
      return permissionError;
    }

    return ok("id,personel_id,tarih,state\n1,1,2026-06-03,HAZIR\n2,2,2026-06-04,UYGULANDI\n");
  }

  return null;
}
