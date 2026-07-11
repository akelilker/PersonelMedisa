import { useEffect, useMemo, useRef, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useBildirimler } from "../../../hooks/useBildirimler";
import { useHaftalikBildirimMutabakat } from "../../../hooks/useHaftalikBildirimMutabakat";
import {
  canCancelGunlukBildirim,
  canEditGunlukBildirim,
  canRequestCorrectionGunlukBildirim,
  canSubmitGunlukBildirim
} from "../../../lib/bildirim/gunluk-bildirim-actions";
import {
  computeHaftaBitisFromMonday,
  isHaftalikMutabakatApproveEnabled,
  isMondayIsoDate,
  resolveHaftalikMutabakatStatusMessage
} from "../../../lib/bildirim/haftalik-mutabakat";
import {
  formatBildirimStateLabel
} from "../../../lib/display/enum-display";
import type { Bildirim } from "../../../types/bildirim";
import type { HaftalikBildirimMutabakatCounts } from "../../../types/haftalik-bildirim-mutabakat";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import {
  formatGunlukKayitDayanak,
  formatGunlukKayitGunTipi,
  formatGunlukKayitHareketDurumu,
  formatGunlukKayitHesapEtkisi,
  resolveGunlukKayitPreset,
  type GunlukKayitOption,
  type GunlukKayitPreset
} from "../gunluk-kayit-presets";

const GUNLUK_KAYIT_CREATE_FORM_ID = "gunluk-kayit-create-form";
const GUNLUK_KAYIT_EDIT_FORM_ID = "gunluk-kayit-edit-form";
const GUNLUK_KAYIT_CORRECTION_FORM_ID = "gunluk-kayit-correction-form";
const KAYIT_SENARYOSU_LABEL = "Kayıt Senaryosu";

function digitsOnly(value: string | null | undefined) {
  return (value ?? "").replace(/\D+/g, "");
}

function buildTelHref(value: string | null | undefined) {
  const digits = digitsOnly(value);
  return digits ? `tel:${digits}` : null;
}

function formatPersonelOptionLabel(personel: Personel) {
  const title = `${personel.ad} ${personel.soyad}`;
  const meta = [personel.departman_adi, personel.gorev_adi, personel.telefon]
    .filter(Boolean)
    .join(" | ");
  return meta ? `${title} | ${meta}` : title;
}

function formatDepartmanLabel(
  departmanId: number | undefined,
  departmanLabel: string | undefined,
  departmanOptions: IdOption[]
) {
  if (departmanLabel) {
    return departmanLabel;
  }

  if (typeof departmanId !== "number") {
    return "-";
  }

  return departmanOptions.find((option) => option.id === departmanId)?.label ?? `#${departmanId}`;
}

type PersonelContextCardProps = {
  personel: Personel | null;
};

function PersonelContextCard({ personel }: PersonelContextCardProps) {
  if (!personel) {
    return null;
  }

  const personelCallHref = buildTelHref(personel.telefon);
  const emergencyCallHref = buildTelHref(personel.acil_durum_telefon);

  return (
    <div className="bildirim-personel-context">
      <strong>
        {personel.ad} {personel.soyad}
      </strong>
      <p>
        Bölüm: {personel.departman_adi ?? "-"}
        {personel.gorev_adi ? ` | Görev: ${personel.gorev_adi}` : ""}
      </p>
      <p>
        Telefon: {personel.telefon ?? "-"}
        {personel.kan_grubu ? ` | Kan Grubu: ${personel.kan_grubu}` : ""}
      </p>
      <p>
        Acil Durum: {personel.acil_durum_kisi ?? "-"}
        {personel.acil_durum_telefon ? ` | ${personel.acil_durum_telefon}` : ""}
      </p>
      {personelCallHref || emergencyCallHref ? (
        <div className="module-item-actions">
          {personelCallHref ? (
            <a className="universal-btn-aux" href={personelCallHref}>
              Personeli Ara
            </a>
          ) : null}
          {emergencyCallHref ? (
            <a className="universal-btn-aux" href={emergencyCallHref}>
              Acil Ara
            </a>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

type GunlukKayitModelCardProps = {
  preset: GunlukKayitPreset;
  title?: string;
};

function GunlukKayitModelCard({ preset, title = "Puantaj Yansıması" }: GunlukKayitModelCardProps) {
  return (
    <div className="bildirim-model-panel">
      <div className="bildirim-model-panel-head">
        <strong>{title}</strong>
        <span>{preset.label}</span>
      </div>
      <div className="bildirim-model-grid">
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Gün Tipi</span>
          <strong>{formatGunlukKayitGunTipi(preset.gunTipi)}</strong>
        </div>
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Hareket Durumu</span>
          <strong>{formatGunlukKayitHareketDurumu(preset.hareketDurumu)}</strong>
        </div>
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Dayanak</span>
          <strong>{formatGunlukKayitDayanak(preset.dayanak)}</strong>
        </div>
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Hesap Etkisi</span>
          <strong>{formatGunlukKayitHesapEtkisi(preset.hesapEtkisi)}</strong>
        </div>
      </div>
      <p className="bildirim-model-note">{preset.aciklama}</p>
    </div>
  );
}

function toSelectOptions(options: GunlukKayitOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

type KayitSenaryosuChoiceGroupProps = {
  name: string;
  value: string;
  options: GunlukKayitOption[];
  onSelect: (value: string) => void;
};

function KayitSenaryosuChoiceGroup({ name, value, options, onSelect }: KayitSenaryosuChoiceGroupProps) {
  return (
    <div className="form-section bildirim-kayit-senaryosu-field">
      <span className="form-label">{KAYIT_SENARYOSU_LABEL}</span>
      <div className="bildirim-kayit-senaryosu-group" role="group" aria-label={KAYIT_SENARYOSU_LABEL}>
        {options.map((option) => {
          const isActive = option.key === value;

          return (
            <button
              key={`${name}-${option.key}`}
              type="button"
              className={`bildirim-kayit-senaryosu-btn${isActive ? " is-active" : ""}`}
              aria-pressed={isActive}
              onClick={() => onSelect(option.key)}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

const HAFTALIK_MUTABAKAT_COUNT_LABELS: Array<{
  key: keyof HaftalikBildirimMutabakatCounts;
  label: string;
}> = [
  { key: "toplam", label: "Toplam" },
  { key: "taslak", label: "Taslak" },
  { key: "gonderildi", label: "Gönderildi" },
  { key: "duzeltme_istendi", label: "Düzeltme İstendi" },
  { key: "haftalik_mutabakata_alindi", label: "Mutabakata Alındı" },
  { key: "iptal", label: "İptal" }
];

type HaftalikMutabakatPanelProps = {
  canApprove: boolean;
  onWeekApplied: (baslangic: string, bitis: string) => void;
  onApproved: () => void | Promise<void>;
};

function HaftalikMutabakatPanel({ canApprove, onWeekApplied, onApproved }: HaftalikMutabakatPanelProps) {
  const userSelectedWeekRef = useRef(false);
  const {
    haftaBaslangic,
    setHaftaBaslangic,
    haftaBitis,
    ozet,
    isLoading,
    error,
    weekWarning,
    approveWeek,
    isApproving
  } = useHaftalikBildirimMutabakat({ onApproved });

  useEffect(() => {
    if (!userSelectedWeekRef.current || !ozet || !isMondayIsoDate(haftaBaslangic)) {
      return;
    }

    onWeekApplied(ozet.hafta_baslangic, ozet.hafta_bitis);
  }, [haftaBaslangic, onWeekApplied, ozet]);

  const handleWeekChange = (value: string) => {
    userSelectedWeekRef.current = true;
    setHaftaBaslangic(value);
  };

  const statusMessage = resolveHaftalikMutabakatStatusMessage(ozet);
  const approveEnabled = isHaftalikMutabakatApproveEnabled(canApprove, ozet);
  const displayedWeekEnd =
    haftaBitis ?? (isMondayIsoDate(haftaBaslangic) ? computeHaftaBitisFromMonday(haftaBaslangic) : null);

  return (
    <div className="state-card bildirim-mutabakat-panel" data-testid="haftalik-mutabakat-panel">
      <div className="bildirim-mutabakat-panel-head">
        <h3>Haftalık Mutabakat</h3>
        {displayedWeekEnd ? (
          <p className="bildirim-mutabakat-meta">Hafta bitişi: {displayedWeekEnd}</p>
        ) : null}
      </div>

      <FormField
        label="Hafta Başlangıcı"
        name="haftalik-mutabakat-hafta-baslangic"
        type="date"
        value={haftaBaslangic}
        onChange={handleWeekChange}
      />

      {weekWarning ? <p className="bildirim-form-error">{weekWarning}</p> : null}
      {isLoading ? <LoadingState label="Haftalık mutabakat özeti yükleniyor..." /> : null}
      {!isLoading && error ? <p className="bildirim-form-error">{error}</p> : null}

      {!isLoading && ozet ? (
        <>
          <div className="bildirim-model-grid" data-testid="haftalik-mutabakat-counts">
            {HAFTALIK_MUTABAKAT_COUNT_LABELS.map(({ key, label }) => (
              <div key={key} className="bildirim-model-card" data-testid={`haftalik-mutabakat-count-${key}`}>
                <span className="bildirim-model-label">{label}</span>
                <strong>{ozet.counts[key]}</strong>
              </div>
            ))}
          </div>

          {statusMessage ? (
            <p className="bildirim-mutabakat-status" data-testid="haftalik-mutabakat-status">
              {statusMessage}
            </p>
          ) : null}

          {typeof ozet.mevcut_mutabakat_id === "number" ? (
            <p className="bildirim-mutabakat-meta" data-testid="haftalik-mutabakat-id">
              Mutabakat ID: {ozet.mevcut_mutabakat_id}
            </p>
          ) : null}

          {canApprove ? (
            <div className="bildirim-mutabakat-actions">
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="haftalik-mutabakat-approve"
                onClick={() => void approveWeek()}
                disabled={!approveEnabled || isApproving || Boolean(weekWarning)}
              >
                {isApproving ? "Onaylanıyor..." : "Haftayı Onayla"}
              </button>
            </div>
          ) : null}
        </>
      ) : null}
    </div>
  );
}

export function BildirimlerPage() {
  const {
    listQuery,
    updateDraft,
    bildirimler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    departmanOptions,
    gunlukKayitOptions,
    personelOptions,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    createPreview,
    setCreateForm,
    createErrorMessage,
    isCreateSubmitting,
    createBildirimHandler,
    editingBildirim,
    openEditModal,
    closeEditModal,
    editForm,
    editPreview,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    updateBildirimHandler,
    cancelingBildirimId,
    cancelBildirimHandler,
    submittingBildirimId,
    submitBildirimHandler,
    correctingBildirim,
    openCorrectionModal,
    closeCorrectionModal,
    correctionReason,
    setCorrectionReason,
    correctionErrorMessage,
    isCorrectionSubmitting,
    requestCorrectionHandler,
    currentUserId,
    submitFilters,
    clearFilters,
    applyWeekRange,
    setPage
  } = useBildirimler();

  const { hasPermission, uiProfile } = useRoleAccess();
  const canCreateBildirim = hasPermission("gunluk_bildirim.create");
  const canOpenBildirimDetail = hasPermission("bildirimler.detail.view");
  const canViewHaftalikMutabakat = hasPermission("haftalik_mutabakat.view");
  const canApproveHaftalikMutabakat = hasPermission("haftalik_mutabakat.approve");
  const isBirimAmiri = uiProfile === "birim_amiri";
  const location = useLocation();
  const navigate = useNavigate();

  const { draft } = listQuery;
  const page = listQuery.page;

  const personelMap = useMemo(
    () => new Map(personelOptions.map((personel) => [personel.id, personel])),
    [personelOptions]
  );

  const personelSelectOptions = useMemo(
    () =>
      personelOptions.map((personel) => ({
        value: String(personel.id),
        label: formatPersonelOptionLabel(personel)
      })),
    [personelOptions]
  );

  const selectedCreatePersonel = useMemo(() => {
    const personelId = Number.parseInt(createForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [createForm.personelId, personelMap]);

  const selectedEditPersonel = useMemo(() => {
    const personelId = Number.parseInt(editForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [editForm.personelId, personelMap]);

  useEffect(() => {
    const currentState = (location.state ?? null) as Record<string, unknown> | null;
    const prefillPersonelId =
      typeof currentState?.prefillPersonelId === "number"
        ? String(currentState.prefillPersonelId)
        : typeof currentState?.prefillPersonelId === "string"
          ? currentState.prefillPersonelId
          : "";

    if (!currentState?.openCreateModal && !prefillPersonelId) {
      return;
    }

    if (prefillPersonelId) {
      updateDraft({ personelId: prefillPersonelId });
    }

    if (canCreateBildirim && (currentState?.openCreateModal || prefillPersonelId)) {
      openCreateModal();
      if (prefillPersonelId) {
        const selected = personelOptions.find((option) => String(option.id) === prefillPersonelId);
        setCreateForm((prev) => ({
          ...prev,
          personelId: prefillPersonelId,
          departmanId:
            typeof selected?.departman_id === "number" ? String(selected.departman_id) : prev.departmanId
        }));
      }
    }

    const nextState = { ...currentState };
    delete nextState.openCreateModal;
    delete nextState.prefillPersonelId;

    navigate(location.pathname, {
      replace: true,
      state: Object.keys(nextState).length > 0 ? nextState : null
    });
  }, [
    canCreateBildirim,
    location.pathname,
    location.state,
    navigate,
    openCreateModal,
    personelOptions,
    setCreateForm,
    updateDraft
  ]);

  function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createBildirimHandler(event, canCreateBildirim);
  }

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    if (!editingBildirim) {
      return;
    }
    void updateBildirimHandler(
      event,
      canEditGunlukBildirim(editingBildirim, hasPermission, currentUserId)
    );
  }

  function handleCreatePersonelChange(value: string) {
    const selected = personelOptions.find((option) => String(option.id) === value);
    setCreateForm((prev) => ({
      ...prev,
      personelId: value,
      departmanId:
        typeof selected?.departman_id === "number"
          ? String(selected.departman_id)
          : value
            ? prev.departmanId
            : ""
    }));
  }

  const createTitle = isBirimAmiri ? "Günlük Kayıt Gir" : "Yeni Günlük Kayıt";
  const createButtonLabel = isBirimAmiri ? "Günlük Kayıt Gir" : "Yeni Günlük Kayıt";

  return (
    <section className="bildirimler-page">
      <div className="bildirimler-header-row">
        <h2>Günlük Kayıt Merkezi</h2>
        {canCreateBildirim ? (
          <button type="button" className="universal-btn-aux" onClick={openCreateModal}>
            {createButtonLabel}
          </button>
        ) : null}
      </div>

      <div className="state-card">
        <h3>Günlük Kayıt Akışı</h3>
        <p>
          Bu ekran, puantaj ham verisini hızlı toplamak için kullanılır. Kayıt senaryosu seçilir,
          personel ve tarih belirlenir, sistem puantaj tarafına gidecek temel hareket ve dayanak
          bilgisini aynı kayıtta toplar.
        </p>
      </div>

      <SubeDetailListNotice />

      {canViewHaftalikMutabakat ? (
        <HaftalikMutabakatPanel
          canApprove={canApproveHaftalikMutabakat}
          onWeekApplied={applyWeekRange}
          onApproved={refetch}
        />
      ) : null}

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          {personelSelectOptions.length > 0 ? (
            <FormField
              as="select"
              label="Personel"
              name="bildirim-filter-personel"
              value={draft.personelId}
              onChange={(value) => updateDraft({ personelId: value })}
              placeholderOption={{ value: "", label: "Tümü" }}
              selectOptions={personelSelectOptions}
            />
          ) : (
            <FormField
              label="Personel ID"
              name="bildirim-filter-personel"
              type="number"
              min={1}
              value={draft.personelId}
              onChange={(value) => updateDraft({ personelId: value })}
            />
          )}
          {gunlukKayitOptions.length > 0 ? (
            <FormField
              as="select"
              label="Kayıt Senaryosu"
              name="bildirim-filter-turu"
              value={draft.bildirimTuru}
              onChange={(value) => updateDraft({ bildirimTuru: value })}
              placeholderOption={{ value: "", label: "Tümü" }}
              selectOptions={toSelectOptions(gunlukKayitOptions)}
            />
          ) : (
            <FormField
              label="Kayıt Senaryosu"
              name="bildirim-filter-turu-text"
              placeholder="Örn. GEC_GELDI, GELMEDI..."
              value={draft.bildirimTuru}
              onChange={(value) => updateDraft({ bildirimTuru: value })}
            />
          )}
          <FormField
            label="Tarih"
            name="bildirim-filter-tarih"
            type="date"
            value={draft.tarih}
            onChange={(value) => updateDraft({ tarih: value })}
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux">
            Filtrele
          </button>
          <button type="button" className="universal-btn-aux" onClick={clearFilters}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Günlük kayıt verileri yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length === 0 ? (
        <EmptyState
          title="Günlük kayıt bulunamadı"
          message="Seçilen tarih veya filtre için günlük kayıt bulunmuyor."
        />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length > 0 ? (
        <ul className="bildirimler-list">
          {bildirimler.map((bildirim: Bildirim) => {
            const personel =
              typeof bildirim.personel_id === "number" ? personelMap.get(bildirim.personel_id) ?? null : null;
            const personelCallHref = buildTelHref(personel?.telefon);
            const emergencyCallHref = buildTelHref(personel?.acil_durum_telefon);
            const preset = resolveGunlukKayitPreset(bildirim.bildirim_turu);
            const canEditRow = canEditGunlukBildirim(bildirim, hasPermission, currentUserId);
            const canCancelRow = canCancelGunlukBildirim(bildirim, hasPermission, currentUserId);
            const canSubmitRow = canSubmitGunlukBildirim(bildirim, hasPermission, currentUserId);
            const canRequestCorrectionRow = canRequestCorrectionGunlukBildirim(bildirim, hasPermission);
            const rowBusy =
              cancelingBildirimId === bildirim.id || submittingBildirimId === bildirim.id;

            return (
              <li key={bildirim.id} className="bildirimler-item">
                <div className="bildirimler-item-content">
                  <strong>{preset.label}</strong>
                  <p>Kayıt Durumu: {formatBildirimStateLabel(bildirim.state)}</p>
                  <p>Tarih: {bildirim.tarih ?? "-"}</p>
                  <p>Personel: {personel ? `${personel.ad} ${personel.soyad}` : bildirim.personel_id ?? "-"}</p>
                  <p>
                    Bölüm:{" "}
                    {formatDepartmanLabel(
                      bildirim.departman_id,
                      personel?.departman_adi,
                      departmanOptions
                    )}
                  </p>
                  {personel?.telefon ? <p>Telefon: {personel.telefon}</p> : null}
                  {bildirim.aciklama ? <p>Açıklama: {bildirim.aciklama}</p> : null}
                  <GunlukKayitModelCard preset={preset} title="Puantaj Katmanı" />
                </div>

                <div className="module-item-actions">
                  {personelCallHref ? (
                    <a className="universal-btn-aux" href={personelCallHref}>
                      Ara
                    </a>
                  ) : null}
                  {emergencyCallHref ? (
                    <a className="universal-btn-aux" href={emergencyCallHref}>
                      Acil Ara
                    </a>
                  ) : null}
                  {canOpenBildirimDetail ? (
                    <Link to={`/bildirimler/${bildirim.id}`} className="universal-btn-aux">
                      Detay
                    </Link>
                  ) : null}
                  {canEditRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(bildirim, true)}
                      disabled={rowBusy}
                    >
                      Düzenle
                    </button>
                  ) : null}
                  {canSubmitRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void submitBildirimHandler(bildirim)}
                      disabled={rowBusy}
                    >
                      {submittingBildirimId === bildirim.id ? "Gönderiliyor..." : "Gönder"}
                    </button>
                  ) : null}
                  {canRequestCorrectionRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openCorrectionModal(bildirim)}
                      disabled={rowBusy}
                    >
                      Düzeltme iste
                    </button>
                  ) : null}
                  {canCancelRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void cancelBildirimHandler(bildirim, true)}
                      disabled={rowBusy}
                    >
                      {cancelingBildirimId === bildirim.id ? "İptal Ediliyor..." : "İptal"}
                    </button>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      ) : null}

      <div className="module-pagination">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => Math.max(1, prev - 1))}
          disabled={isLoading || page <= 1}
        >
          Önceki
        </button>
        <span className="module-page-info">
          Sayfa {page}
          {totalPages ? ` / ${totalPages}` : ""}
        </span>
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => prev + 1)}
          disabled={isLoading || !hasNextPage}
        >
          Sonraki
        </button>
      </div>

      <div className="module-links">
        <Link to="/">Ana ekrana dön</Link>
        <Link to="/surecler">Süreç takibe git</Link>
        <Link to="/puantaj">Puantaj ekranına git</Link>
      </div>

      {canCreateBildirim && isCreateModalOpen ? (
        <AppModal
          title={createTitle}
          onClose={closeCreateModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={GUNLUK_KAYIT_CREATE_FORM_ID}
                className="universal-btn-save"
                disabled={isCreateSubmitting}
              >
                {isCreateSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeCreateModal}
                disabled={isCreateSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={GUNLUK_KAYIT_CREATE_FORM_ID} className="bildirim-form-grid" onSubmit={handleCreateSubmit}>
            <FormField
              label="Tarih"
              name="bildirim-create-tarih"
              type="date"
              value={createForm.tarih}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, tarih: value }))}
              required
            />

            {personelSelectOptions.length > 0 ? (
              <FormField
                as="select"
                label="Personel"
                name="bildirim-create-personel"
                value={createForm.personelId}
                onChange={handleCreatePersonelChange}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={personelSelectOptions}
              />
            ) : (
              <FormField
                label="Personel ID"
                name="bildirim-create-personel-num"
                type="number"
                min={1}
                value={createForm.personelId}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, personelId: value }))}
                required
              />
            )}

            {personelSelectOptions.length > 0 ? (
              <FormField
                label="Bölüm"
                name="bildirim-create-departman-info"
                value={formatDepartmanLabel(
                  selectedCreatePersonel?.departman_id,
                  selectedCreatePersonel?.departman_adi,
                  departmanOptions
                )}
                onChange={() => undefined}
                disabled
              />
            ) : (
              <FormField
                label="Bölüm"
                name="bildirim-create-departman-num"
                type="number"
                min={1}
                value={createForm.departmanId}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                required
              />
            )}

            <PersonelContextCard personel={selectedCreatePersonel} />

            {gunlukKayitOptions.length > 0 ? (
              <KayitSenaryosuChoiceGroup
                name="bildirim-create-turu"
                value={createForm.bildirimTuru}
                options={gunlukKayitOptions}
                onSelect={(nextValue) => setCreateForm((prev) => ({ ...prev, bildirimTuru: nextValue }))}
              />
            ) : (
              <FormField
                label={KAYIT_SENARYOSU_LABEL}
                name="bildirim-create-turu-text"
                value={createForm.bildirimTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
              />
            )}

            <GunlukKayitModelCard preset={createPreview} />

            <FormField
              as="textarea"
              label="Not / Açıklama"
              name="bildirim-create-aciklama"
              value={createForm.aciklama}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, aciklama: value }))}
              rows={3}
            />
            {createErrorMessage ? <p className="bildirim-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {editingBildirim &&
      canEditGunlukBildirim(editingBildirim, hasPermission, currentUserId) ? (
        <AppModal
          title={`Günlük Kaydı Düzenle #${editingBildirim.id}`}
          onClose={closeEditModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={GUNLUK_KAYIT_EDIT_FORM_ID}
                className="universal-btn-save"
                disabled={isEditSubmitting}
              >
                {isEditSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeEditModal}
                disabled={isEditSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={GUNLUK_KAYIT_EDIT_FORM_ID} className="bildirim-form-grid" onSubmit={handleEditSubmit}>
            <FormField
              label="Tarih"
              name="bildirim-edit-tarih"
              type="date"
              value={editForm.tarih}
              onChange={() => undefined}
              disabled
            />

            {personelSelectOptions.length > 0 ? (
              <FormField
                as="select"
                label="Personel"
                name="bildirim-edit-personel"
                value={editForm.personelId}
                onChange={() => undefined}
                disabled
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={personelSelectOptions}
              />
            ) : (
              <FormField
                label="Personel ID"
                name="bildirim-edit-personel-num"
                type="number"
                min={1}
                value={editForm.personelId}
                onChange={() => undefined}
                disabled
              />
            )}

            {personelSelectOptions.length > 0 ? (
              <FormField
                label="Bölüm"
                name="bildirim-edit-departman-info"
                value={formatDepartmanLabel(
                  selectedEditPersonel?.departman_id,
                  selectedEditPersonel?.departman_adi,
                  departmanOptions
                )}
                onChange={() => undefined}
                disabled
              />
            ) : (
              <FormField
                label="Bölüm"
                name="bildirim-edit-departman-num"
                type="number"
                min={1}
                value={editForm.departmanId}
                onChange={() => undefined}
                disabled
              />
            )}

            <PersonelContextCard personel={selectedEditPersonel} />

            {gunlukKayitOptions.length > 0 ? (
              <KayitSenaryosuChoiceGroup
                name="bildirim-edit-turu"
                value={editForm.bildirimTuru}
                options={gunlukKayitOptions}
                onSelect={(nextValue) => setEditForm((prev) => ({ ...prev, bildirimTuru: nextValue }))}
              />
            ) : (
              <FormField
                label={KAYIT_SENARYOSU_LABEL}
                name="bildirim-edit-turu-text"
                value={editForm.bildirimTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
              />
            )}

            <GunlukKayitModelCard preset={editPreview} />

            <FormField
              as="textarea"
              label="Not / Açıklama"
              name="bildirim-edit-aciklama"
              value={editForm.aciklama}
              onChange={(value) => setEditForm((prev) => ({ ...prev, aciklama: value }))}
              rows={3}
            />
            {editErrorMessage ? <p className="bildirim-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {correctingBildirim ? (
        <AppModal
          title={`Düzeltme İste #${correctingBildirim.id}`}
          onClose={closeCorrectionModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={GUNLUK_KAYIT_CORRECTION_FORM_ID}
                className="universal-btn-save"
                disabled={isCorrectionSubmitting}
              >
                {isCorrectionSubmitting ? "Gönderiliyor..." : "Gönder"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeCorrectionModal}
                disabled={isCorrectionSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form
            id={GUNLUK_KAYIT_CORRECTION_FORM_ID}
            className="bildirim-form-grid"
            onSubmit={(event) => void requestCorrectionHandler(event)}
          >
            <FormField
              as="textarea"
              label="Düzeltme Nedeni"
              name="bildirim-correction-reason"
              value={correctionReason}
              onChange={(value) => setCorrectionReason(value)}
              rows={4}
              required
            />
            {correctionErrorMessage ? (
              <p className="bildirim-form-error">{correctionErrorMessage}</p>
            ) : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
