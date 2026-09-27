import { useCallback, useEffect, useState, type FormEvent } from "react";
import {
  fetchAttendanceToday,
  type AttendanceBoxEvent,
  type AttendanceTodayResponse
} from "../../../api/attendance-mobile.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import {
  createSelfAvansTalebi,
  createSelfGeriBildirim,
  createSelfIzinTalebi,
  fetchSelfAvansTalepleri,
  fetchSelfCorrectionRequests,
  fetchSelfGeriBildirimler,
  fetchSelfIzinler,
  type SelfAvansTalep,
  type SelfCorrectionRequest,
  type SelfGeriBildirim,
  type SelfIzinKaydi
} from "../../../api/self-product.api";
import { LoadingState } from "../../../components/states/LoadingState";
import { formatSurecStateLabel, formatSurecTuruLabel } from "../../../lib/display/enum-display";
import { AttendanceCorrectionRequestModal } from "../components/AttendanceCorrectionRequestModal";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";
import { PersonelMobileCapabilityService } from "../personel-mobile-capability";

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

export function PersonelSelfServiceTaleplerPage() {
  const [loading, setLoading] = useState(true);
  const [today, setToday] = useState<AttendanceTodayResponse | null>(null);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [correctDraft, setCorrectDraft] = useState<CorrectDraft | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [corrections, setCorrections] = useState<SelfCorrectionRequest[]>([]);
  const [leaves, setLeaves] = useState<SelfIzinKaydi[]>([]);
  const [advances, setAdvances] = useState<SelfAvansTalep[]>([]);
  const [feedback, setFeedback] = useState<SelfGeriBildirim[]>([]);
  const [leaveForm, setLeaveForm] = useState({
    izin_turu: "YILLIK_IZIN",
    baslangic_tarihi: "",
    bitis_tarihi: "",
    aciklama: ""
  });
  const [advanceForm, setAdvanceForm] = useState({ tutar: "", talep_tarihi: "", aciklama: "" });
  const [feedbackForm, setFeedbackForm] = useState({
    tur: "ONERI" as "ONERI" | "SIKAYET",
    konu: "",
    aciklama: ""
  });

  const load = useCallback(async () => {
    if (shouldPreferDemoApi()) {
      setLoading(false);
      setToday(null);
      setLoadError("Demo modda düzeltme talebi yok.");
      return;
    }
    setLoading(true);
    try {
      const [attendance, correctionList, izinList, avansList, feedbackList] = await Promise.all([
        fetchAttendanceToday(),
        fetchSelfCorrectionRequests().catch(() => []),
        fetchSelfIzinler().catch(() => null),
        fetchSelfAvansTalepleri().catch(() => []),
        fetchSelfGeriBildirimler().catch(() => [])
      ]);
      setToday(attendance);
      setCorrections(correctionList);
      setLeaves([...(izinList?.aktif ? [izinList.aktif] : []), ...(izinList?.gecmis ?? [])]);
      setAdvances(avansList);
      setFeedback(feedbackList);
      setLoadError(null);
    } catch (cause) {
      setToday(null);
      setLoadError(
        isApiRequestError(cause) && cause.code === "SELF_SERVICE_BINDING_REQUIRED"
          ? "Personel bağlantınız yok."
          : "Talepler yüklenemedi. Tekrar deneyin."
      );
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

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-talepler-page">
      <ul className="pm-self-request-list">
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
                  <li key={item.id}>
                    {item.tarih} · {correctionKind(item.talep_turu)} · {statusLabel(item.durum)}
                    {item.aciklama ? ` · ${item.aciklama}` : ""}
                    {item.sonuc ? ` · ${item.sonuc}` : ""}
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        </li>
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
                    {item.tarih} · {item.tur === "SIKAYET" ? "Şikâyet" : "Öneri"} · {item.konu} ·{" "}
                    {statusLabel(item.durum)}
                    {item.sonuc ? ` · ${item.sonuc}` : ""}
                  </li>
                ))}
              </ul>
            ) : null}
          </form>
        </li>
      </ul>

      <AttendanceCorrectionRequestModal
        open={correctDraft !== null}
        eventId={correctDraft?.eventId ?? 0}
        eventType={correctDraft?.eventType ?? "GIRIS"}
        initialTime={correctDraft?.currentTime ?? ""}
        onClose={() => setCorrectDraft(null)}
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
