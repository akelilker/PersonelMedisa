import { useEffect, useRef, useState } from "react";
import { useSearchParams } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { createQrRequestNonce, postMeQrScan } from "../../../api/qr.api";
import type { MeQrAttendanceEvent, MeQrEarlyExitConfirm, QrEventType } from "../../../types/self-service";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import {
  mapCameraError,
  startQrScanner,
  takePrimedQrCamera,
  type QrScannerHandle
} from "../qr/qr-scanner";
import { formatSelfServiceClock } from "../self-service-datetime";

type Phase =
  | { kind: "idle" }
  | { kind: "scanning" }
  | { kind: "choose"; token: string }
  | { kind: "submitting"; token: string; eventType: QrEventType }
  | {
      kind: "success";
      event: MeQrAttendanceEvent;
      idempotent: boolean;
      lateEarly?: { kind: string; message: string; delta_dakika: number } | null;
    }
  | { kind: "error"; message: string };

function mapScanError(error: unknown): string {
  if (!isApiRequestError(error)) {
    if (typeof navigator !== "undefined" && navigator.onLine === false) {
      return "İnternet Bağlantısı Yok. İşlem Kaydedilmedi.";
    }
    return "Bağlantı hatası. İşlem kaydedilmedi.";
  }
  switch (error.code) {
    case "QR_TOKEN_EXPIRED":
      return "QR Kodunun Süresi Doldu. Yeni Kodu Okutun.";
    case "QR_TOKEN_INVALID":
    case "QR_SIGNATURE_INVALID":
      return "QR kodu geçersiz. Kiosk ekranındaki güncel kodu okutun.";
    case "QR_CROSS_BRANCH_DENIED":
      return "Bu QR Kodu Çalışma Yerinizle Eşleşmiyor.";
    case "QR_IDEMPOTENCY_CONFLICT":
      return "İşlem zaten kaydedilmiş.";
    case "QR_OPEN_SHIFT_EXISTS":
      return "Açık giriş kaydı varken yeni giriş yapılamaz. Önce çıkış veya düzeltme gerekir.";
    case "QR_STALE_OPEN_SHIFT":
      return "Önceki vardiyanın çıkış kaydı eksik. Düzeltme talebi oluşturun. Yeni vardiya için giriş yapabilirsiniz.";
    case "QR_NO_OPEN_SHIFT":
      return "Açık giriş olmadan çıkış kaydedilemez. Önce giriş okutun.";
    case "MOBILE_CAPABILITY_PENDING_SCOPE":
      return "Yapım Aşamasındadır. Onay Bekleyen Kapsamlar Tamamlandığında Kullanıma Açılacaktır.";
    case "QR_REPLAY":
    case "QR_JTI_REUSED":
      return "Bu QR daha önce kullanıldı. Kiosk ekranındaki yeni kodu okutun.";
    case "SELF_SERVICE_BINDING_REQUIRED":
      return "Personel bağlantınız yok. QR okutma için hesabınızın personel kaydına bağlanması gerekir.";
    case "SELF_SERVICE_PERSONEL_INACTIVE":
    case "PERSONEL_INACTIVE":
      return "Personel hesabınız aktif değil.";
    case "FORBIDDEN":
    case "UNAUTHORIZED":
      return "Bu işlem için yetkiniz yok. QR okutma yalnızca uygun personel hesabında açılır.";
    case "QR_CONFIG_NOT_READY":
    case "QR_SCHEMA_NOT_READY":
      return "Şu an işlem yapılamıyor. Yöneticinize bildirin.";
    case "NETWORK_ERROR":
      return "İnternet Bağlantısı Yok. İşlem Kaydedilmedi.";
    default:
      return "Sıra dışı işlem. Kayıt oluşturulamadı. Tekrar deneyin.";
  }
}

export function PersonelQrScanPage() {
  const [searchParams] = useSearchParams();
  const preset = searchParams.get("event");
  const presetEvent: QrEventType | null =
    preset === "GIRIS" || preset === "CIKIS" ? preset : null;

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const scannerRef = useRef<QrScannerHandle | null>(null);
  const scanGeneration = useRef(0);
  const [phase, setPhase] = useState<Phase>(presetEvent ? { kind: "scanning" } : { kind: "idle" });
  const [earlyExitPending, setEarlyExitPending] = useState<{
    token: string;
    eventType: QrEventType;
    confirm: MeQrEarlyExitConfirm;
  } | null>(null);
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

  const submitToken = async (
    token: string,
    eventType: QrEventType,
    options?: { earlyExitConfirmed?: boolean }
  ) => {
    if (submittingRef.current) return;
    submittingRef.current = true;
    setPhase({ kind: "submitting", token, eventType });
    try {
      const response = await postMeQrScan({
        token,
        event_type: eventType,
        request_nonce: createQrRequestNonce(),
        early_exit_confirmed: options?.earlyExitConfirmed
      });
      if (response.confirmation_required && response.early_exit_confirm) {
        setEarlyExitPending({
          token,
          eventType,
          confirm: response.early_exit_confirm
        });
        setPhase({ kind: "idle" });
        return;
      }
      if (!response.event) {
        setPhase({ kind: "error", message: "Kayıt oluşturulamadı. Tekrar deneyin." });
        return;
      }
      const lateEarly = response.late_early_info ?? null;
      setPhase({
        kind: "success",
        event: response.event,
        idempotent: response.idempotent,
        lateEarly
      });
      stopScanner();
    } catch (error) {
      setPhase({ kind: "error", message: mapScanError(error) });
    } finally {
      submittingRef.current = false;
    }
  };

  const submitTokenRef = useRef(submitToken);
  submitTokenRef.current = submitToken;

  // Playwright-only seam: drive confirm path without camera decode.
  useEffect(() => {
    const w = window as unknown as {
      __pmQrScanSubmitForTest?: (token: string, eventType: QrEventType) => void;
    };
    w.__pmQrScanSubmitForTest = (token, eventType) => {
      void submitTokenRef.current(token, eventType);
    };
    return () => {
      delete w.__pmQrScanSubmitForTest;
    };
  }, []);

  const beginScan = async (generation?: number) => {
    const gen = generation ?? ++scanGeneration.current;
    stopScanner();
    setPhase({ kind: "scanning" });
    const video = videoRef.current;
    if (!video) {
      if (scanGeneration.current !== gen) return;
      setPhase({ kind: "error", message: "Kamera açılamadı. Tekrar deneyin." });
      return;
    }
    const primed = takePrimedQrCamera();
    try {
      const handle = await startQrScanner({
        video,
        stream: primed,
        onResult: (result) => {
          if (scanGeneration.current !== gen) return;
          stopScanner();
          if (presetEvent) {
            void submitToken(result.rawValue, presetEvent);
          } else {
            setPhase({ kind: "choose", token: result.rawValue });
          }
        },
        onError: () => {
          if (scanGeneration.current !== gen) return;
          stopScanner();
          setPhase({ kind: "error", message: "QR kodu okunamadı. Tekrar deneyin." });
        }
      });
      if (scanGeneration.current !== gen) {
        handle.stop();
        return;
      }
      scannerRef.current = handle;
    } catch (error) {
      if (scanGeneration.current !== gen) return;
      setPhase({ kind: "error", message: mapCameraError(error).message });
    }
  };

  const beginScanRef = useRef(beginScan);
  beginScanRef.current = beginScan;

  useEffect(() => {
    if (!presetEvent) return;
    const gen = ++scanGeneration.current;
    void beginScanRef.current(gen);
    return () => {
      scanGeneration.current += 1;
      stopScanner();
    };
  }, [presetEvent]);

  const submit = async (eventType: QrEventType) => {
    if (phase.kind !== "choose") return;
    await submitToken(phase.token, eventType);
  };

  const videoCollapsed = phase.kind === "success";
  const showManualStart = phase.kind === "idle" && presetEvent === null && earlyExitPending === null;
  const successClock =
    phase.kind === "success" ? formatSelfServiceClock(phase.event.occurred_at) : "";
  const successLead =
    phase.kind === "success"
      ? phase.event.event_type === "GIRIS"
        ? `Girişiniz kaydedildi ${successClock}`
        : `Çıkışınız kaydedildi ${successClock}`
      : "";

  const dismissEarlyExit = () => {
    setEarlyExitPending(null);
    if (presetEvent) {
      void beginScan();
      return;
    }
    setPhase({ kind: "idle" });
  };

  return (
    <section className="personel-mobile-shell qr-scan-page" data-testid="personel-qr-scan-page">
      <div
        className={`qr-scan-video-wrap${videoCollapsed ? " qr-scan-video-wrap--collapsed" : ""}`}
        data-testid="qr-scan-video-wrap"
      >
        <video ref={videoRef} className="qr-scan-video" playsInline muted />
        {phase.kind === "idle" || phase.kind === "scanning" ? (
          <p className="qr-scan-video-hint" aria-hidden={phase.kind !== "scanning"}>
            {phase.kind === "scanning" ? "Kodu çerçeveye hizalayın" : "Kamera kapalı"}
          </p>
        ) : null}
      </div>

      <div className="qr-scan-cta-zone" data-testid="qr-scan-cta-zone">
        {showManualStart ? (
          <button
            type="button"
            className="self-service-action self-service-action--primary"
            data-testid="qr-scan-start"
            onClick={() => void beginScan()}
          >
            QR Okut
          </button>
        ) : null}

        {phase.kind === "choose" ? (
          <div className="qr-scan-actions" data-testid="qr-scan-choose">
            <button
              type="button"
              className="self-service-action self-service-action--primary"
              onClick={() => void submit("GIRIS")}
            >
              GİRİŞ
            </button>
            <button
              type="button"
              className="self-service-action self-service-action--primary"
              onClick={() => void submit("CIKIS")}
            >
              ÇIKIŞ
            </button>
          </div>
        ) : null}

        {phase.kind === "submitting" ? (
          <p className="self-service-muted" data-testid="qr-scan-submitting">
            Kaydediliyor...
          </p>
        ) : null}

        {phase.kind === "success" ? (
          <article
            className="state-card self-service-card qr-scan-success-card"
            data-testid="qr-scan-success"
            role="status"
          >
            <h3>{successLead}</h3>
            {phase.idempotent ? <p>Bu işlem daha önce kaydedilmiş.</p> : null}
            {phase.lateEarly?.message ? (
              <p data-testid="qr-scan-late-early-info" role="status">
                {phase.lateEarly.message}
              </p>
            ) : null}
          </article>
        ) : null}

        {phase.kind === "error" ? (
          <div className="self-service-home__warnings" role="alert" data-testid="qr-scan-error">
            <p>{phase.message}</p>
            <button
              type="button"
              className="self-service-action self-service-action--primary"
              onClick={() => void beginScan()}
            >
              Tekrar dene
            </button>
          </div>
        ) : null}
      </div>

      <BackgroundlessNoticeModal
        open={earlyExitPending !== null}
        title="Erken Çıkış"
        body={earlyExitPending?.confirm.message ?? ""}
        primaryLabel="Evet"
        secondaryLabel="Hayır"
        onSecondary={dismissEarlyExit}
        onPrimary={() => {
          if (!earlyExitPending) return;
          const pending = earlyExitPending;
          setEarlyExitPending(null);
          void submitToken(pending.token, pending.eventType, { earlyExitConfirmed: true });
        }}
        onClose={dismissEarlyExit}
        testId="early-exit-confirm-modal"
      />
    </section>
  );
}
