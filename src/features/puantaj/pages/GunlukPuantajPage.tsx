import { useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { fetchGunlukPuantaj, upsertGunlukPuantaj } from "../../../api/puantaj.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { GunlukPuantaj } from "../../../types/puantaj";

type QueryFormState = {
  personelId: string;
  tarih: string;
};

type PuantajFormState = {
  girisSaati: string;
  cikisSaati: string;
  gercekMolaDakika: string;
};

type ActiveQuery = {
  personelId: number;
  tarih: string;
};

function toDateInputValue(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function parseRequiredPositiveInt(value: string, label: string) {
  const number = Number.parseInt(value, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error(`${label} pozitif sayi olmalidir.`);
  }

  return number;
}

function parseOptionalNonNegativeInt(value: string) {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number < 0) {
    throw new Error("Gercek mola dakika sifirdan kucuk olamaz.");
  }

  return number;
}

function toPuantajFormState(puantaj: GunlukPuantaj | null): PuantajFormState {
  return {
    girisSaati: puantaj?.giris_saati ?? "",
    cikisSaati: puantaj?.cikis_saati ?? "",
    gercekMolaDakika:
      puantaj?.gercek_mola_dakika !== undefined ? String(puantaj.gercek_mola_dakika) : ""
  };
}

export function GunlukPuantajPage() {
  const { hasPermission } = useRoleAccess();
  const canUpdatePuantaj = hasPermission("puantaj.update");

  const [queryForm, setQueryForm] = useState<QueryFormState>({
    personelId: "",
    tarih: toDateInputValue(new Date())
  });
  const [activeQuery, setActiveQuery] = useState<ActiveQuery | null>(null);
  const [puantaj, setPuantaj] = useState<GunlukPuantaj | null>(null);
  const [puantajForm, setPuantajForm] = useState<PuantajFormState>(toPuantajFormState(null));
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);

  async function loadPuantaj(query: ActiveQuery) {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchGunlukPuantaj(query.personelId, query.tarih);
      setPuantaj(data);
      setPuantajForm(toPuantajFormState(data));
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Gunluk puantaj kaydi alinamadi.");
      setPuantaj(null);
      setPuantajForm(toPuantajFormState(null));
    } finally {
      setIsLoading(false);
    }
  }

  async function handleQuerySubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    try {
      const personelId = parseRequiredPositiveInt(queryForm.personelId, "Personel ID");
      if (!queryForm.tarih) {
        throw new Error("Tarih zorunludur.");
      }

      const nextQuery = {
        personelId,
        tarih: queryForm.tarih
      };

      setActiveQuery(nextQuery);
      await loadPuantaj(nextQuery);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Puantaj sorgusu gecersiz.");
    }
  }

  function handleQueryClear() {
    setQueryForm({
      personelId: "",
      tarih: toDateInputValue(new Date())
    });
    setActiveQuery(null);
    setPuantaj(null);
    setPuantajForm(toPuantajFormState(null));
    setErrorMessage(null);
    setSubmitErrorMessage(null);
  }

  async function handlePuantajSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    if (!activeQuery) {
      setSubmitErrorMessage("Kayit guncellemek icin once personel ve tarih sec.");
      return;
    }

    if (!canUpdatePuantaj) {
      setSubmitErrorMessage("Bu islem icin yetkin bulunmuyor.");
      return;
    }

    setSubmitErrorMessage(null);
    setIsSubmitting(true);

    try {
      const girisSaati = puantajForm.girisSaati.trim();
      const cikisSaati = puantajForm.cikisSaati.trim();

      if (!girisSaati || !cikisSaati) {
        throw new Error("Giris ve cikis saati zorunludur.");
      }

      const updated = await upsertGunlukPuantaj(activeQuery.personelId, activeQuery.tarih, {
        giris_saati: girisSaati,
        cikis_saati: cikisSaati,
        gercek_mola_dakika: parseOptionalNonNegativeInt(puantajForm.gercekMolaDakika)
      });

      setPuantaj(updated);
      setPuantajForm(toPuantajFormState(updated));
    } catch (error) {
      setSubmitErrorMessage(error instanceof Error ? error.message : "Puantaj kaydi guncellenemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <section className="puantaj-page">
      <div className="puantaj-header-row">
        <h2>Gunluk Puantaj</h2>
      </div>

      <form className="module-filter-form" onSubmit={handleQuerySubmit}>
        <div className="module-filter-grid">
          <label className="module-filter-field">
            <span>Personel ID</span>
            <input
              type="number"
              min={1}
              value={queryForm.personelId}
              onChange={(event) => setQueryForm((prev) => ({ ...prev, personelId: event.target.value }))}
              required
            />
          </label>

          <label className="module-filter-field">
            <span>Tarih</span>
            <input
              type="date"
              value={queryForm.tarih}
              onChange={(event) => setQueryForm((prev) => ({ ...prev, tarih: event.target.value }))}
              required
            />
          </label>
        </div>

        <div className="module-filter-actions">
          <button type="submit" className="state-action-btn" disabled={isLoading}>
            Kaydi Getir
          </button>
          <button type="button" className="state-action-btn" onClick={handleQueryClear} disabled={isLoading}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Puantaj verisi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState
          message={errorMessage}
          onRetry={activeQuery ? () => void loadPuantaj(activeQuery) : undefined}
        />
      ) : null}

      {!isLoading && !errorMessage && activeQuery && !puantaj ? (
        <EmptyState
          title="Kayit bulunamadi"
          message="Secilen gun icin puantaj kaydi yok. Asagidan kayit olusturabilirsin."
        />
      ) : null}

      {!isLoading && !errorMessage && puantaj ? (
        <div className="puantaj-detail-card">
          <p>
            <strong>Personel ID:</strong> {puantaj.personel_id}
          </p>
          <p>
            <strong>Tarih:</strong> {puantaj.tarih}
          </p>
          <p>
            <strong>Durum:</strong> {puantaj.state ?? "-"}
          </p>
          <p>
            <strong>Hesaplanan Mola (dk):</strong>{" "}
            {puantaj.hesaplanan_mola_dakika !== undefined ? puantaj.hesaplanan_mola_dakika : "-"}
          </p>
          <p>
            <strong>Net Calisma (dk):</strong>{" "}
            {puantaj.net_calisma_suresi_dakika !== undefined ? puantaj.net_calisma_suresi_dakika : "-"}
          </p>
          <p>
            <strong>Gunluk Brut Sure (dk):</strong>{" "}
            {puantaj.gunluk_brut_sure_dakika !== undefined ? puantaj.gunluk_brut_sure_dakika : "-"}
          </p>

          {puantaj.compliance_uyarilari.length > 0 ? (
            <ul className="puantaj-alert-list">
              {puantaj.compliance_uyarilari.map((uyari, index) => (
                <li key={`${uyari.code}-${index}`}>
                  <strong>{uyari.level ?? "UYARI"}:</strong> {uyari.message}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      <div className="puantaj-edit-card">
        <h3>Giris Cikis Kaydi</h3>

        <form className="puantaj-form-grid" onSubmit={handlePuantajSubmit}>
          <label className="module-filter-field">
            <span>Giris Saati</span>
            <input
              type="time"
              value={puantajForm.girisSaati}
              onChange={(event) => setPuantajForm((prev) => ({ ...prev, girisSaati: event.target.value }))}
              required
            />
          </label>

          <label className="module-filter-field">
            <span>Cikis Saati</span>
            <input
              type="time"
              value={puantajForm.cikisSaati}
              onChange={(event) => setPuantajForm((prev) => ({ ...prev, cikisSaati: event.target.value }))}
              required
            />
          </label>

          <label className="module-filter-field">
            <span>Gercek Mola (dk)</span>
            <input
              type="number"
              min={0}
              value={puantajForm.gercekMolaDakika}
              onChange={(event) =>
                setPuantajForm((prev) => ({ ...prev, gercekMolaDakika: event.target.value }))
              }
            />
          </label>

          {submitErrorMessage ? <p className="puantaj-form-error">{submitErrorMessage}</p> : null}
          {!canUpdatePuantaj ? (
            <p className="puantaj-form-readonly">Bu modulu sadece goruntuleme yetkin var.</p>
          ) : null}

          <div className="module-filter-actions">
            <button
              type="submit"
              className="state-action-btn"
              disabled={!activeQuery || !canUpdatePuantaj || isSubmitting}
            >
              {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
            </button>
          </div>
        </form>
      </div>

      <div className="module-links">
        <Link to="/haftalik-kapanis">Haftalik kapanisa git</Link>
        <Link to="/surecler">Surec takibe don</Link>
      </div>
    </section>
  );
}
