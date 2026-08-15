import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { fetchManagerQrAttendance } from "../../../../api/qr.api";
import type { Personel } from "../../../../types/personel";
import {
  formatQrTime,
  istanbulDateDaysAgo,
  istanbulToday,
  qrAttendanceStatus,
  qrReadErrorMessage
} from "../../../puantaj/qr-read-utils";

export function PersonelQrHistorySection({ personel }: { personel: Personel }) {
  const [rows, setRows] = useState<Awaited<ReturnType<typeof fetchManagerQrAttendance>>["items"]>([]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const from = istanbulDateDaysAgo(30);
    const to = istanbulToday();
    void fetchManagerQrAttendance({ personel_id: personel.id, from, to, limit: 100 })
      .then((result) => setRows(result.items))
      .catch((cause) => setError(qrReadErrorMessage(cause, true)));
  }, [personel.id]);

  return (
    <section className="personel-dossier-section personel-qr-history" data-testid="personel-qr-history">
      <div className="personel-dossier-section-head">
        <div>
          <h3>Giriş / Çıkış — Son 30 gün</h3>
          <p>Son 30 günün QR giriş/çıkış geçmişi salt okunur gösterilir.</p>
        </div>
        <Link to={`/puantaj?personel_id=${personel.id}`} className="universal-btn-aux">Puantajda İncele</Link>
      </div>
      {error ? <p className="yonetim-error">{error}</p> : null}
      {!error && rows.length === 0 ? <p className="puantaj-form-readonly">QR hareketi bulunamadı.</p> : null}
      {rows.length > 0 ? (
        <div className="raporlar-table-wrap">
          <table className="raporlar-table">
            <thead><tr><th>Tarih</th><th>Giriş</th><th>Çıkış</th><th>QR eşleşme süresi</th><th>Durum</th><th>Anomali</th><th>Aksiyon</th></tr></thead>
            <tbody>{rows.map((row) => (
              <tr key={`${row.personel_id}-${row.date_from}`}>
                <td>{row.date_from === row.date_to ? row.date_from : `${row.date_from} – ${row.date_to}`}</td>
                <td>{formatQrTime(row.first_entry)}</td>
                <td>{formatQrTime(row.last_exit)}</td>
                <td>{Math.floor(row.matched_seconds / 3600)}s {Math.floor((row.matched_seconds % 3600) / 60)}dk</td>
                <td>{qrAttendanceStatus(row)}</td>
                <td>{row.anomalies.length ? row.anomalies.join(", ") : "Yok"}</td>
                <td><Link to={`/puantaj?personel_id=${personel.id}&tarih=${row.date_from}`}>Günlük puantaj</Link></td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
