import { Link, useNavigate } from "react-router-dom";
import type { Personel } from "../../../types/personel";
import { buildKayitSurecRouteState } from "../kayit-surec-navigation";

type KayitSurecPersonelHaftalikKapanisPanelProps = {
  personel: Personel;
};

export function KayitSurecPersonelHaftalikKapanisPanel({ personel }: KayitSurecPersonelHaftalikKapanisPanelProps) {
  const navigate = useNavigate();
  const personelQuery = encodeURIComponent(String(personel.id));
  const returnState = buildKayitSurecRouteState(personel.id, "haftalik-kapanis");

  return (
    <div className="surec-shell-panel" data-testid="kayit-surec-haftalik-kapanis-panel">
      <p className="workspace-empty-hint">
        <strong>Haftalık Kapanış</strong> — haftalık mutabakat, önkoşul kontrolleri ve kapanış yaşam döngüsü
        personel bağlamında yönetilir. Kapanmış hafta snapshot&apos;ları değiştirilemez.
      </p>

      <div className="workspace-inline-actions">
        <button
          type="button"
          className="universal-btn-primary"
          data-testid="kayit-surec-haftalik-kapanis-open"
          onClick={() =>
            navigate(`/haftalik-kapanis?personel_id=${personelQuery}`, {
              state: returnState
            })
          }
        >
          Haftalık Kapanış ekranını aç
        </button>
        <Link
          className="universal-btn-aux"
          data-testid="kayit-surec-revizyon-merkezi-open"
          to={`/haftalik-kapanis/revizyonlar?personel_id=${personelQuery}`}
          state={returnState}
        >
          Revizyon Merkezi
        </Link>
      </div>

      <p className="workspace-empty-hint">
        Revizyon Merkezi, kapanmış hafta düzeltmeleri için Haftalık Kapanış yaşam döngüsünün parçasıdır; bağımsız
        bir üst süreç değildir.
      </p>
    </div>
  );
}
