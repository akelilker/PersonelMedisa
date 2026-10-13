import { userErrorText } from "../../../lib/display/user-error";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { useLocation } from "react-router-dom";
import {
  fetchAttendanceToday,
  type AttendanceAnomaly,
  type AttendanceBoxEvent,
  type AttendanceTodayResponse
} from "../../../api/attendance-mobile.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import {
  createSelfAvansTalebi,
  createSelfGeriBildirim,
  createSelfIzinTalebi,
  createSelfRaporTalebi,
  fetchSelfAvansTalepleri,
  fetchSelfCorrectionRequests,
  fetchSelfGeriBildirimler,
  fetchSelfIzinler,
  fetchSelfRaporlar,
  type SelfAvansTalep,
  type SelfCorrectionRequest,
  type SelfGeriBildirim,
  type SelfIzinKaydi,
  type SelfRaporKaydi
} from "../../../api/self-product.api";
import { LoadingState } from "../../../components/states/LoadingState";
import { formatSurecStateLabel, formatSurecTuruLabel } from "../../../lib/display/enum-display";
import { AttendanceCorrectionRequestModal } from "../components/AttendanceCorrectionRequestModal";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import { PersonelMobileCapabilityService } from "../personel-mobile-capability";
import { formatDisplayDateOrText } from "../../../lib/display/iso-date-format";

type CorrectDraft = {
  eventId: number;
  eventType: "GIRIS" | "CIKIS";
  currentTime: string;
};

const STATUS_LABEL: Record<string, string> = {
  BEKLIYOR: "Bekliyor",
  ONAYLANDI: "Onaylandı",
  UYGUN_GORULMEDI: "Uygun görülmedi",
  REDDEDILDI: "Reddedildi",
  ALINDI: "Alındı",
  SONUCLANDI: "Sonuçlandı"
};

function statusLabel(value: string): string {
  return STATUS_LABEL[value] ?? formatSurecStateLabel(value);
}

function correctionTarget(
  event: AttendanceBoxEvent | null,
  pending: boolean
): AttendanceBoxEvent | null {
  if (!event || pending || event.correction_allowed !== true) {
    return null;
  }
  return event;
}

function correctionKind(value: string): string {
  if (value === "CIKIS_DUZELTME") return "Çıkış düzeltme";
  if (value === "GIRIS_DUZELTME") return "Giriş düzeltme";
  return value;
}

/** Onaylı karar (t873): Talepler ekranı tür seçimi — ikon + etiket, çerçevesiz; dokununca yalnız o form açılır. */
type TalepTuruId = "duzeltme" | "izin" | "rapor" | "avans" | "oneri";

const TALEP_TURLERI: readonly { id: TalepTuruId; label: string }[] = [
  { id: "duzeltme", label: "Giriş/Çıkış Düzeltme" },
  { id: "izin", label: "İzin" },
  { id: "rapor", label: "Sağlık Raporu" },
  { id: "avans", label: "Avans" },
  { id: "oneri", label: "Öneri / Şikâyet" }
];

const IKON_YOLLARI: Record<TalepTuruId, string> = {
  duzeltme: "M12 7v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z",
  izin: "M8 3v3M16 3v3M4 9h16M5 5h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z",
  rapor: "M12 8v8M8 12h8M6 3h12a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z",
  avans: "M3 7h18v10H3zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z",
  oneri: "M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12Z"
};

function TalepIkon({ id }: { id: TalepTuruId }) {
  return (
    <svg viewBox="0 0 24 24" width="40" height="40" aria-hidden="true" focusable="false">
      <path
        d={IKON_YOLLARI[id]}
        fill="none"
        stroke="currentColor"
        strokeWidth="1.6"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

export function PersonelSelfServiceTaleplerPage() {
  const location = useLocation();
  const [loading, setLoading] = useState(true);
  const [today, setToday] = useState<AttendanceTodayResponse | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [correctDraft, setCorrectDraft] = useState<CorrectDraft | null>(null);
  const [anomalyDraft, setAnomalyDraft] = useState<AttendanceAnomaly | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [corrections, setCorrections] = useState<SelfCorrectionRequest[]>([]);
  const [leaves, setLeaves] = useState<SelfIzinKaydi[]>([]);
  const [advances, setAdvances] = useState<SelfAvansTalep[]>([]);
  const [feedback, setFeedback] = useState<SelfGeriBildirim[]>([]);
  const [raporlar, setRaporlar] = useState<SelfRaporKaydi[]>([]);
  const [raporForm, setRaporForm] = useState({
    baslangic_tarihi: "",
    bitis_tarihi: "",
    aciklama: "",
    dosya_adi: "",
    dosya_mime: "",
    dosya_icerik_base64: ""
  });
  const [leaveForm, setLeaveForm] = useState({
    izin_turu: "YILLIK_IZIN",
    baslangic_tarihi: "",
    bitis_tarihi: "",
    aciklama: ""
  });
  const [advanceForm, setAdvanceForm] = useState({ tutar: "", talep_tarihi: "", aciklama: "" });

  useEffect(() => {
    const params = new URLSearchParams(location.search);
    const raw = params.get("correctionId");
    const id = raw ? Number.parseInt(raw, 10) : NaN;
    if (!Number.isFinite(id) || id <= 0 || corrections.length === 0) {
      return;
    }
    const el = document.getElementById(`personel-talep-correction-${id}`);
    el?.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }, [corrections, location.search]);
  const [feedbackForm, setFeedbackForm] = useState({
    tur: "ONERI" as "ONERI" | "SIKAYET",
    konu: "",
    aciklama: ""
  });

  const [secili, setSecili] = useState<TalepTuruId | null>(null);

  const load = useCallback(async () => {
    if (shouldPreferDemoApi()) {
      setLoading(false);
      setToday(null);
      setLoadError(null);
      return;
    }
    setLoading(true);
    try {
      const [attendance, correctionList, izinList, avansList, feedbackList, raporList] = await Promise.all([
        fetchAttendanceToday(),
        fetchSelfCorrectionRequests().catch(() => []),
        fetchSelfIzinler().catch(() => null),
        fetchSelfAvansTalepleri().catch(() => []),
        fetchSelfGeriBildirimler().catch(() => []),
        fetchSelfRaporlar().catch(() => [])
      ]);
      setToday(attendance);
      setCorrections(correctionList);
      setLeaves([...(izinList?.aktif ? [izinList.aktif] : []), ...(izinList?.gecmis ?? [])]);
      setAdvances(avansList);
      setFeedback(feedbackList);
      setRaporlar(raporList);
      setLoadError(null);
    } catch (cause) {
      setToday(null);
      setLoadError(userErrorText(isApiRequestError(cause) && cause.code === "SELF_SERVICE_BINDING_REQUIRED"
          ? "Personel bağlantınız yok."
          : "Talepler yüklenemedi. Tekrar deneyin."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const comingSoon =
    today?.capabilities.coming_soon_message ?? PersonelMobileCapabilityService.MESSAGE_COMING_SOON;
  const correctionEnabled = today?.capabilities.attendance_correct === true;
  const leaveEnabled = today?.capabilities.izin_write === true;
  const girisTarget = today
    ? correctionTarget(today.giris, today.pending_giris_correction != null)
    : null;
  const cikisTarget = today
    ? correctionTarget(today.cikis, today.pending_cikis_correction != null)
    : null;

  function openCorrection(event: AttendanceBoxEvent) {
    if (!correctionEnabled) {
      setNotice(comingSoon);
      return;
    }
    setCorrectDraft({
      eventId: event.id,
      eventType: event.event_type,
      currentTime: event.display_local_time ?? event.local_time
    });
  }

  async function submitLeave(event: FormEvent) {
    event.preventDefault();
    if (!leaveEnabled) {
      setNotice(comingSoon);
      return;
    }
    try {
      await createSelfIzinTalebi(leaveForm);
      setNotice("İzin talebiniz alındı.");
      setLeaveForm({ izin_turu: "YILLIK_IZIN", baslangic_tarihi: "", bitis_tarihi: "", aciklama: "" });
      await load();
    } catch (cause) {
      setNotice(isApiRequestError(cause) ? cause.message : "İzin talebi gönderilemedi.");
    }
  }

  async function submitRapor(event: FormEvent) {
    event.preventDefault();
    try {
      const body: {
        baslangic_tarihi: string;
        bitis_tarihi?: string;
        aciklama?: string;
        dosya_adi?: string;
        dosya_mime?: string;
        dosya_icerik_base64?: string;
      } = {
        baslangic_tarihi: raporForm.baslangic_tarihi,
        bitis_tarihi: raporForm.bitis_tarihi || raporForm.baslangic_tarihi,
        aciklama: raporForm.aciklama || undefined
      };
      if (raporForm.dosya_icerik_base64) {
        body.dosya_adi = raporForm.dosya_adi;
        body.dosya_mime = raporForm.dosya_mime;
        body.dosya_icerik_base64 = raporForm.dosya_icerik_base64;
      }
      await createSelfRaporTalebi(body);
      setNotice("Sağlık raporu bildiriminiz alındı.");
      setRaporForm({
        baslangic_tarihi: "",
        bitis_tarihi: "",
        aciklama: "",
        dosya_adi: "",
        dosya_mime: "",
        dosya_icerik_base64: ""
      });
      await load();
    } catch (cause) {
      setNotice(isApiRequestError(cause) ? cause.message : "Rapor bildirimi gönderilemedi.");
    }
  }

  async function submitAdvance(event: FormEvent) {
    event.preventDefault();
    try {
      await createSelfAvansTalebi(advanceForm);
      setNotice("Avans talebiniz alındı. Onaylanmadan finans kaydı oluşmaz.");
      setAdvanceForm({ tutar: "", talep_tarihi: "", aciklama: "" });
      await load();
    } catch (cause) {
      setNotice(isApiRequestError(cause) ? cause.message : "Avans talebi gönderilemedi.");
    }
  }

  async function submitFeedback(event: FormEvent) {
    event.preventDefault();
    try {
      await createSelfGeriBildirim(feedbackForm);
      setNotice("Geri bildiriminiz alındı.");
      setFeedbackForm({ tur: "ONERI", konu: "", aciklama: "" });
      await load();
    } catch (cause) {
      setNotice(isApiRequestError(cause) ? cause.message : "Geri bildirim gönderilemedi.");
    }
  }

  const anomalies = today?.unresolved_attendance_anomalies ?? [];

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-talepler-page">
      {secili === null ? (
        <nav className="pm-talep-turleri" data-testid="personel-talep-turleri" aria-label="Talep türleri">
          {TALEP_TURLERI.map((t) => (
            <button
              key={t.id}
              type="button"
              className="pm-talep-turu"
              data-testid={`personel-talep-turu-${t.id}`}
              onClick={() => setSecili(t.id)}
            >
              <span className="pm-talep-turu__ikon" aria-hidden="true">
                <TalepIkon id={t.id} />
              </span>
              <span className="pm-talep-turu__etiket">{t.label}</span>
            </button>
          ))}
        </nav>
      ) : (
        <button type="button" className="pm-talep-geri" data-testid="personel-talep-geri" onClick={() => setSecili(null)}>
          ‹ Talep Türleri
        </button>
      )}
      {secili === null && anomalies.length > 0 ? (
        <div className="pm-self-request-list">
          {anomalies.map((anomaly) => (
            <div
              key={anomaly.identity}
              className="pm-self-request"
              data-testid="personel-talep-anomaly-card"
            >
              <p className="pm-self-request__title">Giriş/Çıkış Kaydı Düzeltme Gerekiyor</p>
              <p>
                {anomaly.business_date_label} — {anomaly.problem}
              </p>
              {anomaly.pending_request_id ? (
                <p data-testid="personel-talep-anomaly-pending">
                  Düzeltme talebiniz değerlendirme bekliyor.
                </p>
              ) : (
                <button
                  type="button"
                  className="self-service-action"
                  data-testid="personel-talep-anomaly-cta"
                  onClick={() => setAnomalyDraft(anomaly)}
                >
                  Düzeltme Talebi Oluştur
                </button>
              )}
            </div>
          ))}
        </div>
      ) : null}
      {secili !== null ? (
      <ul className="pm-self-request-list">
        {secili === "duzeltme" ? (
        <li>
          <div className="pm-self-request" data-testid="personel-talep-duzeltme">
            <p className="pm-self-request__title">Giriş/Çıkış Düzeltme Talebi</p>
            {loading ? <LoadingState label="Kayıtlar yükleniyor..." /> : null}
            {!loading && loadError ? <p className="self-service-muted">{loadError}</p> : null}
            {!loading && today && !correctionEnabled ? (
              <p className="self-service-muted">{comingSoon}</p>
            ) : null}
            {!loading && today && correctionEnabled && !girisTarget && !cikisTarget ? (
              <p className="self-service-muted" data-testid="personel-talep-duzeltme-empty">
                Düzeltilebilecek bir giriş veya çıkış kaydı yok.
              </p>
            ) : null}
            {!loading && today && correctionEnabled && (girisTarget || cikisTarget) ? (
              <div className="pm-self-request__actions">
                {girisTarget ? (
                  <button
                    type="button"
                    className="self-service-action"
                    data-testid="personel-talep-duzeltme-giris"
                    onClick={() => openCorrection(girisTarget)}
                  >
                    Giriş düzeltme talebi
                  </button>
                ) : null}
                {cikisTarget ? (
                  <button
                    type="button"
                    className="self-service-action"
                    data-testid="personel-talep-duzeltme-cikis"
                    onClick={() => openCorrection(cikisTarget)}
                  >
                    Çıkış düzeltme talebi
                  </button>
                ) : null}
              </div>
            ) : null}
            {corrections.length > 0 ? (
              <ul data-testid="personel-talep-duzeltme-list">
                {corrections.map((item) => (
                  <li key={item.id} id={`personel-talep-correction-${item.id}`}>
                    {formatDisplayDateOrText(item.tarih)} · {correctionKind(item.talep_turu)} · {statusLabel(item.durum)}
                    {item.aciklama ? ` · ${item.aciklama}` : ""}
                    {item.sonuc ? ` · ${item.sonuc}` : ""}
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        </li>
        ) : null}
        {secili === "izin" ? (
        <li>
          <form className="pm-self-request" data-testid="personel-talep-izin" onSubmit={(event) => void submitLeave(event)}>
            <p className="pm-self-request__title">İzin Talebi</p>
            <label>
              İzin türü
              <select
                value={leaveForm.izin_turu}
                onChange={(event) => setLeaveForm((current) => ({ ...current, izin_turu: event.target.value }))}
              >
                <option value="YILLIK_IZIN">Yıllık</option>
                <option value="MAZERET_IZNI">Mazeret</option>
                <option value="UCRETSIZ_IZIN">Ücretsiz</option>
              </select>
            </label>
            <label>
              Başlangıç
              <input
                type="date"
                required
                value={leaveForm.baslangic_tarihi}
                onChange={(event) =>
                  setLeaveForm((current) => ({ ...current, baslangic_tarihi: event.target.value }))
                }
              />
            </label>
            <label>
              Bitiş
              <input
                type="date"
                required
                value={leaveForm.bitis_tarihi}
                onChange={(event) => setLeaveForm((current) => ({ ...current, bitis_tarihi: event.target.value }))}
              />
            </label>
            <label>
              Açıklama
              <textarea
                value={leaveForm.aciklama}
                onChange={(event) => setLeaveForm((current) => ({ ...current, aciklama: event.target.value }))}
              />
            </label>
            <button type="submit" className="self-service-action" disabled={!leaveEnabled && !loading}>
              İzin talebi gönder
            </button>
            {leaves.length > 0 ? (
              <ul data-testid="personel-talep-izin-list">
                {leaves.map((item) => (
                  <li key={item.id}>
                    {formatSurecTuruLabel(item.izin_turu)} · {item.baslangic}
                    {item.bitis ? `–${item.bitis}` : ""}
                    {typeof item.gun === "number" ? ` · ${item.gun} gün` : ""} · {statusLabel(item.durum)}
                  </li>
                ))}
              </ul>
            ) : null}
          </form>
        </li>
        ) : null}
        {secili === "rapor" ? (
        <li>
          <form className="pm-self-request" data-testid="personel-talep-rapor" onSubmit={(event) => void submitRapor(event)}>
            <p className="pm-self-request__title">Sağlık Raporu</p>
            <label>
              Başlangıç
              <input
                type="date"
                required
                value={raporForm.baslangic_tarihi}
                onChange={(event) =>
                  setRaporForm((current) => ({ ...current, baslangic_tarihi: event.target.value }))
                }
              />
            </label>
            <label>
              Bitiş
              <input
                type="date"
                value={raporForm.bitis_tarihi}
                onChange={(event) => setRaporForm((current) => ({ ...current, bitis_tarihi: event.target.value }))}
              />
            </label>
            <label>
              Açıklama
              <textarea
                value={raporForm.aciklama}
                onChange={(event) => setRaporForm((current) => ({ ...current, aciklama: event.target.value }))}
              />
            </label>
            <label>
              Rapor dosyası (isteğe bağlı)
              <input
                type="file"
                accept="application/pdf,image/jpeg,image/png,image/webp"
                data-testid="personel-talep-rapor-dosya"
                onChange={(event) => {
                  const file = event.target.files?.[0];
                  if (!file) {
                    setRaporForm((current) => ({
                      ...current,
                      dosya_adi: "",
                      dosya_mime: "",
                      dosya_icerik_base64: ""
                    }));
                    return;
                  }
                  const reader = new FileReader();
                  reader.onload = () => {
                    const result = typeof reader.result === "string" ? reader.result : "";
                    const base64 = result.includes(",") ? result.split(",")[1] ?? "" : result;
                    setRaporForm((current) => ({
                      ...current,
                      dosya_adi: file.name,
                      dosya_mime: file.type || "application/octet-stream",
                      dosya_icerik_base64: base64
                    }));
                  };
                  reader.readAsDataURL(file);
                }}
              />
            </label>
            <button type="submit" className="self-service-action">Rapor bildir</button>
            {raporlar.length > 0 ? (
              <ul data-testid="personel-talep-rapor-list">
                {raporlar.map((item) => (
                  <li key={item.id}>
                    {item.baslangic_tarihi}
                    {item.bitis_tarihi ? `–${item.bitis_tarihi}` : ""} · {statusLabel(item.state)}
                  </li>
                ))}
              </ul>
            ) : null}
          </form>
        </li>
        ) : null}
        {secili === "avans" ? (
        <li>
          <form className="pm-self-request" data-testid="personel-talep-avans" onSubmit={(event) => void submitAdvance(event)}>
            <p className="pm-self-request__title">Avans Talebi</p>
            <label>
              Tutar
              <input
                inputMode="decimal"
                required
                value={advanceForm.tutar}
                onChange={(event) => setAdvanceForm((current) => ({ ...current, tutar: event.target.value }))}
              />
            </label>
            <label>
              Tarih
              <input
                type="date"
                required
                value={advanceForm.talep_tarihi}
                onChange={(event) => setAdvanceForm((current) => ({ ...current, talep_tarihi: event.target.value }))}
              />
            </label>
            <label>
              Açıklama
              <textarea
                value={advanceForm.aciklama}
                onChange={(event) => setAdvanceForm((current) => ({ ...current, aciklama: event.target.value }))}
              />
            </label>
            <button type="submit" className="self-service-action">
              Avans talebi gönder
            </button>
            {advances.length > 0 ? (
              <ul data-testid="personel-talep-avans-list">
                {advances.map((item) => (
                  <li key={item.id}>
                    {item.talep_tarihi} · {item.tutar} · {statusLabel(item.durum)}
                    {item.sonuc ? ` · ${item.sonuc}` : ""}
                  </li>
                ))}
              </ul>
            ) : null}
          </form>
        </li>
        ) : null}
        {secili === "oneri" ? (
        <li>
          <form className="pm-self-request" data-testid="personel-talep-oneri" onSubmit={(event) => void submitFeedback(event)}>
            <p className="pm-self-request__title">Öneri / Şikâyet</p>
            <label>
              Tür
              <select
                value={feedbackForm.tur}
                onChange={(event) =>
                  setFeedbackForm((current) => ({
                    ...current,
                    tur: event.target.value === "SIKAYET" ? "SIKAYET" : "ONERI"
                  }))
                }
              >
                <option value="ONERI">Öneri</option>
                <option value="SIKAYET">Şikâyet</option>
              </select>
            </label>
            <label>
              Konu
              <input
                required
                value={feedbackForm.konu}
                onChange={(event) => setFeedbackForm((current) => ({ ...current, konu: event.target.value }))}
              />
            </label>
            <label>
              Açıklama
              <textarea
                required
                value={feedbackForm.aciklama}
                onChange={(event) => setFeedbackForm((current) => ({ ...current, aciklama: event.target.value }))}
              />
            </label>
            <button type="submit" className="self-service-action">
              Gönder
            </button>
            {feedback.length > 0 ? (
              <ul data-testid="personel-talep-oneri-list">
                {feedback.map((item) => (
                  <li key={item.id}>
                    {formatDisplayDateOrText(item.tarih)} · {item.tur === "SIKAYET" ? "Şikâyet" : "Öneri"} · {item.konu} ·{" "}
                    {statusLabel(item.durum)}
                    {item.sonuc ? ` · ${item.sonuc}` : ""}
                  </li>
                ))}
              </ul>
            ) : null}
          </form>
        </li>
        ) : null}
      </ul>
      ) : null}

      <AttendanceCorrectionRequestModal
        open={correctDraft !== null || anomalyDraft !== null}
        eventId={anomalyDraft?.source_event_id ?? correctDraft?.eventId ?? 0}
        eventType={
          anomalyDraft?.anomaly_type === "MISSING_CIKIS"
            ? "CIKIS"
            : anomalyDraft
              ? "GIRIS"
              : (correctDraft?.eventType ?? "GIRIS")
        }
        initialTime={anomalyDraft ? "" : (correctDraft?.currentTime ?? "")}
        anomaly={anomalyDraft}
        onClose={() => {
          setCorrectDraft(null);
          setAnomalyDraft(null);
        }}
        onSuccess={(message) => {
          setNotice(message);
          void load();
        }}
        onError={(message) => setNotice(message)}
      />

      <BackgroundlessNoticeModal
        open={notice !== null}
        title="Talep"
        body={notice ?? ""}
        onClose={() => setNotice(null)}
        testId="personel-talep-notice"
      />
    </section>
  );
}
