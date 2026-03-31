import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { createPersonel, fetchPersonellerList } from "../../../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions
} from "../../../api/referans.api";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";

const PAGE_SIZE = 10;

type PersonellerFilters = {
  search: string;
  aktiflik: "aktif" | "pasif" | "tum";
};

type CreatePersonelFormState = {
  tcKimlikNo: string;
  ad: string;
  soyad: string;
  dogumTarihi: string;
  telefon: string;
  acilDurumKisi: string;
  acilDurumTelefon: string;
  sicilNo: string;
  iseGirisTarihi: string;
  departmanId: string;
  gorevId: string;
  personelTipiId: string;
  aktifDurum: "AKTIF" | "PASIF";
  dogumYeri: string;
  kanGrubu: string;
  bagliAmirId: string;
};

const INITIAL_CREATE_FORM: CreatePersonelFormState = {
  tcKimlikNo: "",
  ad: "",
  soyad: "",
  dogumTarihi: "",
  telefon: "",
  acilDurumKisi: "",
  acilDurumTelefon: "",
  sicilNo: "",
  iseGirisTarihi: "",
  departmanId: "",
  gorevId: "",
  personelTipiId: "",
  aktifDurum: "AKTIF",
  dogumYeri: "",
  kanGrubu: "",
  bagliAmirId: ""
};

function parseRequiredPositiveInt(value: string, label: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }

  return number;
}

function parseOptionalPositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }
  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number <= 0) {
    return undefined;
  }
  return number;
}

export function PersonellerPage() {
  const [filters, setFilters] = useState<PersonellerFilters>({
    search: "",
    aktiflik: "tum"
  });
  const [searchInput, setSearchInput] = useState("");
  const [aktiflikInput, setAktiflikInput] = useState<"aktif" | "pasif" | "tum">("tum");
  const [page, setPage] = useState(1);
  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [hasNextPage, setHasNextPage] = useState(false);
  const [totalPages, setTotalPages] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
  const [isCreateSubmitting, setIsCreateSubmitting] = useState(false);
  const [createErrorMessage, setCreateErrorMessage] = useState<string | null>(null);
  const [createForm, setCreateForm] = useState<CreatePersonelFormState>(INITIAL_CREATE_FORM);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [gorevOptions, setGorevOptions] = useState<IdOption[]>([]);
  const [personelTipiOptions, setPersonelTipiOptions] = useState<IdOption[]>([]);
  const [bagliAmirOptions, setBagliAmirOptions] = useState<IdOption[]>([]);
  const [referenceError, setReferenceError] = useState<string | null>(null);
  const { hasPermission } = useRoleAccess();
  const canCreatePersonel = hasPermission("personeller.create");
  const canOpenDetail = hasPermission("personeller.detail.view");

  const loadPersoneller = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const nextData = await fetchPersonellerList({
        search: filters.search || undefined,
        aktiflik: filters.aktiflik,
        page,
        limit: PAGE_SIZE
      });
      setPersoneller(nextData.items);
      setHasNextPage(nextData.pagination.hasNextPage ?? nextData.items.length === PAGE_SIZE);
      setTotalPages(nextData.pagination.totalPages);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Personel listesi alinamadi.");
      setHasNextPage(false);
      setTotalPages(null);
    } finally {
      setIsLoading(false);
    }
  }, [filters.aktiflik, filters.search, page]);

  useEffect(() => {
    void loadPersoneller();
  }, [loadPersoneller]);

  useEffect(() => {
    let isCancelled = false;

    async function loadReferences() {
      setReferenceError(null);
      try {
        const [departmanlar, gorevler, personelTipleri, bagliAmirler] = await Promise.all([
          fetchDepartmanOptions(),
          fetchGorevOptions(),
          fetchPersonelTipiOptions(),
          fetchBagliAmirOptions()
        ]);

        if (isCancelled) {
          return;
        }

        setDepartmanOptions(departmanlar);
        setGorevOptions(gorevler);
        setPersonelTipiOptions(personelTipleri);
        setBagliAmirOptions(bagliAmirler);
      } catch (error) {
        if (isCancelled) {
          return;
        }

        setReferenceError(
          error instanceof Error ? error.message : "Referans veriler alinamadi, manuel giris aktif."
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
      search: searchInput.trim(),
      aktiflik: aktiflikInput
    });
    setPage(1);
  }

  function handleFilterClear() {
    setSearchInput("");
    setAktiflikInput("tum");
    setFilters({
      search: "",
      aktiflik: "tum"
    });
    setPage(1);
  }

  async function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isCreateSubmitting) {
      return;
    }
    if (!canCreatePersonel) {
      setCreateErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setCreateErrorMessage(null);
    setIsCreateSubmitting(true);

    try {
      const bagliAmirId = parseOptionalPositiveInt(createForm.bagliAmirId);
      await createPersonel({
        tc_kimlik_no: createForm.tcKimlikNo.trim(),
        ad: createForm.ad.trim(),
        soyad: createForm.soyad.trim(),
        dogum_tarihi: createForm.dogumTarihi,
        telefon: createForm.telefon.trim(),
        acil_durum_kisi: createForm.acilDurumKisi.trim(),
        acil_durum_telefon: createForm.acilDurumTelefon.trim(),
        sicil_no: createForm.sicilNo.trim(),
        ise_giris_tarihi: createForm.iseGirisTarihi,
        departman_id: parseRequiredPositiveInt(createForm.departmanId, "Departman ID"),
        gorev_id: parseRequiredPositiveInt(createForm.gorevId, "Gorev ID"),
        personel_tipi_id: parseRequiredPositiveInt(createForm.personelTipiId, "Personel Tipi ID"),
        aktif_durum: createForm.aktifDurum,
        ...(createForm.dogumYeri.trim() ? { dogum_yeri: createForm.dogumYeri.trim() } : {}),
        ...(createForm.kanGrubu.trim() ? { kan_grubu: createForm.kanGrubu.trim() } : {}),
        ...(bagliAmirId !== undefined ? { bagli_amir_id: bagliAmirId } : {})
      });

      setIsCreateModalOpen(false);
      setCreateForm(INITIAL_CREATE_FORM);
      if (page === 1) {
        await loadPersoneller();
      } else {
        setPage(1);
      }
    } catch (error) {
      setCreateErrorMessage(
        error instanceof Error ? error.message : "Personel kaydi sirasinda bir hata olustu."
      );
    } finally {
      setIsCreateSubmitting(false);
    }
  }

  return (
    <section className="personeller-page">
      <div className="personeller-header-row">
        <h2>Personeller</h2>
        {canCreatePersonel ? (
          <button
            type="button"
            className="universal-btn-aux"
            onClick={() => {
              setCreateErrorMessage(null);
              setIsCreateModalOpen(true);
            }}
          >
            Yeni Personel
          </button>
        ) : null}
      </div>

      <form className="module-filter-form" onSubmit={handleFilterSubmit}>
        <div className="module-filter-grid">
          <label className="module-filter-field">
            <span>Ara</span>
            <input
              type="text"
              placeholder="Ad, soyad veya T.C. Kimlik No"
              value={searchInput}
              onChange={(event) => setSearchInput(event.target.value)}
            />
          </label>

          <label className="module-filter-field">
            <span>Aktiflik</span>
            <select
              value={aktiflikInput}
              onChange={(event) =>
                setAktiflikInput(event.target.value as "aktif" | "pasif" | "tum")
              }
            >
              <option value="tum">Tum</option>
              <option value="aktif">Aktif</option>
              <option value="pasif">Pasif</option>
            </select>
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

      {isLoading ? <LoadingState label="Personel verileri yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadPersoneller()} />
      ) : null}

      {!isLoading && !errorMessage && personeller.length === 0 ? (
        <EmptyState
          title="Personel kaydi bulunamadi"
          message="Filtre veya kaynak veri durumunu kontrol et."
        />
      ) : null}

      {!isLoading && !errorMessage && personeller.length > 0 ? (
        <ul className="personeller-list">
          {personeller.map((personel) => (
            <li key={personel.id} className="personeller-item">
              <div>
                <strong>{`${personel.ad} ${personel.soyad}`}</strong>
                <p>Durum: {personel.aktif_durum}</p>
              </div>
              {canOpenDetail ? <Link to={`/personeller/${personel.id}`}>Detay</Link> : null}
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
        <Link to="/surecler">Surec takibe git</Link>
        <Link to="/bildirimler">Bildirimlere git</Link>
        <Link to="/puantaj">Puantaja git</Link>
      </div>

      {canCreatePersonel && isCreateModalOpen ? (
        <AppModal title="Yeni Personel Ekle" onClose={() => setIsCreateModalOpen(false)}>
          <form className="personel-create-form" onSubmit={handleCreateSubmit}>
            <div className="personel-create-grid">
              <label className="module-filter-field">
                <span>T.C. Kimlik No</span>
                <input
                  type="text"
                  value={createForm.tcKimlikNo}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, tcKimlikNo: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Ad</span>
                <input
                  type="text"
                  value={createForm.ad}
                  onChange={(event) => setCreateForm((prev) => ({ ...prev, ad: event.target.value }))}
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Soyad</span>
                <input
                  type="text"
                  value={createForm.soyad}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, soyad: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Dogum Tarihi</span>
                <input
                  type="date"
                  value={createForm.dogumTarihi}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, dogumTarihi: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Telefon</span>
                <input
                  type="tel"
                  value={createForm.telefon}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, telefon: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Acil Durum Kisi</span>
                <input
                  type="text"
                  value={createForm.acilDurumKisi}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, acilDurumKisi: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Acil Durum Telefon</span>
                <input
                  type="tel"
                  value={createForm.acilDurumTelefon}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, acilDurumTelefon: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Dogum Yeri</span>
                <input
                  type="text"
                  value={createForm.dogumYeri}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, dogumYeri: event.target.value }))
                  }
                />
              </label>

              <label className="module-filter-field">
                <span>Kan Grubu</span>
                <input
                  type="text"
                  value={createForm.kanGrubu}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, kanGrubu: event.target.value }))
                  }
                />
              </label>

              <label className="module-filter-field">
                <span>Sicil No</span>
                <input
                  type="text"
                  value={createForm.sicilNo}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, sicilNo: event.target.value }))
                  }
                  required
                />
              </label>

              <label className="module-filter-field">
                <span>Ise Giris Tarihi</span>
                <input
                  type="date"
                  value={createForm.iseGirisTarihi}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, iseGirisTarihi: event.target.value }))
                  }
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
              <span>Gorev ID</span>
              {gorevOptions.length > 0 ? (
                <select
                  value={createForm.gorevId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, gorevId: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {gorevOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="number"
                  min={1}
                  value={createForm.gorevId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, gorevId: event.target.value }))
                  }
                  required
                />
              )}
            </label>

            <label className="module-filter-field">
              <span>Bagli Amir</span>
              {bagliAmirOptions.length > 0 ? (
                <select
                  value={createForm.bagliAmirId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, bagliAmirId: event.target.value }))
                  }
                >
                  <option value="">Seciniz</option>
                  {bagliAmirOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="number"
                  min={1}
                  value={createForm.bagliAmirId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, bagliAmirId: event.target.value }))
                  }
                />
              )}
            </label>

            <label className="module-filter-field">
              <span>Personel Tipi ID</span>
              {personelTipiOptions.length > 0 ? (
                <select
                  value={createForm.personelTipiId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, personelTipiId: event.target.value }))
                  }
                  required
                >
                  <option value="">Seciniz</option>
                  {personelTipiOptions.map((option) => (
                    <option key={option.id} value={option.id}>
                      {option.label}
                    </option>
                  ))}
                </select>
              ) : (
                <input
                  type="number"
                  min={1}
                  value={createForm.personelTipiId}
                  onChange={(event) =>
                    setCreateForm((prev) => ({ ...prev, personelTipiId: event.target.value }))
                  }
                  required
                />
              )}
            </label>

              <label className="module-filter-field">
                <span>Aktif Durum</span>
                <select
                  value={createForm.aktifDurum}
                  onChange={(event) =>
                    setCreateForm((prev) => ({
                      ...prev,
                      aktifDurum: event.target.value as "AKTIF" | "PASIF"
                    }))
                  }
                >
                  <option value="AKTIF">AKTIF</option>
                  <option value="PASIF">PASIF</option>
                </select>
              </label>
            </div>

            {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}

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
    </section>
  );
}
