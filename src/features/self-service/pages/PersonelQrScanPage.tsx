import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { createQrRequestNonce, postMeQrScan } from "../../../api/qr.api";
import type { MeQrAttendanceEvent, QrEventType } from "../../../types/self-service";
import { startQrScanner, type QrScannerHandle } from "../qr/qr-scanner";
import "../self-service.css";

type Phase =
  | { kind: "idle" }
  | { kind: "scanning" }
  | { kind: "choose"; token: string }
  | { kind: "submitting"; token: string; eventType: QrEventType }
  | { kind: "success"; event: MeQrAttendanceEvent; idempotent: boolean }
  | { kind: "error"; message: string };

function mapScanError(error: unknown): string {
  if (!isApiRequestError(error)) {
    return "Baglanti kurulamadi, kayit olusturulmadi.";
  }
  switch (error.code) {
    case "QR_TOKEN_EXPIRED":
      return "QR suresi doldu. Tekrar okutun.";
    case "QR_CROSS_BRANCH_DENIED":
      return "Bu QR baska bir subeye aittir.";
    case "SELF_SERVICE_BINDING_REQUIRED":
      return "Personel baglantiniz yok.";
    case "SELF_SERVICE_PERSONEL_INACTIVE":
      return "Personel hesabiniz pasif.";
    case "QR_CONFIG_NOT_READY":
    case "QR_SCHEMA_NOT_READY":
      return "QR servisi su an hazir degil.";
    default:
      return error.message || "Kayit olusturulamadi.";
  }
}

export function PersonelQrScanPage() {
  const videoRef = useRef<HTMLVideoElement | null>(null);
  const scannerRef = useRef<QrScannerHandle | null>(null);
  const [phase, setPhase] = useState<Phase>({ kind: "idle" });
  const submittingRef = useRef(false);

  const stopScanner = () => {
    scannerRef.current?.stop();
    scannerRef.current = null;
  };

  useEffect(() => {
    return () => {
      stopScanner();
    };
  }, []);

  const beginScan = async () => {
    stopScanner();
    setPhase({ kind: "scanning" });
    const video = videoRef.current;
    if (!video) {
      setPhase({ kind: "error", message: "Kamera alani hazir degil." });
      return;
    }
    try {
      scannerRef.current = await startQrScanner({
        video,
        onResult: (result) => {
          stopScanner();
          setPhase({ kind: "choose", token: result.rawValue });
        },
        onError: (message) => {
          setPhase({ kind: "error", message });
        }
      });
    } catch (error) {
      setPhase({
        kind: "error",
        message: error instanceof Error ? error.message : "Kamera acilamadi."
      });
    }
  };

  const submit = async (eventType: QrEventType) => {
    if (phase.kind !== "choose" || submittingRef.current) {
      return;
    }
    submittingRef.current = true;
    const token = phase.token;
    setPhase({ kind: "submitting", token, eventType });
    try {
      const response = await postMeQrScan({
        token,
        event_type: eventType,
        request_nonce: createQrRequestNonce()
      });
      setPhase({
        kind: "success",
        event: response.event,
        idempotent: response.idempotent
      });
    } catch (error) {
      setPhase({ kind: "error", message: mapScanError(error) });
    } finally {
      submittingRef.current = false;
    }
  };

  return (
    <section className="self-service-home qr-scan-page" data-testid="personel-qr-scan-page">
      <header className="self-service-home__header">
        <h2>QR Okut</h2>
        <p>Once QR kodu okutun, sonra Giris veya Cikis secin.</p>
      </header>

      <div className="qr-scan-video-wrap">
        <video ref={videoRef} className="qr-scan-video" playsInline muted />
      </div>

      {phase.kind === "idle" ? (
        <button type="button" className="self-service-action" data-testid="qr-scan-start" onClick={() => void beginScan()}>
          Kamerayi ac
        </button>
      ) : null}

      {phase.kind === "scanning" ? (
        <p className="self-service-muted" data-testid="qr-scan-scanning">
          QR kodu cerceveye hizalayin...
        </p>
      ) : null}

      {phase.kind === "choose" ? (
        <div className="qr-scan-actions" data-testid="qr-scan-choose">
          <button type="button" className="self-service-action" onClick={() => void submit("GIRIS")}>
            Giris
          </button>
          <button type="button" className="self-service-action" onClick={() => void submit("CIKIS")}>
            Cikis
          </button>
        </div>
      ) : null}

      {phase.kind === "submitting" ? (
        <p className="self-service-muted">Kaydediliyor...</p>
      ) : null}

      {phase.kind === "success" ? (
        <article className="state-card self-service-card" data-testid="qr-scan-success">
          <h3>{phase.event.event_type === "GIRIS" ? "Giris kaydedildi" : "Cikis kaydedildi"}</h3>
          <dl className="self-service-dl">
            <div>
              <dt>Zaman</dt>
              <dd>{new Date(phase.event.occurred_at).toLocaleString("tr-TR")}</dd>
            </div>
            <div>
              <dt>Sube</dt>
              <dd>{phase.event.sube.ad || `#${phase.event.sube.id}`}</dd>
            </div>
          </dl>
          <button type="button" className="self-service-action" onClick={() => void beginScan()}>
            Yeni okutma
          </button>
        </article>
      ) : null}

      {phase.kind === "error" ? (
        <div className="self-service-home__warnings" role="alert" data-testid="qr-scan-error">
          <p>{phase.message}</p>
          <button type="button" className="self-service-action" onClick={() => void beginScan()}>
            Tekrar dene
          </button>
        </div>
      ) : null}

      <p>
        <Link to="/">Ozet</Link>
        {" · "}
        <Link to="/self/qr-hareketleri">QR Hareketlerim</Link>
      </p>
    </section>
  );
}
