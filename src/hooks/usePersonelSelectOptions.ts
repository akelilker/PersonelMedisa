import { useEffect, useMemo, useState } from "react";
import {
  PERSONEL_LIST_PAGE_MAX,
  fetchPersonellerListForSelect
} from "../api/personeller.api";
import { buildPersonelSelectLabels } from "../features/kayit/kayit-surec-utils";
import type { Personel } from "../types/personel";

/** Sayfa boyutu: backend list üst sınırı (seçim listesi sayfalanarak tamamı alınır). */
export const PERSONEL_SELECT_OPTIONS_LIMIT = PERSONEL_LIST_PAGE_MAX;

export type PersonelSelectOption = { value: string; label: string };

export type UsePersonelSelectOptionsResult = {
  personeller: Personel[];
  options: PersonelSelectOption[];
  /** internal id → görünür label (Ad Soyad; aynı isim birden fazlaysa • Sicil No). */
  labelById: Map<number, string>;
};

/**
 * Kullanıcıya dönük personel seçimi için görünür seçenek listesi.
 *
 * Seçilen değer teknik olarak internal personel ID'dir (`personel_id`); kullanıcı
 * yalnız Ad Soyad (+ gerekirse Sicil No) görür. Internal ID hiçbir etikette veya
 * ekranda gösterilmez. Etiket üretimi mevcut canonical owner'dan
 * (`buildPersonelSelectLabels`) gelir; paralel bir etiket sistemi kurulmaz.
 */
export function usePersonelSelectOptions(): UsePersonelSelectOptionsResult {
  const [personeller, setPersoneller] = useState<Personel[]>([]);

  useEffect(() => {
    let cancelled = false;

    void (async () => {
      try {
        const items = await fetchPersonellerListForSelect({ aktiflik: "tum" });
        if (!cancelled) {
          setPersoneller(items);
        }
      } catch {
        if (!cancelled) {
          setPersoneller([]);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  const labelById = useMemo(() => buildPersonelSelectLabels(personeller), [personeller]);

  const options = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: labelById.get(personel.id)?.trim() || "Personel"
      })),
    [personeller, labelById]
  );

  return { personeller, options, labelById };
}
