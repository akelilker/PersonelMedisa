import { useEffect, useMemo, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useBildirimler } from "../../../hooks/useBildirimler";
import {
  formatBildirimStateLabel
} from "../../../lib/display/enum-display";
import type { Bildirim } from "../../../types/bildirim";
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

const QUICK_KAYIT_KEYS = [
  "GEC_GELDI",
  "GELMEDI",
  "IZINLI_GELMEDI",
  "IZINSIZ_GELMEDI",
  "RAPORLU"
] as const;

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
    submitFilters,
    clearFilters,
    setPage
  } = useBildirimler();

  const { hasPermission, uiProfile } = useRoleAccess();
  const canCreateBildirim = hasPermission("bildirimler.create");
  const canEditBildirim = hasPermission("bildirimler.update");
  const canCancelBildirim = hasPermission("bildirimler.cancel");
  const canOpenBildirimDetail = hasPermission("bildirimler.detail.view");
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

  const quickKayitOptions = useMemo(
    () =>
      QUICK_KAYIT_KEYS.map((key) => {
        const option = gunlukKayitOptions.find((item) => item.key === key);
        if (option) {
          return option;
        }

        const preset = resolveGunlukKayitPreset(key);
        return { key, label: preset.label, preset };
      }),
    [gunlukKayitOptions]
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
    void updateBildirimHandler(event, canEditBildirim);
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

  function handleEditPersonelChange(value: string) {
    const selected = personelOptions.find((option) => String(option.id) === value);
    setEditForm((prev) => ({
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

  function applyCreateQuickType(value: string) {
    setCreateForm((prev) => ({ ...prev, bildirimTuru: value }));
  }

  function applyEditQuickType(value: string) {
    setEditForm((prev) => ({ ...prev, bildirimTuru: value }));
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
                  {canEditBildirim ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(bildirim, canEditBildirim)}
                      disabled={cancelingBildirimId === bildirim.id}
                    >
                      Düzenle
                    </button>
                  ) : null}
                  {canCancelBildirim ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void cancelBildirimHandler(bildirim, canCancelBildirim)}
                      disabled={cancelingBildirimId === bildirim.id}
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

            <div className="bildirim-quick-types">
              <span className="bildirim-quick-types-label">Hazır Günlük Kayıt Seç</span>
              <div className="bildirim-quick-types-row">
                {quickKayitOptions.map((option) => (
                  <button
                    key={option.key}
                    type="button"
                    className={`universal-btn-aux${createForm.bildirimTuru === option.key ? " is-active" : ""}`}
                    onClick={() => applyCreateQuickType(option.key)}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            </div>

            {gunlukKayitOptions.length > 0 ? (
              <FormField
                as="select"
                label="Kayıt Senaryosu"
                name="bildirim-create-turu"
                value={createForm.bildirimTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={toSelectOptions(gunlukKayitOptions)}
              />
            ) : (
              <FormField
                label="Kayıt Senaryosu"
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

      {canEditBildirim && editingBildirim ? (
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
              onChange={(value) => setEditForm((prev) => ({ ...prev, tarih: value }))}
              required
            />

            {personelSelectOptions.length > 0 ? (
              <FormField
                as="select"
                label="Personel"
                name="bildirim-edit-personel"
                value={editForm.personelId}
                onChange={handleEditPersonelChange}
                required
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
                onChange={(value) => setEditForm((prev) => ({ ...prev, personelId: value }))}
                required
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
                onChange={(value) => setEditForm((prev) => ({ ...prev, departmanId: value }))}
                required
              />
            )}

            <PersonelContextCard personel={selectedEditPersonel} />

            <div className="bildirim-quick-types">
              <span className="bildirim-quick-types-label">Hazır Günlük Kayıt Seç</span>
              <div className="bildirim-quick-types-row">
                {quickKayitOptions.map((option) => (
                  <button
                    key={option.key}
                    type="button"
                    className={`universal-btn-aux${editForm.bildirimTuru === option.key ? " is-active" : ""}`}
                    onClick={() => applyEditQuickType(option.key)}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            </div>

            {gunlukKayitOptions.length > 0 ? (
              <FormField
                as="select"
                label="Kayıt Senaryosu"
                name="bildirim-edit-turu"
                value={editForm.bildirimTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={toSelectOptions(gunlukKayitOptions)}
              />
            ) : (
              <FormField
                label="Kayıt Senaryosu"
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
    </section>
  );
}
