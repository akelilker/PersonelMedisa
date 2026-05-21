import { useMemo } from "react";
import {
  dataCacheKeys,
  ensureAppData,
  getCacheEntry,
  useAppDataRevision
} from "../data/data-manager";
import { hesaplaDevamPrimiEligibility } from "../services/devam-primi-hesap-motoru";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";
import type { GunlukPuantaj } from "../types/puantaj";

export type DevamPrimiEligibilityDurum = "hak_kazandi" | "kesildi" | "manuel_inceleme";

export type DevamPrimiEligibilityOzetiView = {
  donem: string;
  durum: DevamPrimiEligibilityDurum;
  durumLabel: string;
  aciklama: string;
  kayitKapsamiNotu: string | null;
};

export const DEVAM_PRIMI_VERI_KAPSAMI_EKSIK_ACIKLAMA =
  "Bu dönem için tüm günlük puantaj kayıtları yüklenmeden devam primi kesin değerlendirilemez.";

function isDonemVeriKapsamiEksik(kayitSayisi: number, donemGunSayisi: number): boolean {
  return kayitSayisi < donemGunSayisi;
}

function buildKayitKapsamiNotu(kayitSayisi: number, donemGunSayisi: number): string {
  return `Bu dönem için önbellekte ${kayitSayisi}/${donemGunSayisi} günlük kayıt bulundu.`;
}

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

function toplaDonemPuantajKayitlari(
  activeSube: number | null,
  personelId: number,
  yil: number,
  ay: number
): GunlukPuantaj[] {
  const tarihler = listDonemTarihleri(yil, ay);
  const byTarih = new Map<string, GunlukPuantaj>();

  for (const tarih of tarihler) {
    const key = dataCacheKeys.puantajDetail(activeSube, personelId, tarih);
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

    const cached = getCacheEntry<GunlukPuantaj | null>(key);
    if (cached != null) {
      byTarih.set(tarih, cached);
    }
  }

  return [...byTarih.values()];
}

export function mapDevamPrimiEligibilityToView(
  sonuc: ReturnType<typeof hesaplaDevamPrimiEligibility>,
  kayitSayisi: number,
  donemGunSayisi: number
): DevamPrimiEligibilityOzetiView {
  const kapsamEksik = isDonemVeriKapsamiEksik(kayitSayisi, donemGunSayisi);
  const kapsamNotu = kapsamEksik ? buildKayitKapsamiNotu(kayitSayisi, donemGunSayisi) : null;

  if (kapsamEksik) {
    if (sonuc.kesildi_mi) {
      return {
        donem: sonuc.donem,
        durum: "kesildi",
        durumLabel: "Kesildi",
        aciklama: sonuc.aciklama?.trim() || "-",
        kayitKapsamiNotu: kapsamNotu
      };
    }

    return {
      donem: sonuc.donem,
      durum: "manuel_inceleme",
      durumLabel: "Manuel İnceleme Gerekli",
      aciklama: DEVAM_PRIMI_VERI_KAPSAMI_EKSIK_ACIKLAMA,
      kayitKapsamiNotu: kapsamNotu
    };
  }

  let durum: DevamPrimiEligibilityDurum;
  let durumLabel: string;

  if (sonuc.kesildi_mi) {
    durum = "kesildi";
    durumLabel = "Kesildi";
  } else if (sonuc.manuel_inceleme_gerekli_mi) {
    durum = "manuel_inceleme";
    durumLabel = "Manuel İnceleme Gerekli";
  } else {
    durum = "hak_kazandi";
    durumLabel = "Hak Kazandı";
  }

  return {
    donem: sonuc.donem,
    durum,
    durumLabel,
    aciklama: sonuc.aciklama?.trim() || "-",
    kayitKapsamiNotu: null
  };
}

export function useDevamPrimiEligibilityOzeti(personel: Personel): DevamPrimiEligibilityOzetiView | null {
  const { session } = useAuth();
  const activeSube = session?.active_sube_id ?? null;
  const appDataRevision = useAppDataRevision();

  return useMemo(() => {
    void appDataRevision;

    const parsedDonem = parseDonemFromPersonel(personel);
    if (!parsedDonem) {
      return null;
    }

    const gunlukKayitlar = toplaDonemPuantajKayitlari(
      activeSube,
      personel.id,
      parsedDonem.yil,
      parsedDonem.ay
    );

    const sonuc = hesaplaDevamPrimiEligibility({
      personel_id: personel.id,
      yil: parsedDonem.yil,
      ay: parsedDonem.ay,
      prim_kurali_id: personel.prim_kurali_id,
      gunluk_kayitlar: gunlukKayitlar
    });

    return mapDevamPrimiEligibilityToView(
      sonuc,
      gunlukKayitlar.length,
      listDonemTarihleri(parsedDonem.yil, parsedDonem.ay).length
    );
  }, [
    activeSube,
    appDataRevision,
    personel.id,
    personel.prim_kurali_id,
    personel.sgk_donem
  ]);
}
