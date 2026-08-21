import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { fetchMeQrAraliklari, fetchMeQrHareketleri } from "../../../api/qr.api";
import { LoadingState } from "../../../components/states/LoadingState";
import type {
  MeQrAraliklariResponse,
  MeQrAttendanceEvent,
  MeQrIntervalAnomaly
} from "../../../types/self-service";

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
    return "Cikis eksik";
  }
  if (anomaly.type === "MISSING_GIRIS") {
    return "Giris eksik";
  }
  return "Sube uyusmazligi";
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
          setStatus({
            kind: "error",
            message: isApiRequestError(error) ? error.message : "Hareketler yuklenemedi."
          });
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

  if (status.kind === "loading") {
    return <LoadingState label="QR hareketleri yukleniyor..." />;
  }

  if (status.kind === "error") {
    return (
      <section className="states-page state-error" data-testid="personel-qr-history-error">
        <h2>QR Hareketlerim</h2>
        <p>{status.message}</p>
        <Link to="/">Ozet</Link>
      </section>
    );
  }

  const { intervals } = status;

  return (
    <section className="self-service-home" data-testid="personel-qr-history-page">
      <header className="self-service-home__header">
        <h2>QR Hareketlerim</h2>
        <p>Ham giris/cikis kayitlari ve QR giris/cikis eslesmeleri.</p>
      </header>

      <section className="qr-interval-section" data-testid="personel-qr-intervals-section">
        <h3>QR Eslesmeleri</h3>
        <p className="self-service-muted">
          QR eslesme suresi gosterilir. Kanonik calisma suresi / puantaj hesabi sonraki fazdadir.
        </p>
        <p className="self-service-muted">
          Tam eslesme: {intervals.summary.complete_interval_count} · Anomali:{" "}
          {intervals.summary.anomaly_count} · Toplam eslesme:{" "}
          {formatDuration(intervals.summary.complete_duration_seconds)}
        </p>

        {intervals.intervals.length === 0 && intervals.anomalies.length === 0 ? (
          <p className="self-service-muted">Bu donemde QR eslesmesi yok.</p>
        ) : null}

        {intervals.intervals.length > 0 ? (
          <ul className="qr-history-list" data-testid="personel-qr-intervals-list">
            {intervals.intervals.map((item) => (
              <li
                key={`${item.entry_event_id}-${item.exit_event_id}`}
                className="qr-history-item"
              >
                <strong>Tam eslesme</strong>
                <span>
                  {new Date(item.entry_at).toLocaleString("tr-TR")} →{" "}
                  {new Date(item.exit_at).toLocaleString("tr-TR")}
                </span>
                <span>{formatDuration(item.duration_seconds)}</span>
                <span>{item.sube.ad || `Sube #${item.sube.id}`}</span>
                {item.spans_local_midnight ? <span>Gece yarisi asan</span> : null}
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
                    ? new Date(anomaly.occurred_at).toLocaleString("tr-TR")
                    : anomaly.local_date}
                </span>
                {anomaly.type === "BRANCH_MISMATCH" ? (
                  <span>
                    {(anomaly.entry_sube.ad || `#${anomaly.entry_sube.id}`) +
                      " → " +
                      (anomaly.exit_sube.ad || `#${anomaly.exit_sube.id}`)}
                  </span>
                ) : (
                  <span>{anomaly.sube.ad || `Sube #${anomaly.sube.id}`}</span>
                )}
              </li>
            ))}
          </ul>
        ) : null}
      </section>

      <section data-testid="personel-qr-raw-history-section">
        <h3>Ham QR Kayitlari</h3>
        {status.items.length === 0 ? (
          <p className="self-service-muted">Henuz QR hareketi yok.</p>
        ) : (
          <ul className="qr-history-list">
            {status.items.map((item) => (
              <li key={item.id} className="qr-history-item">
                <strong>{item.event_type === "GIRIS" ? "Giris" : "Cikis"}</strong>
                <span>{new Date(item.occurred_at).toLocaleString("tr-TR")}</span>
                <span>{item.sube.ad || `Sube #${item.sube.id}`}</span>
              </li>
            ))}
          </ul>
        )}
      </section>

      <p>
        <Link to="/">Ozet</Link>
        {" · "}
        <Link to="/self/qr-okut">QR Okut</Link>
      </p>
    </section>
  );
}
