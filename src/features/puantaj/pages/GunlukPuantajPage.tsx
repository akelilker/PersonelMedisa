import { type FormEvent } from "react";
import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePuantaj } from "../../../hooks/usePuantaj";

export function GunlukPuantajPage() {
  const { hasPermission } = useRoleAccess();
  const canUpdatePuantaj = hasPermission("puantaj.update");

  const {
    formState,
    patchFormState,
    activeQuery,
    puantaj,
    isLoading,
    isSubmitting,
    errorMessage,
    submitErrorMessage,
    submitQuery,
    clearQuery,
    refetchActive,
    submitPuantaj
  } = usePuantaj();

  function handleQuerySubmit(event: FormEvent<HTMLFormElement>) {
    void submitQuery(event);
  }

  function handlePuantajSubmit(event: FormEvent<HTMLFormElement>) {
    void submitPuantaj(event, canUpdatePuantaj);
  }

  return (
    <section className="puantaj-page">
      <div className="puantaj-header-row">
        <h2>Gunluk Puantaj</h2>
      </div>

      <form className="form-filter-panel" onSubmit={handleQuerySubmit}>
        <div className="form-field-grid">
          <FormField
            label="Personel ID"
            name="puantaj-query-personel"
            type="number"
            min={1}
            value={formState.queryPersonelId}
            onChange={(value) => patchFormState({ queryPersonelId: value })}
            required
          />
          <FormField
            label="Tarih"
            name="puantaj-query-tarih"
            type="date"
            value={formState.queryTarih}
            onChange={(value) => patchFormState({ queryTarih: value })}
            required
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux" disabled={isLoading}>
            Kaydi Getir
          </button>
          <button type="button" className="universal-btn-aux" onClick={clearQuery} disabled={isLoading}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Puantaj verisi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState
          message={errorMessage}
          onRetry={activeQuery ? () => void refetchActive() : undefined}
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
          <FormField
            label="Giris Saati"
            name="puantaj-giris"
            type="time"
            value={formState.entryGirisSaati}
            onChange={(value) => patchFormState({ entryGirisSaati: value })}
            required
          />
          <FormField
            label="Cikis Saati"
            name="puantaj-cikis"
            type="time"
            value={formState.entryCikisSaati}
            onChange={(value) => patchFormState({ entryCikisSaati: value })}
            required
          />
          <FormField
            label="Gercek Mola (dk)"
            name="puantaj-mola"
            type="number"
            min={0}
            value={formState.entryGercekMolaDakika}
            onChange={(value) => patchFormState({ entryGercekMolaDakika: value })}
          />

          {submitErrorMessage ? <p className="puantaj-form-error">{submitErrorMessage}</p> : null}
          {!canUpdatePuantaj ? (
            <p className="puantaj-form-readonly">Bu modulu sadece goruntuleme yetkin var.</p>
          ) : null}

          <div className="form-actions-row">
            <button
              type="submit"
              className="universal-btn-aux"
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
