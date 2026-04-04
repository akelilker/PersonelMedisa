import { Link } from "react-router-dom";
import { dataCacheKeys, getCacheEntry, useAppDataRevision } from "../data/data-manager";
import { useRoleAccess } from "../hooks/use-role-access";
import { formatBildirimTuruLabel, formatUserRoleLabel } from "../lib/display/enum-display";
import { useAuth } from "../state/auth.store";
import type { PaginatedResult } from "../types/api";
import type { Bildirim } from "../types/bildirim";
import type { Personel } from "../types/personel";

type HomeAction = {
  title: string;
  description: string;
  to: string;
};

function formatSubeLabel(subeIds: number[], subeList: Array<{ id: number; ad: string }> | undefined) {
  if (subeIds.length === 0) {
    return "Tum subelerde gorunum aktif";
  }

  if (subeIds.length === 1) {
    const subeId = subeIds[0];
    const label = subeList?.find((sube) => sube.id === subeId)?.ad ?? `Sube ${subeId}`;
    return `${label} odakli calisma`;
  }

  return `${subeIds.length} sube yetkisi aktif`;
}

export function HomeDashboard() {
  const revision = useAppDataRevision();
  const { session } = useAuth();
  const { hasPermission } = useRoleAccess();

  const activeSubeId = session?.active_sube_id ?? null;
  const personelList =
    getCacheEntry<PaginatedResult<Personel>>(dataCacheKeys.personellerList(activeSubeId, "", "tum", "", "", 1)) ??
    null;
  const bildirimList =
    getCacheEntry<PaginatedResult<Bildirim>>(dataCacheKeys.bildirimlerHeader(activeSubeId)) ?? null;

  const personelCount = personelList?.pagination.total ?? personelList?.items.length ?? 0;
  const aktifPersonelCount = (personelList?.items ?? []).filter((item) => item.aktif_durum === "AKTIF").length;
  const bekleyenBildirimCount = (bildirimList?.items ?? []).filter(
    (item) => item.state !== "IPTAL" && item.okundu_mi !== true
  ).length;
  const latestBildirim = bildirimList?.items?.[0] ?? null;

  const actions: HomeAction[] = [
    {
      title: "Personel Kartlari",
      description: "Listeyi ac, kartlari kontrol et ve detaylara ilerle.",
      to: "/personeller"
    }
  ];

  if (hasPermission("surecler.view") || hasPermission("surecler.view.sube")) {
    actions.push({
      title: "Surec Takibi",
      description: "Izin, rapor ve durum akislarini yonet.",
      to: "/surecler"
    });
  }

  if (hasPermission("bildirimler.view")) {
    actions.push({
      title: "Bildirimler",
      description: "Gelen son hareketleri ve aksiyon bekleyen kayitlari gor.",
      to: "/bildirimler"
    });
  }

  if (hasPermission("raporlar.view")) {
    actions.push({
      title: "Raporlar",
      description: "Toplu tablo ve ozetleri hizlica al.",
      to: "/raporlar"
    });
  }

  void revision;

  return (
    <section className="home-dashboard" aria-label="Ana ekran">
      <div className="home-dashboard-hero">
        <div className="home-dashboard-copy">
          <p className="home-dashboard-eyebrow">{formatUserRoleLabel(session?.user.rol)}</p>
          <h2>Bugunku operasyon merkezi</h2>
          <p className="home-dashboard-description">
            {formatSubeLabel(session?.user.sube_ids ?? [], session?.sube_list)}
          </p>
        </div>
        <div className="home-dashboard-stat-grid">
          <article className="home-stat-card">
            <span className="home-stat-label">Toplam personel</span>
            <strong>{personelCount}</strong>
            <p>Bu gorunum icin onbellekte hazir kayitlar.</p>
          </article>
          <article className="home-stat-card">
            <span className="home-stat-label">Aktif gorunen</span>
            <strong>{aktifPersonelCount}</strong>
            <p>Ilk yuklenen listede aktif durumda olan personeller.</p>
          </article>
          <article className="home-stat-card">
            <span className="home-stat-label">Bekleyen bildirim</span>
            <strong>{bekleyenBildirimCount}</strong>
            <p>Okunmamis veya aksiyon bekleyen son kayitlar.</p>
          </article>
        </div>
      </div>

      <div className="home-dashboard-grid">
        <article className="home-panel">
          <div className="home-panel-head">
            <h3>Hizli yonlendirmeler</h3>
            <span>{actions.length} modül</span>
          </div>
          <div className="home-action-grid">
            {actions.map((action) => (
              <Link key={action.to} to={action.to} className="home-action-card">
                <strong>{action.title}</strong>
                <p>{action.description}</p>
              </Link>
            ))}
          </div>
        </article>

        <article className="home-panel">
          <div className="home-panel-head">
            <h3>Son durum</h3>
            <span>Canli ozet</span>
          </div>
          {latestBildirim ? (
            <div className="home-feed-card">
              <strong>{formatBildirimTuruLabel(latestBildirim.bildirim_turu)}</strong>
              <p>
                {latestBildirim.tarih ? `Tarih: ${latestBildirim.tarih}` : "Tarih bilgisi yok"}
              </p>
              <p>
                {latestBildirim.okundu_mi ? "Bildirim okundu." : "Bildirim inceleme bekliyor."}
              </p>
              {hasPermission("bildirimler.view") ? (
                <Link to="/bildirimler" className="home-inline-link">
                  Tum bildirimleri ac
                </Link>
              ) : null}
            </div>
          ) : (
            <div className="home-feed-card is-empty">
              <strong>Bildirim akisi sakin</strong>
              <p>Su an cache'te gosterilecek yeni bir bildirim yok.</p>
            </div>
          )}
        </article>
      </div>
    </section>
  );
}
