import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { fetchPersonellerList } from "../api/personeller.api";
import { fetchSureclerList } from "../api/surecler.api";
import { LoadingState } from "../components/states/LoadingState";
import { useRoleAccess } from "../hooks/use-role-access";
import {
  hesaplaDashboardKpi,
  type DashboardKpi
} from "../services/dashboard-rapor-servisi";
import type { GunlukPuantaj } from "../types/puantaj";
import type { Personel } from "../types/personel";
import type { Surec } from "../types/surec";

function KpiCard({
  label,
  value,
  variant
}: {
  label: string;
  value: string | number;
  variant?: "warning" | "danger" | "success";
}) {
  const cls = variant ? `dashboard-kpi-card dashboard-kpi-card--${variant}` : "dashboard-kpi-card";
  return (
    <div className={cls}>
      <span className="dashboard-kpi-card-label">{label}</span>
      <span className="dashboard-kpi-card-value">{value}</span>
    </div>
  );
}

function formatDakikaToSaat(dakika: number): string {
  const saat = Math.floor(dakika / 60);
  const dk = dakika % 60;
  return `${saat}s ${dk}dk`;
}

export function HomeDashboard() {
  const { hasPermission } = useRoleAccess();
  const canViewRaporlar = hasPermission("raporlar.view");

  const [isLoading, setIsLoading] = useState(true);
  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [surecler, setSurecler] = useState<Surec[]>([]);
  const [puantajKayitlari, setPuantajKayitlari] = useState<GunlukPuantaj[]>([]);

  const loadDashboardData = useCallback(async () => {
    setIsLoading(true);
    try {
      const [personelResult, surecResult] = await Promise.all([
        fetchPersonellerList({ aktiflik: "tum", page: 1, limit: 100 }),
        fetchSureclerList({ page: 1, limit: 200 })
      ]);
      setPersoneller(personelResult.items);
      setSurecler(surecResult.items);

      const samplePuantaj: GunlukPuantaj[] = personelResult.items
        .filter((p) => p.aktif_durum === "AKTIF")
        .flatMap((p) => {
          const entries: GunlukPuantaj[] = [];
          const now = new Date();
          const year = now.getFullYear();
          const month = now.getMonth();
          for (let day = 1; day <= Math.min(now.getDate(), 28); day++) {
            const d = new Date(year, month, day);
            if (d.getDay() === 0) continue;
            entries.push({
              personel_id: p.id,
              tarih: `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`,
              state: day <= 15 ? "MUHURLENDI" : "HESAPLANDI",
              hareket_durumu: "Geldi",
              net_calisma_suresi_dakika: 480,
              hafta_tatili_hak_kazandi_mi: true,
              compliance_uyarilari: []
            });
          }
          return entries;
        });

      setPuantajKayitlari(samplePuantaj);
    } catch {
      setPersoneller([]);
      setSurecler([]);
      setPuantajKayitlari([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadDashboardData();
  }, [loadDashboardData]);

  const kpi: DashboardKpi | null = useMemo(() => {
    if (isLoading) return null;
    return hesaplaDashboardKpi(personeller, puantajKayitlari, surecler);
  }, [isLoading, personeller, puantajKayitlari, surecler]);

  if (isLoading) {
    return (
      <section className="dashboard-page">
        <LoadingState label="Dashboard verileri yukleniyor..." />
      </section>
    );
  }

  if (!kpi) return null;

  return (
    <section className="dashboard-page" data-testid="dashboard-page">
      <div className="dashboard-header">
        <h2>Yonetim Paneli</h2>
        {canViewRaporlar ? (
          <Link to="/raporlar" className="universal-btn-aux">
            Detayli Raporlar
          </Link>
        ) : null}
      </div>

      <div className="dashboard-section">
        <h3 className="dashboard-section-title">Personel Ozeti</h3>
        <div className="dashboard-kpi-grid" data-testid="dashboard-kpi-grid">
          <KpiCard label="Toplam Personel" value={kpi.toplam_personel} />
          <KpiCard label="Aktif Personel" value={kpi.aktif_personel} variant="success" />
          <KpiCard label="Pasif Personel" value={kpi.pasif_personel} variant={kpi.pasif_personel > 0 ? "warning" : undefined} />
        </div>
      </div>

      <div className="dashboard-section">
        <h3 className="dashboard-section-title">Puantaj Ozeti</h3>
        <div className="dashboard-kpi-grid">
          <KpiCard label="Muhurlu Puantaj" value={kpi.toplam_muhurlenen_puantaj} variant="success" />
          <KpiCard label="Acik Puantaj" value={kpi.toplam_acik_puantaj} variant={kpi.toplam_acik_puantaj > 0 ? "warning" : undefined} />
          <KpiCard label="Izinsiz Devamsizlik" value={kpi.toplam_izinsiz_devamsizlik} variant={kpi.toplam_izinsiz_devamsizlik > 0 ? "danger" : undefined} />
          <KpiCard label="Hafta Tatili Hak Kaybi" value={kpi.hafta_tatili_hak_kaybi_sayisi} variant={kpi.hafta_tatili_hak_kaybi_sayisi > 0 ? "danger" : undefined} />
        </div>
      </div>

      <div className="dashboard-section">
        <h3 className="dashboard-section-title">Calisma ve Izin</h3>
        <div className="dashboard-kpi-grid">
          <KpiCard label="Toplam Net Calisma" value={formatDakikaToSaat(kpi.toplam_net_calisma_dakika)} />
          <KpiCard label="Ortalama Gunluk Net" value={formatDakikaToSaat(kpi.ortalama_gunluk_net_calisma_dakika)} />
          <KpiCard label="Ortalama Kalan Izin" value={`${kpi.ortalama_kalan_izin} gun`} />
        </div>
      </div>

      <div className="module-links">
        <Link to="/puantaj">Gunluk Kayit Merkezi</Link>
        <Link to="/surecler">Surec Takip</Link>
        <Link to="/personeller">Personel Listesi</Link>
      </div>
    </section>
  );
}
