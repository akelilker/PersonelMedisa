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
  const { hasAnyPermission, hasPermission, uiProfile } = useRoleAccess();

  const canKayitSection = hasPermission("personeller.create") || hasPermission("surecler.create");
  const canViewPersoneller = hasAnyPermission(PERSONELLER_LIST_ANY);
  const canViewBildirimler = hasPermission(ROUTE_PERMISSION.bildirimlerPage);
  const canOpenDailyStatus = uiProfile === "birim_amiri" && hasPermission("bildirimler.create");
  const canViewPuantaj = hasPermission(ROUTE_PERMISSION.puantajPage);
  const canViewRaporlar = hasPermission(ROUTE_PERMISSION.raporlarPage);
  const canViewFinans = hasPermission(ROUTE_PERMISSION.finansPage);

  const { pathname } = location;
  const isKayitSurecActive = pathname.startsWith("/personeller") || pathname.startsWith("/surecler");
  const isBildirimlerActive = pathname.startsWith("/bildirimler");
  const isPersonelActive = pathname.startsWith("/personeller");
  const isPuantajActive = pathname.startsWith("/puantaj");
  const isRaporlarActive = pathname.startsWith("/raporlar");
  const isFinansActive = pathname.startsWith("/finans");

  return (
    <nav id="main-menu" className="menu-container" aria-label="Ana modüller">
      {canKayitSection ? (
        <button
          type="button"
          className={`menu-btn${isKayitSurecActive ? " is-active" : ""}`}
          aria-current={isKayitSurecActive ? "page" : undefined}
          data-testid="menu-kayit-surec"
          onClick={() => {
            const tab: KayitTab = pathname.startsWith("/surecler") ? "surec" : "yeni-kayit";
            onKayitOpen(tab);
          }}
        >
          <div className="ttl">Kayıt ve Süreç</div>
        </button>
      ) : null}

      {canViewBildirimler ? (
        <button
          type="button"
          className={`menu-btn${isBildirimlerActive ? " is-active" : ""}`}
          aria-current={isBildirimlerActive ? "page" : undefined}
          data-testid="menu-gunluk-durum"
          onClick={() => {
            if (canOpenDailyStatus) {
              navigate("/bildirimler", { state: { openCreateModal: true } });
              return;
            }

            navigate("/bildirimler");
          }}
        >
          <div className="ttl">Günlük Kayıt</div>
        </button>
      ) : null}

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

      {canViewPuantaj ? (
        <button
          type="button"
          className={`menu-btn${isPuantajActive ? " is-active" : ""}`}
          aria-current={isPuantajActive ? "page" : undefined}
          data-testid="menu-puantaj"
          onClick={() => {
            navigate("/puantaj");
          }}
        >
          <div className="ttl">Puantaj</div>
        </button>
      ) : null}

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

      {canViewFinans ? (
        <button
          type="button"
          className={`menu-btn${isFinansActive ? " is-active" : ""}`}
          aria-current={isFinansActive ? "page" : undefined}
          data-testid="menu-finans"
          onClick={() => {
            navigate("/finans");
          }}
        >
          <div className="ttl">Finans</div>
        </button>
      ) : null}
    </nav>
  );
}
