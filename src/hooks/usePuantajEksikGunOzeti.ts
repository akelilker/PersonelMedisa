import { useEffect, useMemo, useState } from "react";
import {
  fetchSgkPrimGunuSonuclari,
  type SgkPrimGunuSonucu
} from "../api/maas-hesaplama.api";
import { useAuth } from "../state/auth.store";
import type { Personel } from "../types/personel";

export type PuantajEksikGunOzetiDurum = "hazir" | "manuel_inceleme" | "bulunamadi" | "hata";

export type PuantajEksikGunOzetiView = {
  donem: string;
  durum: PuantajEksikGunOzetiDurum;
  durumLabel: string;
  hesaplananPrimGunu: number | null;
  eksikGunSayisi: number | null;
  eksikGunKodu: string | null;
  eksikGunAciklamasi: string | null;
  kaynakSurecIdleri: number[];
  kaynakPuantajIdleri: number[];
  kaynakBelgeIdleri: number[];
  ucretModeli: string | null;
  ucretModeliLabel: string | null;
  sirketPolitikaSurumId: number | null;
  sgkOdenekDurumu: string | null;
  sgkOdenekDurumuLabel: string | null;
  manuelIncelemeGerekliMi: boolean;
  blockerKodlari: string[];
  blockerEtiketleri: string[];
  sgkHesapHash: string | null;
  katalogSurumu: string | null;
  kaynakManifestHash: string | null;
  snapshotId: number | null;
  snapshotRevisionNo: number | null;
  sourceHash: string | null;
  isLoading: boolean;
  errorMessage: string | null;
};

const UCRET_MODELI_ETIKET: Record<string, string> = {
  MAKTU_AYLIK: "Maktu aylık",
  GUNLUK: "Günlük",
  SAATLIK: "Saatlik",
  DIGER: "Diğer",
  BELIRSIZ: "Belirsiz"
};

const ODENEK_DURUMU_ETIKET: Record<string, string> = {
  UYGULANMAZ: "Uygulanmaz",
  KESINLESMEMIS: "Kesinleşmemiş",
  KESINLESTI: "Kesinleşti",
  MAHSUP_BEKLIYOR: "Mahsup bekliyor"
};

const BLOCKER_ETIKET: Record<string, string> = {
  SGK_PRIM_GUNU_HESAPLANAMADI: "SGK prim günü hesaplanamadı",
  SGK_EKSIK_GUN_KODU_BULUNAMADI: "Eksik gün kodu bulunamadı",
  SGK_EKSIK_GUN_KODU_CAKISTI: "Eksik gün kodları çakıştı",
  SGK_KATALOG_SURUMU_GECERSIZ: "SGK katalog sürümü geçersiz",
  SGK_EKSIK_GUN_BELGESI_EKSIK: "Eksik gün belgesi eksik",
  SGK_KAYNAK_SUREC_CELISKILI: "Kaynak süreç çelişkili",
  RAPOR_TURU_BELIRSIZ: "Rapor türü belirsiz",
  HASTALIK_ILK_IKI_GUN_POLITIKASI_EKSIK: "Hastalık ilk iki gün politikası eksik",
  UCRET_MODELI_BELIRSIZ: "Ücret modeli belirsiz",
  SGK_ODENEK_MAHSUP_POLITIKASI_EKSIK: "SGK ödenek/mahsup politikası eksik",
  CANONICAL_TAKVIM_EKSIK: "Canonical takvim eksik"
};

export function formatSgkUcretModeli(value: string | null | undefined): string | null {
  if (value == null || value === "") {
    return null;
  }
  return UCRET_MODELI_ETIKET[value] ?? value;
}

export function formatSgkOdenekDurumu(value: string | null | undefined): string | null {
  if (value == null || value === "") {
    return null;
  }
  return ODENEK_DURUMU_ETIKET[value] ?? value;
}

export function formatSgkBlockerKodu(code: string): string {
  return BLOCKER_ETIKET[code] ?? code;
}

function parseDonem(personel: Personel): { yil: number; ay: number; donem: string } | null {
  const match = personel.sgk_donem?.trim().match(/^(\d{4})-(\d{2})$/);
  if (!match) {
    return null;
  }
  const yil = Number.parseInt(match[1], 10);
  const ay = Number.parseInt(match[2], 10);
  return ay >= 1 && ay <= 12 ? { yil, ay, donem: `${yil}-${match[2]}` } : null;
}

export function mapCanonicalSgkSonucuToView(
  donem: string,
  row: SgkPrimGunuSonucu | null,
  isLoading = false,
  errorMessage: string | null = null
): PuantajEksikGunOzetiView {
  if (isLoading) {
    return emptyView(donem, "bulunamadi", "Yükleniyor", true, null);
  }
  if (errorMessage) {
    return emptyView(donem, "hata", "Yüklenemedi", false, errorMessage);
  }
  if (!row) {
    return emptyView(
      donem,
      "bulunamadi",
      "Immutable snapshot yok",
      false,
      "Bu dönem için authoritative SGK snapshot sonucu bulunamadı; frontend tahmin üretmedi."
    );
  }

  const manuel = row.manuel_inceleme_gerekli_mi || row.blocker_kodlari.length > 0;
  return {
    donem,
    durum: manuel ? "manuel_inceleme" : "hazir",
    durumLabel: manuel ? "Manuel İnceleme Gerekli" : "Snapshot Hazır",
    hesaplananPrimGunu: row.hesaplanan_prim_gunu,
    eksikGunSayisi: row.eksik_gun_sayisi,
    eksikGunKodu: row.eksik_gun_kodu,
    eksikGunAciklamasi: row.eksik_gun_aciklamasi,
    kaynakSurecIdleri: row.kaynak_surec_idleri,
    kaynakPuantajIdleri: row.kaynak_puantaj_idleri,
    kaynakBelgeIdleri: row.kaynak_belge_idleri,
    ucretModeli: row.ucret_modeli,
    ucretModeliLabel: formatSgkUcretModeli(row.ucret_modeli),
    sirketPolitikaSurumId: row.sirket_politika_surum_id,
    sgkOdenekDurumu: row.sgk_odenek_durumu,
    sgkOdenekDurumuLabel: formatSgkOdenekDurumu(row.sgk_odenek_durumu),
    manuelIncelemeGerekliMi: manuel,
    blockerKodlari: row.blocker_kodlari,
    blockerEtiketleri: row.blocker_kodlari.map(formatSgkBlockerKodu),
    sgkHesapHash: row.sgk_hesap_hash,
    katalogSurumu: row.katalog_surumu,
    kaynakManifestHash: row.kaynak_manifest_hash,
    snapshotId: row.snapshot_id,
    snapshotRevisionNo: row.snapshot_revision_no,
    sourceHash: row.source_hash,
    isLoading: false,
    errorMessage: null
  };
}

function emptyView(
  donem: string,
  durum: PuantajEksikGunOzetiDurum,
  durumLabel: string,
  isLoading: boolean,
  errorMessage: string | null
): PuantajEksikGunOzetiView {
  return {
    donem,
    durum,
    durumLabel,
    hesaplananPrimGunu: null,
    eksikGunSayisi: null,
    eksikGunKodu: null,
    eksikGunAciklamasi: null,
    kaynakSurecIdleri: [],
    kaynakPuantajIdleri: [],
    kaynakBelgeIdleri: [],
    ucretModeli: null,
    ucretModeliLabel: null,
    sirketPolitikaSurumId: null,
    sgkOdenekDurumu: null,
    sgkOdenekDurumuLabel: null,
    manuelIncelemeGerekliMi: false,
    blockerKodlari: [],
    blockerEtiketleri: [],
    sgkHesapHash: null,
    katalogSurumu: null,
    kaynakManifestHash: null,
    snapshotId: null,
    snapshotRevisionNo: null,
    sourceHash: null,
    isLoading,
    errorMessage
  };
}

export function usePuantajEksikGunOzeti(
  personel: Personel,
  enabled = true
): PuantajEksikGunOzetiView | null {
  const { session } = useAuth();
  const activeSube = session?.active_sube_id ?? null;
  const parsedDonem = useMemo(() => parseDonem(personel), [personel.sgk_donem]);
  const [row, setRow] = useState<SgkPrimGunuSonucu | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    if (!enabled || activeSube == null || !parsedDonem) {
      setRow(null);
      setIsLoading(false);
      setErrorMessage(null);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    fetchSgkPrimGunuSonuclari({
      sube_id: activeSube,
      yil: parsedDonem.yil,
      ay: parsedDonem.ay,
      personel_id: personel.id
    })
      .then((items) => {
        if (!cancelled) {
          setRow(items[0] ?? null);
        }
      })
      .catch((error: unknown) => {
        if (!cancelled) {
          setRow(null);
          setErrorMessage(error instanceof Error ? error.message : "SGK sonucu yüklenemedi.");
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [activeSube, enabled, parsedDonem, personel.id]);

  if (!parsedDonem || !enabled) {
    return null;
  }
  return mapCanonicalSgkSonucuToView(parsedDonem.donem, row, isLoading, errorMessage);
}
