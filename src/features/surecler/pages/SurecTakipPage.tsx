import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { cancelSurec, createSurec, fetchSureclerList, updateSurec } from "../../../api/surecler.api";
import { fetchSurecTuruOptions } from "../../../api/referans.api";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { KeyOption } from "../../../types/referans";
import type { Surec } from "../../../types/surec";

const PAGE_SIZE = 10;

const SUREC_CREATE_FORM_ID = "surec-create-form";
const SUREC_EDIT_FORM_ID = "surec-edit-form";

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

const UCRETLI_SELECT_OPTIONS = [
  { value: "evet", label: "Evet" },
  { value: "hayir", label: "Hayir" }
];

type SurecFilters = {
  personelId: string;
  surecTuru: string;
  state: string;
  baslangicTarihi: string;
  bitisTarihi: string;
};

type SurecFormState = {
  personelId: string;
  surecTuru: string;
  altTur: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  ucretliMi: boolean;
  aciklama: string;
};

const INITIAL_SUREC_FORM: SurecFormState = {
  personelId: "",
  surecTuru: "",
  altTur: "",
  baslangicTarihi: "",
  bitisTarihi: "",
  ucretliMi: true,
  aciklama: ""
};

function parsePositiveInt(value: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    return undefined;
  }

  return number;
}

function parseRequiredPositiveInt(value: string, label: string) {
  const number = parsePositiveInt(value);
  if (!number) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }

  return number;
}

function toSurecFormState(surec: Surec): SurecFormState {
  return {
    personelId: String(surec.personel_id),
    surecTuru: surec.surec_turu,
    altTur: surec.alt_tur ?? "",
    baslangicTarihi: surec.baslangic_tarihi ?? "",
    bitisTarihi: surec.bitis_tarihi ?? "",
    ucretliMi: surec.ucretli_mi ?? true,
    aciklama: surec.aciklama ?? ""
  };
}

export function SurecTakipPage() {
  const [filters, setFilters] = useState<SurecFilters>({
    personelId: "",
    surecTuru: "",
    state: "",
    baslangicTarihi: "",
    bitisTarihi: ""
  });
  const [personelIdInput, setPersonelIdInput] = useState("");
  const [surecTuruInput, setSurecTuruInput] = useState("");
  const [stateInput, setStateInput] = useState("");
  const [baslangicInput, setBaslangicInput] = useState("");
  const [bitisInput, setBitisInput] = useState("");
  const [page, setPage] = useState(1);
  const [surecler, setSurecler] = useState<Surec[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createForm, setCreateForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);

  const [editingSurec, setEditingSurec] = useState<Surec | null>(null);
  const [editForm, setEditForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [isEditSubmitting, setIsEditSubmitting] = useState(false);
  const [cancelingSurecId, setCancelingSurecId] = useState<number | null>(null);
  const [surecTuruOptions, setSurecTuruOptions] = useState<KeyOption[]>([]);
  const [referenceError, setReferenceError] = useState<string | null>(null);
  const { hasPermission } = useRoleAccess();
  const canCreateSurec = hasPermission("surecler.create");
  const canEditSurec = hasPermission("surecler.update");
  const canCancelSurec = hasPermission("surecler.cancel");
  const canOpenSurecDetail = hasPermission("surecler.detail.view");

  const loadSurecler = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const nextData = await fetchSureclerList({
        personel_id: parsePositiveInt(filters.personelId),
        surec_turu: filters.surecTuru || undefined,
        state: filters.state || undefined,
        baslangic_tarihi: filters.baslangicTarihi || undefined,
        bitis_tarihi: filters.bitisTarihi || undefined,
        page,
        limit: PAGE_SIZE
      });
      setSurecler(nextData.items);
      setHasNextPage(nextData.pagination.hasNextPage ?? nextData.items.length === PAGE_SIZE);
      setTotalPages(nextData.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Surec listesi alinamadi.");
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [filters.baslangicTarihi, filters.bitisTarihi, filters.personelId, filters.state, filters.surecTuru, page]);

  useEffect(() => {
    void loadSurecler();
  }, [loadSurecler]);

  useEffect(() => {
    let isCancelled = false;

    async function loadReferences() {
      setReferenceError(null);
      try {
        const options = await fetchSurecTuruOptions();
        if (!isCancelled) {
          setSurecTuruOptions(options);
        }
      } catch (error) {
        if (!isCancelled) {
          setReferenceError(
            error instanceof Error ? error.message : "Surec turleri alinamadi, manuel giris aktif."
          );
        }
      }
    }

    void loadReferences();

    return () => {
      isCancelled = true;
    };
  }, []);

  function handleFilterSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setFilters({
      personelId: personelIdInput.trim(),
      surecTuru: surecTuruInput.trim(),
      state: stateInput.trim(),
      baslangicTarihi: baslangicInput,
      bitisTarihi: bitisInput
    });
    setPage(1);
  }

  function handleFilterClear() {
    setPersonelIdInput("");
    setSurecTuruInput("");
    setStateInput("");
    setBaslangicInput("");
    setBitisInput("");
    setFilters({
      personelId: "",
      surecTuru: "",
      state: "",
      baslangicTarihi: "",
      bitisTarihi: ""
    });
    setPage(1);
  }

  async function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isCreateSubmitting) {
      return;
    }
    if (!canCreateSurec) {
      setCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setCreateErrorMessage(null);
    setIsCreateSubmitting(true);

    try {
      await createSurec({
        personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
        surec_turu: createForm.surecTuru.trim(),
        alt_tur: createForm.altTur.trim() || undefined,
        baslangic_tarihi: createForm.baslangicTarihi,
        bitis_tarihi: createForm.bitisTarihi,
        ucretli_mi: createForm.ucretliMi,
        aciklama: createForm.aciklama.trim() || undefined
      });

      setIsCreateModalOpen(false);
      setCreateForm(INITIAL_SUREC_FORM);
      if (page === 1) {
        await loadSurecler();
      } else {
        setPage(1);
      }
    } catch (error) {
      setCreateErrorMessage(error instanceof Error ? error.message : "Surec kaydi yapilamadi.");
    } finally {
      setIsCreateSubmitting(false);
    }
  }

  function openEditModal(surec: Surec) {
    if (!canEditSurec) {
      setErrorMessage("Bu sureci duzenlemek icin yetkin bulunmuyor.");
      return;
    }

    setEditErrorMessage(null);
    setEditingSurec(surec);
    setEditForm(toSurecFormState(surec));
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingSurec || isEditSubmitting) {
      return;
    }
    if (!canEditSurec) {
      setEditErrorMessage("Bu sureci duzenlemek icin yetkin bulunmuyor.");
      return;
    }

    setEditErrorMessage(null);
    setIsEditSubmitting(true);

    try {
      await updateSurec(editingSurec.id, {
        personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
        surec_turu: editForm.surecTuru.trim(),
        alt_tur: editForm.altTur.trim() || undefined,
        baslangic_tarihi: editForm.baslangicTarihi,
        bitis_tarihi: editForm.bitisTarihi,
        ucretli_mi: editForm.ucretliMi,
        aciklama: editForm.aciklama.trim() || undefined
      });

      setEditingSurec(null);
      if (page === 1) {
        await loadSurecler();
      } else {
        setPage(1);
      }
    } catch (error) {
      setEditErrorMessage(error instanceof Error ? error.message : "Surec guncellenemedi.");
    } finally {
      setIsEditSubmitting(false);
    }
  }

  async function handleCancelSurec(surec: Surec) {
    if (!canCancelSurec) {
      setErrorMessage("Bu sureci iptal etmek icin yetkin bulunmuyor.");
      return;
    }

    const confirmed = window.confirm(`Surec #${surec.id} kaydini iptal etmek istiyor musun?`);
    if (!confirmed) {
      return;
    }

    setCancelingSurecId(surec.id);
    try {
      await cancelSurec(surec.id);
      if (page === 1) {
        await loadSurecler();
      } else {
        setPage(1);
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Surec iptal edilemedi.");
    } finally {
      setCancelingSurecId(null);
    }
  }

  return (
    <section className="surec-page">
      <div className="surecler-header-row">
        <h2>Surec Takibi</h2>
        {canCreateSurec ? (
          <button
            type="button"
            className="universal-btn-aux"
            onClick={() => {
              setCreateErrorMessage(null);
              setCreateForm(INITIAL_SUREC_FORM);
              setIsCreateModalOpen(true);
            }}
          >
            Yeni Surec
          </button>
        ) : null}
      </div>

      <form className="form-filter-panel" onSubmit={handleFilterSubmit}>
        <div className="form-field-grid">
          <FormField
            label="Personel ID"
            name="surec-filter-personel"
            type="number"
            min={1}
            value={personelIdInput}
            onChange={setPersonelIdInput}
          />
          {surecTuruOptions.length > 0 ? (
            <FormField
              as="select"
              label="Surec Turu"
              name="surec-filter-turu"
              value={surecTuruInput}
              onChange={setSurecTuruInput}
              placeholderOption={{ value: "", label: "Tum" }}
              selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
            />
          ) : (
            <FormField
              label="Surec Turu"
              name="surec-filter-turu-text"
              placeholder="IZIN, RAPOR..."
              value={surecTuruInput}
              onChange={setSurecTuruInput}
            />
          )}
          <FormField
            label="Durum"
            name="surec-filter-state"
            placeholder="AKTIF, IPTAL..."
            value={stateInput}
            onChange={setStateInput}
          />
          <FormField
            label="Baslangic"
            name="surec-filter-bas"
            type="date"
            value={baslangicInput}
            onChange={setBaslangicInput}
          />
          <FormField
            label="Bitis"
            name="surec-filter-bitis"
            type="date"
            value={bitisInput}
            onChange={setBitisInput}
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux">
            Filtrele
          </button>
          <button type="button" className="universal-btn-aux" onClick={handleFilterClear}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Surec verileri yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadSurecler()} />
      ) : null}

      {!isLoading && !errorMessage && surecler.length === 0 ? (
        <EmptyState title="Surec kaydi yok" message="Bu filtrede gosterilecek surec bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && surecler.length > 0 ? (
        <ul className="surecler-list">
          {surecler.map((surec) => (
            <li key={surec.id} className="surecler-item">
              <div>
                <strong>{surec.surec_turu}</strong>
                <p>Personel: {surec.personel_id}</p>
                <p>Durum: {surec.state ?? "-"}</p>
                <p>
                  Tarih: {surec.baslangic_tarihi ?? "-"} / {surec.bitis_tarihi ?? "-"}
                </p>
              </div>
              {canOpenSurecDetail || canEditSurec || canCancelSurec ? (
                <div className="module-item-actions">
                  {canOpenSurecDetail ? (
                    <Link to={`/surecler/${surec.id}`} className="universal-btn-aux">
                      Detay
                    </Link>
                  ) : null}
                  {canEditSurec ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(surec)}
                      disabled={cancelingSurecId === surec.id}
                    >
                      Duzenle
                    </button>
                  ) : null}
                  {canCancelSurec ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void handleCancelSurec(surec)}
                      disabled={cancelingSurecId === surec.id}
                    >
                      {cancelingSurecId === surec.id ? "Iptal Ediliyor..." : "Iptal"}
                    </button>
                  ) : null}
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      ) : null}

      <div className="module-pagination">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => Math.max(1, prev - 1))}
          disabled={isLoading || page <= 1}
        >
          Onceki
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
        <Link to="/personeller">Personellere don</Link>
        <Link to="/bildirimler">Bildirimlere git</Link>
        <Link to="/puantaj">Puantaja git</Link>
      </div>

      {canCreateSurec && isCreateModalOpen ? (
        <AppModal
          title="Yeni Surec Ekle"
          onClose={() => setIsCreateModalOpen(false)}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={SUREC_CREATE_FORM_ID}
                className="universal-btn-save"
                disabled={isCreateSubmitting}
              >
                {isCreateSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => setIsCreateModalOpen(false)}
                disabled={isCreateSubmitting}
              >
                Vazgec
              </button>
            </div>
          }
        >
          <form id={SUREC_CREATE_FORM_ID} className="surec-form-grid" onSubmit={handleCreateSubmit}>
            <FormField
              label="Personel ID"
              name="surec-create-personel"
              type="number"
              min={1}
              value={createForm.personelId}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Surec Turu"
                name="surec-create-turu"
                value={createForm.surecTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Surec Turu"
                name="surec-create-turu-text"
                value={createForm.surecTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, surecTuru: value }))}
                required
              />
            )}
            <FormField
              label="Alt Tur"
              name="surec-create-alt"
              value={createForm.altTur}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, altTur: value }))}
            />
            <FormField
              label="Baslangic Tarihi"
              name="surec-create-bas"
              type="date"
              value={createForm.baslangicTarihi}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitis Tarihi"
              name="surec-create-bitis"
              type="date"
              value={createForm.bitisTarihi}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, bitisTarihi: value }))}
              required
            />
            <FormField
              as="select"
              label="Ucretli Mi"
              name="surec-create-ucret"
              value={createForm.ucretliMi ? "evet" : "hayir"}
              onChange={(value) =>
                setCreateForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))
              }
              selectOptions={UCRETLI_SELECT_OPTIONS}
            />
            <FormField
              label="Aciklama"
              name="surec-create-aciklama"
              value={createForm.aciklama}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {createErrorMessage ? <p className="surec-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {canEditSurec && editingSurec ? (
        <AppModal
          title={`Surec Duzenle #${editingSurec.id}`}
          onClose={() => setEditingSurec(null)}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={SUREC_EDIT_FORM_ID}
                className="universal-btn-save"
                disabled={isEditSubmitting}
              >
                {isEditSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => setEditingSurec(null)}
                disabled={isEditSubmitting}
              >
                Vazgec
              </button>
            </div>
          }
        >
          <form id={SUREC_EDIT_FORM_ID} className="surec-form-grid" onSubmit={handleEditSubmit}>
            <FormField
              label="Personel ID"
              name="surec-edit-personel"
              type="number"
              min={1}
              value={editForm.personelId}
              onChange={(value) => setEditForm((prev) => ({ ...prev, personelId: value }))}
              required
            />
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Surec Turu"
                name="surec-edit-turu"
                value={editForm.surecTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Surec Turu"
                name="surec-edit-turu-text"
                value={editForm.surecTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, surecTuru: value }))}
                required
              />
            )}
            <FormField
              label="Alt Tur"
              name="surec-edit-alt"
              value={editForm.altTur}
              onChange={(value) => setEditForm((prev) => ({ ...prev, altTur: value }))}
            />
            <FormField
              label="Baslangic Tarihi"
              name="surec-edit-start"
              type="date"
              value={editForm.baslangicTarihi}
              onChange={(value) => setEditForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitis Tarihi"
              name="surec-edit-end"
              type="date"
              value={editForm.bitisTarihi}
              onChange={(value) => setEditForm((prev) => ({ ...prev, bitisTarihi: value }))}
              required
            />
            <FormField
              as="select"
              label="Ucretli Mi"
              name="surec-edit-ucret"
              value={editForm.ucretliMi ? "evet" : "hayir"}
              onChange={(value) =>
                setEditForm((prev) => ({ ...prev, ucretliMi: value === "evet" }))
              }
              selectOptions={UCRETLI_SELECT_OPTIONS}
            />
            <FormField
              label="Aciklama"
              name="surec-edit-aciklama"
              value={editForm.aciklama}
              onChange={(value) => setEditForm((prev) => ({ ...prev, aciklama: value }))}
            />
            {editErrorMessage ? <p className="surec-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
