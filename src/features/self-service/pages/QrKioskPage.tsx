import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import QRCode from "qrcode";
import { isApiRequestError } from "../../../api/api-client";
import { fetchQrKioskToken } from "../../../api/qr.api";
import { LoadingState } from "../../../components/states/LoadingState";
import { AppSelect } from "../../../components/form/AppSelect";
import { useAuth } from "../../../state/auth.store";
import { GLOBAL_SCOPE_ROLES } from "../../../types/auth";
import { canonicalizeUserRole } from "../../../lib/authorization/canonicalize-user-role";
import type { QrKioskTokenResponse } from "../../../types/self-service";

const REFRESH_LEAD_SECONDS = 8;

type Status =
  | { kind: "loading" }
  | { kind: "ready"; token: QrKioskTokenResponse; dataUrl: string; secondsLeft: number }
  | { kind: "error"; message: string }
  | { kind: "pick_sube" };

/**
 * QR kiosk for management roles.
 * Global roles (GENEL/SISTEM) with no active_sube must pick a local branch for token mint
 * without mutating session active_sube_id.
 */
export function QrKioskPage() {
  const { session } = useAuth();
  const role = canonicalizeUserRole(session?.user.rol ?? null);
  const isGlobal = role != null && (GLOBAL_SCOPE_ROLES as readonly string[]).includes(role);
  const sessionActive = session?.active_sube_id ?? null;
  const subeList = session?.sube_list ?? [];

  const [localSubeId, setLocalSubeId] = useState<number | null>(null);
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const refreshTimer = useRef<number | null>(null);
  const countdownTimer = useRef<number | null>(null);
  const mounted = useRef(true);

  const effectiveSubeId = useMemo(() => {
    if (sessionActive != null && sessionActive > 0) {
      return sessionActive;
    }
    if (localSubeId != null && localSubeId > 0) {
      return localSubeId;
    }
    return null;
  }, [sessionActive, localSubeId]);

  const needsLocalPick = isGlobal && sessionActive == null;

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
    if (needsLocalPick && (localSubeId == null || localSubeId <= 0)) {
      setStatus({ kind: "pick_sube" });
      return;
    }
    const requestSubeId = effectiveSubeId;
    if (requestSubeId == null || requestSubeId <= 0) {
      setStatus({ kind: "error", message: "Aktif şube seçilmelidir." });
      return;
    }
    setStatus((prev) => (prev.kind === "ready" ? prev : { kind: "loading" }));
    try {
      // Pass explicit sube_id for global kiosk pick; scoped sessions still send header.
      const token = await fetchQrKioskToken(needsLocalPick ? requestSubeId : undefined);
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
  }, [effectiveSubeId, localSubeId, needsLocalPick]);

  useEffect(() => {
    mounted.current = true;
    void loadToken();
    return () => {
      mounted.current = false;
      clearTimers();
    };
  }, [loadToken]);

  if (status.kind === "pick_sube") {
    return (
      <section className="qr-kiosk" data-testid="qr-kiosk-page">
        <header className="qr-kiosk__header">
          <h1>QR Giriş Ekranı</h1>
          <p>Token için şube seçin. Bu seçim oturumdaki aktif şubeyi değiştirmez.</p>
        </header>
        <label htmlFor="qr-kiosk-local-sube">
          Şube
          <AppSelect
            id="qr-kiosk-local-sube"
            dataTestId="qr-kiosk-local-sube"
            value={localSubeId != null ? String(localSubeId) : ""}
            placeholderOption={{ value: "", label: "Şube seçin" }}
            options={subeList.map((sube) => ({ value: String(sube.id), label: sube.ad }))}
            onChange={(value) => {
              const next = Number.parseInt(value, 10);
              setLocalSubeId(Number.isFinite(next) && next > 0 ? next : null);
            }}
          />
        </label>
        <button
          type="button"
          className="self-service-action"
          disabled={localSubeId == null}
          onClick={() => void loadToken()}
        >
          QR oluştur
        </button>
      </section>
    );
  }

  if (status.kind === "loading") {
    return <LoadingState label="QR Giriş Ekranı hazırlanıyor..." />;
  }

  if (status.kind === "error") {
    return (
      <section className="qr-kiosk qr-kiosk--error" data-testid="qr-kiosk-page">
        <h1>QR Giriş Ekranı</h1>
        <p role="alert">{status.message}</p>
        <button type="button" className="self-service-action" onClick={() => void loadToken()}>
          Yeniden dene
        </button>
        {needsLocalPick ? (
          <button
            type="button"
            className="self-service-action"
            onClick={() => {
              setLocalSubeId(null);
              setStatus({ kind: "pick_sube" });
            }}
          >
            Şube değiştir
          </button>
        ) : null}
      </section>
    );
  }

  return (
    <section className="qr-kiosk" data-testid="qr-kiosk-page">
      <header className="qr-kiosk__header">
        <h1>{status.token.sube.ad || "Şube"}</h1>
        <p>Personel QR okutarak giriş/çıkış kaydı oluşturur.</p>
      </header>
      <div className="qr-kiosk__frame">
        <img src={status.dataUrl} alt="Şube QR kodu" width={420} height={420} />
      </div>
      <p className="qr-kiosk__countdown" data-testid="qr-kiosk-countdown">
        Yenilenmeye {status.secondsLeft} sn
      </p>
      {needsLocalPick ? (
        <button
          type="button"
          className="self-service-action"
          onClick={() => {
            clearTimers();
            setLocalSubeId(null);
            setStatus({ kind: "pick_sube" });
          }}
        >
          Şube değiştir
        </button>
      ) : null}
    </section>
  );
}
