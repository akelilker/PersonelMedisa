import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { cancelSurec, createSurec, fetchSureclerList, updateSurec } from "../../../api/surecler.api";
import { fetchSurecTuruOptions } from "../../../api/referans.api";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { KeyOption } from "../../../types/referans";
import type { Surec } from "../../../types/surec";

const PAGE_SIZE = 10;

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
            className="state-action-btn"
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

      <form className="module-filter-form" onSubmit={handleFilterSubmit}>
        <div className="module-filter-grid">
          <label className="module-filter-field">
            <span>Personel ID</span>
            <input
              type="number"
              min={1}
              value={personelIdInput}
              onChange={(event) => setPersonelIdInput(event.target.value)}
            />
          </label>

          <label className="module-filter-field">
            <span>Surec Turu</span>
            {surecTuruOptions.length > 0 ? (
              <select value={surecTuruInput} onChange={(event) => setSurecTuruInput(event.target.value)}>
                <option value="">Tum</option>
                {surecTuruOptions.map((option) => (
                  <option key={option.key} value={option.key}>
                    {option.label}
                  </option>
                ))}
              </select>
            ) : (
              <input
                type="text"
                placeholder="IZIN, RAPOR..."
                value={surecTuruInput}
                onChange={(event) => setSurecTuruInput(event.target.value)}
              />
            )}
          </label>

          <label className="module-filter-field">
            <span>Durum</span>
            <input
              type="text"
              placeholder="AKTIF, IPTAL..."
              value={stateInput}
              onChange={(event) => setStateInput(event.target.value)}
            />
          </label>

          <label className="module-filter-field">
            <span>Baslangic</span>
            <input
              type="date"
              value={baslangicInput}
              onChange={(event) => setBaslangicInput(event.target.value)}
            />
          </label>

          <label className="module-filter-field">
            <span>Bitis</span>
            <input
              type="date"
              value={bitisInput}
              onChange={(event) => setBitisInput(event.target.value)}
            />
          </label>
        </div>

        <div className="module-filter-actions">
          <button type="submit" className="state-action-btn">
            Filtrele
          </button>
          <button type="button" className="state-action-btn" onClick={handleFilterClear}>
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
                    <Link to={`/surecler/${surec.id}`} className="state-action-btn">
                      Detay
                    </Link>
                  ) : null}
                  {canEditSurec ? (
                    <button
                      type="button"
                      className="state-action-btn"
                      onClick={() => openEditModal(surec)}
                      disabled={cancelingSurecId === surec.id}
                    >
                      Duzenle
                    </button>
                  ) : null}
                  {canCancelSurec ? (
                    <button
                      type="button"
                      className="state-action-btn"
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
          className="state-action-btn"
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
          className="state-action-btn"
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
        <AppModal title="Yeni Surec Ekle" onClose={() => setIsCreateModalOpen(false)}>
          <form className="surec-form-grid" onSubmit={handleCreateSubmit}>
            <label className="module-filter-field">
              <span>Personel ID</span>
              <input
                type="number"
                min={1}
                value={createForm.personelId}
                onChange={(event) =>
                  setCreateForm((prev) => ({ ...prev, personelId: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Surec Turu</span>
              {surecTuruOptions.length > 0 ? (
                <select
                  value={createForm.surecTuru}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, surecTuru: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {surecTuruOptions.map((option) => (
                    <option key={option.key} value={option.key}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="text"
                  value={createForm.surecTuru}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, surecTuru: event.target.value }))
                  }
                  required
                />
              )}
            </label>

            <label className="module-filter-field">
              <span>Alt Tur</span>
              <input
                type="text"
                value={createForm.altTur}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, altTur: event.target.value }))}
              />
            </label>

            <label className="module-filter-field">
              <span>Baslangic Tarihi</span>
              <input
                type="date"
                value={createForm.baslangicTarihi}
                onChange={(event) =>
                  setCreateForm((prev) => ({ ...prev, baslangicTarihi: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Bitis Tarihi</span>
              <input
                type="date"
                value={createForm.bitisTarihi}
                onChange={(event) =>
                  setCreateForm((prev) => ({ ...prev, bitisTarihi: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Ucretli Mi</span>
              <select
                value={createForm.ucretliMi ? "evet" : "hayir"}
                onChange={(event) =>
                  setCreateForm((prev) => ({ ...prev, ucretliMi: event.target.value === "evet" }))
                }
              >
                <option value="evet">Evet</option>
                <option value="hayir">Hayir</option>
              </select>
            </label>

            <label className="module-filter-field">
              <span>Aciklama</span>
              <input
                type="text"
                value={createForm.aciklama}
                onChange={(event) =>
                  setCreateForm((prev) => ({ ...prev, aciklama: event.target.value }))
                }
              />
            </label>

            {createErrorMessage ? <p className="surec-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}

            <div className="universal-btn-group">
              <button type="submit" className="universal-btn-save" disabled={isCreateSubmitting}>
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
          </form>
        </AppModal>
      ) : null}

      {canEditSurec && editingSurec ? (
        <AppModal title={`Surec Duzenle #${editingSurec.id}`} onClose={() => setEditingSurec(null)}>
          <form className="surec-form-grid" onSubmit={handleEditSubmit}>
            <label className="module-filter-field">
              <span>Personel ID</span>
              <input
                type="number"
                min={1}
                value={editForm.personelId}
                onChange={(event) => setEditForm((prev) => ({ ...prev, personelId: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Surec Turu</span>
              {surecTuruOptions.length > 0 ? (
                <select
                  value={editForm.surecTuru}
                  onChange={(event) => setEditForm((prev) => ({ ...prev, surecTuru: event.target.value }))}
                  required
                >
                  <option value="">Seciniz</option>
                  {surecTuruOptions.map((option) => (
                    <option key={option.key} value={option.key}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="text"
                  value={editForm.surecTuru}
                  onChange={(event) => setEditForm((prev) => ({ ...prev, surecTuru: event.target.value }))}
                  required
                />
              )}
            </label>

            <label className="module-filter-field">
              <span>Alt Tur</span>
              <input
                type="text"
                value={editForm.altTur}
                onChange={(event) => setEditForm((prev) => ({ ...prev, altTur: event.target.value }))}
              />
            </label>

            <label className="module-filter-field">
              <span>Baslangic Tarihi</span>
              <input
                type="date"
                value={editForm.baslangicTarihi}
                onChange={(event) =>
                  setEditForm((prev) => ({ ...prev, baslangicTarihi: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Bitis Tarihi</span>
              <input
                type="date"
                value={editForm.bitisTarihi}
                onChange={(event) =>
                  setEditForm((prev) => ({ ...prev, bitisTarihi: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Ucretli Mi</span>
              <select
                value={editForm.ucretliMi ? "evet" : "hayir"}
                onChange={(event) =>
                  setEditForm((prev) => ({ ...prev, ucretliMi: event.target.value === "evet" }))
                }
              >
                <option value="evet">Evet</option>
                <option value="hayir">Hayir</option>
              </select>
            </label>

            <label className="module-filter-field">
              <span>Aciklama</span>
              <input
                type="text"
                value={editForm.aciklama}
                onChange={(event) => setEditForm((prev) => ({ ...prev, aciklama: event.target.value }))}
              />
            </label>

            {editErrorMessage ? <p className="surec-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="surec-form-error">{referenceError}</p> : null}

            <div className="universal-btn-group">
              <button type="submit" className="universal-btn-save" disabled={isEditSubmitting}>
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
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
