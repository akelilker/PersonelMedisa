import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import {
  cancelFinansKalem,
  createFinansKalem,
  fetchFinansKalemList,
  updateFinansKalem
} from "../../../api/finans.api";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { FinansKalem } from "../../../types/finans";

const PAGE_SIZE = 10;

type FinansFilters = {
  personelId: string;
  donem: string;
  kalemTuru: string;
  state: string;
};

type FinansFormState = {
  personelId: string;
  donem: string;
  kalemTuru: string;
  tutar: string;
  aciklama: string;
};

function toMonthInputValue(date: Date): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}`;
}

function parsePositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const parsed = Number.parseInt(trimmed, 10);
  if (Number.isNaN(parsed) || parsed <= 0) {
    return undefined;
  }

  return parsed;
}

function parseRequiredPositiveInt(value: string, label: string): number {
  const parsed = parsePositiveInt(value);
  if (!parsed) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }

  return parsed;
}

function parseRequiredPositiveNumber(value: string, label: string): number {
  const trimmed = value.trim();
  const parsed = Number.parseFloat(trimmed);
  if (!trimmed || Number.isNaN(parsed) || parsed <= 0) {
    throw new Error(`${label} sifirdan buyuk olmali.`);
  }

  return parsed;
}

function validateDonem(donem: string): string {
  const value = donem.trim();
  if (!/^\d{4}-\d{2}$/.test(value)) {
    throw new Error("Donem YYYY-MM formatinda olmali.");
  }

  return value;
}

function toFormState(item: FinansKalem): FinansFormState {
  return {
    personelId: String(item.personel_id),
    donem: item.donem,
    kalemTuru: item.kalem_turu,
    tutar: String(item.tutar),
    aciklama: item.aciklama ?? ""
  };
}

export function FinansPage() {
  const { hasPermission } = useRoleAccess();
  const canCreateFinans = hasPermission("finans.create");
  const canEditFinans = hasPermission("finans.update");
  const canCancelFinans = hasPermission("finans.cancel");

  const [filters, setFilters] = useState<FinansFilters>({
    personelId: "",
    donem: "",
    kalemTuru: "",
    state: ""
  });
  const [personelIdInput, setPersonelIdInput] = useState("");
  const [donemInput, setDonemInput] = useState("");
  const [kalemTuruInput, setKalemTuruInput] = useState("");
  const [stateInput, setStateInput] = useState("");
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<FinansKalem[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [createForm, setCreateForm] = useState<FinansFormState>({
    personelId: "",
    donem: toMonthInputValue(new Date()),
    kalemTuru: "AVANS",
    tutar: "",
    aciklama: ""
  });
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);

  const [editingItem, setEditingItem] = useState<FinansKalem | null>(null);
  const [editForm, setEditForm] = useState<FinansFormState>({
    personelId: "",
    donem: "",
    kalemTuru: "",
    tutar: "",
    aciklama: ""
  });
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [isEditSubmitting, setIsEditSubmitting] = useState(false);
  const [cancelingItemId, setCancelingItemId] = useState<number | null>(null);

  const loadItems = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const result = await fetchFinansKalemList({
        personel_id: parsePositiveInt(filters.personelId),
        donem: filters.donem || undefined,
        kalem_turu: filters.kalemTuru || undefined,
        state: filters.state || undefined,
        page,
        limit: PAGE_SIZE
      });

      setItems(result.items);
      setHasNextPage(result.pagination.hasNextPage ?? result.items.length === PAGE_SIZE);
      setTotalPages(result.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Finans kayitlari alinamadi.");
      setItems([]);
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [filters.donem, filters.kalemTuru, filters.personelId, filters.state, page]);

  useEffect(() => {
    void loadItems();
  }, [loadItems]);

  function handleFilterSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setFilters({
      personelId: personelIdInput.trim(),
      donem: donemInput.trim(),
      kalemTuru: kalemTuruInput.trim(),
      state: stateInput.trim()
    });
    setPage(1);
  }

  function handleFilterClear() {
    setPersonelIdInput("");
    setDonemInput("");
    setKalemTuruInput("");
    setStateInput("");
    setFilters({
      personelId: "",
      donem: "",
      kalemTuru: "",
      state: ""
    });
    setPage(1);
  }

  async function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isCreateSubmitting) {
      return;
    }
    if (!canCreateFinans) {
      setCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setCreateErrorMessage(null);
    setIsCreateSubmitting(true);

    try {
      await createFinansKalem({
        personel_id: parseRequiredPositiveInt(createForm.personelId, "Personel ID"),
        donem: validateDonem(createForm.donem),
        kalem_turu: createForm.kalemTuru.trim(),
        tutar: parseRequiredPositiveNumber(createForm.tutar, "Tutar"),
        aciklama: createForm.aciklama.trim() || undefined
      });

      setIsCreateModalOpen(false);
      setCreateForm({
        personelId: "",
        donem: toMonthInputValue(new Date()),
        kalemTuru: "AVANS",
        tutar: "",
        aciklama: ""
      });

      if (page === 1) {
        await loadItems();
      } else {
        setPage(1);
      }
    } catch (error) {
      setCreateErrorMessage(error instanceof Error ? error.message : "Finans kaydi olusturulamadi.");
    } finally {
      setIsCreateSubmitting(false);
    }
  }

  function openEditModal(item: FinansKalem) {
    if (!canEditFinans) {
      setErrorMessage("Bu kaydi duzenlemek icin yetkin bulunmuyor.");
      return;
    }

    setEditingItem(item);
    setEditForm(toFormState(item));
    setEditErrorMessage(null);
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editingItem || isEditSubmitting) {
      return;
    }
    if (!canEditFinans) {
      setEditErrorMessage("Bu kaydi duzenlemek icin yetkin bulunmuyor.");
      return;
    }

    setEditErrorMessage(null);
    setIsEditSubmitting(true);

    try {
      await updateFinansKalem(editingItem.id, {
        personel_id: parseRequiredPositiveInt(editForm.personelId, "Personel ID"),
        donem: validateDonem(editForm.donem),
        kalem_turu: editForm.kalemTuru.trim(),
        tutar: parseRequiredPositiveNumber(editForm.tutar, "Tutar"),
        aciklama: editForm.aciklama.trim() || undefined
      });

      setEditingItem(null);
      if (page === 1) {
        await loadItems();
      } else {
        setPage(1);
      }
    } catch (error) {
      setEditErrorMessage(error instanceof Error ? error.message : "Finans kaydi guncellenemedi.");
    } finally {
      setIsEditSubmitting(false);
    }
  }

  async function handleCancel(item: FinansKalem) {
    if (!canCancelFinans) {
      setErrorMessage("Bu kaydi iptal etmek icin yetkin bulunmuyor.");
      return;
    }

    const confirmed = window.confirm(`Finans kaydi #${item.id} iptal edilsin mi?`);
    if (!confirmed) {
      return;
    }

    setCancelingItemId(item.id);
    try {
      await cancelFinansKalem(item.id);
      if (page === 1) {
        await loadItems();
      } else {
        setPage(1);
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Finans kaydi iptal edilemedi.");
    } finally {
      setCancelingItemId(null);
    }
  }

  return (
    <section className="finans-page">
      <div className="finans-header-row">
        <h2>Finans</h2>
        {canCreateFinans ? (
          <button
            type="button"
            className="universal-btn-aux"
            onClick={() => {
              setCreateErrorMessage(null);
              setIsCreateModalOpen(true);
            }}
          >
            Yeni Finans Kalemi
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
            <span>Donem (YYYY-MM)</span>
            <input type="month" value={donemInput} onChange={(event) => setDonemInput(event.target.value)} />
          </label>

          <label className="module-filter-field">
            <span>Kalem Turu</span>
            <input
              type="text"
              placeholder="AVANS, PRIM..."
              value={kalemTuruInput}
              onChange={(event) => setKalemTuruInput(event.target.value)}
            />
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

      {isLoading ? <LoadingState label="Finans verileri yukleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadItems()} /> : null}
      {!isLoading && !errorMessage && items.length === 0 ? (
        <EmptyState title="Finans kaydi yok" message="Bu filtrede gosterilecek finans kalemi bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && items.length > 0 ? (
        <ul className="finans-list">
          {items.map((item) => (
            <li key={item.id} className="finans-item">
              <div>
                <strong>{item.kalem_turu}</strong>
                <p>Personel: {item.personel_id}</p>
                <p>Donem: {item.donem}</p>
                <p>Tutar: {item.tutar}</p>
                <p>Durum: {item.state ?? "-"}</p>
              </div>

              {canEditFinans || canCancelFinans ? (
                <div className="module-item-actions">
                  {canEditFinans ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(item)}
                      disabled={cancelingItemId === item.id}
                    >
                      Duzenle
                    </button>
                  ) : null}
                  {canCancelFinans ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void handleCancel(item)}
                      disabled={cancelingItemId === item.id}
                    >
                      {cancelingItemId === item.id ? "Iptal Ediliyor..." : "Iptal"}
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
        <Link to="/raporlar">Raporlara git</Link>
        <Link to="/personeller">Personellere don</Link>
      </div>

      {canCreateFinans && isCreateModalOpen ? (
        <AppModal title="Yeni Finans Kalemi" onClose={() => setIsCreateModalOpen(false)}>
          <form className="finans-form-grid" onSubmit={handleCreateSubmit}>
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
              <span>Donem</span>
              <input
                type="month"
                value={createForm.donem}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, donem: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Kalem Turu</span>
              <input
                type="text"
                value={createForm.kalemTuru}
                onChange={(event) =>
                  setCreateForm((prev) => ({ ...prev, kalemTuru: event.target.value }))
                }
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Tutar</span>
              <input
                type="number"
                min={0.01}
                step="0.01"
                value={createForm.tutar}
                onChange={(event) => setCreateForm((prev) => ({ ...prev, tutar: event.target.value }))}
                required
              />
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

            {createErrorMessage ? <p className="finans-form-error">{createErrorMessage}</p> : null}

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

      {canEditFinans && editingItem ? (
        <AppModal title={`Finans Duzenle #${editingItem.id}`} onClose={() => setEditingItem(null)}>
          <form className="finans-form-grid" onSubmit={handleEditSubmit}>
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
              <span>Donem</span>
              <input
                type="month"
                value={editForm.donem}
                onChange={(event) => setEditForm((prev) => ({ ...prev, donem: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Kalem Turu</span>
              <input
                type="text"
                value={editForm.kalemTuru}
                onChange={(event) => setEditForm((prev) => ({ ...prev, kalemTuru: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Tutar</span>
              <input
                type="number"
                min={0.01}
                step="0.01"
                value={editForm.tutar}
                onChange={(event) => setEditForm((prev) => ({ ...prev, tutar: event.target.value }))}
                required
              />
            </label>

            <label className="module-filter-field">
              <span>Aciklama</span>
              <input
                type="text"
                value={editForm.aciklama}
                onChange={(event) => setEditForm((prev) => ({ ...prev, aciklama: event.target.value }))}
              />
            </label>

            {editErrorMessage ? <p className="finans-form-error">{editErrorMessage}</p> : null}

            <div className="universal-btn-group">
              <button type="submit" className="universal-btn-save" disabled={isEditSubmitting}>
                {isEditSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => setEditingItem(null)}
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
