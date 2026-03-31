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
  const canViewBildirimler = hasPermission("bildirimler.view");
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewHaftalikKapanis = hasPermission("haftalik-kapanis.view");
  const canViewRaporlar = hasPermission("raporlar.view");
  const canViewFinans = hasPermission("finans.view");

  const operasyonTarget = canViewBildirimler
    ? "/bildirimler"
    : canViewPuantaj
      ? "/puantaj"
      : canViewHaftalikKapanis
        ? "/haftalik-kapanis"
        : null;
  const raporTarget = canViewRaporlar ? "/raporlar" : canViewFinans ? "/finans" : null;

  const isKayitSectionActive =
    location.pathname.startsWith("/personeller") || location.pathname.startsWith("/surecler");
  const isOperasyonSectionActive =
    location.pathname.startsWith("/bildirimler") ||
    location.pathname.startsWith("/puantaj") ||
    location.pathname.startsWith("/haftalik-kapanis");
  const isRaporSectionActive =
    location.pathname.startsWith("/raporlar") || location.pathname.startsWith("/finans");

  return (
    <div id="main-menu" className="menu-container">
      {canKayitSection ? (
        <button
          type="button"
          className={`menu-btn${isKayitSectionActive ? " is-active" : ""}`}
          onClick={() => {
            const tab: KayitTab =
              isKayitSectionActive && location.pathname.startsWith("/surecler") ? "surec" : "yeni-kayit";
            onKayitOpen(tab);
          }}
        >
          <div className="ttl">KAYIT ISLEMLERI</div>
        </button>
      ) : null}

      <button
        type="button"
        className={`menu-btn${isOperasyonSectionActive ? " is-active" : ""}`}
        onClick={() => {
          if (operasyonTarget) {
            navigate(operasyonTarget);
          }
        }}
        disabled={!operasyonTarget}
      >
        <div className="ttl">OPERASYON</div>
      </button>

      <button
        type="button"
        className={`menu-btn${isRaporSectionActive ? " is-active" : ""}`}
        onClick={() => {
          if (raporTarget) {
            navigate(raporTarget);
          }
        }}
        disabled={!raporTarget}
      >
        <div className="ttl">RAPOR VE FINANS</div>
      </button>
    </div>
  );
}
