import { formatFinansKalemTuruLabel } from "../../../../lib/display/enum-display";
import type { FinansKalem } from "../../../../types/finans";
import { formatDetailValue } from "./personel-dosya-format-utils";
import {
  FINANS_ADAY_DONEM_YOK_MESAJI,
  FINANS_ADAY_KAYIT_YOK_MESAJI,
  FINANS_ADAY_YETKI_YOK_MESAJI,
  formatFinansKayitAdayRolu,
  formatFinansKayitTutar
} from "./personel-finans-adaylari-utils";

export function PersonelFinansAdaylariSection({
  finansKayitlari,
  isLoading,
  errorMessage,
  canViewFinans,
  hasDonem
}: {
  finansKayitlari: FinansKalem[];
  isLoading: boolean;
  errorMessage: string | null;
  canViewFinans: boolean;
  hasDonem: boolean;
}) {
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
