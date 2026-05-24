import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { fetchGunlukPuantaj } from "../api/puantaj.api";
import {
  dataCacheKeys,
  ensureAppData,
  fetchWithCacheMerge,
  getCacheEntry,
  useAppDataRevision
} from "../data/data-manager";
import { runDeduped } from "../lib/in-flight-dedupe";
import { hesaplaAylikPuantajEksikGunOzeti } from "../services/puantaj-hesap-motoru";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type { GunlukPuantaj } from "../types/puantaj";

const PUANTAJ_EKSIK_GUN_HYDRATE_LIMIT = 7;

export type PuantajEksikGunOzetiDurum =
  | "hazir"
  | "manuel_inceleme"
  | "veri_kapsami_eksik";

export type PuantajEksikGunHydrateDurumu = "idle" | "loading" | "success" | "error";

export type PuantajEksikGunOzetiView = {
  donem: string;
  durum: PuantajEksikGunOzetiDurum;
  durumLabel: string;
  toplamKayitSayisi: number;
  donemGunSayisi: number;
  eksikGunAdayiKayitSayisi: number;
  sgkPrimGununuDusurenEksikGunSayisi: number;
  manuelIncelemeKayitSayisi: number;
  dakikaBazliUcretEtkisiAdayiSayisi: number;
  gunlukKesintiAdayiSayisi: number;
  ucretKorunanKayitSayisi: number;
  haberliYoklukSinyaliSayisi: number;
  habersizYoklukSinyaliSayisi: number;
  kesinSgkPrimGunuHesaplanabilirMi: boolean;
  eksikTarihSayisi: number;
  eksikTarihListesi: string[];
  veriKapsamiTamMi: boolean;
  hydrateEksikPuantajTarihleri: () => Promise<void>;
  hydrateDurumu: PuantajEksikGunHydrateDurumu;
  hydrateEdilenTarihSayisi: number;
  hydrateHataMesaji: string | null;
  hydrateMumkunMu: boolean;
  aciklama: string;
  kayitKapsamiNotu: string | null;
};

export const PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA =
  "Bu dönem için tüm günlük puantaj kayıtları yüklenmeden eksik gün / SGK prim günü özeti kesinleştirilemez.";

function parseDonemFromPersonel(personel: Personel): { yil: number; ay: number; donem: string } | null {
  const raw = personel.sgk_donem?.trim();
  if (!raw) {
    return null;
  }

  const match = raw.match(/^(\d{4})-(\d{2})$/);
  if (!match) {
    return null;
  }

  const yil = Number.parseInt(match[1], 10);
  const ay = Number.parseInt(match[2], 10);
  if (!Number.isInteger(yil) || !Number.isInteger(ay) || ay < 1 || ay > 12) {
    return null;
  }

  return { yil, ay, donem: `${yil}-${String(ay).padStart(2, "0")}` };
}

function listDonemTarihleri(yil: number, ay: number): string[] {
  const daysInMonth = new Date(yil, ay, 0).getDate();
  const ayStr = String(ay).padStart(2, "0");
  const out: string[] = [];
  for (let day = 1; day <= daysInMonth; day++) {
    out.push(`${yil}-${ayStr}-${String(day).padStart(2, "0")}`);
  }
  return out;
}

function buildKayitKapsamiNotu(kayitSayisi: number, donemGunSayisi: number): string {
  return `Bu dönem için önbellekte ${kayitSayisi}/${donemGunSayisi} günlük kayıt bulundu.`;
}

function toplaDonemPuantajKapsami(
  activeSube: number | null,
  personelId: number,
  yil: number,
  ay: number
): {
  kayitlar: GunlukPuantaj[];
  tumTarihler: string[];
  eksikTarihListesi: string[];
  kapsamdakiTarihSayisi: number;
} {
  const tarihler = listDonemTarihleri(yil, ay);
  const byTarih = new Map<string, GunlukPuantaj>();
  const kapsamdakiTarihler = new Set<string>();
  const cache = ensureAppData().cache;

  for (const tarih of tarihler) {
    const key = dataCacheKeys.puantajDetail(activeSube, personelId, tarih);
    if (Object.prototype.hasOwnProperty.call(cache, key)) {
      kapsamdakiTarihler.add(tarih);
    }
    const cached = getCacheEntry<GunlukPuantaj | null>(key);
    if (cached != null) {
      byTarih.set(tarih, cached);
    }
  }

  const activeSubePersonelPrefix = dataCacheKeys.puantajDetail(activeSube, personelId, "");
  const donemPrefix = `${yil}-${String(ay).padStart(2, "0")}-`;
  for (const key of Object.keys(ensureAppData().cache)) {
    if (!key.startsWith(activeSubePersonelPrefix)) {
      continue;
    }

    const match = key.match(/^puantaj:s[^:]+:(\d+)\|(\d{4}-\d{2}-\d{2})$/);
    if (!match) {
      continue;
    }

    const cachedPersonelId = Number.parseInt(match[1], 10);
    const tarih = match[2];
    if (cachedPersonelId !== personelId || !tarih.startsWith(donemPrefix)) {
      continue;
    }

    kapsamdakiTarihler.add(tarih);
    const cached = getCacheEntry<GunlukPuantaj | null>(key);
    if (cached != null) {
      byTarih.set(tarih, cached);
    }
  }

  return {
    kayitlar: [...byTarih.values()],
    tumTarihler: tarihler,
    eksikTarihListesi: tarihler.filter((tarih) => !kapsamdakiTarihler.has(tarih)),
    kapsamdakiTarihSayisi: kapsamdakiTarihler.size
  };
}

export function mapAylikPuantajEksikGunOzetiToView(
  sonuc: ReturnType<typeof hesaplaAylikPuantajEksikGunOzeti>,
  donem: string,
  kayitSayisi: number,
  donemGunSayisi: number,
  eksikTarihListesi: string[],
  kapsamdakiTarihSayisi = donemGunSayisi - eksikTarihListesi.length
): PuantajEksikGunOzetiView {
  const eksikTarihSayisi = eksikTarihListesi.length;
  const veriKapsamiTamMi = eksikTarihSayisi === 0 && kapsamdakiTarihSayisi >= donemGunSayisi;
  const kapsamEksik = !veriKapsamiTamMi;
  const kesinSgkPrimGunuHesaplanabilirMi =
    sonuc.kesin_sgk_prim_gunu_hesaplanabilir_mi && !kapsamEksik;

  let durum: PuantajEksikGunOzetiDurum = "hazir";
  let durumLabel = "Hesaplanabilir";
  let aciklama = sonuc.aciklama;

  if (kapsamEksik) {
    durum = "veri_kapsami_eksik";
    durumLabel = "Veri Kapsamı Eksik";
    aciklama = PUANTAJ_EKSIK_GUN_VERI_KAPSAMI_EKSIK_ACIKLAMA;
  } else if (sonuc.manuel_inceleme_kayit_sayisi > 0) {
    durum = "manuel_inceleme";
    durumLabel = "Manuel İnceleme Gerekli";
  }

  return {
    donem,
    durum,
    durumLabel,
    toplamKayitSayisi: sonuc.toplam_kayit_sayisi,
    donemGunSayisi,
    eksikGunAdayiKayitSayisi: sonuc.eksik_gun_adayi_kayit_sayisi,
    sgkPrimGununuDusurenEksikGunSayisi: sonuc.sgk_prim_gununu_dusuren_eksik_gun_sayisi,
    manuelIncelemeKayitSayisi: sonuc.manuel_inceleme_kayit_sayisi,
    dakikaBazliUcretEtkisiAdayiSayisi: sonuc.dakika_bazli_ucret_etkisi_adayi_sayisi,
    gunlukKesintiAdayiSayisi: sonuc.gunluk_kesinti_adayi_sayisi,
    ucretKorunanKayitSayisi: sonuc.ucret_korunan_kayit_sayisi,
    haberliYoklukSinyaliSayisi: sonuc.haberli_yokluk_sinyali_sayisi,
    habersizYoklukSinyaliSayisi: sonuc.habersiz_yokluk_sinyali_sayisi,
    kesinSgkPrimGunuHesaplanabilirMi,
    eksikTarihSayisi,
    eksikTarihListesi,
    veriKapsamiTamMi,
    hydrateEksikPuantajTarihleri: async () => undefined,
    hydrateDurumu: "idle",
    hydrateEdilenTarihSayisi: 0,
    hydrateHataMesaji: null,
    hydrateMumkunMu: false,
    aciklama,
    kayitKapsamiNotu: kapsamEksik ? buildKayitKapsamiNotu(kayitSayisi, donemGunSayisi) : null
  };
}

export function usePuantajEksikGunOzeti(personel: Personel): PuantajEksikGunOzetiView | null {
  const { session } = useAuth();
  const activeSube = session?.active_sube_id ?? null;
  const appDataRevision = useAppDataRevision();
  const parsedDonem = useMemo(() => parseDonemFromPersonel(personel), [personel.sgk_donem]);
  const snapshotKey = parsedDonem
    ? `${activeSube ?? "all"}|${personel.id}|${parsedDonem.donem}`
    : null;
  const latestSnapshotRef = useRef<string | null>(snapshotKey);
  latestSnapshotRef.current = snapshotKey;
  const [hydrateDurumu, setHydrateDurumu] = useState<PuantajEksikGunHydrateDurumu>("idle");
  const [hydrateEdilenTarihSayisi, setHydrateEdilenTarihSayisi] = useState(0);
  const [hydrateHataMesaji, setHydrateHataMesaji] = useState<string | null>(null);

  useEffect(() => {
    setHydrateDurumu("idle");
    setHydrateEdilenTarihSayisi(0);
    setHydrateHataMesaji(null);
  }, [snapshotKey]);

  const readonlyOzeti = useMemo(() => {
    void appDataRevision;

    if (!parsedDonem) {
      return null;
    }

    const puantajKapsami = toplaDonemPuantajKapsami(
      activeSube,
      personel.id,
      parsedDonem.yil,
      parsedDonem.ay
    );
    const donemGunSayisi = puantajKapsami.tumTarihler.length;

    const sonuc = hesaplaAylikPuantajEksikGunOzeti({ kayitlar: puantajKapsami.kayitlar });

    return mapAylikPuantajEksikGunOzetiToView(
      sonuc,
      parsedDonem.donem,
      puantajKapsami.kayitlar.length,
      donemGunSayisi,
      puantajKapsami.eksikTarihListesi,
      puantajKapsami.kapsamdakiTarihSayisi
    );
  }, [activeSube, appDataRevision, parsedDonem, personel.id]);

  const hydrateMumkunMu =
    readonlyOzeti !== null &&
    readonlyOzeti.eksikTarihSayisi > 0 &&
    hydrateDurumu !== "loading";

  const hydrateEksikPuantajTarihleri = useCallback(async () => {
    if (!parsedDonem || !readonlyOzeti || hydrateDurumu === "loading") {
      return;
    }

    const hedefTarihler = readonlyOzeti.eksikTarihListesi.slice(0, PUANTAJ_EKSIK_GUN_HYDRATE_LIMIT);
    if (hedefTarihler.length === 0) {
      return;
    }

    const hydrateSnapshotKey = snapshotKey;
    setHydrateDurumu("loading");
    setHydrateEdilenTarihSayisi(0);
    setHydrateHataMesaji(null);

    const sonuclar = await Promise.allSettled(
      hedefTarihler.map(async (tarih) => {
        const key = dataCacheKeys.puantajDetail(activeSube, personel.id, tarih);
        const fetched = await runDeduped(key, () => fetchGunlukPuantaj(personel.id, tarih));
        await fetchWithCacheMerge(key, () => Promise.resolve(fetched));
      })
    );

    if (latestSnapshotRef.current !== hydrateSnapshotKey) {
      return;
    }

    const basariliSayisi = sonuclar.filter((sonuc) => sonuc.status === "fulfilled").length;
    const hata = sonuclar.find((sonuc): sonuc is PromiseRejectedResult => sonuc.status === "rejected");

    setHydrateEdilenTarihSayisi(basariliSayisi);
    if (hata) {
      setHydrateDurumu("error");
      setHydrateHataMesaji(
        hata.reason instanceof Error ? hata.reason.message : "Eksik puantaj tarihleri yüklenemedi."
      );
      return;
    }

    setHydrateDurumu("success");
    setHydrateHataMesaji(null);
  }, [activeSube, hydrateDurumu, parsedDonem, personel.id, readonlyOzeti, snapshotKey]);

  return useMemo(() => {
    if (!readonlyOzeti) {
      return null;
    }

    return {
      ...readonlyOzeti,
      hydrateEksikPuantajTarihleri,
      hydrateDurumu,
      hydrateEdilenTarihSayisi,
      hydrateHataMesaji,
      hydrateMumkunMu
    };
  }, [
    hydrateDurumu,
    hydrateEdilenTarihSayisi,
    hydrateEksikPuantajTarihleri,
    hydrateHataMesaji,
    hydrateMumkunMu,
    readonlyOzeti
  ]);
}
