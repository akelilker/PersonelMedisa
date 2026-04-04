import { useMemo } from "react";
import { Link } from "react-router-dom";
import { dataCacheKeys, getCacheEntry, useAppDataRevision } from "../data/data-manager";
import { useRoleAccess } from "../hooks/use-role-access";
import {
  formatBildirimTuruLabel,
  formatUiProfileLabel,
  formatUserRoleLabel
} from "../lib/display/enum-display";
import { useAuth } from "../state/auth.store";
import type { PaginatedResult } from "../types/api";
import type { Bildirim } from "../types/bildirim";
import type { Personel } from "../types/personel";

type DashboardMetric = {
  label: string;
  value: string;
  hint: string;
};

type DashboardAction = {
  to: string;
  label: string;
};

export function HomeDashboard() {
  const revision = useAppDataRevision();
  const { session } = useAuth();
  const { hasPermission } = useRoleAccess();

  const activeSubeId = session?.active_sube_id ?? null;
  const activeSubeLabel = useMemo(() => {
    if (!session) {
      return "-";
    }

    if (activeSubeId === null) {
      return "Tum subeler";
    }

    return session.sube_list?.find((sube) => sube.id === activeSubeId)?.ad ?? `Sube ${activeSubeId}`;
  }, [activeSubeId, session]);

  const personelList = useMemo(
    () =>
      getCacheEntry<PaginatedResult<Personel>>(
        dataCacheKeys.personellerList(activeSubeId, "", "tum", "", "", 1)
      ),
    [activeSubeId, revision]
  );
  const headerBildirimler = useMemo(
    () => getCacheEntry<PaginatedResult<Bildirim>>(dataCacheKeys.bildirimlerHeader(activeSubeId)),
    [activeSubeId, revision]
  );

  const totalPersonel = personelList?.pagination.total ?? null;
  const unreadBildirimCount =
    headerBildirimler?.items.filter((item) => item.state !== "IPTAL" && item.okundu_mi !== true).length ?? 0;

  const metrics = useMemo<DashboardMetric[]>(
    () => [
      {
        label: "Toplam personel",
        value: totalPersonel === null ? "-" : String(totalPersonel),
        hint: totalPersonel === null ? "Veri ilk esitlemeyi bekliyor" : "Kayitli kadro gorunumu"
      },
      {
        label: "Okunmamis bildirim",
        value: String(unreadBildirimCount),
        hint: unreadBildirimCount > 0 ? "Bugun aksiyon bekleyen kayit var" : "Header paneli temiz"
      },
      {
        label: "Aktif kapsam",
        value: activeSubeLabel,
        hint: activeSubeId === null ? "Yonetim kapsami acik" : "Secili sube filtresi uygulaniyor"
      }
    ],
    [activeSubeId, activeSubeLabel, totalPersonel, unreadBildirimCount]
  );

  const focusItems = useMemo(() => {
    const items: string[] = [];

    if (hasPermission("personeller.create")) {
      items.push("Yeni personel kaydini acip cekirdek bilgileri eksiksiz topla.");
    }
    if (hasPermission("surecler.view") || hasPermission("surecler.view.sube")) {
      items.push("Surec akisini acip izin, rapor ve gorevlendirme hareketlerini kontrol et.");
    }
    if (hasPermission("bildirimler.view")) {
      items.push("Bildirim panelindeki okunmamis kayitlari gozden gecir.");
    }
    if (hasPermission("puantaj.view")) {
      items.push("Gunluk puantaj sorgularinda kritik uyari ve eksik saatleri teyit et.");
    }
    if (hasPermission("haftalik-kapanis.view")) {
      items.push("Hafta kapanisi oncesi son puantaj ve surec etkilerini tamamla.");
    }
    if (hasPermission("finans.view")) {
      items.push("Ek odeme ve kesinti satirlarini raporlarla capraz kontrol et.");
    }

    return items.slice(0, 4);
  }, [hasPermission]);

  const spotlightActions = useMemo<DashboardAction[]>(() => {
    const actions: DashboardAction[] = [];

    if (hasPermission("bildirimler.view")) {
      actions.push({ to: "/bildirimler", label: "Bildirimleri ac" });
    }
    if (hasPermission("puantaj.view")) {
      actions.push({ to: "/puantaj", label: "Puantaj kontrol et" });
    }
    if (hasPermission("raporlar.view")) {
      actions.push({ to: "/raporlar", label: "Rapor calistir" });
    }
    if (hasPermission("finans.view")) {
      actions.push({ to: "/finans", label: "Finans kalemlerini incele" });
    }

    return actions.slice(0, 3);
  }, [hasPermission]);

  return (
    <section className="home-dashboard" aria-labelledby="home-dashboard-title">
      <article className="dashboard-hero-card">
        <div className="dashboard-hero-copy">
          <p className="dashboard-eyebrow">Ana panel</p>
          <h2 id="home-dashboard-title">Bugunun is akislarini tek yerden topla.</h2>
          <p className="dashboard-hero-text">
            {session?.user.ad_soyad ?? "Kullanici"} olarak {formatUserRoleLabel(session?.user.rol)} ve{" "}
            {formatUiProfileLabel(session?.ui_profile)} ile oturumdasin. Moduller artik ana sayfa disinda da
            gorunur; bu panel ise gunluk operasyona hizli giris verir.
          </p>
        </div>

        <div className="dashboard-spotlight">
          <span className="dashboard-spotlight-label">Aktif odak</span>
          <strong>{activeSubeLabel}</strong>
          <p>
            Footer, header ve moduller ayni kabukta kalir; ara ekranlarda gizli navigasyon ya da sag-sol
            sarkan katman birakilmaz.
          </p>
          <div className="dashboard-spotlight-actions">
            {spotlightActions.map((action) => (
              <Link key={action.to} to={action.to} className="universal-btn-aux">
                {action.label}
              </Link>
            ))}
          </div>
        </div>
      </article>

      <div className="dashboard-metric-grid">
        {metrics.map((metric) => (
          <article key={metric.label} className="dashboard-metric-card">
            <span className="dashboard-metric-label">{metric.label}</span>
            <strong className="dashboard-metric-value">{metric.value}</strong>
            <p className="dashboard-metric-hint">{metric.hint}</p>
          </article>
        ))}
      </div>

      <div className="dashboard-panel-grid">
        <article className="dashboard-panel">
          <h3>Bugun takip et</h3>
          {focusItems.length > 0 ? (
            <ul className="dashboard-list">
              {focusItems.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          ) : (
            <p className="dashboard-panel-empty">Yetkiye bagli gorev listesi hazirlaniyor.</p>
          )}
        </article>

        <article className="dashboard-panel">
          <h3>Header ozeti</h3>
          {headerBildirimler && headerBildirimler.items.length > 0 ? (
            <ul className="dashboard-activity-list">
              {headerBildirimler.items.slice(0, 3).map((item) => (
                <li key={item.id}>
                  <strong>{formatBildirimTuruLabel(item.bildirim_turu)}</strong>
                  <span>{item.tarih ?? "Tarih yok"}</span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="dashboard-panel-empty">Header bildirim panelinde su an bekleyen kayit gorunmuyor.</p>
          )}
        </article>
      </div>
    </section>
  );
}
