import { useCallback, useEffect, useState } from "react";
import {
  fetchFazlaCalismaOdemeTercihi,
  putFazlaCalismaOdemeTercihi
} from "../../../api/fazla-calisma-odeme-tercihi.api";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type {
  FazlaCalismaOdemeTercihi,
  OdemeTipi
} from "../../../types/fazla-calisma-odeme-tercihi";
import { ODEME_TIPI_VALUES } from "../../../types/fazla-calisma-odeme-tercihi";

const ODEME_TIPI_LABELS: Record<OdemeTipi, string> = {
  KARAR_BEKLIYOR: "Karar bekliyor",
  UCRET: "Ücret",
  SERBEST_ZAMAN: "Serbest zaman"
};

type FazlaCalismaOdemeTercihiPanelProps = {
  snapshotId: number;
  personelId?: number;
  fazlaCalismaDakika?: number;
  canEdit: boolean;
};

export function FazlaCalismaOdemeTercihiPanel({
  snapshotId,
  personelId,
  fazlaCalismaDakika,
  canEdit
}: FazlaCalismaOdemeTercihiPanelProps) {
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [record, setRecord] = useState<FazlaCalismaOdemeTercihi | null>(null);
  const [odemeTipi, setOdemeTipi] = useState<OdemeTipi>("KARAR_BEKLIYOR");
  const [talepTarihi, setTalepTarihi] = useState("");
  const [belgeId, setBelgeId] = useState("");
  const [gerekce, setGerekce] = useState("");

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    setSuccess(null);
    try {
      const data = await fetchFazlaCalismaOdemeTercihi(snapshotId);
      setRecord(data);
      setOdemeTipi(data.odeme_tipi);
      setTalepTarihi(data.talep_tarihi ?? "");
      setBelgeId(
        data.imzali_talep_belge_id !== undefined ? String(data.imzali_talep_belge_id) : ""
      );
      setGerekce(data.gerekce ?? "");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ödeme tercihi yüklenemedi.");
      setRecord(null);
    } finally {
      setLoading(false);
    }
  }, [snapshotId]);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleSave() {
    if (!canEdit || saving) return;
    setSaving(true);
    setError(null);
    setSuccess(null);
    try {
      const parsedBelgeId = belgeId.trim() ? Number.parseInt(belgeId.trim(), 10) : undefined;
      const saved = await putFazlaCalismaOdemeTercihi({
        snapshot_id: snapshotId,
        odeme_tipi: odemeTipi,
        ...(gerekce.trim() ? { gerekce: gerekce.trim() } : {}),
        ...(talepTarihi.trim() ? { talep_tarihi: talepTarihi.trim() } : {}),
        ...(parsedBelgeId !== undefined && Number.isFinite(parsedBelgeId) && parsedBelgeId >= 1
          ? { imzali_talep_belge_id: parsedBelgeId }
          : {})
      });
      setRecord(saved);
      setOdemeTipi(saved.odeme_tipi);
      setTalepTarihi(saved.talep_tarihi ?? "");
      setBelgeId(
        saved.imzali_talep_belge_id !== undefined ? String(saved.imzali_talep_belge_id) : ""
      );
      setGerekce(saved.gerekce ?? "");
      setSuccess("Ödeme tercihi kaydedildi.");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Ödeme tercihi kaydedilemedi.");
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <LoadingState label="Ödeme tercihi yükleniyor..." />;
  }

  if (error && !record) {
    return <ErrorState message={error} />;
  }

  const displayPersonelId = personelId ?? record?.personel_id;
  const displayFazlaDk = fazlaCalismaDakika ?? record?.fazla_calisma_dakika;
  const fieldsDisabled = !canEdit || saving;

  return (
    <section
      className="yonetim-page"
      data-testid="fm-odeme-tercihi-panel"
      aria-label="Fazla çalışma ödeme tercihi"
    >
      <div className="kapanis-ozet-grid">
        <div>
          <strong>Snapshot</strong>
          <p data-testid="fm-odeme-tercihi-snapshot-id">{snapshotId}</p>
        </div>
        {displayPersonelId !== undefined ? (
          <div>
            <strong>Personel</strong>
            <p data-testid="fm-odeme-tercihi-personel-id">{displayPersonelId}</p>
          </div>
        ) : null}
        {displayFazlaDk !== undefined ? (
          <div>
            <strong>Fazla çalışma (dk)</strong>
            <p data-testid="fm-odeme-tercihi-fazla-dk">{displayFazlaDk}</p>
          </div>
        ) : null}
      </div>

      <form
        className="form-filter-panel"
        onSubmit={(event) => {
          event.preventDefault();
          void handleSave();
        }}
      >
        <fieldset className="form-field-grid" disabled={fieldsDisabled}>
          <legend>Ödeme tipi</legend>
          {ODEME_TIPI_VALUES.map((tip) => (
            <label key={tip} className="form-label">
              <input
                type="radio"
                name="fm-odeme-tipi"
                value={tip}
                checked={odemeTipi === tip}
                data-testid={`fm-odeme-tipi-${tip}`}
                onChange={() => setOdemeTipi(tip)}
              />{" "}
              {ODEME_TIPI_LABELS[tip]}
            </label>
          ))}
        </fieldset>

        {odemeTipi === "SERBEST_ZAMAN" ? (
          <div className="form-field-grid">
            <div className="form-section">
              <label className="form-label" htmlFor="fm-talep-tarihi">
                Talep tarihi
              </label>
              <input
                id="fm-talep-tarihi"
                name="fm-talep-tarihi"
                type="date"
                className="form-input"
                required
                disabled={fieldsDisabled}
                value={talepTarihi}
                data-testid="fm-talep-tarihi"
                onChange={(event) => setTalepTarihi(event.target.value)}
              />
            </div>
            <div className="form-section">
              <label className="form-label" htmlFor="fm-belge-id">
                İmzalı talep belge ID
              </label>
              <input
                id="fm-belge-id"
                name="fm-belge-id"
                type="number"
                className="form-input"
                min={1}
                required
                disabled={fieldsDisabled}
                value={belgeId}
                data-testid="fm-belge-id"
                onChange={(event) => setBelgeId(event.target.value)}
              />
            </div>
            <div className="form-section">
              <label className="form-label" htmlFor="fm-gerekce">
                Gerekçe / not
              </label>
              <textarea
                id="fm-gerekce"
                name="fm-gerekce"
                className="form-input"
                rows={3}
                required
                disabled={fieldsDisabled}
                value={gerekce}
                data-testid="fm-gerekce"
                onChange={(event) => setGerekce(event.target.value)}
              />
            </div>
          </div>
        ) : null}

        {error ? (
          <p className="yonetim-error" data-testid="fm-odeme-tercihi-error">
            {error}
          </p>
        ) : null}
        {success ? (
          <p className="yonetim-success" data-testid="fm-odeme-tercihi-success">
            {success}
          </p>
        ) : null}

        <div className="form-actions-row">
          <button
            type="submit"
            className="universal-btn-save"
            data-testid="fm-kaydet"
            disabled={fieldsDisabled}
          >
            {saving ? "Kaydediliyor..." : "Kaydet"}
          </button>
        </div>
      </form>
    </section>
  );
}
