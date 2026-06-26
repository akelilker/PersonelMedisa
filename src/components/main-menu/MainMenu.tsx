import { useLocation, useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../hooks/use-role-access";
import {
  PERSONELLER_LIST_ANY,
  ROUTE_PERMISSION
} from "../../lib/authorization/role-permissions";

export type KayitTab = "yeni-kayit" | "surec";

type MainMenuProps = {
  onKayitOpen: (tab: KayitTab) => void;
};

export function MainMenu({ onKayitOpen }: MainMenuProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { hasAnyPermission, hasPermission } = useRoleAccess();

  const canKayitSection = hasPermission("personeller.create") || hasPermission("surecler.create");
  const canViewPersoneller = hasAnyPermission(PERSONELLER_LIST_ANY);
  const canViewRaporlar = hasPermission(ROUTE_PERMISSION.raporlarPage);

  const { pathname } = location;
  const isKayitSurecActive = pathname.startsWith("/surecler");
  const isPersonelActive = pathname.startsWith("/personeller");
  const isRaporlarActive = pathname.startsWith("/raporlar");

  return (
    <nav id="main-menu" className="menu-container" aria-label="Ana omurga modulleri">
      <button
        type="button"
        className={`menu-btn${isKayitSurecActive ? " is-active" : ""}`}
        aria-current={isKayitSurecActive ? "page" : undefined}
        data-testid="menu-kayit-surec"
        onClick={() => {
          const tab: KayitTab = pathname.startsWith("/surecler") ? "surec" : "yeni-kayit";
          onKayitOpen(tab);
        }}
        disabled={!canKayitSection}
      >
        <div className="ttl">Kayıt ve Süreç</div>
      </button>

      <button
        type="button"
        className={`menu-btn${isPersonelActive ? " is-active" : ""}`}
        aria-current={isPersonelActive ? "page" : undefined}
        data-testid="menu-personel-karti"
        onClick={() => {
          navigate("/personeller");
        }}
        disabled={!canViewPersoneller}
      >
        <div className="ttl">Personel Kartı</div>
      </button>

      <button
        type="button"
        className={`menu-btn${isRaporlarActive ? " is-active" : ""}`}
        aria-current={isRaporlarActive ? "page" : undefined}
        data-testid="menu-raporlar"
        onClick={() => {
          navigate("/raporlar");
        }}
        disabled={!canViewRaporlar}
      >
        <div className="ttl">Raporlar</div>
      </button>
    </nav>
  );
}
