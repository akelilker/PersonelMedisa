import { useLocation, useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../hooks/use-role-access";

export type KayitTab = "yeni-kayit" | "surec";

type MainMenuProps = {
  onKayitOpen: (tab: KayitTab) => void;
};

export function MainMenu({ onKayitOpen }: MainMenuProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { hasPermission, uiProfile } = useRoleAccess();

  const canKayitSection = hasPermission("personeller.create") || hasPermission("surecler.create");
  const canViewPersoneller = hasPermission("personeller.view") || hasPermission("personeller.view.sube");
  const canOpenDailyStatus = uiProfile === "birim_amiri" && hasPermission("bildirimler.create");
  const canViewRaporlar = hasPermission("raporlar.view");

  const { pathname } = location;
  const isKayitSurecActive = pathname.startsWith("/personeller") || pathname.startsWith("/surecler");
  const isBildirimlerActive = pathname.startsWith("/bildirimler");
  const isPersonelActive = pathname.startsWith("/personeller");
  const isRaporlarActive = pathname.startsWith("/raporlar");

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

      {canOpenDailyStatus ? (
        <button
          type="button"
          className={`menu-btn${isBildirimlerActive ? " is-active" : ""}`}
          aria-current={isBildirimlerActive ? "page" : undefined}
          data-testid="menu-gunluk-durum"
          onClick={() => {
            navigate("/bildirimler", { state: { openCreateModal: true } });
          }}
        >
          <div className="ttl">Günlük Durum Bildir</div>
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
