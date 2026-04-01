import { useLocation, useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../hooks/use-role-access";

export type KayitTab = "yeni-kayit" | "surec";

type MainMenuProps = {
  onKayitOpen: (tab: KayitTab) => void;
};

export function MainMenu({ onKayitOpen }: MainMenuProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { hasPermission } = useRoleAccess();

  const canKayitSection = hasPermission("personeller.create") || hasPermission("surecler.create");
  const canViewPersoneller = hasPermission("personeller.view") || hasPermission("personeller.view.sube");
  const canViewRaporlar = hasPermission("raporlar.view");

  return (
    <div id="main-menu" className="menu-container">
      {canKayitSection ? (
        <button
          type="button"
          className="menu-btn"
          data-testid="menu-giris-surec"
          onClick={() => {
            const tab: KayitTab = location.pathname.startsWith("/surecler") ? "surec" : "yeni-kayit";
            onKayitOpen(tab);
          }}
        >
          <div className="ttl">Personel Giris ve Surec Takibi</div>
        </button>
      ) : null}

      <button
        type="button"
        className="menu-btn"
        data-testid="menu-personel-karti"
        onClick={() => {
          navigate("/personeller");
        }}
        disabled={!canViewPersoneller}
      >
        <div className="ttl">Personel Karti</div>
      </button>

      <button
        type="button"
        className="menu-btn"
        data-testid="menu-raporlar"
        onClick={() => {
          navigate("/raporlar");
        }}
        disabled={!canViewRaporlar}
      >
        <div className="ttl">Raporlar</div>
      </button>
    </div>
  );
}
