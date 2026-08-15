import { type FormEvent } from "react";
import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { downloadReportCsv } from "../../../reports/export-report";
import { useManagerQrAttendance } from "../hooks/useManagerQrAttendance";
import { formatQrTime, qrAttendanceStatus, qrReadErrorMessage } from "../qr-read-utils";

export function QrGirisCikisOperationSection() {
  const { hasPermission } = useRoleAccess();
  const canView = hasPermission("puantaj.view");
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
    items,
    filteredItems,
    total,
    loading,
    error,
    hasLoaded,
    load
  } = useManagerQrAttendance({ autoLoad: canView });

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void load();
  }

  if (!canView) return null;

  const summary = {
    inside: items.filter((item) => item.inside).length,
    anomalies: items.filter((item) => item.anomalies.length > 0).length,
    intervals: items.reduce((sum, item) => sum + item.interval_count, 0)
  };

  return (
    <section className="puantaj-qr-operation" data-testid="puantaj-qr-operation">
      <header className="puantaj-qr-operation-head">
        <div>
          <p className="puantaj-section-eyebrow">Günlük operasyon / kontrol</p>
          <h2>QR Giriş / Çıkış</h2>
          <p>QR hareketleri salt okunur kanıt görünümüdür; puantaj adayı kararını değiştirmez.</p>
        </div>
        <Link className="universal-btn-aux" to="/qr-kiosk">
          QR Ekranını Aç
        </Link>
      </header>

      {hasLoaded ? (
        <div className="puantaj-qr-summary" aria-label="Bugün özeti">
          <div><span>Personel</span><strong>{total}</strong></div>
          <div><span>İçeride</span><strong>{summary.inside}</strong></div>
          <div><span>Interval</span><strong>{summary.intervals}</strong></div>
          <div><span>Anomali</span><strong>{summary.anomalies}</strong></div>
        </div>
      ) : null}

      <form className="form-filter-panel puantaj-qr-filters" onSubmit={submit}>
        <div className="form-field-grid">
          <FormField label="Başlangıç" name="qr-from" type="date" value={from} onChange={setFrom} required />
          <FormField label="Bitiş" name="qr-to" type="date" value={to} onChange={setTo} required />
          <FormField label="Personel ID" name="qr-personel" type="number" min={1} value={personelId} onChange={setPersonelId} />
          <FormField label="Şube ID" name="qr-sube" type="number" min={1} value={subeId} onChange={setSubeId} />
          <FormField
            as="select"
            label="Durum / anomali"
            name="qr-anomaly"
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
          <button className="universal-btn-aux" type="submit" disabled={loading}>Hareketleri Getir</button>
          <button
            className="universal-btn-aux"
            type="button"
            disabled={filteredItems.length === 0}
            onClick={() =>
              downloadReportCsv(
                `qr-giris-cikis-${from}-${to}.csv`,
                ["Personel", "Sicil", "Şube", "İlk giriş", "Son çıkış", "Durum", "Anomali"],
                filteredItems.map((item) => ({
                  Personel: item.ad_soyad,
                  Sicil: item.sicil_no ?? "—",
                  Şube: item.sube,
                  "İlk giriş": item.first_entry ?? "—",
                  "Son çıkış": item.last_exit ?? "—",
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

      {loading ? <LoadingState label="QR hareketleri yükleniyor..." /> : null}
      {!loading && error ? (
        <ErrorState message={qrReadErrorMessage(error)} onRetry={() => void load()} />
      ) : null}
      {!loading && !error && hasLoaded && filteredItems.length === 0 ? (
        <p className="puantaj-form-readonly">Seçilen aralıkta QR hareketi bulunamadı.</p>
      ) : null}

      {!loading && !error && filteredItems.length > 0 ? (
        <div className="raporlar-table-wrap puantaj-qr-table-wrap">
          <table className="raporlar-table" data-testid="puantaj-qr-table">
            <thead><tr><th>Personel</th><th>Sicil</th><th>Şube</th><th>Tarih</th><th>İlk giriş</th><th>Son çıkış</th><th>Son hareket</th><th>Durum</th><th>Interval</th><th>Anomali</th><th>Aksiyon</th></tr></thead>
            <tbody>
              {filteredItems.map((item) => (
                <tr key={item.personel_id}>
                  <td>{item.ad_soyad}</td><td>{item.sicil_no ?? "—"}</td><td>{item.sube || item.sube_id}</td>
                  <td>{item.date_from === item.date_to ? item.date_from : `${item.date_from} – ${item.date_to}`}</td>
                  <td>{formatQrTime(item.first_entry)}</td><td>{formatQrTime(item.last_exit)}</td>
                  <td>{formatQrTime(item.last_movement)} {item.last_movement_type ? `(${item.last_movement_type})` : ""}</td>
                  <td>{qrAttendanceStatus(item)}</td><td>{item.interval_count}</td>
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
