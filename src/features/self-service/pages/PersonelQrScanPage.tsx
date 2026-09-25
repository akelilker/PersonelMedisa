import { useEffect, useRef, useState } from "react";
import { Link, useNavigate, useSearchParams } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { createQrRequestNonce, postMeQrScan } from "../../../api/qr.api";
import type { MeQrAttendanceEvent, QrEventType } from "../../../types/self-service";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import { QrPuantajExpectationNote } from "../components/QrPuantajExpectationNote";
import { startQrScanner, type QrScannerHandle } from "../qr/qr-scanner";
import { formatSelfServiceClock, qrEventTypeLabel } from "../self-service-datetime";

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
      return "Bağlantı yok, işlem kaydedilmedi.";
    }
    return "Bağlantı kurulamadı, kayıt oluşturulmadı.";
  }
  switch (error.code) {
    case "QR_TOKEN_EXPIRED":
      return "QR süresi doldu. Kiosk ekranındaki yeni kodu tekrar okutun.";
    case "QR_TOKEN_INVALID":
    case "QR_SIGNATURE_INVALID":
      return "QR kodu geçersiz. Kiosk ekranındaki güncel kodu okutun.";
    case "QR_CROSS_BRANCH_DENIED":
      return "Bu QR sizin çalışma şubenize ait değil. Kendi şube kiosk kodunu okutun.";
    case "QR_IDEMPOTENCY_CONFLICT":
      return "İşlem zaten kaydedilmiş.";
    case "QR_OPEN_SHIFT_EXISTS":
      return "Açık giriş kaydı varken yeni giriş yapılamaz. Önce çıkış veya düzeltme gerekir.";
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
      return "QR servisi şu an hazır değil. Yönetiminize bildirin.";
    case "NETWORK_ERROR":
      return "Bağlantı yok, işlem kaydedilmedi.";
    default:
      return "Kayıt oluşturulamadı. Tekrar deneyin.";
  }
}

export function PersonelQrScanPage() {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const preset = searchParams.get("event");
  const presetEvent: QrEventType | null =
    preset === "GIRIS" || preset === "CIKIS" ? preset : null;

  const videoRef = useRef<HTMLVideoElement | null>(null);
  const scannerRef = useRef<QrScannerHandle | null>(null);
  const [phase, setPhase] = useState<Phase>({ kind: "idle" });
  const [infoNotice, setInfoNotice] = useState<string | null>(null);
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

  const submitToken = async (token: string, eventType: QrEventType) => {
    if (submittingRef.current) return;
    submittingRef.current = true;
    setPhase({ kind: "submitting", token, eventType });
    try {
      const response = await postMeQrScan({
        token,
        event_type: eventType,
        request_nonce: createQrRequestNonce()
      });
      const lateEarly =
        response && typeof response === "object" && "late_early_info" in response
          ? (response as { late_early_info?: { kind: string; message: string; delta_dakika: number } | null })
              .late_early_info
          : null;
      setPhase({
        kind: "success",
        event: response.event,
        idempotent: response.idempotent,
        lateEarly: lateEarly ?? null
      });
      if (lateEarly?.message) {
        setInfoNotice(lateEarly.message);
      }
    } catch (error) {
      setPhase({ kind: "error", message: mapScanError(error) });
    } finally {
      submittingRef.current = false;
    }
  };

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
          if (presetEvent) {
            void submitToken(result.rawValue, presetEvent);
          } else {
            setPhase({ kind: "choose", token: result.rawValue });
          }
        },
        onError: (message) => {
          setPhase({ kind: "error", message });
        }
      });
    } catch (error) {
      setPhase({
        kind: "error",
        message: "Kamera açılamadı. Tekrar deneyin."
      });
    }
  };

  const submit = async (eventType: QrEventType) => {
    if (phase.kind !== "choose") return;
    await submitToken(phase.token, eventType);
  };

  return (
    <section className="personel-mobile-shell qr-scan-page" data-testid="personel-qr-scan-page">
      <p className="qr-scan-lead" data-testid="qr-scan-lead">
        Şube kiosk ekranındaki QR kodunu okutun.
      </p>

      <div className="qr-scan-video-wrap" data-testid="qr-scan-video-wrap">
        <video ref={videoRef} className="qr-scan-video" playsInline muted />
        {phase.kind === "idle" || phase.kind === "scanning" ? (
          <p className="qr-scan-video-hint" aria-hidden={phase.kind !== "scanning"}>
            {phase.kind === "scanning" ? "Kodu çerçeveye hizalayın" : "Kamera kapalı"}
          </p>
        ) : null}
      </div>

      <div className="qr-scan-cta-zone" data-testid="qr-scan-cta-zone">
        {phase.kind === "idle" ? (
          <button
            type="button"
            className="self-service-action self-service-action--primary"
            data-testid="qr-scan-start"
            onClick={() => void beginScan()}
          >
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
          <article className="state-card self-service-card qr-scan-success-card" data-testid="qr-scan-success">
            <p
              className={`qr-event-badge qr-event-badge--${phase.event.event_type === "GIRIS" ? "giris" : "cikis"}`}
            >
              {qrEventTypeLabel(phase.event.event_type)}
            </p>
            <h3>
              {phase.event.event_type === "GIRIS" ? "Giriş kaydedildi" : "Çıkış kaydedildi"} —{" "}
              {formatSelfServiceClock(phase.event.occurred_at)}
            </h3>
            <dl className="self-service-dl">
              <div>
                <dt>Şube</dt>
                <dd>{phase.event.sube.ad || `#${phase.event.sube.id}`}</dd>
              </div>
              {phase.idempotent ? (
                <div>
                  <dt>Not</dt>
                  <dd>Bu işlem daha önce kaydedilmiş.</dd>
                </div>
              ) : null}
            </dl>
            <p className="self-service-muted">
              Bu kayıt puantaja otomatik yazılmaz; kontrol edildikten sonra puantaja işlenir.
            </p>
            <div className="qr-scan-actions">
              <button
                type="button"
                className="self-service-action self-service-action--primary"
                onClick={() => navigate("/")}
              >
                Anasayfaya Dön
              </button>
              <Link to="/self/qr-hareketleri" className="self-service-action">
                QR Hareketlerim
              </Link>
            </div>
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

      <QrPuantajExpectationNote />

      <nav className="pm-secondary-nav qr-scan-footer-nav" aria-label="QR sayfa bağlantıları">
        <Link to="/">Özet</Link>
        <Link to="/self/qr-hareketleri">QR Hareketlerim</Link>
      </nav>

      <BackgroundlessNoticeModal
        open={infoNotice !== null}
        title="Bilgi"
        body={infoNotice ?? ""}
        infoTooltip="Bilgi Amaçlıdır."
        onClose={() => setInfoNotice(null)}
        testId="late-early-info-modal"
      />
    </section>
  );
}
