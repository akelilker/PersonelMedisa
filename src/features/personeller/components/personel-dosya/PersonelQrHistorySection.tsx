import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { fetchManagerQrAttendance } from "../../../../api/qr.api";
import type { Personel } from "../../../../types/personel";
import type { ManagerQrAttendanceItem } from "../../../../types/self-service";
import {
  formatQrTime,
  istanbulDateDaysAgo,
  istanbulToday,
  qrAttendanceStatus,
  qrReadErrorMessage
} from "../../../puantaj/qr-read-utils";

function formatQrHistoryDateLabel(row: ManagerQrAttendanceItem): string {
  return row.date_from === row.date_to ? row.date_from : `${row.date_from} – ${row.date_to}`;
}

function formatMatchedDuration(seconds: number): string {
  const hours = Math.floor(seconds / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  return `${hours}s ${minutes}dk`;
}

function formatAnomalyLabel(anomalies: string[]): string {
  return anomalies.length ? anomalies.join(", ") : "Yok";
}

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
    <section className="personel-dosya-section personel-qr-history" data-testid="personel-qr-history">
      <div className="personel-dosya-section-head personel-dosya-section-head--with-action">
        <div>
          <h3>Giriş / Çıkış — Son 30 gün</h3>
          <p>Son 30 günün QR giriş/çıkış geçmişi salt okunur gösterilir.</p>
        </div>
        <Link to={`/puantaj?personel_id=${personel.id}`} className="universal-btn-aux">
          Puantajda İncele
        </Link>
      </div>
      {error ? <p className="yonetim-error">{error}</p> : null}
      {!error && rows.length === 0 ? <p className="puantaj-form-readonly">QR hareketi bulunamadı.</p> : null}
      {rows.length > 0 ? (
        <>
          <div className="raporlar-table-wrap personel-qr-history-table-wrap">
            <table className="raporlar-table">
              <thead>
                <tr>
                  <th>Tarih</th>
                  <th>Giriş</th>
                  <th>Çıkış</th>
                  <th>QR eşleşme süresi</th>
                  <th>Durum</th>
                  <th>Anomali</th>
                  <th>Aksiyon</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={`${row.personel_id}-${row.date_from}`}>
                    <td>{formatQrHistoryDateLabel(row)}</td>
                    <td>{formatQrTime(row.first_entry)}</td>
                    <td>{formatQrTime(row.last_exit)}</td>
                    <td>{formatMatchedDuration(row.matched_seconds)}</td>
                    <td>{qrAttendanceStatus(row)}</td>
                    <td>{formatAnomalyLabel(row.anomalies)}</td>
                    <td>
                      <Link to={`/puantaj?personel_id=${personel.id}&tarih=${row.date_from}`}>
                        Günlük puantaj
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <ul className="pm-self-request-list personel-qr-history-card-list" data-testid="personel-qr-history-cards">
            {rows.map((row) => (
              <li
                key={`card-${row.personel_id}-${row.date_from}`}
                className="pm-self-request"
                data-testid={`personel-qr-history-card-${row.date_from}`}
              >
                <p className="pm-self-request__title">{formatQrHistoryDateLabel(row)}</p>
                <p className="personel-puantaj-summary-note">
                  {formatQrTime(row.first_entry)} · {formatQrTime(row.last_exit)}
                </p>
                <p className="personel-puantaj-summary-note">{formatMatchedDuration(row.matched_seconds)}</p>
                <p className="personel-puantaj-summary-note">
                  {qrAttendanceStatus(row)} · {formatAnomalyLabel(row.anomalies)}
                </p>
                <div className="pm-self-request__actions">
                  <Link to={`/puantaj?personel_id=${personel.id}&tarih=${row.date_from}`}>
                    Günlük puantaj
                  </Link>
                </div>
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </section>
  );
}
