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
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";

const PAGE_SIZE = 10;

const PERSONEL_CREATE_FORM_ID = "personel-create-form";

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

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
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

  const aktiflikSelectOptions = [
    { value: "tum", label: "Tum" },
    { value: "aktif", label: "Aktif" },
    { value: "pasif", label: "Pasif" }
  ];

  const aktifDurumOptions = [
    { value: "AKTIF", label: "AKTIF" },
    { value: "PASIF", label: "PASIF" }
  ];

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

      <form className="form-filter-panel" onSubmit={handleFilterSubmit}>
        <div className="form-field-grid">
          <FormField
            label="Ara"
            name="personel-filter-search"
            placeholder="Ad, soyad veya T.C. Kimlik No"
            value={searchInput}
            onChange={setSearchInput}
          />
          <FormField
            as="select"
            label="Aktiflik"
            name="personel-filter-aktiflik"
            value={aktiflikInput}
            onChange={(value) => setAktiflikInput(value as "aktif" | "pasif" | "tum")}
            selectOptions={aktiflikSelectOptions}
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
        <AppModal
          title="Yeni Personel Ekle"
          onClose={() => setIsCreateModalOpen(false)}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_CREATE_FORM_ID}
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
          <form
            id={PERSONEL_CREATE_FORM_ID}
            className="personel-create-form"
            onSubmit={handleCreateSubmit}
          >
            <div className="personel-create-grid">
              <FormField
                label="T.C. Kimlik No"
                name="create-tc"
                value={createForm.tcKimlikNo}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, tcKimlikNo: value }))}
                required
              />
              <FormField
                label="Ad"
                name="create-ad"
                value={createForm.ad}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, ad: value }))}
                required
              />
              <FormField
                label="Soyad"
                name="create-soyad"
                value={createForm.soyad}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, soyad: value }))}
                required
              />
              <FormField
                label="Dogum Tarihi"
                name="create-dogum"
                type="date"
                value={createForm.dogumTarihi}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, dogumTarihi: value }))}
                required
              />
              <FormField
                label="Telefon"
                name="create-telefon"
                type="tel"
                value={createForm.telefon}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, telefon: value }))}
                required
              />
              <FormField
                label="Acil Durum Kisi"
                name="create-acil-kisi"
                value={createForm.acilDurumKisi}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, acilDurumKisi: value }))}
                required
              />
              <FormField
                label="Acil Durum Telefon"
                name="create-acil-tel"
                type="tel"
                value={createForm.acilDurumTelefon}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, acilDurumTelefon: value }))}
                required
              />
              <FormField
                label="Dogum Yeri"
                name="create-dogum-yeri"
                value={createForm.dogumYeri}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, dogumYeri: value }))}
              />
              <FormField
                label="Kan Grubu"
                name="create-kan"
                value={createForm.kanGrubu}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, kanGrubu: value }))}
              />
              <FormField
                label="Sicil No"
                name="create-sicil"
                value={createForm.sicilNo}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, sicilNo: value }))}
                required
              />
              <FormField
                label="Ise Giris Tarihi"
                name="create-ise-giris"
                type="date"
                value={createForm.iseGirisTarihi}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, iseGirisTarihi: value }))}
                required
              />
              {departmanOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Departman ID"
                  name="create-departman"
                  value={createForm.departmanId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(departmanOptions)}
                />
              ) : (
                <FormField
                  label="Departman ID"
                  name="create-departman-num"
                  type="number"
                  min={1}
                  value={createForm.departmanId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, departmanId: value }))}
                  required
                />
              )}
              {gorevOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Gorev ID"
                  name="create-gorev"
                  value={createForm.gorevId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, gorevId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(gorevOptions)}
                />
              ) : (
                <FormField
                  label="Gorev ID"
                  name="create-gorev-num"
                  type="number"
                  min={1}
                  value={createForm.gorevId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, gorevId: value }))}
                  required
                />
              )}
              {bagliAmirOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Bagli Amir"
                  name="create-bagli-amir"
                  value={createForm.bagliAmirId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, bagliAmirId: value }))}
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(bagliAmirOptions)}
                />
              ) : (
                <FormField
                  label="Bagli Amir"
                  name="create-bagli-amir-num"
                  type="number"
                  min={1}
                  value={createForm.bagliAmirId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, bagliAmirId: value }))}
                />
              )}
              {personelTipiOptions.length > 0 ? (
                <FormField
                  as="select"
                  label="Personel Tipi ID"
                  name="create-personel-tipi"
                  value={createForm.personelTipiId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, personelTipiId: value }))}
                  required
                  placeholderOption={{ value: "", label: "Seciniz" }}
                  selectOptions={toSelectOptions(personelTipiOptions)}
                />
              ) : (
                <FormField
                  label="Personel Tipi ID"
                  name="create-personel-tipi-num"
                  type="number"
                  min={1}
                  value={createForm.personelTipiId}
                  onChange={(value) => setCreateForm((prev) => ({ ...prev, personelTipiId: value }))}
                  required
                />
              )}
              <FormField
                as="select"
                label="Aktif Durum"
                name="create-aktif-durum"
                value={createForm.aktifDurum}
                onChange={(value) =>
                  setCreateForm((prev) => ({ ...prev, aktifDurum: value as "AKTIF" | "PASIF" }))
                }
                selectOptions={aktifDurumOptions}
              />
            </div>

            {createErrorMessage ? <p className="personel-create-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="personel-create-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
