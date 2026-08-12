import { useCallback, useEffect, useRef, useState } from "react";
import QRCode from "qrcode";
import { isApiRequestError } from "../../../api/api-client";
import { fetchQrKioskToken } from "../../../api/qr.api";
import { LoadingState } from "../../../components/states/LoadingState";
import type { QrKioskTokenResponse } from "../../../types/self-service";
import "../self-service.css";

const REFRESH_LEAD_SECONDS = 8;

type Status =
  | { kind: "loading" }
  | { kind: "ready"; token: QrKioskTokenResponse; dataUrl: string; secondsLeft: number }
  | { kind: "error"; message: string };

export function QrKioskPage() {
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const refreshTimer = useRef<number | null>(null);
  const countdownTimer = useRef<number | null>(null);
  const mounted = useRef(true);

  const clearTimers = () => {
    if (refreshTimer.current != null) {
      window.clearTimeout(refreshTimer.current);
      refreshTimer.current = null;
    }
    if (countdownTimer.current != null) {
      window.clearInterval(countdownTimer.current);
      countdownTimer.current = null;
    }
  };

  const loadToken = useCallback(async () => {
    clearTimers();
    if (!mounted.current) {
      return;
    }
    setStatus((prev) => (prev.kind === "ready" ? prev : { kind: "loading" }));
    try {
      const token = await fetchQrKioskToken();
      const dataUrl = await QRCode.toDataURL(token.token, {
        errorCorrectionLevel: "M",
        margin: 1,
        width: 420,
        color: { dark: "#0f172a", light: "#ffffff" }
      });
      if (!mounted.current) {
        return;
      }
      const tick = () => {
        const left = Math.max(0, token.expires_at - Math.floor(Date.now() / 1000));
        setStatus({ kind: "ready", token, dataUrl, secondsLeft: left });
        if (left <= 0) {
          setStatus({ kind: "error", message: "QR yenilenemedi." });
        }
      };
      tick();
      countdownTimer.current = window.setInterval(tick, 1000);
      const refreshInMs = Math.max(1000, (token.ttl_seconds - REFRESH_LEAD_SECONDS) * 1000);
      refreshTimer.current = window.setTimeout(() => {
        void loadToken();
      }, refreshInMs);
    } catch (error) {
      if (!mounted.current) {
        return;
      }
      const message =
        isApiRequestError(error) && error.message
          ? error.message
          : "QR yenilenemedi.";
      setStatus({ kind: "error", message });
    }
  }, []);

  useEffect(() => {
    mounted.current = true;
    void loadToken();
    return () => {
      mounted.current = false;
      clearTimers();
    };
  }, [loadToken]);

  if (status.kind === "loading") {
    return <LoadingState label="Kiosk QR hazirlaniyor..." />;
  }

  if (status.kind === "error") {
    return (
      <section className="qr-kiosk qr-kiosk--error" data-testid="qr-kiosk-page">
        <h1>QR Kiosk</h1>
        <p role="alert">{status.message}</p>
        <button type="button" className="self-service-action" onClick={() => void loadToken()}>
          Yeniden dene
        </button>
      </section>
    );
  }

  return (
    <section className="qr-kiosk" data-testid="qr-kiosk-page">
      <header className="qr-kiosk__header">
        <h1>{status.token.sube.ad || "Sube"}</h1>
        <p>Personel QR okutarak giris/cikis kaydi olusturur.</p>
      </header>
      <div className="qr-kiosk__frame">
        <img src={status.dataUrl} alt="Sube QR kodu" width={420} height={420} />
      </div>
      <p className="qr-kiosk__countdown" data-testid="qr-kiosk-countdown">
        Yenilenmeye {status.secondsLeft} sn
      </p>
    </section>
  );
}
