import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { createQrRequestNonce, postMeQrScan } from "../../../api/qr.api";
import type { MeQrAttendanceEvent, QrEventType } from "../../../types/self-service";
import { startQrScanner, type QrScannerHandle } from "../qr/qr-scanner";

type Phase =
  | { kind: "idle" }
  | { kind: "scanning" }
  | { kind: "choose"; token: string }
  | { kind: "submitting"; token: string; eventType: QrEventType }
  | { kind: "success"; event: MeQrAttendanceEvent; idempotent: boolean }
  | { kind: "error"; message: string };

function mapScanError(error: unknown): string {
  if (!isApiRequestError(error)) {
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      return "Bağlantı yok, işlem kaydedilmedi.";
    }
    return "Bağlantı kurulamadı, kayıt oluşturulmadı.";
  }
  switch (error.code) {
    case "QR_TOKEN_EXPIRED":
      return "QR süresi doldu. Tekrar okutun.";
    case "QR_TOKEN_INVALID":
    case "QR_SIGNATURE_INVALID":
      return "QR kodu geçersiz.";
    case "QR_CROSS_BRANCH_DENIED":
      return "Bu QR sizin çalışma şubenize ait değil.";
    case "QR_IDEMPOTENCY_CONFLICT":
      return "İşlem zaten kaydedilmiş.";
    case "QR_REPLAY":
    case "QR_JTI_REUSED":
      return "QR daha önce kullanıldı.";
    case "SELF_SERVICE_BINDING_REQUIRED":
      return "Personel bağlantınız yok.";
    case "SELF_SERVICE_PERSONEL_INACTIVE":
    case "PERSONEL_INACTIVE":
      return "Personel hesabınız aktif değil.";
    case "QR_CONFIG_NOT_READY":
    case "QR_SCHEMA_NOT_READY":
      return "QR servisi şu an hazır değil.";
    case "NETWORK_ERROR":
      return "Bağlantı yok, işlem kaydedilmedi.";
    default:
      return error.message || "Kayıt oluşturulamadı.";
  }
}

function formatEventClock(iso: string): string {
  try {
    return new Intl.DateTimeFormat("tr-TR", {
      timeZone: "Europe/Istanbul",
      hour: "2-digit",
      minute: "2-digit"
    }).format(new Date(iso));
  } catch {
    return new Date(iso).toLocaleTimeString("tr-TR", { hour: "2-digit", minute: "2-digit" });
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
      setPhase({ kind: "error", message: "Kamera alanı hazır değil." });
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
        message: error instanceof Error ? error.message : "Kamera açılamadı."
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
        <p>Önce QR kodu okutun, sonra Giriş veya Çıkış seçin.</p>
      </header>

      <div className="qr-scan-video-wrap">
        <video ref={videoRef} className="qr-scan-video" playsInline muted />
      </div>

      {phase.kind === "idle" ? (
        <button type="button" className="self-service-action" data-testid="qr-scan-start" onClick={() => void beginScan()}>
          Kamerayı aç
        </button>
      ) : null}

      {phase.kind === "scanning" ? (
        <p className="self-service-muted" data-testid="qr-scan-scanning">
          QR kodu çerçeveye hizalayın...
        </p>
      ) : null}

      {phase.kind === "choose" ? (
        <div className="qr-scan-actions" data-testid="qr-scan-choose">
          <button type="button" className="self-service-action" onClick={() => void submit("GIRIS")}>
            Giriş
          </button>
          <button type="button" className="self-service-action" onClick={() => void submit("CIKIS")}>
            Çıkış
          </button>
        </div>
      ) : null}

      {phase.kind === "submitting" ? (
        <p className="self-service-muted">Kaydediliyor...</p>
      ) : null}

      {phase.kind === "success" ? (
        <article className="state-card self-service-card" data-testid="qr-scan-success">
          <h3>
            {phase.event.event_type === "GIRIS" ? "Giriş kaydedildi" : "Çıkış kaydedildi"} —{" "}
            {formatEventClock(phase.event.occurred_at)}
          </h3>
          <dl className="self-service-dl">
            <div>
              <dt>Şube</dt>
              <dd>{phase.event.sube.ad || `#${phase.event.sube.id}`}</dd>
            </div>
            {phase.idempotent ? (
              <div>
                <dt>Not</dt>
                <dd>İşlem zaten kaydedilmişti (idempotent).</dd>
              </div>
            ) : null}
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
        <Link to="/">Özet</Link>
        {" · "}
        <Link to="/self/qr-hareketleri">QR Hareketlerim</Link>
      </p>
    </section>
  );
}
