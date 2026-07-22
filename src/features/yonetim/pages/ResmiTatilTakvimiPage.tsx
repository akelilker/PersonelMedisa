import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { ApiRequestError } from "../../../api/api-client";
import {
  activateResmiTatilTakvimi,
  cancelResmiTatilTakvimi,
  createResmiTatilTakvimi,
  fetchResmiTatilEnvanterOzet,
  fetchResmiTatilHistory,
  fetchResmiTatilTakvimiList,
  previewResmiTatilProjection,
  reviseResmiTatilTakvimi,
  updateResmiTatilTakvimi,
  type ResmiTatilDurum,
  type ResmiTatilEnvanterOzet,
  type ResmiTatilGunKapsami,
  type ResmiTatilHistoryResponse,
  type ResmiTatilProjectionPreview,
  type ResmiTatilTakvimKaydi,
  type ResmiTatilTuru,
  type ResmiTatilUpsertPayload
} from "../../../api/resmi-tatil-takvimi.api";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";

const FORM_ID = "resmi-tatil-takvimi-form";
const IPTAL_FORM_ID = "resmi-tatil-iptal-form";

type FormMode = "create" | "edit" | "revise";

type FormState = {
  tarih: string;
  tatil_kodu: string;
  tatil_adi: string;
  tatil_turu: ResmiTatilTuru;
  gun_kapsami: ResmiTatilGunKapsami;
  tatil_interval_baslangic: string;
  tatil_interval_bitis: string;
  kaynak_turu: string;
  kaynak_referansi: string;
  kaynak_tarihi: string;
  aciklama: string;
  iptal_gerekcesi: string;
};

const EMPTY_FORM: FormState = {
  tarih: "",
  tatil_kodu: "",
  tatil_adi: "",
  tatil_turu: "UBGT",
  gun_kapsami: "TAM_GUN",
  tatil_interval_baslangic: "",
  tatil_interval_bitis: "",
  kaynak_turu: "",
  kaynak_referansi: "",
  kaynak_tarihi: "",
  aciklama: "",
  iptal_gerekcesi: ""
};

function formatDateOnly(value: string | null | undefined): string {
  if (!value) return "-";
  const parsed = Date.parse(`${value.slice(0, 10)}T00:00:00Z`);
  if (!Number.isFinite(parsed)) return value;
  return new Intl.DateTimeFormat("tr-TR", { dateStyle: "short", timeZone: "UTC" }).format(parsed);
}

function formatTime(value: string | null | undefined): string {
  if (!value) return "-";
  return value.slice(0, 5);
}

function apiErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiRequestError) {
    if (error.code === "TATIL_TAKVIM_AKTIF_CAKISMA") {
      return "Bu tarihte aktif UBGT kaydı zaten var.";
    }
    if (error.code === "VALIDATION_ERROR") {
      return error.message;
    }
    return error.message || fallback;
  }
  if (error instanceof Error) return error.message;
  return fallback;
}

function toPayload(form: FormState): ResmiTatilUpsertPayload {
  const isHalf = form.gun_kapsami === "YARIM_GUN";
  return {
    tarih: form.tarih,
    tatil_kodu: form.tatil_kodu.trim(),
    tatil_adi: form.tatil_adi.trim(),
    tatil_turu: form.tatil_turu,
    gun_kapsami: form.gun_kapsami,
    tatil_interval_baslangic: isHalf ? form.tatil_interval_baslangic || null : null,
    tatil_interval_bitis: isHalf ? form.tatil_interval_bitis || null : null,
    kaynak_turu: form.kaynak_turu.trim(),
    kaynak_referansi: form.kaynak_referansi.trim(),
    kaynak_tarihi: form.kaynak_tarihi || null,
    aciklama: form.aciklama.trim() || null
  };
}

function recordToForm(item: ResmiTatilTakvimKaydi): FormState {
  return {
    tarih: item.tarih.slice(0, 10),
    tatil_kodu: item.tatil_kodu,
    tatil_adi: item.tatil_adi,
    tatil_turu: item.tatil_turu,
    gun_kapsami: item.gun_kapsami,
    tatil_interval_baslangic: item.tatil_interval_baslangic?.slice(0, 5) ?? "",
    tatil_interval_bitis: item.tatil_interval_bitis?.slice(0, 5) ?? "",
    kaynak_turu: item.kaynak_turu,
    kaynak_referansi: item.kaynak_referansi,
    kaynak_tarihi: item.kaynak_tarihi?.slice(0, 10) ?? "",
    aciklama: item.aciklama ?? "",
    iptal_gerekcesi: ""
  };
}

export function ResmiTatilTakvimiPage() {
  const { hasPermission } = useRoleAccess();
  const canManage = hasPermission("resmi_tatil_takvimi.manage");

  const currentYear = new Date().getUTCFullYear();
  const [yil, setYil] = useState(String(currentYear));
  const [tarihBas, setTarihBas] = useState(`${currentYear}-01-01`);
  const [tarihBit, setTarihBit] = useState(`${currentYear}-12-31`);
  const [durum, setDurum] = useState<ResmiTatilDurum | "">("");
  const [gunKapsami, setGunKapsami] = useState<ResmiTatilGunKapsami | "">("");
  const [tatilTuru, setTatilTuru] = useState<ResmiTatilTuru | "">("");
  const [viewMode, setViewMode] = useState<"liste" | "takvim">("liste");

  const [items, setItems] = useState<ResmiTatilTakvimKaydi[]>([]);
  const [envanter, setEnvanter] = useState<ResmiTatilEnvanterOzet | null>(null);
  const [preview, setPreview] = useState<ResmiTatilProjectionPreview | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [formMode, setFormMode] = useState<FormMode | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [cancelTarget, setCancelTarget] = useState<ResmiTatilTakvimKaydi | null>(null);
  const [cancelGerekce, setCancelGerekce] = useState("");
  const [historyData, setHistoryData] = useState<ResmiTatilHistoryResponse | null>(null);

  const load = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const y = Number.parseInt(yil, 10) || currentYear;
      const [list, ozet, prev] = await Promise.all([
        fetchResmiTatilTakvimiList({
          durum,
          gun_kapsami: gunKapsami,
          tatil_turu: tatilTuru,
          tarih_bas: tarihBas,
          tarih_bit: tarihBit
        }),
        fetchResmiTatilEnvanterOzet(y, 1),
        previewResmiTatilProjection({
          tarih_bas: tarihBas,
          tarih_bit: tarihBit,
          preview_modu: "OZET"
        })
      ]);
      setItems(list);
      setEnvanter(ozet);
      setPreview(prev);
    } catch (error) {
      setErrorMessage(apiErrorMessage(error, "Resmî tatil takvimi yüklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }, [currentYear, durum, gunKapsami, tatilTuru, tarihBas, tarihBit, yil]);

  useEffect(() => {
    void load();
  }, [load]);

  const calendarBuckets = useMemo(() => {
    const map = new Map<string, ResmiTatilTakvimKaydi[]>();
    for (const item of items) {
      const key = item.tarih.slice(0, 10);
      const bucket = map.get(key) ?? [];
      bucket.push(item);
      map.set(key, bucket);
    }
    return Array.from(map.entries()).sort(([a], [b]) => a.localeCompare(b));
  }, [items]);

  function openCreate() {
    setForm(EMPTY_FORM);
    setFormMode("create");
    setEditingId(null);
    setSubmitError(null);
  }

  function openEdit(item: ResmiTatilTakvimKaydi) {
    setForm(recordToForm(item));
    setFormMode("edit");
    setEditingId(item.id);
    setSubmitError(null);
  }

  function openRevise(item: ResmiTatilTakvimKaydi) {
    setForm({ ...recordToForm(item), iptal_gerekcesi: "" });
    setFormMode("revise");
    setEditingId(item.id);
    setSubmitError(null);
  }

  function closeForm() {
    setFormMode(null);
    setEditingId(null);
    setForm(EMPTY_FORM);
    setSubmitError(null);
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (!canManage || !formMode) return;
    if (form.gun_kapsami === "YARIM_GUN") {
      if (!form.tatil_interval_baslangic || !form.tatil_interval_bitis) {
        setSubmitError("Yarım gün için başlangıç ve bitiş saati zorunludur.");
        return;
      }
      if (form.tatil_interval_baslangic >= form.tatil_interval_bitis) {
        setSubmitError("Bitiş saati başlangıçtan sonra olmalıdır.");
        return;
      }
    }
    if (formMode === "revise" && !form.iptal_gerekcesi.trim()) {
      setSubmitError("Revizyon gerekçesi zorunludur.");
      return;
    }

    setIsSubmitting(true);
    setSubmitError(null);
    setActionError(null);
    try {
      const payload = toPayload(form);
      if (formMode === "create") {
        await createResmiTatilTakvimi(payload);
        setActionMessage("Taslak oluşturuldu.");
      } else if (formMode === "edit" && editingId != null) {
        await updateResmiTatilTakvimi(editingId, payload);
        setActionMessage("Taslak güncellendi.");
      } else if (formMode === "revise" && editingId != null) {
        const next = await reviseResmiTatilTakvimi(editingId, {
          ...payload,
          iptal_gerekcesi: form.iptal_gerekcesi.trim()
        });
        setActionMessage(`Yeni revizyon oluşturuldu (rev ${next.revizyon_no}).`);
      }
      closeForm();
      await load();
    } catch (error) {
      setSubmitError(apiErrorMessage(error, "Kayıt kaydedilemedi."));
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleActivate(item: ResmiTatilTakvimKaydi) {
    if (!canManage) return;
    setActionError(null);
    try {
      await activateResmiTatilTakvimi(item.id);
      setActionMessage(`${item.tatil_adi} aktifleştirildi.`);
      await load();
    } catch (error) {
      setActionError(apiErrorMessage(error, "Aktifleştirme başarısız."));
    }
  }

  async function handleCancelSubmit(event: FormEvent) {
    event.preventDefault();
    if (!cancelTarget || !cancelGerekce.trim()) {
      setActionError("İptal gerekçesi zorunludur.");
      return;
    }
    setIsSubmitting(true);
    try {
      await cancelResmiTatilTakvimi(cancelTarget.id, cancelGerekce.trim());
      setCancelTarget(null);
      setCancelGerekce("");
      setActionMessage("Kayıt iptal edildi.");
      await load();
    } catch (error) {
      setActionError(apiErrorMessage(error, "İptal başarısız."));
    } finally {
      setIsSubmitting(false);
    }
  }

  async function openHistory(item: ResmiTatilTakvimKaydi) {
    setActionError(null);
    try {
      setHistoryData(await fetchResmiTatilHistory(item.id));
    } catch (error) {
      setActionError(apiErrorMessage(error, "Geçmiş yüklenemedi."));
    }
  }

  const sinif = envanter?.siniflandirma;
  const readinessCards = [
    { key: "aktif", label: "Aktif kayıt", value: envanter?.aktif ?? 0 },
    { key: "taslak", label: "Taslak kayıt", value: envanter?.taslak ?? 0 },
    { key: "tam", label: "TAM_GUN", value: sinif?.tam_gun ?? preview?.tam_gun ?? 0 },
    { key: "yarim", label: "YARIM_GUN", value: sinif?.yarim_gun ?? preview?.yarim_gun ?? 0 },
    { key: "kaynak", label: "KAYNAK_EKSIK", value: sinif?.kaynak_eksik ?? preview?.kaynak_eksik ?? 0 },
    { key: "bilinmiyor", label: "BILINMIYOR", value: sinif?.bilinmiyor ?? preview?.bilinmiyor ?? 0 },
    { key: "cakisma", label: "CAKISMA", value: sinif?.cakisma ?? preview?.cakisma ?? 0 },
    {
      key: "muhur",
      label: "Mühür projection eksik",
      value: preview?.muhur_projection_eksik ?? 0
    },
    {
      key: "interval",
      label: "Interval ölçümü eksik",
      value: preview?.interval_olcumu_eksik ?? 0
    },
    {
      key: "policy",
      label: "Policy activation blocker",
      value: sinif?.policy_activation_blocker ?? preview?.policy_blocker ?? 0
    }
  ];

  return (
    <section className="yonetim-page" data-testid="resmi-tatil-takvimi-page">
      <header className="yonetim-page-header">
        <div>
          <h1>Resmî Tatil Takvimi</h1>
          <p className="muted">
            UBGT gün kapsamı owner’ı. Production ödeme politikası henüz aktif değildir.
          </p>
        </div>
        {canManage ? (
          <button type="button" className="universal-btn-save" data-testid="rtt-create-btn" onClick={openCreate}>
            Yeni taslak
          </button>
        ) : null}
      </header>

      <div className="form-field-grid" data-testid="rtt-filters">
        <FormField
          label="Yıl"
          name="rtt-yil"
          type="number"
          value={yil}
          onChange={(value) => setYil(value)}
        />
        <FormField
          label="Tarih başlangıç"
          name="rtt-tarih-bas"
          type="date"
          value={tarihBas}
          onChange={(value) => setTarihBas(value)}
        />
        <FormField
          label="Tarih bitiş"
          name="rtt-tarih-bit"
          type="date"
          value={tarihBit}
          onChange={(value) => setTarihBit(value)}
        />
        <FormField
          label="Kapsam"
          name="rtt-kapsam"
          as="select"
          value={gunKapsami}
          onChange={(value) => setGunKapsami(value as ResmiTatilGunKapsami | "")}
          selectOptions={[
            { value: "", label: "Tümü" },
            { value: "TAM_GUN", label: "Tam gün" },
            { value: "YARIM_GUN", label: "Yarım gün" }
          ]}
        />
        <FormField
          label="Durum"
          name="rtt-durum"
          as="select"
          value={durum}
          onChange={(value) => setDurum(value as ResmiTatilDurum | "")}
          selectOptions={[
            { value: "", label: "Tümü" },
            { value: "TASLAK", label: "Taslak" },
            { value: "AKTIF", label: "Aktif" },
            { value: "IPTAL", label: "İptal" }
          ]}
        />
        <FormField
          label="Tatil türü"
          name="rtt-tur"
          as="select"
          value={tatilTuru}
          onChange={(value) => setTatilTuru(value as ResmiTatilTuru | "")}
          selectOptions={[
            { value: "", label: "Tümü" },
            { value: "UBGT", label: "UBGT" },
            { value: "DIGER", label: "Diğer" }
          ]}
        />
      </div>

      <section className="kapanis-issue-section" data-testid="rtt-readiness-cards">
        <h2>Readiness özeti</h2>
        <p className="muted" data-testid="rtt-policy-not-active">
          Policy activation: kapalı · Genel sistem tamamen hazır: hayır
        </p>
        <div className="form-field-grid">
          {readinessCards.map((card) => (
            <article key={card.key} className="yonetim-list-surface" data-testid={`rtt-card-${card.key}`}>
              <strong>{card.label}</strong>
              <p>{card.value}</p>
            </article>
          ))}
        </div>
        <div className="form-field-grid" data-testid="rtt-readiness-status">
          <article className="yonetim-list-surface">
            <strong>TAM_GUN aktivasyona hazır</strong>
            <p>{preview?.tam_gun_aktivasyona_hazir ?? 0}</p>
          </article>
          <article className="yonetim-list-surface">
            <strong>YARIM_GUN ödeme politikası bekliyor</strong>
            <p>{preview?.yarim_gun_odeme_politikasi_bekliyor ?? 0}</p>
          </article>
          <article className="yonetim-list-surface">
            <strong>Genel sistem tamamen hazır</strong>
            <p>Hayır</p>
          </article>
        </div>
        {(preview?.bilinmiyor ?? 0) > 0 || (preview?.cakisma ?? 0) > 0 ? (
          <p className="error-text" data-testid="rtt-blocker-message">
            BILINMIYOR / CAKISMA tespit edildi; payable hesap ve policy activation engellenir.
          </p>
        ) : null}
      </section>

      <div className="yonetim-tab-row">
        <button
          type="button"
          data-testid="rtt-view-liste"
          className={viewMode === "liste" ? "is-active" : undefined}
          onClick={() => setViewMode("liste")}
        >
          Liste
        </button>
        <button
          type="button"
          data-testid="rtt-view-takvim"
          className={viewMode === "takvim" ? "is-active" : undefined}
          onClick={() => setViewMode("takvim")}
        >
          Takvim
        </button>
      </div>

      {actionMessage ? <p data-testid="rtt-action-message">{actionMessage}</p> : null}
      {actionError ? (
        <p className="error-text" data-testid="rtt-action-error">
          {actionError}
        </p>
      ) : null}

      {isLoading ? <LoadingState label="Takvim yükleniyor..." /> : null}
      {errorMessage ? <ErrorState message={errorMessage} onRetry={() => void load()} /> : null}

      {!isLoading && !errorMessage && items.length === 0 ? (
        <EmptyState title="Kayıt yok" message="Seçili filtrelerde resmî tatil kaydı bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && viewMode === "liste" && items.length > 0 ? (
        <div className="table-scroll yonetim-list-surface" data-testid="rtt-list">
          <table className="data-table">
            <thead>
              <tr>
                <th>Tarih</th>
                <th>Ad</th>
                <th>Kod</th>
                <th>Tür</th>
                <th>Kapsam</th>
                <th>Durum</th>
                <th>Rev</th>
                <th>Kaynak</th>
                <th>İşlem</th>
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} data-testid={`rtt-row-${item.tatil_kodu}-${item.revizyon_no}`}>
                  <td data-testid={`rtt-date-${item.id}`}>{formatDateOnly(item.tarih)}</td>
                  <td>{item.tatil_adi}</td>
                  <td>{item.tatil_kodu}</td>
                  <td>{item.tatil_turu}</td>
                  <td>
                    <span data-testid={`rtt-badge-kapsam-${item.id}`}>{item.gun_kapsami}</span>
                    {item.gun_kapsami === "YARIM_GUN"
                      ? ` (${formatTime(item.tatil_interval_baslangic)}–${formatTime(item.tatil_interval_bitis)})`
                      : null}
                  </td>
                  <td>
                    <span data-testid={`rtt-badge-durum-${item.id}`}>{item.durum}</span>
                  </td>
                  <td>{item.revizyon_no}</td>
                  <td>
                    {item.kaynak_turu} / {item.kaynak_referansi}
                  </td>
                  <td>
                    <button type="button" data-testid={`rtt-history-${item.id}`} onClick={() => void openHistory(item)}>
                      Geçmiş
                    </button>
                    {canManage && item.durum === "TASLAK" ? (
                      <>
                        <button type="button" data-testid={`rtt-edit-${item.id}`} onClick={() => openEdit(item)}>
                          Düzenle
                        </button>
                        <button
                          type="button"
                          data-testid={`rtt-activate-${item.id}`}
                          onClick={() => void handleActivate(item)}
                        >
                          Aktifleştir
                        </button>
                      </>
                    ) : null}
                    {canManage && item.durum === "AKTIF" ? (
                      <button type="button" data-testid={`rtt-revise-${item.id}`} onClick={() => openRevise(item)}>
                        Revize
                      </button>
                    ) : null}
                    {canManage && item.durum !== "IPTAL" ? (
                      <button
                        type="button"
                        data-testid={`rtt-cancel-${item.id}`}
                        onClick={() => {
                          setCancelTarget(item);
                          setCancelGerekce("");
                        }}
                      >
                        İptal
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {!isLoading && !errorMessage && viewMode === "takvim" ? (
        <div className="yonetim-list-surface" data-testid="rtt-calendar">
          {calendarBuckets.map(([date, rows]) => (
            <article key={date} data-testid={`rtt-cal-${date}`}>
              <h3>{formatDateOnly(date)}</h3>
              <ul>
                {rows.map((row) => (
                  <li key={row.id}>
                    {row.tatil_adi} · {row.gun_kapsami} · {row.durum} · rev {row.revizyon_no}
                  </li>
                ))}
              </ul>
            </article>
          ))}
        </div>
      ) : null}

      {canManage && formMode ? (
        <AppModal
          title={
            formMode === "create" ? "Yeni taslak" : formMode === "edit" ? "Taslak düzenle" : "Aktif kaydı revize et"
          }
          onClose={closeForm}
          footer={
            <>
              <button type="button" className="universal-btn-cancel" onClick={closeForm}>
                Vazgeç
              </button>
              <button
                type="submit"
                form={FORM_ID}
                className="universal-btn-save"
                data-testid="rtt-submit"
                disabled={isSubmitting}
              >
                Kaydet
              </button>
            </>
          }
        >
          <form id={FORM_ID} className="workspace-form" onSubmit={handleSubmit} data-testid="rtt-form">
            <div className="form-field-grid">
              <FormField
                label="Tarih"
                name="rtt-form-tarih"
                type="date"
                required
                value={form.tarih}
                onChange={(value) => setForm((prev) => ({ ...prev, tarih: value }))}
              />
              <FormField
                label="Tatil kodu"
                name="rtt-form-kod"
                required
                value={form.tatil_kodu}
                onChange={(value) => setForm((prev) => ({ ...prev, tatil_kodu: value }))}
              />
              <FormField
                label="Tatil adı"
                name="rtt-form-adi"
                required
                value={form.tatil_adi}
                onChange={(value) => setForm((prev) => ({ ...prev, tatil_adi: value }))}
              />
              <FormField
                label="Tatil türü"
                name="rtt-form-tur"
                as="select"
                value={form.tatil_turu}
                onChange={(value) => setForm((prev) => ({ ...prev, tatil_turu: value as ResmiTatilTuru }))}
                selectOptions={[
                  { value: "UBGT", label: "UBGT" },
                  { value: "DIGER", label: "Diğer" }
                ]}
              />
              <FormField
                label="Gün kapsamı"
                name="rtt-form-kapsam"
                as="select"
                value={form.gun_kapsami}
                onChange={(value) => setForm((prev) => ({ ...prev, gun_kapsami: value as ResmiTatilGunKapsami, tatil_interval_baslangic: value === "TAM_GUN" ? "" : prev.tatil_interval_baslangic, tatil_interval_bitis: value === "TAM_GUN" ? "" : prev.tatil_interval_bitis }))}
                selectOptions={[
                  { value: "TAM_GUN", label: "Tam gün" },
                  { value: "YARIM_GUN", label: "Yarım gün" }
                ]}
              />
              {form.gun_kapsami === "YARIM_GUN" ? (
                <>
                  <FormField
                    label="Interval başlangıç"
                    name="rtt-form-interval-bas"
                    type="time"
                    required
                    value={form.tatil_interval_baslangic}
                    onChange={(value) => setForm((prev) => ({ ...prev, tatil_interval_baslangic: value }))}
                  />
                  <FormField
                    label="Interval bitiş"
                    name="rtt-form-interval-bit"
                    type="time"
                    required
                    value={form.tatil_interval_bitis}
                    onChange={(value) => setForm((prev) => ({ ...prev, tatil_interval_bitis: value }))}
                  />
                </>
              ) : null}
              <FormField
                label="Kaynak türü"
                name="rtt-form-kaynak-tur"
                required
                value={form.kaynak_turu}
                onChange={(value) => setForm((prev) => ({ ...prev, kaynak_turu: value }))}
              />
              <FormField
                label="Kaynak referansı"
                name="rtt-form-kaynak-ref"
                required
                value={form.kaynak_referansi}
                onChange={(value) => setForm((prev) => ({ ...prev, kaynak_referansi: value }))}
              />
              <FormField
                label="Kaynak tarihi"
                name="rtt-form-kaynak-tarih"
                type="date"
                value={form.kaynak_tarihi}
                onChange={(value) => setForm((prev) => ({ ...prev, kaynak_tarihi: value }))}
              />
              <FormField
                label="Açıklama"
                name="rtt-form-aciklama"
                value={form.aciklama}
                onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
              />
              {formMode === "revise" ? (
                <FormField
                  label="Revizyon gerekçesi"
                  name="rtt-form-revize-gerekce"
                  required
                  value={form.iptal_gerekcesi}
                  onChange={(value) => setForm((prev) => ({ ...prev, iptal_gerekcesi: value }))}
                />
              ) : null}
            </div>
            {submitError ? (
              <p className="error-text" data-testid="rtt-submit-error">
                {submitError}
              </p>
            ) : null}
          </form>
        </AppModal>
      ) : null}

      {canManage && cancelTarget ? (
        <AppModal
          title="Kaydı iptal et"
          onClose={() => setCancelTarget(null)}
          footer={
            <>
              <button type="button" className="universal-btn-cancel" onClick={() => setCancelTarget(null)}>
                Vazgeç
              </button>
              <button type="submit" form={IPTAL_FORM_ID} className="universal-btn-save" data-testid="rtt-cancel-submit">
                İptal et
              </button>
            </>
          }
        >
          <form id={IPTAL_FORM_ID} onSubmit={handleCancelSubmit} data-testid="rtt-cancel-form">
            <p>{cancelTarget.tatil_adi} iptal edilecek. Hard delete yoktur.</p>
            <FormField
              label="İptal gerekçesi"
              name="rtt-iptal-gerekce"
              required
              value={cancelGerekce}
              onChange={(value) => setCancelGerekce(value)}
            />
          </form>
        </AppModal>
      ) : null}

      {historyData ? (
        <AppModal title="Revizyon geçmişi" onClose={() => setHistoryData(null)} footer={null}>
          <div data-testid="rtt-history-modal">
            <h3>Kayıt zinciri</h3>
            <ul>
              {historyData.items.map((item) => (
                <li key={item.id} data-testid={`rtt-history-item-rev-${item.revizyon_no}`}>
                  Rev {item.revizyon_no} · {item.durum} · {item.gun_kapsami} · {item.kaynak_referansi}
                </li>
              ))}
            </ul>
            <h3>Audit</h3>
            <ul>
              {historyData.auditler.map((audit) => (
                <li key={audit.id}>
                  {audit.aksiyon} · {audit.actor_rol ?? "-"} · {audit.created_at}
                </li>
              ))}
            </ul>
          </div>
        </AppModal>
      ) : null}

      <p className="muted" data-testid="rtt-preview-readonly">
        Projection preview read-only · puantaj/mühür yazılmaz
      </p>
    </section>
  );
}
