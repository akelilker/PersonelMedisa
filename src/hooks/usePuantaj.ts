import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { getApiErrorMessage, shouldQueueOfflineMutation } from "../api/api-client";
import { fetchPersonelDetail } from "../api/personeller.api";
import { fetchGunlukPuantaj, upsertGunlukPuantaj } from "../api/puantaj.api";
import {
  dataCacheKeys,
  enqueueSyncOperation,
  fetchWithCacheMerge,
  getCacheEntry,
  mergePuantajCache,
  processSyncQueue,
  useAppDataRevision
} from "../data/data-manager";
import { runDeduped } from "../lib/in-flight-dedupe";
import { formatPuantajStateLabel } from "../lib/display/enum-display";
import {
  deriveGunTipi,
  hesapla,
  hesaplaDevamsizlikKesintiOzeti,
  hesaplaGecErkenEksikSure,
  hesaplaGecKalmaErkenCikmaKesintiOzeti,
  hesaplaHaftaAraligi,
  hesaplaHaftalikPuantajUcretOzeti,
  hesaplaTatilEkOdemeOzeti,
  hesaplaYasKuraliBlokMesaji,
  hesapSonucuToGunlukPuantaj,
  birlestirUbgtFazlaMesaiCakismaUyari,
  birlestirMazeretsizDevamsizlikAdayUyariari,
  BORDRO_ETKISI_KESINLESME_NOTU,
  mazeretsizDevamsizlikParasalNetKilitliMi,
  parasalNetEtkidenDusulecekKesintiTutari,
  type DevamsizlikKesintiOzeti,
  type HaftalikPuantajUcretOzeti,
  type TatilEkOdemeOzeti
} from "../services/puantaj-hesap-motoru";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type {
  GunlukPuantaj,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu
} from "../types/puantaj";

type ActiveQuery = {
  personelId: number;
  tarih: string;
};

function toDateInputValue(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function hareketDurumuSaatGerekliMi(
  hareketDurumu: PuantajHareketDurumu | "" | undefined
): boolean {
  return hareketDurumu === "Geldi" || hareketDurumu === "Gec_Geldi" || hareketDurumu === "Erken_Cikti";
}

function hareketDurumuBeklenenSaatBilgisiGosterilmeliMi(
  hareketDurumu: PuantajHareketDurumu | "" | undefined
): boolean {
  return hareketDurumu === "Gec_Geldi" || hareketDurumu === "Erken_Cikti";
}

export type GunlukPuantajFormState = {
  queryPersonelId: string;
  queryTarih: string;
  entryGunTipi: PuantajGunTipi | "";
  entryHareketDurumu: PuantajHareketDurumu | "";
  entryDayanak: PuantajDayanak | "";
  entryDurumuBildirdiMi: "" | "evet" | "hayir";
  entryDurumBildirimAciklamasi: string;
  entryBeklenenGirisSaati: string;
  entryBeklenenCikisSaati: string;
  entryGirisSaati: string;
  entryCikisSaati: string;
  entryGercekMolaDakika: string;
};

function toPuantajFormState(
  puantaj: GunlukPuantaj | null,
  fallbackTarih: string
): Pick<
  GunlukPuantajFormState,
  | "entryGunTipi"
  | "entryHareketDurumu"
  | "entryDayanak"
  | "entryDurumuBildirdiMi"
  | "entryDurumBildirimAciklamasi"
  | "entryBeklenenGirisSaati"
  | "entryBeklenenCikisSaati"
  | "entryGirisSaati"
  | "entryCikisSaati"
  | "entryGercekMolaDakika"
> {
  const effectiveTarih = puantaj?.tarih ?? fallbackTarih;

  return {
    entryGunTipi: puantaj?.gun_tipi ?? deriveGunTipi(effectiveTarih),
    entryHareketDurumu: puantaj?.hareket_durumu ?? "",
    entryDayanak: puantaj?.dayanak ?? "",
    entryDurumuBildirdiMi:
      puantaj?.durumu_bildirdi_mi === true
        ? "evet"
        : puantaj?.durumu_bildirdi_mi === false
          ? "hayir"
          : "",
    entryDurumBildirimAciklamasi: puantaj?.durum_bildirim_aciklamasi ?? "",
    entryBeklenenGirisSaati: puantaj?.beklenen_giris_saati ?? "",
    entryBeklenenCikisSaati: puantaj?.beklenen_cikis_saati ?? "",
    entryGirisSaati: puantaj?.giris_saati ?? "",
    entryCikisSaati: puantaj?.cikis_saati ?? "",
    entryGercekMolaDakika:
      puantaj?.gercek_mola_dakika !== undefined ? String(puantaj.gercek_mola_dakika) : ""
  };
}

function parseRequiredPositiveInt(value: string, label: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }
  return number;
}

async function loadPersonelDogumTarihi(
  activeSube: number | null,
  personelId: number
): Promise<string | undefined> {
  const detailKey = dataCacheKeys.personelDetail(activeSube, personelId);
  const cached = getCacheEntry<{ dogum_tarihi?: string }>(detailKey);
  if (cached?.dogum_tarihi) {
    return cached.dogum_tarihi;
  }

  const personel = await fetchWithCacheMerge(detailKey, () =>
    runDeduped(detailKey, () => fetchPersonelDetail(personelId))
  );
  return personel?.dogum_tarihi;
}

function parseOptionalNonNegativeInt(value: string) {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number < 0) {
    throw new Error("Gercek mola dakika sifirdan kucuk olamaz.");
  }

  return number;
}

const TODAY_INPUT = toDateInputValue(new Date());

const INITIAL_FORM: GunlukPuantajFormState = {
  queryPersonelId: "",
  queryTarih: TODAY_INPUT,
  entryGunTipi: deriveGunTipi(TODAY_INPUT),
  entryHareketDurumu: "",
  entryDayanak: "",
  entryDurumuBildirdiMi: "",
  entryDurumBildirimAciklamasi: "",
  entryBeklenenGirisSaati: "",
  entryBeklenenCikisSaati: "",
  entryGirisSaati: "",
  entryCikisSaati: "",
  entryGercekMolaDakika: ""
};

/** Pazartesi başlangıcından itibaren 7 gün YYYY-MM-DD. */
function listHaftaGGaatarihleri(haftaBaslangic: string): string[] {
  const m1 = /^(\d{4})-(\d{2})-(\d{2})$/.exec(haftaBaslangic);
  if (!m1) return [];
  const d0 = new Date(Number(m1[1]), Number(m1[2]) - 1, Number(m1[3]));
  const out: string[] = [];
  for (let i = 0; i < 7; i++) {
    const cur = new Date(d0.getFullYear(), d0.getMonth(), d0.getDate() + i);
    const y = cur.getFullYear();
    const mo = String(cur.getMonth() + 1).padStart(2, "0");
    const day = String(cur.getDate()).padStart(2, "0");
    out.push(`${y}-${mo}-${day}`);
  }
  return out;
}

function toplaHaftalikPuantajGunleri(
  activeSube: number | null,
  personelId: number,
  aralik: { hafta_baslangic: string; hafta_bitis: string },
  aktifKayit: GunlukPuantaj | null
): GunlukPuantaj[] {
  const tarihler = listHaftaGGaatarihleri(aralik.hafta_baslangic);
  const gunler: GunlukPuantaj[] = [];
  for (const tarih of tarihler) {
    if (aktifKayit && aktifKayit.personel_id === personelId && aktifKayit.tarih === tarih) {
      gunler.push(aktifKayit);
      continue;
    }
    const key = dataCacheKeys.puantajDetail(activeSube, personelId, tarih);
    const cached = getCacheEntry<GunlukPuantaj | null>(key);
    if (cached != null) {
      gunler.push(cached);
    }
  }
  return gunler;
}

function hesaplaHaftalikPuantajBaglam(
  activeSube: number | null,
  personelId: number,
  referansTarih: string,
  aktifKayit: GunlukPuantaj | null
): { gunler: GunlukPuantaj[]; tamHaftaVerisi: boolean } | null {
  const aralik = hesaplaHaftaAraligi(referansTarih);
  if (!aralik) {
    return null;
  }
  const gunler = toplaHaftalikPuantajGunleri(activeSube, personelId, aralik, aktifKayit);
  const tumGunler = listHaftaGGaatarihleri(aralik.hafta_baslangic);
  return {
    gunler,
    tamHaftaVerisi: gunler.length >= tumGunler.length
  };
}

export type HaftalikPuantajOzetDurumu = "yok" | "gecersiz_tarih" | "hazir";

/** Readonly “Parasal Etki Ön İzleme” — mevcut özetlerin birleşimi; bordro kesinliği taşımaz. */
export type ParasalEtkiOzeti = {
  haftalik_fazla_calisma_tutari: number;
  tatil_ek_odeme_tutari: number;
  devamsizlik_kesinti_tutari: number;
  net_etki_tutari: number | null;
  net_etki_hesaplanabilir_mi: boolean;
  manuel_inceleme_gerekli_mi: boolean;
  notlar: string[];
};

export type GecErkenKesintiOzeti = {
  tip: "GEC_KALMA" | "ERKEN_CIKMA";
  gercek_eksik_dakika: number;
  kesintiye_esas_dakika: number;
  kesinti_tutari: number;
};

export type GecErkenKesintiPreview = {
  gecErkenKesintiOzeti: GecErkenKesintiOzeti | null;
  gecErkenKesintiTutari: number;
  gecErkenKesintiNotu: string | null;
  gecErkenKesintiHesaplanamadiMi: boolean;
};

export function deriveGecErkenKesintiPreview(
  puantaj: GunlukPuantaj,
  maasTutari: number
): GecErkenKesintiPreview {
  let gecErkenKesintiTutari = 0;
  let gecErkenKesintiOzeti: GecErkenKesintiOzeti | null = null;
  let gecErkenKesintiNotu: string | null = null;
  let gecErkenKesintiHesaplanamadiMi = false;

  if (puantaj.hareket_durumu === "Gec_Geldi" || puantaj.hareket_durumu === "Erken_Cikti") {
    const eksikSureSonucu = hesaplaGecErkenEksikSure({
      hareket_durumu: puantaj.hareket_durumu,
      giris_saati: puantaj.giris_saati,
      cikis_saati: puantaj.cikis_saati,
      beklenen_giris_saati: puantaj.beklenen_giris_saati,
      beklenen_cikis_saati: puantaj.beklenen_cikis_saati
    });

    if (eksikSureSonucu.hesaplanabilir_mi) {
      if (eksikSureSonucu.eksik_dakika > 0) {
        const ozet = hesaplaGecKalmaErkenCikmaKesintiOzeti(eksikSureSonucu.eksik_dakika, maasTutari);
        gecErkenKesintiTutari = ozet.kesinti_tutari;
        gecErkenKesintiOzeti = {
          tip:
            eksikSureSonucu.tip ??
            (puantaj.hareket_durumu === "Erken_Cikti" ? "ERKEN_CIKMA" : "GEC_KALMA"),
          gercek_eksik_dakika: ozet.gercek_eksik_dakika,
          kesintiye_esas_dakika: ozet.kesintiye_esas_dakika,
          kesinti_tutari: ozet.kesinti_tutari
        };
      }
    } else if (eksikSureSonucu.neden === "BEKLENEN_SAAT_YOK") {
      gecErkenKesintiHesaplanamadiMi = true;
      gecErkenKesintiNotu =
        "Geç kalma veya erken çıkma kesintisi için beklenen mesai saati bulunmadığından saatlik kesinti ön izlemesi gösterilmiyor.";
    } else if (eksikSureSonucu.neden === "GERCEK_SAAT_YOK") {
      gecErkenKesintiHesaplanamadiMi = true;
      gecErkenKesintiNotu =
        "Geç kalma veya erken çıkma kesintisi için gerçek saat bilgisi eksik olduğundan saatlik kesinti ön izlemesi gösterilmiyor.";
    } else if (eksikSureSonucu.neden === "GECERSIZ_SAAT") {
      gecErkenKesintiHesaplanamadiMi = true;
      gecErkenKesintiNotu =
        "Geç kalma veya erken çıkma kesintisi için saat formatı geçersiz olduğundan saatlik kesinti ön izlemesi gösterilmiyor.";
    }
  }

  return {
    gecErkenKesintiOzeti,
    gecErkenKesintiTutari,
    gecErkenKesintiNotu,
    gecErkenKesintiHesaplanamadiMi
  };
}

export type PuantajReadonlyFieldView = {
  label: string;
  value: string;
};

export type PuantajAnaDetayView = {
  fields: PuantajReadonlyFieldView[];
};

const GUN_TIPI_LABELS: Record<PuantajGunTipi, string> = {
  Normal_Is_Gunu: "Normal İş Günü",
  Hafta_Tatili_Pazar: "Hafta Tatili Pazar",
  UBGT_Resmi_Tatil: "UBGT Resmi Tatil"
};

const HAREKET_DURUMU_LABELS: Record<PuantajHareketDurumu, string> = {
  Geldi: "Geldi",
  Gelmedi: "Gelmedi",
  Gec_Geldi: "Geç Geldi",
  Erken_Cikti: "Erken Çıktı"
};

const DAYANAK_LABELS: Record<PuantajDayanak, string> = {
  Yok_Izinsiz: "Yok / İzinsiz",
  Ucretli_Izinli: "Ücretli İzinli",
  Raporlu_Hastalik: "Raporlu Hastalık",
  Raporlu_Is_Kazasi: "Raporlu İş Kazası",
  Yillik_Izin: "Yıllık İzin",
  Telafi_Calismasi: "Telafi Çalışması"
};

const HESAP_ETKISI_LABELS = {
  Kesinti_Yap: "Kesinti Yap",
  Tam_Yevmiye_Ver: "Tam Yevmiye Ver",
  Mesai_Yaz: "Mesai Yaz"
} as const;

const KONTROL_DURUMU_LABELS = {
  BEKLIYOR: "Bekliyor",
  AMIR_KONTROL_ETTI: "Amir kontrol etti"
} as const;

function humanizeFallback(value: string) {
  return value
    .split("_")
    .filter(Boolean)
    .map((token) => token.charAt(0).toUpperCase() + token.slice(1).toLowerCase())
    .join(" ");
}

function formatMappedValue<T extends string>(
  value: T | "" | null | undefined,
  labels: Record<string, string>
) {
  if (!value) {
    return "-";
  }

  return labels[value] ?? humanizeFallback(value);
}

function formatSaatValue(value: string | null | undefined) {
  return value && value.trim() ? value : "-";
}

function formatDakikaValue(value: number | null | undefined) {
  return value !== undefined && value !== null ? String(value) : "-";
}

function formatHakKazanimi(value: boolean | null | undefined) {
  if (value === true) {
    return "Hak Kazandı";
  }

  if (value === false) {
    return "Hak Kazanmadı";
  }

  return "-";
}

function formatDayanakValue(value: GunlukPuantaj["dayanak"]) {
  if (!value) {
    return "Yok";
  }

  return formatMappedValue(value, DAYANAK_LABELS);
}

function formatKontrolDurumu(value: GunlukPuantaj["kontrol_durumu"]) {
  const key = value ?? "BEKLIYOR";
  return KONTROL_DURUMU_LABELS[key] ?? key;
}

function toPuantajAnaDetayView(puantaj: GunlukPuantaj | null): PuantajAnaDetayView | null {
  if (!puantaj) {
    return null;
  }

  return {
    fields: [
      { label: "Personel ID", value: String(puantaj.personel_id) },
      { label: "Tarih", value: puantaj.tarih },
      { label: "Kayıt Durumu", value: formatPuantajStateLabel(puantaj.state) },
      { label: "Kontrol Durumu", value: formatKontrolDurumu(puantaj.kontrol_durumu) },
      { label: "Gün Tipi", value: formatMappedValue(puantaj.gun_tipi, GUN_TIPI_LABELS) },
      {
        label: "Hareket Durumu",
        value: formatMappedValue(puantaj.hareket_durumu, HAREKET_DURUMU_LABELS)
      },
      { label: "Dayanak", value: formatDayanakValue(puantaj.dayanak) },
      {
        label: "Hesap Etkisi",
        value: formatMappedValue(puantaj.hesap_etkisi, HESAP_ETKISI_LABELS)
      },
      {
        label: "Hafta Tatili Hakkı",
        value: formatHakKazanimi(puantaj.hafta_tatili_hak_kazandi_mi)
      },
      { label: "Beklenen Giriş", value: formatSaatValue(puantaj.beklenen_giris_saati) },
      { label: "Beklenen Çıkış", value: formatSaatValue(puantaj.beklenen_cikis_saati) },
      { label: "Giriş Saati", value: formatSaatValue(puantaj.giris_saati) },
      { label: "Çıkış Saati", value: formatSaatValue(puantaj.cikis_saati) },
      { label: "Gerçek Mola (dk)", value: formatDakikaValue(puantaj.gercek_mola_dakika) },
      { label: "Hesaplanan Mola (dk)", value: formatDakikaValue(puantaj.hesaplanan_mola_dakika) },
      { label: "Net Çalışma (dk)", value: formatDakikaValue(puantaj.net_calisma_suresi_dakika) },
      { label: "Günlük Brüt Süre (dk)", value: formatDakikaValue(puantaj.gunluk_brut_sure_dakika) }
    ]
  };
}

function onIzlemeParasalGuvenlikNotu(not: string | null | undefined): boolean {
  if (!not?.trim()) {
    return false;
  }
  const t = not.toLowerCase();
  return (
    t.includes("yükleniyor") ||
    t.includes("geçici") ||
    t.includes("sıfır görünebilir") ||
    t.includes("sıfır görünür") ||
    t.includes("tanımlı değil") ||
    t.includes("net değil") ||
    t.includes("gösterilmiyor")
  );
}

export function usePuantaj() {
  const { session } = useAuth();
  const activeSube = session?.active_sube_id ?? null;
  const appDataRevision = useAppDataRevision();

  const [formState, setFormState] = useState<GunlukPuantajFormState>({ ...INITIAL_FORM });
  const [activeQuery, setActiveQuery] = useState<ActiveQuery | null>(null);
  const [puantaj, setPuantaj] = useState<GunlukPuantaj | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isKontrolSubmitting, setIsKontrolSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);
  const [personelMaasTutari, setPersonelMaasTutari] = useState<number | undefined>(undefined);

  const patchFormState = useCallback((partial: Partial<GunlukPuantajFormState>) => {
    setFormState((prev) => {
      const next = { ...prev, ...partial };
      if (partial.queryTarih !== undefined && partial.entryGunTipi === undefined && !activeQuery) {
        next.entryGunTipi = deriveGunTipi(partial.queryTarih);
      }
      return next;
    });
  }, [activeQuery]);

  const detailKeyFor = useCallback(
    (query: ActiveQuery) => dataCacheKeys.puantajDetail(activeSube, query.personelId, query.tarih),
    [activeSube]
  );

  const loadPuantaj = useCallback(
    async (query: ActiveQuery) => {
      setIsLoading(true);
      setErrorMessage(null);
      const key = detailKeyFor(query);

      try {
        const data = await fetchWithCacheMerge(key, () =>
          runDeduped(key, () => fetchGunlukPuantaj(query.personelId, query.tarih))
        );
        setPuantaj(data);
        patchFormState(toPuantajFormState(data, query.tarih));
      } catch {
        setErrorMessage("Gunluk puantaj kaydi su an guncellenemiyor.");
        const cached = getCacheEntry<GunlukPuantaj | null>(key);
        if (cached !== undefined) {
          setPuantaj(cached);
          patchFormState(toPuantajFormState(cached, query.tarih));
        } else {
          setPuantaj(null);
          patchFormState(toPuantajFormState(null, query.tarih));
        }
      } finally {
        setIsLoading(false);
      }
    },
    [detailKeyFor, patchFormState]
  );

  useEffect(() => {
    if (!activeQuery) {
      return;
    }
    void loadPuantaj(activeQuery);
  }, [activeSube, activeQuery, loadPuantaj]);

  const submitQuery = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();

      try {
        const personelId = parseRequiredPositiveInt(formState.queryPersonelId, "Personel ID");
        if (!formState.queryTarih) {
          throw new Error("Tarih zorunludur.");
        }

        const nextQuery: ActiveQuery = {
          personelId,
          tarih: formState.queryTarih
        };

        setActiveQuery(nextQuery);
        setSubmitErrorMessage(null);
        await loadPuantaj(nextQuery);
      } catch (error) {
        setErrorMessage(error instanceof Error ? error.message : "Puantaj sorgusu gecersiz.");
      }
    },
    [formState.queryPersonelId, formState.queryTarih, loadPuantaj]
  );

  useEffect(() => {
    if (!activeQuery) {
      setPersonelMaasTutari(undefined);
      return;
    }

    let cancelled = false;
    setPersonelMaasTutari(undefined);

    void (async () => {
      const detailKey = dataCacheKeys.personelDetail(activeSube, activeQuery.personelId);
      const cached = getCacheEntry<Personel>(detailKey);
      if (cached) {
        const m = cached.maas_tutari;
        if (!cancelled) {
          setPersonelMaasTutari(m != null && Number.isFinite(m) ? m : 0);
        }
        return;
      }

      try {
        const p = await fetchWithCacheMerge(detailKey, () =>
          runDeduped(detailKey, () => fetchPersonelDetail(activeQuery.personelId))
        );
        if (cancelled) {
          return;
        }
        const m = p?.maas_tutari;
        setPersonelMaasTutari(m != null && Number.isFinite(m) ? m : 0);
      } catch {
        if (!cancelled) {
          setPersonelMaasTutari(0);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [activeSube, activeQuery]);

  const {
    haftalikOzet,
    haftalikOzetDurumu,
    haftalikOzetEksikVeriNotu,
    haftaPuantajGunleri,
    tamHaftaVerisi
  } = useMemo(() => {
    if (!activeQuery) {
      return {
        haftalikOzet: null as HaftalikPuantajUcretOzeti | null,
        haftalikOzetDurumu: "yok" as HaftalikPuantajOzetDurumu,
        haftalikOzetEksikVeriNotu: null as string | null,
        haftaPuantajGunleri: [] as GunlukPuantaj[],
        tamHaftaVerisi: false
      };
    }

    const aralik = hesaplaHaftaAraligi(activeQuery.tarih);
    if (!aralik) {
      return {
        haftalikOzet: null,
        haftalikOzetDurumu: "gecersiz_tarih" as const,
        haftalikOzetEksikVeriNotu: "Sorgu tarihi geçersiz; haftalık özet hesaplanamadı.",
        haftaPuantajGunleri: [] as GunlukPuantaj[],
        tamHaftaVerisi: false
      };
    }

    const gunler = toplaHaftalikPuantajGunleri(
      activeSube,
      activeQuery.personelId,
      aralik,
      puantaj
    );
    const maas = personelMaasTutari ?? 0;
    const ozet = hesaplaHaftalikPuantajUcretOzeti(gunler, activeQuery.tarih, maas);

    const tumGunler = listHaftaGGaatarihleri(aralik.hafta_baslangic);
    const tamHaftaVerisi = gunler.length >= tumGunler.length;
    const parcalar: string[] = [];
    if (!tamHaftaVerisi) {
      parcalar.push(
        `Özet, bu personel için önbellekte bulunan ${gunler.length} günlük kayıtla hesaplandı (tam hafta ${tumGunler.length} gün). Diğer günler getirilmediyse toplamlar eksik olabilir.`
      );
    }
    if (personelMaasTutari === undefined) {
      parcalar.push("Personel maaşı yükleniyor; saatlik ücret ve tutar geçici olarak sıfır görünebilir.");
    }

    return {
      haftalikOzet: ozet,
      haftalikOzetDurumu: "hazir" as const,
      haftalikOzetEksikVeriNotu: parcalar.length > 0 ? parcalar.join(" ") : null,
      haftaPuantajGunleri: gunler,
      tamHaftaVerisi
    };
  }, [activeQuery, activeSube, puantaj, personelMaasTutari, appDataRevision]);

  const puantajGoruntuleme = useMemo(() => {
    if (!puantaj) {
      return puantaj;
    }
    let compliance = birlestirMazeretsizDevamsizlikAdayUyariari(
      puantaj.compliance_uyarilari,
      puantaj
    );
    if (haftalikOzetDurumu === "hazir") {
      compliance = birlestirUbgtFazlaMesaiCakismaUyari(
        compliance,
        puantaj,
        haftaPuantajGunleri,
        tamHaftaVerisi
      );
    }
    return {
      ...puantaj,
      compliance_uyarilari: compliance
    };
  }, [puantaj, haftalikOzetDurumu, haftaPuantajGunleri, tamHaftaVerisi]);

  const {
    devamsizlikKesintiOzet,
    gecErkenKesintiOzeti,
    gecErkenKesintiTutari,
    gecErkenKesintiNotu,
    gecErkenKesintiHesaplanamadiMi,
    kesintiOzetNotu
  } = useMemo(() => {
    if (!activeQuery || !puantaj) {
      return {
        devamsizlikKesintiOzet: null as DevamsizlikKesintiOzeti | null,
        gecErkenKesintiOzeti: null as GecErkenKesintiOzeti | null,
        gecErkenKesintiTutari: 0,
        gecErkenKesintiNotu: null as string | null,
        gecErkenKesintiHesaplanamadiMi: false,
        kesintiOzetNotu: null as string | null
      };
    }

    const maas = personelMaasTutari ?? 0;

    let devamsizlikKesintiOzet: DevamsizlikKesintiOzeti | null = null;
    if (puantaj.hareket_durumu === "Gelmedi" && puantaj.dayanak === "Yok_Izinsiz") {
      const haftaKayipGun =
        puantaj.hafta_tatili_hak_kazandi_mi === false
          ? 1
          : 0;
      devamsizlikKesintiOzet = hesaplaDevamsizlikKesintiOzeti(maas, {
        devamsizlik_gun_sayisi: 1,
        hafta_tatili_kaybi_gun_sayisi: haftaKayipGun
      });
    }

    let gecErkenKesintiTutari = 0;
    let gecErkenKesintiOzeti: GecErkenKesintiOzeti | null = null;
    let gecErkenKesintiNotu: string | null = null;
    let gecErkenKesintiHesaplanamadiMi = false;
    if (puantaj.hareket_durumu === "Gec_Geldi" || puantaj.hareket_durumu === "Erken_Cikti") {
      const preview = deriveGecErkenKesintiPreview(puantaj, maas);
      gecErkenKesintiTutari = preview.gecErkenKesintiTutari;
      gecErkenKesintiOzeti = preview.gecErkenKesintiOzeti;
      gecErkenKesintiNotu = preview.gecErkenKesintiNotu;
      gecErkenKesintiHesaplanamadiMi = preview.gecErkenKesintiHesaplanamadiMi;
    }

    const notlar: string[] = [];
    if (
      personelMaasTutari === undefined &&
      (devamsizlikKesintiOzet || gecErkenKesintiNotu || gecErkenKesintiTutari > 0)
    ) {
      notlar.push("Personel maaşı yükleniyor; kesinti tutarları geçici olarak sıfır görünebilir.");
    }

    return {
      devamsizlikKesintiOzet,
      gecErkenKesintiOzeti,
      gecErkenKesintiTutari,
      gecErkenKesintiNotu,
      gecErkenKesintiHesaplanamadiMi,
      kesintiOzetNotu: notlar.length > 0 ? notlar.join(" ") : null
    };
  }, [activeQuery, puantaj, personelMaasTutari, appDataRevision]);

  const { tatilEkOdemeOzeti, tatilEkOdemeNotu } = useMemo(() => {
    if (!activeQuery || !puantaj) {
      return {
        tatilEkOdemeOzeti: null as TatilEkOdemeOzeti | null,
        tatilEkOdemeNotu: null as string | null
      };
    }

    const maas = personelMaasTutari ?? 0;
    const ozet = hesaplaTatilEkOdemeOzeti(maas, {
      gun_tipi: puantaj.gun_tipi,
      hesap_etkisi: puantaj.hesap_etkisi,
      giris_saati: puantaj.giris_saati,
      cikis_saati: puantaj.cikis_saati,
      hafta_tatili_hak_kazandi_mi: puantaj.hafta_tatili_hak_kazandi_mi
    });

    if (!ozet) {
      return { tatilEkOdemeOzeti: null, tatilEkOdemeNotu: null };
    }

    const notlar: string[] = [];
    if (personelMaasTutari === undefined) {
      notlar.push("Personel maaşı yükleniyor; ek ödeme tutarı geçici olarak sıfır görünebilir.");
    } else if (!Number.isFinite(personelMaasTutari) || personelMaasTutari <= 0) {
      notlar.push("Personel maaşı tanımlı değil veya sıfır; ek ödeme tutarı sıfır görünür.");
    }

    if (ozet.tur === "HAFTA_TATILI") {
      const karar = ozet.hafta_tatili_pazar_karar;
      if (karar) {
        if (karar.manuel_inceleme_gerekli_mi) {
          notlar.push("Bu kayıt için manuel inceleme gerekli.");
        }
        if (karar.aciklama?.trim()) {
          notlar.push(karar.aciklama.trim());
        }
      } else {
        notlar.push(
          "Pazar hafta tatili hakkı bilgisi net değil; otomatik ek ödeme gösterilmiyor."
        );
      }
    }

    return {
      tatilEkOdemeOzeti: ozet,
      tatilEkOdemeNotu: notlar.length > 0 ? notlar.join(" ") : null
    };
  }, [activeQuery, puantaj, personelMaasTutari]);

  const parasalEtkiOzeti = useMemo((): ParasalEtkiOzeti | null => {
    if (!activeQuery || !puantaj) {
      return null;
    }

    const haftalik_fazla_calisma_tutari =
      haftalikOzetDurumu === "hazir" && haftalikOzet
        ? haftalikOzet.fazla_calisma_tutari
        : 0;

    const tatil_ek_odeme_tutari = tatilEkOdemeOzeti?.ek_odeme_tutari ?? 0;

    const devamsizlik_kesinti_tutari =
      (devamsizlikKesintiOzet?.toplam_kesinti_tutari ?? 0) + gecErkenKesintiTutari;

    const mazeretsizDevamsizlikAdayi = mazeretsizDevamsizlikParasalNetKilitliMi(puantaj);

    const manuel_inceleme_gerekli_mi =
      tatilEkOdemeOzeti?.hafta_tatili_pazar_karar?.manuel_inceleme_gerekli_mi === true;

    const haftalikEksikVeyaGuvenilmezNot = Boolean(haftalikOzetEksikVeriNotu?.trim());
    const tatilNotuGuvenliDegil = onIzlemeParasalGuvenlikNotu(tatilEkOdemeNotu);
    const kesintiOzetNotuGuvenliDegil = onIzlemeParasalGuvenlikNotu(kesintiOzetNotu);
    const gecErkenHesaplanamadi = gecErkenKesintiHesaplanamadiMi;

    const net_etki_hesaplanabilir_mi = !(
      mazeretsizDevamsizlikAdayi ||
      manuel_inceleme_gerekli_mi ||
      haftalikEksikVeyaGuvenilmezNot ||
      tatilNotuGuvenliDegil ||
      kesintiOzetNotuGuvenliDegil ||
      gecErkenHesaplanamadi
    );

    const netHam =
      haftalik_fazla_calisma_tutari +
      tatil_ek_odeme_tutari -
      parasalNetEtkidenDusulecekKesintiTutari(devamsizlik_kesinti_tutari, mazeretsizDevamsizlikAdayi);
    const net_etki_tutari = net_etki_hesaplanabilir_mi
      ? Math.round(netHam * 100) / 100
      : null;

    const notlar: string[] = [];
    notlar.push("Bu özet bordro/muhasebe kesin hesabı değildir.");
    notlar.push("Bu kart günlük kesinti/ek ödeme ile haftalık fazla çalışma tutarını birlikte gösterir.");
    if (gecErkenHesaplanamadi) {
      notlar.push("Geç kalma / erken çıkma kesintileri bu özete dahil edilmedi.");
    }
    if (mazeretsizDevamsizlikAdayi) {
      notlar.push(BORDRO_ETKISI_KESINLESME_NOTU);
    }
    if (manuel_inceleme_gerekli_mi) {
      notlar.push("Pazar kaydı manuel inceleme gerektirdiği için net etki kesinleştirilemedi.");
    }
    if (haftalikEksikVeyaGuvenilmezNot) {
      notlar.push("Haftalık özet eksik kayıtlarla hesaplandı; toplam etki kesinleştirilemedi.");
    }
    if (tatilNotuGuvenliDegil || kesintiOzetNotuGuvenliDegil) {
      notlar.push(
        "Maaş veya tatil ek ödeme bilgisi güvenli olmadığı için net etki kesinleştirilemedi."
      );
    }

    return {
      haftalik_fazla_calisma_tutari,
      tatil_ek_odeme_tutari,
      devamsizlik_kesinti_tutari,
      net_etki_tutari,
      net_etki_hesaplanabilir_mi,
      manuel_inceleme_gerekli_mi,
      notlar
    };
  }, [
    activeQuery,
    puantaj,
    haftalikOzet,
    haftalikOzetDurumu,
    haftalikOzetEksikVeriNotu,
    devamsizlikKesintiOzet,
    gecErkenKesintiOzeti,
    gecErkenKesintiTutari,
    gecErkenKesintiHesaplanamadiMi,
    kesintiOzetNotu,
    tatilEkOdemeOzeti,
    tatilEkOdemeNotu
  ]);

  const anaDetay = useMemo(() => toPuantajAnaDetayView(puantajGoruntuleme), [puantajGoruntuleme]);

  const clearQuery = useCallback(() => {
    setFormState({ ...INITIAL_FORM });
    setActiveQuery(null);
    setPuantaj(null);
    setErrorMessage(null);
    setSubmitErrorMessage(null);
    setPersonelMaasTutari(undefined);
  }, []);

  const refetchActive = useCallback(async () => {
    if (!activeQuery) {
      return;
    }
    await loadPuantaj(activeQuery);
  }, [activeQuery, loadPuantaj]);

  const entryRequiresSaatBilgisi = useMemo(
    () => hareketDurumuSaatGerekliMi(formState.entryHareketDurumu),
    [formState.entryHareketDurumu]
  );

  const submitPuantaj = useCallback(
    async (event: FormEvent<HTMLFormElement>, canUpdate: boolean) => {
      event.preventDefault();
      if (isSubmitting) {
        return;
      }

      if (!activeQuery) {
        setSubmitErrorMessage("Kaydi guncellemek icin once personel ve tarih sec.");
        return;
      }

      if (!canUpdate) {
        setSubmitErrorMessage("Bu islem icin yetkin bulunmuyor.");
        return;
      }

      setSubmitErrorMessage(null);
      setIsSubmitting(true);

      try {
        const gunTipi = formState.entryGunTipi || deriveGunTipi(activeQuery.tarih);
        const hareketDurumu = formState.entryHareketDurumu;
        const dayanak = formState.entryDayanak || undefined;
        const durumuBildirdiMi =
          hareketDurumu === "Gelmedi"
            ? formState.entryDurumuBildirdiMi === "evet"
              ? true
              : formState.entryDurumuBildirdiMi === "hayir"
                ? false
                : undefined
            : undefined;
        const durumBildirimAciklamasi =
          durumuBildirdiMi === true
            ? formState.entryDurumBildirimAciklamasi.trim() || undefined
            : undefined;

        if (!hareketDurumu) {
          throw new Error("Hareket durumu zorunludur.");
        }

        if (hareketDurumu === "Gelmedi" && durumuBildirdiMi === undefined) {
          throw new Error("Durumu bildirdi mi alanı zorunludur.");
        }

        const beklenenSaatBilgisiGosterilmeliMi =
          hareketDurumuBeklenenSaatBilgisiGosterilmeliMi(hareketDurumu);
        const beklenenGirisSaati = formState.entryBeklenenGirisSaati.trim();
        const beklenenCikisSaati = formState.entryBeklenenCikisSaati.trim();
        const girisSaati = formState.entryGirisSaati.trim();
        const cikisSaati = formState.entryCikisSaati.trim();

        if (hareketDurumuSaatGerekliMi(hareketDurumu) && (!girisSaati || !cikisSaati)) {
          throw new Error("Bu hareket durumu icin giris ve cikis saati zorunludur.");
        }

        const body = {
          gun_tipi: gunTipi,
          hareket_durumu: hareketDurumu,
          dayanak,
          durumu_bildirdi_mi: hareketDurumu === "Gelmedi" ? durumuBildirdiMi : null,
          durum_bildirim_aciklamasi:
            durumuBildirdiMi === true ? durumBildirimAciklamasi ?? null : null,
          beklenen_giris_saati: beklenenSaatBilgisiGosterilmeliMi ? (beklenenGirisSaati || undefined) : undefined,
          beklenen_cikis_saati: beklenenSaatBilgisiGosterilmeliMi ? (beklenenCikisSaati || undefined) : undefined,
          giris_saati: hareketDurumuSaatGerekliMi(hareketDurumu) ? girisSaati : undefined,
          cikis_saati: hareketDurumuSaatGerekliMi(hareketDurumu) ? cikisSaati : undefined,
          gercek_mola_dakika: hareketDurumuSaatGerekliMi(hareketDurumu)
            ? parseOptionalNonNegativeInt(formState.entryGercekMolaDakika)
            : undefined
        };

        const dogumTarihi = await loadPersonelDogumTarihi(activeSube, activeQuery.personelId);
        if (!dogumTarihi) {
          throw new Error("Personelin dogum tarihi olmadan yas kurallari dogrulanamadi.");
        }

        const yasBlokMesaji = hesaplaYasKuraliBlokMesaji({
          tarih: activeQuery.tarih,
          dogum_tarihi: dogumTarihi,
          gun_tipi: body.gun_tipi,
          hareket_durumu: body.hareket_durumu,
          dayanak: body.dayanak,
          giris_saati: body.giris_saati,
          cikis_saati: body.cikis_saati
        });

        if (yasBlokMesaji) {
          throw new Error(yasBlokMesaji);
        }

        const hesapSonucu = hesapla({
          personel_id: activeQuery.personelId,
          tarih: activeQuery.tarih,
          gun_tipi: body.gun_tipi,
          hareket_durumu: body.hareket_durumu,
          dayanak: body.dayanak,
          giris_saati: body.giris_saati,
          cikis_saati: body.cikis_saati,
          gercek_mola_dakika: body.gercek_mola_dakika
        });
        const mapped = hesapSonucuToGunlukPuantaj(hesapSonucu, puantaj?.state ?? "ACIK", {
          kontrol_durumu: puantaj?.kontrol_durumu ?? "BEKLIYOR"
        });
        const haftalikBaglam = hesaplaHaftalikPuantajBaglam(
          activeSube,
          activeQuery.personelId,
          activeQuery.tarih,
          mapped
        );
        const optimistic: GunlukPuantaj = {
          ...mapped,
          durumu_bildirdi_mi: body.durumu_bildirdi_mi ?? undefined,
          durum_bildirim_aciklamasi: body.durum_bildirim_aciklamasi ?? undefined,
          compliance_uyarilari: (() => {
            let compliance = birlestirMazeretsizDevamsizlikAdayUyariari(
              mapped.compliance_uyarilari,
              mapped
            );
            if (haftalikBaglam) {
              compliance = birlestirUbgtFazlaMesaiCakismaUyari(
                compliance,
                mapped,
                haftalikBaglam.gunler,
                haftalikBaglam.tamHaftaVerisi
              );
            }
            return compliance;
          })()
        };

        const previousPuantaj = puantaj;
        mergePuantajCache(activeQuery.personelId, activeQuery.tarih, optimistic);
        setPuantaj(optimistic);
        patchFormState(toPuantajFormState(optimistic, activeQuery.tarih));

        try {
          const updated = await upsertGunlukPuantaj(activeQuery.personelId, activeQuery.tarih, body);
          mergePuantajCache(activeQuery.personelId, activeQuery.tarih, updated);
          setPuantaj(updated);
          patchFormState(toPuantajFormState(updated, activeQuery.tarih));
        } catch (error) {
          if (shouldQueueOfflineMutation(error)) {
            enqueueSyncOperation({
              op: "puantaj.upsert",
              payload: {
                personelId: activeQuery.personelId,
                tarih: activeQuery.tarih,
                body
              }
            });
            void processSyncQueue();
            return;
          }

          mergePuantajCache(activeQuery.personelId, activeQuery.tarih, previousPuantaj ?? null);
          setPuantaj(previousPuantaj ?? null);
          patchFormState(toPuantajFormState(previousPuantaj ?? null, activeQuery.tarih));
          setSubmitErrorMessage(getApiErrorMessage(error, "Puantaj kaydi guncellenemedi."));
        }
      } catch (error) {
        setSubmitErrorMessage(getApiErrorMessage(error, "Puantaj kaydi guncellenemedi."));
      } finally {
        setIsSubmitting(false);
      }
    },
    [
      activeQuery,
      activeSube,
      entryRequiresSaatBilgisi,
      formState.entryBeklenenCikisSaati,
      formState.entryBeklenenGirisSaati,
      formState.entryCikisSaati,
      formState.entryDayanak,
      formState.entryDurumBildirimAciklamasi,
      formState.entryDurumuBildirdiMi,
      formState.entryGercekMolaDakika,
      formState.entryGirisSaati,
      formState.entryGunTipi,
      formState.entryHareketDurumu,
      isSubmitting,
      patchFormState,
      puantaj
    ]
  );

  const markAmirKontrolEtti = useCallback(async () => {
    if (!activeQuery || !puantaj || isKontrolSubmitting || isSubmitting) {
      return;
    }
    if (puantaj.kontrol_durumu === "AMIR_KONTROL_ETTI") {
      return;
    }

    setSubmitErrorMessage(null);
    setIsKontrolSubmitting(true);
    const previousPuantaj = puantaj;
    const optimistic: GunlukPuantaj = { ...puantaj, kontrol_durumu: "AMIR_KONTROL_ETTI" };

    try {
      mergePuantajCache(activeQuery.personelId, activeQuery.tarih, optimistic);
      setPuantaj(optimistic);

      const updated = await upsertGunlukPuantaj(activeQuery.personelId, activeQuery.tarih, {
        kontrol_durumu: "AMIR_KONTROL_ETTI"
      });
      mergePuantajCache(activeQuery.personelId, activeQuery.tarih, updated);
      setPuantaj(updated);
    } catch (error) {
      if (shouldQueueOfflineMutation(error)) {
        enqueueSyncOperation({
          op: "puantaj.upsert",
          payload: {
            personelId: activeQuery.personelId,
            tarih: activeQuery.tarih,
            body: { kontrol_durumu: "AMIR_KONTROL_ETTI" }
          }
        });
        void processSyncQueue();
        return;
      }

      mergePuantajCache(activeQuery.personelId, activeQuery.tarih, previousPuantaj);
      setPuantaj(previousPuantaj);
      setSubmitErrorMessage(getApiErrorMessage(error, "Kontrol durumu guncellenemedi."));
    } finally {
      setIsKontrolSubmitting(false);
    }
  }, [activeQuery, puantaj, isKontrolSubmitting, isSubmitting]);

  return {
    formState,
    patchFormState,
    activeQuery,
    puantaj: puantajGoruntuleme,
    isLoading,
    isSubmitting,
    isKontrolSubmitting,
    errorMessage,
    submitErrorMessage,
    submitQuery,
    clearQuery,
    refetchActive,
    submitPuantaj,
    markAmirKontrolEtti,
    entryRequiresSaatBilgisi,
    haftalikOzet,
    haftalikOzetDurumu,
    haftalikOzetEksikVeriNotu,
    devamsizlikKesintiOzet,
    gecErkenKesintiOzeti,
    gecErkenKesintiNotu,
    kesintiOzetNotu,
    tatilEkOdemeOzeti,
    tatilEkOdemeNotu,
    parasalEtkiOzeti,
    anaDetay
  };
}
