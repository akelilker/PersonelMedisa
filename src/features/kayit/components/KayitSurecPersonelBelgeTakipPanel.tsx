import { useNavigate } from "react-router-dom";
import type { Personel } from "../../../types/personel";
import { buildKayitSurecRouteState } from "../kayit-surec-navigation";

type KayitSurecPersonelBelgeTakipPanelProps = {
  personel: Personel;
};

export function KayitSurecPersonelBelgeTakipPanel({ personel }: KayitSurecPersonelBelgeTakipPanelProps) {
  const navigate = useNavigate();
  const personelQuery = encodeURIComponent(String(personel.id));

  return (
    <div className="surec-shell-panel" data-testid="kayit-surec-belge-takip-panel">
      <p className="workspace-empty-hint">
        <strong>Belge Takip</strong> — eksik, süresi dolan veya aksiyon gerektiren belgelerin izleme görünümü.
        Belge CRUD işlemleri <strong>Belgeler</strong> sürecindedir.
      </p>

      <div className="workspace-inline-actions">
        <button
          type="button"
          className="universal-btn-primary"
          data-testid="kayit-surec-belge-takip-open"
          onClick={() =>
            navigate(`/personeller/belge-takip?personel_id=${personelQuery}`, {
              state: buildKayitSurecRouteState(personel.id, "belge-takip")
            })
          }
        >
          Belge Takip ekranını aç
        </button>
      </div>
    </div>
  );
}
