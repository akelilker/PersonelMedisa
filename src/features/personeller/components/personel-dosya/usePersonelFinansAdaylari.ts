import { useEffect, useState } from "react";
import { getApiErrorMessage } from "../../../../api/api-client";
import { fetchFinansKalemList } from "../../../../api/finans.api";
import { getSubeIdForApiRequest } from "../../../../data/data-manager";
import type { FinansKalem } from "../../../../types/finans";
import type { Personel } from "../../../../types/personel";
import { isAktifFinansKaydi, sortFinansKayitlari } from "./personel-finans-adaylari-utils";

export function usePersonelFinansAdaylari({
  personel,
  canViewFinans,
  isActive
}: {
  personel: Personel;
  canViewFinans: boolean;
  isActive: boolean;
}) {
  const [finansKayitlari, setFinansKayitlari] = useState<FinansKalem[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [fetchResolved, setFetchResolved] = useState(false);
  const donem = typeof personel.sgk_donem === "string" ? personel.sgk_donem.trim() : "";
  const hasDonem = donem.length > 0;
  const canFetch = isActive && canViewFinans && Boolean(personel.id) && hasDonem;
  const isFinansLoading = canFetch && (isLoading || !fetchResolved);

  useEffect(() => {
    let isCancelled = false;

    if (!canFetch) {
      setFinansKayitlari([]);
      setIsLoading(false);
      setErrorMessage(null);
      setFetchResolved(false);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    setFetchResolved(false);

    fetchFinansKalemList({
      personel_id: personel.id,
      donem,
      state: "AKTIF",
      sube_id: getSubeIdForApiRequest(),
      limit: 100
    })
      .then((result) => {
        if (isCancelled) {
          return;
        }

        const aktifKayitlar = sortFinansKayitlari(
          result.items.filter((item) => item.personel_id === personel.id && isAktifFinansKaydi(item))
        );
        setFinansKayitlari(aktifKayitlar);
      })
      .catch((err) => {
        if (isCancelled) {
          return;
        }
        setFinansKayitlari([]);
        setErrorMessage(getApiErrorMessage(err, "Finans kayıtları yüklenemedi."));
      })
      .finally(() => {
        if (!isCancelled) {
          setIsLoading(false);
          setFetchResolved(true);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [canFetch, donem, personel.id]);

  return {
    finansKayitlari,
    isLoading: isFinansLoading,
    errorMessage,
    hasDonem,
    donem,
    canFetch,
    fetchResolved
  };
}
