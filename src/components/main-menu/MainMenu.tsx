import { useLocation, useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../hooks/use-role-access";
import {
  PERSONELLER_LIST_ANY,
  ROUTE_PERMISSION
} from "../../lib/authorization/role-permissions";

export type KayitTab = "yeni-kayit" | "surec";

const KAYIT_DISABLED_MESSAGE = "Kayıt ve Süreç işlemleri için yetkiniz bulunmuyor.";
const PERSONEL_DISABLED_MESSAGE = "Personel Kartı modülü için yetkiniz bulunmuyor.";
const RAPORLAR_DISABLED_MESSAGE = "Raporlar modülü için yetkiniz bulunmuyor.";

const KAYIT_DISABLED_DESCRIPTION_ID = "menu-kayit-surec-disabled-description";
const PERSONEL_DISABLED_DESCRIPTION_ID = "menu-personel-karti-disabled-description";
const RAPORLAR_DISABLED_DESCRIPTION_ID = "menu-raporlar-disabled-description";

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

  const kayitDisabled = !canKayitSection;
  const personelDisabled = !canViewPersoneller;
  const raporlarDisabled = !canViewRaporlar;

  return (
    <nav id="main-menu" className="menu-container" aria-label="Ana omurga modulleri">
      <button
        type="button"
        className={`menu-btn${isKayitSurecActive ? " is-active" : ""}`}
        aria-current={isKayitSurecActive ? "page" : undefined}
        data-testid="menu-kayit-surec"
        title={kayitDisabled ? KAYIT_DISABLED_MESSAGE : undefined}
        aria-describedby={kayitDisabled ? KAYIT_DISABLED_DESCRIPTION_ID : undefined}
        onClick={() => {
          const tab: KayitTab = pathname.startsWith("/surecler") ? "surec" : "yeni-kayit";
          onKayitOpen(tab);
        }}
        disabled={kayitDisabled}
      >
        <div className="ttl">Kayıt ve Süreç</div>
      </button>
      {kayitDisabled ? (
        <span id={KAYIT_DISABLED_DESCRIPTION_ID} className="visually-hidden">
          {KAYIT_DISABLED_MESSAGE}
        </span>
      ) : null}

      <button
        type="button"
        className={`menu-btn${isPersonelActive ? " is-active" : ""}`}
        aria-current={isPersonelActive ? "page" : undefined}
        data-testid="menu-personel-karti"
        title={personelDisabled ? PERSONEL_DISABLED_MESSAGE : undefined}
        aria-describedby={personelDisabled ? PERSONEL_DISABLED_DESCRIPTION_ID : undefined}
        onClick={() => {
          navigate("/personeller");
        }}
        disabled={personelDisabled}
      >
        <div className="ttl">Personel Kartı</div>
      </button>
      {personelDisabled ? (
        <span id={PERSONEL_DISABLED_DESCRIPTION_ID} className="visually-hidden">
          {PERSONEL_DISABLED_MESSAGE}
        </span>
      ) : null}

      <button
        type="button"
        className={`menu-btn${isRaporlarActive ? " is-active" : ""}`}
        aria-current={isRaporlarActive ? "page" : undefined}
        data-testid="menu-raporlar"
        title={raporlarDisabled ? RAPORLAR_DISABLED_MESSAGE : undefined}
        aria-describedby={raporlarDisabled ? RAPORLAR_DISABLED_DESCRIPTION_ID : undefined}
        onClick={() => {
          navigate("/raporlar");
        }}
        disabled={raporlarDisabled}
      >
        <div className="ttl">Raporlar</div>
      </button>
      {raporlarDisabled ? (
        <span id={RAPORLAR_DISABLED_DESCRIPTION_ID} className="visually-hidden">
          {RAPORLAR_DISABLED_MESSAGE}
        </span>
      ) : null}
    </nav>
  );
}
