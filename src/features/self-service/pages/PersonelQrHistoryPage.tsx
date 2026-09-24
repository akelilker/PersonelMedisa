import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { fetchMeQrAraliklari, fetchMeQrHareketleri } from "../../../api/qr.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { LoadingState } from "../../../components/states/LoadingState";
import type {
  MeQrAraliklariResponse,
  MeQrAttendanceEvent,
  MeQrIntervalAnomaly
} from "../../../types/self-service";
import { QrPuantajExpectationNote } from "../components/QrPuantajExpectationNote";
import { formatSelfServiceDateTime, qrEventTypeLabel } from "../self-service-datetime";

type Status =
  | { kind: "loading" }
  | {
      kind: "ready";
      items: MeQrAttendanceEvent[];
      intervals: MeQrAraliklariResponse;
    }
  | { kind: "error"; message: string };

function formatDuration(seconds: number): string {
  const safe = Math.max(0, Math.floor(seconds));
  const h = Math.floor(safe / 3600);
  const m = Math.floor((safe % 3600) / 60);
  if (h <= 0) {
    return `${m} dk`;
  }
  return `${h} sa ${m} dk`;
}

function anomalyLabel(anomaly: MeQrIntervalAnomaly): string {
  if (anomaly.type === "MISSING_CIKIS") {
    return "Çıkış eksik";
  }
  if (anomaly.type === "MISSING_GIRIS") {
    return "Giriş eksik";
  }
  return "Şube uyuşmazlığı";
}

export function PersonelQrHistoryPage() {
  const [status, setStatus] = useState<Status>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const [history, intervals] = await Promise.all([
          fetchMeQrHareketleri(),
          fetchMeQrAraliklari()
        ]);
        if (!cancelled) {
          setStatus({
            kind: "ready",
            items: history.items,
            intervals
          });
        }
      } catch (error) {
        if (!cancelled) {
          const unbound =
            isApiRequestError(error) &&
            (error.code === "SELF_SERVICE_BINDING_REQUIRED" || error.code === "FORBIDDEN");
          setStatus({
            kind: "error",
            message: unbound
              ? "Personel bağlantınız yok veya QR hareketleri bu hesap için kapalı."
              : "QR hareketleri yüklenemedi. Tekrar deneyin."
          });
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (status.kind === "loading") {
    return <LoadingState label="QR hareketleri yükleniyor..." />;
  }

  if (status.kind === "error") {
    return (
      <section className="states-page state-error" data-testid="personel-qr-history-error">
        <h2>QR Hareketlerim</h2>
        <p>{status.message}</p>
        <Link to="/">Özet</Link>
      </section>
    );
  }

  const { intervals } = status;
  const hasIntervals = intervals.intervals.length > 0 || intervals.anomalies.length > 0;

  return (
    <section className="personel-mobile-shell self-service-home" data-testid="personel-qr-history-page">
      <header className="self-service-home__header">
        <h2>QR Hareketlerim</h2>
        <p>Ham giriş/çıkış kayıtları ve QR giriş/çıkış eşleşmeleri.</p>
      </header>

      <QrPuantajExpectationNote />

      <section className="qr-interval-section" data-testid="personel-qr-intervals-section">
        <h3>QR Eşleşmeleri</h3>
        <p className="self-service-muted">
          QR eşleşme süresi gösterilir. Kanonik çalışma süresi / puantaj hesabı sonraki fazdadır.
        </p>
        <p className="self-service-muted">
          Tam eşleşme: {intervals.summary.complete_interval_count} · Anomali:{" "}
          {intervals.summary.anomaly_count} · Toplam eşleşme:{" "}
          {formatDuration(intervals.summary.complete_duration_seconds)}
        </p>

        {!hasIntervals ? (
          <div data-testid="personel-qr-intervals-empty">
            <EmptyState
              title="Eşleşme yok"
              message="Bu dönemde tamamlanmış QR giriş/çıkış eşleşmesi yok."
            />
          </div>
        ) : null}

        {intervals.intervals.length > 0 ? (
          <ul className="qr-history-list" data-testid="personel-qr-intervals-list">
            {intervals.intervals.map((item) => (
              <li
                key={`${item.entry_event_id}-${item.exit_event_id}`}
                className="qr-history-item"
              >
                <strong>Tam eşleşme</strong>
                <span>
                  {formatSelfServiceDateTime(item.entry_at)} →{" "}
                  {formatSelfServiceDateTime(item.exit_at)}
                </span>
                <span>{formatDuration(item.duration_seconds)}</span>
                <span>{item.sube.ad || `Şube #${item.sube.id}`}</span>
                {item.spans_local_midnight ? <span>Gece yarısını aşan</span> : null}
              </li>
            ))}
          </ul>
        ) : null}

        {intervals.anomalies.length > 0 ? (
          <ul className="qr-history-list" data-testid="personel-qr-anomalies-list">
            {intervals.anomalies.map((anomaly, index) => (
              <li
                key={
                  anomaly.type === "BRANCH_MISMATCH"
                    ? `mm-${anomaly.entry_event_id}-${anomaly.exit_event_id}`
                    : `an-${anomaly.event_id}-${index}`
                }
                className="qr-history-item"
              >
                <strong>{anomalyLabel(anomaly)}</strong>
                <span>
                  {anomaly.occurred_at
                    ? formatSelfServiceDateTime(anomaly.occurred_at)
                    : anomaly.local_date}
                </span>
                {anomaly.type === "BRANCH_MISMATCH" ? (
                  <span>
                    {(anomaly.entry_sube.ad || `#${anomaly.entry_sube.id}`) +
                      " → " +
                      (anomaly.exit_sube.ad || `#${anomaly.exit_sube.id}`)}
                  </span>
                ) : (
                  <span>{anomaly.sube.ad || `Şube #${anomaly.sube.id}`}</span>
                )}
              </li>
            ))}
          </ul>
        ) : null}
      </section>

      <section data-testid="personel-qr-raw-history-section">
        <h3>Ham QR Kayıtları</h3>
        {status.items.length === 0 ? (
          <div data-testid="personel-qr-raw-empty">
            <EmptyState
              title="Hareket yok"
              message="Henüz QR giriş/çıkış kaydı yok. Kiosk ekranındaki kodu okuttuğunuzda burada görünür."
            />
          </div>
        ) : (
          <ul className="qr-history-list" data-testid="personel-qr-raw-list">
            {status.items.map((item) => (
              <li key={item.id} className="qr-history-item">
                <span
                  className={`qr-event-badge qr-event-badge--${item.event_type === "GIRIS" ? "giris" : "cikis"}`}
                >
                  {qrEventTypeLabel(item.event_type)}
                </span>
                <strong>{formatSelfServiceDateTime(item.occurred_at)}</strong>
                <span>{item.sube.ad || `Şube #${item.sube.id}`}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <nav className="pm-secondary-nav" aria-label="QR sayfa bağlantıları">
        <Link to="/">Özet</Link>
        <Link to="/self/qr-okut">QR Okut</Link>
      </nav>
    </section>
  );
}
