import { useEffect, useState } from "react";
import { getApiErrorMessage } from "../../../../api/api-client";
import { fetchFinansKalemList } from "../../../../api/finans.api";
import { formatFinansKalemTuruLabel } from "../../../../lib/display/enum-display";
import type { FinansKalem } from "../../../../types/finans";
import type { Personel } from "../../../../types/personel";
import { formatDetailValue } from "./personel-dosya-format-utils";
import {
  FINANS_ADAY_DONEM_YOK_MESAJI,
  FINANS_ADAY_KAYIT_YOK_MESAJI,
  FINANS_ADAY_YETKI_YOK_MESAJI,
  formatFinansKayitAdayRolu,
  formatFinansKayitTutar,
  isAktifFinansKaydi,
  sortFinansKayitlari
} from "./personel-finans-adaylari-utils";

export function PersonelFinansAdaylariSection({
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
  const donem = typeof personel.sgk_donem === "string" ? personel.sgk_donem.trim() : "";
  const hasDonem = donem.length > 0;

  useEffect(() => {
    let isCancelled = false;

    if (!isActive || !canViewFinans || !personel.id || !hasDonem) {
      setFinansKayitlari([]);
      setIsLoading(false);
      setErrorMessage(null);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    fetchFinansKalemList({
      personel_id: personel.id,
      donem,
      state: "AKTIF",
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
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [canViewFinans, donem, hasDonem, isActive, personel.id]);

  return (
    <section
      className="personel-puantaj-summary-card personel-devam-primi-card"
      data-testid="personel-finans-adaylari-card"
    >
      <span className="personel-puantaj-summary-kicker">Bu Dönem Finans Kayıtları</span>
      <p className="personel-puantaj-summary-note">
        Bu kayıtlar bordroda dikkate alınacak finans kalemleridir; bordro kesinliği taşımaz.
      </p>

      {!canViewFinans ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-finans-adaylari-yetki-yok">
          {FINANS_ADAY_YETKI_YOK_MESAJI}
        </p>
      ) : null}

      {canViewFinans && !hasDonem ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-finans-adaylari-donem-yok">
          {FINANS_ADAY_DONEM_YOK_MESAJI}
        </p>
      ) : null}

      {canViewFinans && hasDonem && isLoading ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-finans-adaylari-yukleniyor">
          Finans kayıtları yükleniyor...
        </p>
      ) : null}

      {canViewFinans && hasDonem && !isLoading && errorMessage ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-finans-adaylari-hata">
          {errorMessage}
        </p>
      ) : null}

      {canViewFinans && hasDonem && !isLoading && !errorMessage && finansKayitlari.length === 0 ? (
        <p className="personel-puantaj-summary-note" data-testid="personel-finans-adaylari-bos">
          {FINANS_ADAY_KAYIT_YOK_MESAJI}
        </p>
      ) : null}

      {canViewFinans && hasDonem && !isLoading && !errorMessage && finansKayitlari.length > 0 ? (
        <ul className="personel-surec-list personel-izin-list" data-testid="personel-finans-adaylari-list">
          {finansKayitlari.map((item) => (
            <li key={item.id} className="personel-surec-card" data-testid={`personel-finans-kayit-${item.id}`}>
              <span className="personel-surec-card-type">
                {formatFinansKalemTuruLabel(item.kalem_turu)} — {formatFinansKayitTutar(item.tutar)}
              </span>
              <span className="personel-surec-card-state">{formatFinansKayitAdayRolu(item.kalem_turu)}</span>
              {typeof item.gun_sayisi === "number" && item.gun_sayisi > 0 ? (
                <span className="personel-surec-card-dates">{item.gun_sayisi} gün</span>
              ) : null}
              {item.aciklama ? (
                <span className="personel-surec-card-desc">{formatDetailValue(item.aciklama)}</span>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}
