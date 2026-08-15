import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { fetchManagerQrAttendance } from "../../../../api/qr.api";
import type { Personel } from "../../../../types/personel";

export function PersonelQrHistorySection({ personel }: { personel: Personel }) {
  const [rows, setRows] = useState<Awaited<ReturnType<typeof fetchManagerQrAttendance>>["items"]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const now = new Date();
    const to = now.toISOString().slice(0, 10);
    const fromDate = new Date(now);
    fromDate.setDate(now.getDate() - 30);
    const from = fromDate.toISOString().slice(0, 10);
    void fetchManagerQrAttendance({ personel_id: personel.id, from, to, limit: 100 })
      .then((result) => setRows(result.items))
      .catch((cause) => setError(cause instanceof Error ? cause.message : "QR geçmişi yüklenemedi."));
  }, [personel.id]);

  return (
    <section className="personel-dossier-section personel-qr-history" data-testid="personel-qr-history">
      <div className="personel-dossier-section-head">
        <div>
          <h3>Giriş / Çıkış</h3>
          <p>Son 30 günün QR giriş/çıkış geçmişi salt okunur gösterilir.</p>
        </div>
        <Link to={`/puantaj?personel_id=${personel.id}`} className="universal-btn-aux">Puantajda İncele</Link>
      </div>
      {error ? <p className="yonetim-error">{error}</p> : null}
      {!error && rows.length === 0 ? <p className="puantaj-form-readonly">QR hareketi bulunamadı.</p> : null}
      {rows.length > 0 ? (
        <div className="raporlar-table-wrap">
          <table className="raporlar-table">
            <thead><tr><th>Tarih</th><th>Giriş</th><th>Çıkış</th><th>QR eşleşme süresi</th><th>Durum</th><th>Anomali</th></tr></thead>
            <tbody>{rows.map((row) => (
              <tr key={`${row.personel_id}-${row.date_from}`}>
                <td>{row.date_from === row.date_to ? row.date_from : `${row.date_from} – ${row.date_to}`}</td>
                <td>{row.first_entry ? new Date(row.first_entry).toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" }) : "—"}</td>
                <td>{row.last_exit ? new Date(row.last_exit).toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" }) : "—"}</td>
                <td>{Math.floor(row.matched_seconds / 3600)}s {Math.floor((row.matched_seconds % 3600) / 60)}dk</td>
                <td>{row.inside ? "İçeride" : "Çıktı"}</td>
                <td>{row.anomalies.length ? row.anomalies.join(", ") : "Yok"}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
