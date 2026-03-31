import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import {
  cancelBildirim,
  createBildirim,
  fetchBildirimlerList,
  updateBildirim
} from "../../../api/bildirimler.api";
import { fetchBildirimTuruOptions, fetchDepartmanOptions } from "../../../api/referans.api";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { IdOption, KeyOption } from "../../../types/referans";
import type { Bildirim } from "../../../types/bildirim";

const PAGE_SIZE = 10;

type BildirimFilters = {
  personelId: string;
  bildirimTuru: string;
  tarih: string;
};

type BildirimFormState = {
  tarih: string;
  departmanId: string;
  personelId: string;
  bildirimTuru: string;
  aciklama: string;
};

const INITIAL_BILDIRIM_FORM: BildirimFormState = {
  tarih: "",
  departmanId: "",
  personelId: "",
  bildirimTuru: "",
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

function toBildirimFormState(bildirim: Bildirim): BildirimFormState {
  return {
    tarih: bildirim.tarih ?? "",
    departmanId: bildirim.departman_id ? String(bildirim.departman_id) : "",
    personelId: bildirim.personel_id ? String(bildirim.personel_id) : "",
    bildirimTuru: bildirim.bildirim_turu,
    aciklama: bildirim.aciklama ?? ""
  };
}

export function BildirimlerPage() {
  const [filters, setFilters] = useState<BildirimFilters>({
    personelId: "",
    bildirimTuru: "",
    tarih: ""
  });
  const [personelIdInput, setPersonelIdInput] = useState("");
  const [bildirimTuruInput, setBildirimTuruInput] = useState("");
  const [tarihInput, setTarihInput] = useState("");
  const [page, setPage] = useState(1);
  const [bildirimler, setBildirimler] = useState<Bildirim[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createForm, setCreateForm] = useState<BildirimFormState>(INITIAL_BILDIRIM_FORM);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);

  const [editingBildirim, setEditingBildirim] = useState<Bildirim | null>(null);
  const [editForm, setEditForm] = useState<BildirimFormState>(INITIAL_BILDIRIM_FORM);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [isEditSubmitting, setIsEditSubmitting] = useState(false);
  const [cancelingBildirimId, setCancelingBildirimId] = useState<number | null>(null);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [bildirimTuruOptions, setBildirimTuruOptions] = useState<KeyOption[]>([]);
  const [referenceError, setReferenceError] = useState<string | null>(null);
  const { hasPermission } = useRoleAccess();
  const canCreateBildirim = hasPermission("bildirimler.create");
  const canEditBildirim = hasPermission("bildirimler.update");
  const canCancelBildirim = hasPermission("bildirimler.cancel");
  const canOpenBildirimDetail = hasPermission("bildirimler.detail.view");

  const loadBildirimler = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const nextData = await fetchBildirimlerList({
        personel_id: parsePositiveInt(filters.personelId),
        bildirim_turu: filters.bildirimTuru || undefined,
        tarih: filters.tarih || undefined,
        page,
        limit: PAGE_SIZE
      });
      setBildirimler(nextData.items);
      setHasNextPage(nextData.pagination.hasNextPage ?? nextData.items.length === PAGE_SIZE);
      setTotalPages(nextData.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bildirim listesi alinamadi.");
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [filters.bildirimTuru, filters.personelId, filters.tarih, page]);

  useEffect(() => {
    void loadBildirimler();
  }, [loadBildirimler]);

  useEffect(() => {
    let isCancelled = false;

    async function loadReferences() {
      setReferenceError(null);
      try {
        const [departmanlar, bildirimTurleri] = await Promise.all([
          fetchDepartmanOptions(),
          fetchBildirimTuruOptions()
        ]);

        if (isCancelled) {
          return;
        }

        setDepartmanOptions(departmanlar);
        setBildirimTuruOptions(bildirimTurleri);
      } catch (error) {
        if (isCancelled) {
          return;
        }

        setReferenceError(
          error instanceof Error ? error.message : "Bildirim referanslari alinamadi, manuel giris aktif."
        );
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
      bildirimTuru: bildirimTuruInput.trim(),
      tarih: tarihInput
    });
    setPage(1);
  }

  function handleFilterClear() {
    setPersonelIdInput("");
    setBildirimTuruInput("");
    setTarihInput("");
    setFilters({
      personelId: "",
      bildirimTuru: "",
      tarih: ""
    });
    setPage(1);
  }

  async function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isCreateSubmitting) {
      return;
    }
    if (!canCreateBildirim) {
      setCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setCreateErrorMessage(null);
    setIsCreateSubmitting(true);

    try {
      await createBildirim({
        tarih: createForm.tarih,
        departman_id: parseRequiredPositiveInt(createForm.departmanId, "Departman ID"),
        personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
        bildirim_turu: createForm.bildirimTuru.trim(),
        aciklama: createForm.aciklama.trim() || undefined
      });

      setIsCreateModalOpen(false);
      setCreateForm(INITIAL_BILDIRIM_FORM);
      if (page === 1) {
        await loadBildirimler();
      } else {
        setPage(1);
      }
    } catch (error) {
      setCreateErrorMessage(error instanceof Error ? error.message : "Bildirim kaydi yapilamadi.");
    } finally {
      setIsCreateSubmitting(false);
    }
  }

  function openEditModal(bildirim: Bildirim) {
    if (!canEditBildirim) {
      setErrorMessage("Bu bildirimi duzenlemek icin yetkin bulunmuyor.");
      return;
    }

    setEditErrorMessage(null);
    setEditingBildirim(bildirim);
    setEditForm(toBildirimFormState(bildirim));
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingBildirim || isEditSubmitting) {
      return;
    }
    if (!canEditBildirim) {
      setEditErrorMessage("Bu bildirimi duzenlemek icin yetkin bulunmuyor.");
      return;
    }

    setEditErrorMessage(null);
    setIsEditSubmitting(true);

    try {
      await updateBildirim(editingBildirim.id, {
        tarih: editForm.tarih,
        departman_id: parseRequiredPositiveInt(editForm.departmanId, "Departman ID"),
        personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
        bildirim_turu: editForm.bildirimTuru.trim(),
        aciklama: editForm.aciklama.trim() || undefined
      });

      setEditingBildirim(null);
      if (page === 1) {
        await loadBildirimler();
      } else {
        setPage(1);
      }
    } catch (error) {
      setEditErrorMessage(error instanceof Error ? error.message : "Bildirim guncellenemedi.");
    } finally {
      setIsEditSubmitting(false);
    }
  }

  async function handleCancelBildirim(bildirim: Bildirim) {
    if (!canCancelBildirim) {
      setErrorMessage("Bu bildirimi iptal etmek icin yetkin bulunmuyor.");
      return;
    }

    const confirmed = window.confirm(`Bildirim #${bildirim.id} kaydini iptal etmek istiyor musun?`);
    if (!confirmed) {
      return;
    }

    setCancelingBildirimId(bildirim.id);
    try {
      await cancelBildirim(bildirim.id);
      if (page === 1) {
        await loadBildirimler();
      } else {
        setPage(1);
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bildirim iptal edilemedi.");
    } finally {
      setCancelingBildirimId(null);
    }
  }

  return (
    <section className="bildirimler-page">
      <div className="bildirimler-header-row">
        <h2>Bildirimler</h2>
        {canCreateBildirim ? (
          <button
            type="button"
            className="universal-btn-aux"
            onClick={() => {
              setCreateErrorMessage(null);
              setCreateForm(INITIAL_BILDIRIM_FORM);
              setIsCreateModalOpen(true);
            }}
          >
            Yeni Bildirim
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
            <span>Bildirim Turu</span>
            {bildirimTuruOptions.length > 0 ? (
              <select
                value={bildirimTuruInput}
                onChange={(event) => setBildirimTuruInput(event.target.value)}
              >
                <option value="">Tum</option>
                {bildirimTuruOptions.map((option) => (
                  <option key={option.key} value={option.key}>
                    {option.label}
                  </option>
                ))}
              </select>
            ) : (
              <input
                type="text"
                placeholder="GEC_GELDI, DEVAMSIZLIK..."
                value={bildirimTuruInput}
                onChange={(event) => setBildirimTuruInput(event.target.value)}
              />
            )}
          </label>

          <label className="module-filter-field">
            <span>Tarih</span>
            <input
              type="date"
              value={tarihInput}
              onChange={(event) => setTarihInput(event.target.value)}
            />
          </label>
        </div>

        <div className="module-filter-actions">
          <button type="submit" className="universal-btn-aux">
            Filtrele
          </button>
          <button type="button" className="universal-btn-aux" onClick={handleFilterClear}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Bildirim verileri yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadBildirimler()} />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length === 0 ? (
        <EmptyState
          title="Bildirim bulunamadi"
          message="Secilen tarihte veya filtrede bildirim kaydi yok."
        />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length > 0 ? (
        <ul className="bildirimler-list">
          {bildirimler.map((bildirim) => (
            <li key={bildirim.id} className="bildirimler-item">
              <div>
                <strong>{bildirim.bildirim_turu}</strong>
                <p>Tarih: {bildirim.tarih ?? "-"}</p>
                <p>Personel: {bildirim.personel_id ?? "-"}</p>
              </div>
              {canOpenBildirimDetail || canEditBildirim || canCancelBildirim ? (
                <div className="module-item-actions">
                  {canOpenBildirimDetail ? (
                    <Link to={`/bildirimler/${bildirim.id}`} className="universal-btn-aux">
                      Detay
                    </Link>
                  ) : null}
                  {canEditBildirim ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(bildirim)}
                      disabled={cancelingBildirimId === bildirim.id}
                    >
                      Duzenle
                    </button>
                  ) : null}
                  {canCancelBildirim ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void handleCancelBildirim(bildirim)}
                      disabled={cancelingBildirimId === bildirim.id}
                    >
                      {cancelingBildirimId === bildirim.id ? "Iptal Ediliyor..." : "Iptal"}
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
        <Link to="/surecler">Surec takibe git</Link>
        <Link to="/puantaj">Puantaja git</Link>
      </div>

      {canCreateBildirim && isCreateModalOpen ? (
        <AppModal title="Yeni Bildirim Ekle" onClose={() => setIsCreateModalOpen(false)}>
          <form className="bildirim-form-grid" onSubmit={handleCreateSubmit}>
            <label className="module-filter-field">
              <span>Tarih</span>
              <input
                type="date"
                value={createForm.tarih}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, tarih: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Departman ID</span>
              {departmanOptions.length > 0 ? (
                <select
                  value={createForm.departmanId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, departmanId: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {departmanOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="number"
                  min={1}
                  value={createForm.departmanId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, departmanId: event.target.value }))
                  }
                  required
                />
              )}
            </label>

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
              <span>Bildirim Turu</span>
              {bildirimTuruOptions.length > 0 ? (
                <select
                  value={createForm.bildirimTuru}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, bildirimTuru: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {bildirimTuruOptions.map((option) => (
                    <option key={option.key} value={option.key}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="text"
                  value={createForm.bildirimTuru}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, bildirimTuru: event.target.value }))
                  }
                  required
                />
              )}
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

            {createErrorMessage ? <p className="bildirim-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}

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

      {canEditBildirim && editingBildirim ? (
        <AppModal
          title={`Bildirim Duzenle #${editingBildirim.id}`}
          onClose={() => setEditingBildirim(null)}
        >
          <form className="bildirim-form-grid" onSubmit={handleEditSubmit}>
            <label className="module-filter-field">
              <span>Tarih</span>
              <input
                type="date"
                value={editForm.tarih}
                onChange={(event) => setEditForm((prev) => ({ ...prev, tarih: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Departman ID</span>
              {departmanOptions.length > 0 ? (
                <select
                  value={editForm.departmanId}
                  onChange={(event) =>
                    setEditForm((prev) => ({ ...prev, departmanId: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {departmanOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="number"
                  min={1}
                  value={editForm.departmanId}
                  onChange={(event) =>
                    setEditForm((prev) => ({ ...prev, departmanId: event.target.value }))
                  }
                  required
                />
              )}
            </label>

            <label className="module-filter-field">
              <span>Personel ID</span>
              <input
                type="number"
                min={1}
                value={editForm.personelId}
                onChange={(event) =>
                  setEditForm((prev) => ({ ...prev, personelId: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Bildirim Turu</span>
              {bildirimTuruOptions.length > 0 ? (
                <select
                  value={editForm.bildirimTuru}
                  onChange={(event) =>
                    setEditForm((prev) => ({ ...prev, bildirimTuru: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {bildirimTuruOptions.map((option) => (
                    <option key={option.key} value={option.key}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="text"
                  value={editForm.bildirimTuru}
                  onChange={(event) =>
                    setEditForm((prev) => ({ ...prev, bildirimTuru: event.target.value }))
                  }
                  required
                />
              )}
            </label>

            <label className="module-filter-field">
              <span>Aciklama</span>
              <input
                type="text"
                value={editForm.aciklama}
                onChange={(event) => setEditForm((prev) => ({ ...prev, aciklama: event.target.value }))}
              />
            </label>

            {editErrorMessage ? <p className="bildirim-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}

            <div className="universal-btn-group">
              <button type="submit" className="universal-btn-save" disabled={isEditSubmitting}>
                {isEditSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => setEditingBildirim(null)}
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
