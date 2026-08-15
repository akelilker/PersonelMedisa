import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { downloadReportCsv } from "../../../reports/export-report";
import { useManagerQrAttendance } from "../../puantaj/hooks/useManagerQrAttendance";
import {
  formatQrTime,
  istanbulDateDaysAgo,
  istanbulToday,
  qrAttendanceStatus,
  qrReadErrorMessage
} from "../../puantaj/qr-read-utils";

export function QrGirisCikisReportSection() {
  const { hasPermission } = useRoleAccess();
  const canView = hasPermission("raporlar.view");
  const {
    from,
    to,
    personelId,
    subeId,
    anomaly,
    setFrom,
    setTo,
    setPersonelId,
    setSubeId,
    setAnomaly,
    filteredItems,
    loading,
    error,
    hasLoaded,
    load
  } = useManagerQrAttendance({
    initialFrom: istanbulDateDaysAgo(30),
    initialTo: istanbulToday(),
    autoLoad: canView
  });

  if (!canView) return null;

  return (
    <section data-testid="raporlar-qr-giris-cikis">
      <header className="raporlar-panel-heading">
        <div>
          <p className="raporlar-panel-eyebrow">Toplu geçmiş / filtre / inceleme</p>
          <h3 className="raporlar-panel-title">Giriş / Çıkış Raporu</h3>
        </div>
        <p className="raporlar-panel-hint">Sonuç satırından Personel Kartı’na veya günlük puantaja geçebilirsiniz.</p>
      </header>

      <form
        className="form-filter-panel puantaj-qr-filters"
        onSubmit={(event) => {
          event.preventDefault();
          void load();
        }}
      >
        <div className="form-field-grid">
          <FormField label="Başlangıç" name="qr-report-from" type="date" value={from} onChange={setFrom} required />
          <FormField label="Bitiş" name="qr-report-to" type="date" value={to} onChange={setTo} required />
          <FormField label="Personel ID" name="qr-report-personel" type="number" min={1} value={personelId} onChange={setPersonelId} />
          <FormField label="Şube ID" name="qr-report-sube" type="number" min={1} value={subeId} onChange={setSubeId} />
          <FormField
            as="select"
            label="Durum / anomali"
            name="qr-report-anomaly"
            value={anomaly}
            onChange={setAnomaly}
            placeholderOption={{ value: "", label: "Tümü" }}
            selectOptions={[
              { value: "INSIDE", label: "İçeride" },
              { value: "MISSING", label: "Eksik okutma" },
              { value: "BRANCH_MISMATCH", label: "Şube uyuşmazlığı" }
            ]}
          />
        </div>
        <div className="form-actions-row">
          <button className="universal-btn-aux" type="submit" disabled={loading}>Geçmişi Getir</button>
          <button
            className="universal-btn-aux"
            type="button"
            disabled={filteredItems.length === 0}
            onClick={() =>
              downloadReportCsv(
                `qr-giris-cikis-raporu-${from}-${to}.csv`,
                ["Personel", "Sicil", "Şube", "Tarih", "Giriş", "Çıkış", "Durum", "Anomali"],
                filteredItems.map((item) => ({
                  Personel: item.ad_soyad,
                  Sicil: item.sicil_no ?? "—",
                  Şube: item.sube,
                  Tarih: item.date_from,
                  Giriş: item.first_entry ?? "—",
                  Çıkış: item.last_exit ?? "—",
                  Durum: qrAttendanceStatus(item),
                  Anomali: item.anomalies.join(", ") || "Yok"
                }))
              )
            }
          >
            CSV&apos;ye Aktar
          </button>
        </div>
      </form>

      {loading ? <LoadingState label="QR geçmişi yükleniyor..." /> : null}
      {!loading && error ? (
        <ErrorState message={qrReadErrorMessage(error, true)} onRetry={() => void load()} />
      ) : null}
      {!loading && !error && hasLoaded && filteredItems.length === 0 ? (
        <p className="puantaj-form-readonly">Seçilen aralıkta QR geçmişi bulunamadı.</p>
      ) : null}
      {!loading && !error && filteredItems.length > 0 ? (
        <div className="raporlar-table-wrap">
          <table className="raporlar-table" data-testid="raporlar-qr-table">
            <thead><tr><th>Personel</th><th>Sicil</th><th>Şube</th><th>Tarih</th><th>Giriş</th><th>Çıkış</th><th>Son hareket</th><th>Durum</th><th>Interval</th><th>Anomali</th><th>Aksiyon</th></tr></thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={`${item.personel_id}-${item.date_from}`}>
                  <td>{item.ad_soyad}</td>
                  <td>{item.sicil_no ?? "—"}</td>
                  <td>{item.sube || item.sube_id}</td>
                  <td>{item.date_from}</td>
                  <td>{formatQrTime(item.first_entry)}</td>
                  <td>{formatQrTime(item.last_exit)}</td>
                  <td>{formatQrTime(item.last_movement)} {item.last_movement_type ? `(${item.last_movement_type})` : ""}</td>
                  <td>{qrAttendanceStatus(item)}</td>
                  <td>{item.interval_count}</td>
                  <td>{item.anomalies.length ? item.anomalies.join(", ") : "Yok"}</td>
                  <td className="table-actions">
                    <Link to={`/puantaj?personel_id=${item.personel_id}&tarih=${item.date_from}`}>Günlük Puantaja Git</Link>
                    <Link to={`/personeller/${item.personel_id}?tab=genel-bilgiler`}>Personel Kartını Aç</Link>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
