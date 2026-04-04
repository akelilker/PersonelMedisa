import { useMemo } from "react";
import { useLocation, useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../hooks/use-role-access";

export type MainMenuVariant = "dashboard" | "compact";

type MainMenuProps = {
  variant?: MainMenuVariant;
};

type MenuItem = {
  key: string;
  label: string;
  subtitle: string;
  testId: string;
  isActive: boolean;
  onClick: () => void;
};

export function MainMenu({ variant = "dashboard" }: MainMenuProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { hasPermission, uiProfile } = useRoleAccess();

  const canCreatePersonel = hasPermission("personeller.create");
  const canViewPersoneller = hasPermission("personeller.view") || hasPermission("personeller.view.sube");
  const canViewSurecler = hasPermission("surecler.view") || hasPermission("surecler.view.sube");
  const canViewBildirimler = hasPermission("bildirimler.view");
  const canOpenDailyStatus = uiProfile === "birim_amiri" && hasPermission("bildirimler.create");
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewHaftalikKapanis = hasPermission("haftalik-kapanis.view");
  const canViewRaporlar = hasPermission("raporlar.view");
  const canViewFinans = hasPermission("finans.view");

  const { pathname } = location;

  const items = useMemo<MenuItem[]>(() => {
    const nextItems: MenuItem[] = [];

    if (canCreatePersonel) {
      nextItems.push({
        key: "yeni-personel",
        label: "Yeni Personel",
        subtitle: "Kaydi dogrudan personel ekraninda ac",
        testId: "menu-yeni-personel",
        isActive: pathname.startsWith("/personeller"),
        onClick: () => {
          navigate("/personeller", { state: { openCreateModal: true } });
        }
      });
    }

    if (canViewSurecler) {
      nextItems.push({
        key: "surec-takibi",
        label: "Surec Takibi",
        subtitle: "Izin, rapor ve hareket akisini yonet",
        testId: "menu-surec-takibi",
        isActive: pathname.startsWith("/surecler"),
        onClick: () => {
          navigate("/surecler");
        }
      });
    }

    if (canOpenDailyStatus) {
      nextItems.push({
        key: "gunluk-durum",
        label: "Gunluk Durum Bildir",
        subtitle: "Birim icin hizli bildirim akisini ac",
        testId: "menu-gunluk-durum",
        isActive: pathname.startsWith("/bildirimler"),
        onClick: () => {
          navigate("/bildirimler", { state: { openCreateModal: true } });
        }
      });
    }

    if (canViewPersoneller) {
      nextItems.push({
        key: "personel-karti",
        label: "Personel Karti",
        subtitle: "Liste, detay ve ozet gorunumleri ac",
        testId: "menu-personel-karti",
        isActive: pathname.startsWith("/personeller"),
        onClick: () => {
          navigate("/personeller");
        }
      });
    }

    if (canViewBildirimler) {
      nextItems.push({
        key: "bildirimler",
        label: "Bildirimler",
        subtitle: "Gunluk durum ve yonetim kayitlarini izle",
        testId: "menu-bildirimler",
        isActive: pathname.startsWith("/bildirimler"),
        onClick: () => {
          navigate("/bildirimler");
        }
      });
    }

    if (canViewPuantaj) {
      nextItems.push({
        key: "puantaj",
        label: "Gunluk Puantaj",
        subtitle: "Giris, cikis ve uyari kayitlarini kontrol et",
        testId: "menu-puantaj",
        isActive: pathname.startsWith("/puantaj"),
        onClick: () => {
          navigate("/puantaj");
        }
      });
    }

    if (canViewHaftalikKapanis) {
      nextItems.push({
        key: "haftalik-kapanis",
        label: "Haftalik Kapanis",
        subtitle: "Hafta muhru ve sonuc ozetini al",
        testId: "menu-haftalik-kapanis",
        isActive: pathname.startsWith("/haftalik-kapanis"),
        onClick: () => {
          navigate("/haftalik-kapanis");
        }
      });
    }

    if (canViewRaporlar) {
      nextItems.push({
        key: "raporlar",
        label: "Raporlar",
        subtitle: "Backend raporu ve onbellek ozetini calistir",
        testId: "menu-raporlar",
        isActive: pathname.startsWith("/raporlar"),
        onClick: () => {
          navigate("/raporlar");
        }
      });
    }

    if (canViewFinans) {
      nextItems.push({
        key: "finans",
        label: "Finans",
        subtitle: "Ek odeme ve kesinti kalemlerini yonet",
        testId: "menu-finans",
        isActive: pathname.startsWith("/finans"),
        onClick: () => {
          navigate("/finans");
        }
      });
    }

    return nextItems;
  }, [
    canCreatePersonel,
    canOpenDailyStatus,
    canViewBildirimler,
    canViewFinans,
    canViewHaftalikKapanis,
    canViewPersoneller,
    canViewPuantaj,
    canViewRaporlar,
    canViewSurecler,
    navigate,
    pathname
  ]);

  if (items.length === 0) {
    return null;
  }

  return (
    <nav id="main-menu" className={`menu-container menu-container--${variant}`} aria-label="Ana moduller">
      {items.map((item) => (
        <button
          key={item.key}
          type="button"
          className={`menu-btn menu-btn--${variant}${item.isActive ? " is-active" : ""}`}
          aria-current={item.isActive ? "page" : undefined}
          data-testid={item.testId}
          onClick={item.onClick}
        >
          <div className="menu-btn-content">
            <div className="ttl">{item.label}</div>
            <div className="menu-btn-subtitle">{item.subtitle}</div>
          </div>
        </button>
      ))}
    </nav>
  );
}
