import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { fetchMeQrHareketleri } from "../../../api/qr.api";
import { LoadingState } from "../../../components/states/LoadingState";
import type { MeQrAttendanceEvent } from "../../../types/self-service";
import "../self-service.css";

type Status =
  | { kind: "loading" }
  | { kind: "ready"; items: MeQrAttendanceEvent[] }
  | { kind: "error"; message: string };

export function PersonelQrHistoryPage() {
  const [status, setStatus] = useState<Status>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const data = await fetchMeQrHareketleri();
        if (!cancelled) {
          setStatus({ kind: "ready", items: data.items });
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

  return (
    <section className="self-service-home" data-testid="personel-qr-history-page">
      <header className="self-service-home__header">
        <h2>QR Hareketlerim</h2>
        <p>Ham giris/cikis kayitlari. Sure/interval hesabi sonraki fazdadir.</p>
      </header>

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

      <p>
        <Link to="/">Ozet</Link>
        {" · "}
        <Link to="/self/qr-okut">QR Okut</Link>
      </p>
    </section>
  );
}
