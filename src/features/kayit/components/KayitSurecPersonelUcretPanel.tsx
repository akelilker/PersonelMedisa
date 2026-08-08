import { useEffect, useRef, useState, type FormEvent } from "react";
import { FormField } from "../../../components/form/FormField";
import { dataCacheKeys, deleteCacheEntry, getActiveSube } from "../../../data/data-manager";
import { updatePersonel } from "../../../api/personeller.api";
import { getApiErrorMessage } from "../../../api/api-client";
import { PersonelUcretGecmisiSection } from "../../personeller/components/personel-dosya/PersonelUcretGecmisiSection";
import { mapUcretTipiSelectOptions } from "../../../lib/display/ucret-tipi-display";
import type { IdOption } from "../../../types/referans";
import type { Personel } from "../../../types/personel";
import { toOptionalIdValue } from "../kayit-surec-utils";

type KayitSurecPersonelUcretPanelProps = {
  personel: Personel;
  canManageUcret: boolean;
  canUpdatePersonel: boolean;
  ucretTipiOptions: IdOption[];
  isActive: boolean;
  onBusyChange?: (busy: boolean) => void;
  onPersonelUpdated: (updated: Personel) => void;
};

export function KayitSurecPersonelUcretPanel({
  personel,
  canManageUcret,
  canUpdatePersonel,
  ucretTipiOptions,
  isActive,
  onBusyChange,
  onPersonelUpdated
}: KayitSurecPersonelUcretPanelProps) {
  const [ucretTipiId, setUcretTipiId] = useState(toOptionalIdValue(personel.ucret_tipi_id));
  const [tipiSubmitting, setTipiSubmitting] = useState(false);
  const [tipiError, setTipiError] = useState<string | null>(null);
  const [tipiInfo, setTipiInfo] = useState<string | null>(null);
  const [historyBusy, setHistoryBusy] = useState(false);

  const tipiSubmittingRef = useRef(false);
  const historyBusyRef = useRef(false);
  const onBusyChangeRef = useRef(onBusyChange);
  onBusyChangeRef.current = onBusyChange;

  const canEditUcretTipi = canManageUcret && canUpdatePersonel;
  const wageBusy = tipiSubmitting || historyBusy;
  const hasUcretTipiDiff = ucretTipiId !== toOptionalIdValue(personel.ucret_tipi_id);
  const ucretTipiSelectOptions = mapUcretTipiSelectOptions(ucretTipiOptions);

  useEffect(() => {
    setUcretTipiId(toOptionalIdValue(personel.ucret_tipi_id));
    setTipiError(null);
    setTipiInfo(null);
  }, [personel.id, personel.ucret_tipi_id]);

  function publishBusy(nextTipi: boolean, nextHistory: boolean) {
    tipiSubmittingRef.current = nextTipi;
    historyBusyRef.current = nextHistory;
    onBusyChangeRef.current?.(nextTipi || nextHistory);
  }

  useEffect(() => {
    publishBusy(tipiSubmitting, historyBusy);
  }, [tipiSubmitting, historyBusy]);

  function handleHistoryBusy(busy: boolean) {
    setHistoryBusy(busy);
    // Publish immediately via refs so unmount cannot leave parent stuck/unlocked early.
    publishBusy(tipiSubmittingRef.current, busy);
  }

  // Do NOT clear parent lock on unmount — in-flight wage mutations must keep
  // personel context locked until their own finally/publishBusy settles.

  async function handleUcretTipiSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!canEditUcretTipi || tipiSubmitting || historyBusy || !hasUcretTipiDiff) {
      return;
    }

    if (!ucretTipiId) {
      setTipiInfo(null);
      setTipiError("Ücret tipi seçilmelidir.");
      return;
    }

    setTipiSubmitting(true);
    publishBusy(true, historyBusyRef.current);
    setTipiError(null);
    setTipiInfo(null);

    try {
      const updated = await updatePersonel(personel.id, {
        ucret_tipi_id: Number.parseInt(ucretTipiId, 10)
      });
      deleteCacheEntry(dataCacheKeys.personelDetail(getActiveSube(), personel.id));
      onPersonelUpdated(updated);
      setTipiInfo("Ücret tipi güncellendi.");
    } catch (error) {
      setTipiError(getApiErrorMessage(error, "Ücret tipi güncellenemedi."));
    } finally {
      setTipiSubmitting(false);
      // Publish from refs so unmount/tab switch cannot drop lock via stale closure.
      publishBusy(false, historyBusyRef.current);
    }
  }

  return (
    <div className="surec-shell-panel" data-testid="kayit-surec-ucret-panel">
      <div className="surec-person-general-head">
        <div>
          <p className="surec-shell-summary-kicker">Ücret Bilgisi</p>
          <h4 className="surec-person-general-title">Ücret geçmişi ve ücret tipi</h4>
        </div>
      </div>

      <form
        className="workspace-form workspace-form-stack workspace-form-stack--compact"
        onSubmit={handleUcretTipiSubmit}
        data-testid="kayit-surec-ucret-tipi-form"
      >
        <FormField
          as="select"
          label="Ücret Tipi"
          name="kayit-surec-ucret-tipi"
          value={ucretTipiId}
          onChange={(value) => {
            setUcretTipiId(value);
            setTipiError(null);
            setTipiInfo(null);
          }}
          disabled={!canEditUcretTipi || wageBusy}
          placeholderOption={{ value: "", label: "Seçiniz" }}
          selectOptions={ucretTipiSelectOptions}
        />
        {canEditUcretTipi ? (
          <div className="workspace-inline-actions">
            <button
              type="submit"
              className="universal-btn-aux"
              disabled={wageBusy || !hasUcretTipiDiff}
              data-testid="kayit-surec-ucret-tipi-kaydet"
            >
              {tipiSubmitting ? "Kaydediliyor..." : "Ücret Tipini Kaydet"}
            </button>
          </div>
        ) : null}
        {tipiError ? (
          <p className="workspace-error" role="alert" data-testid="kayit-surec-ucret-tipi-hata">
            {tipiError}
          </p>
        ) : null}
        {tipiInfo ? (
          <p className="workspace-success" data-testid="kayit-surec-ucret-tipi-bilgi">
            {tipiInfo}
          </p>
        ) : null}
      </form>

      <PersonelUcretGecmisiSection
        personel={personel}
        canManageUcret={canManageUcret}
        isActive={isActive}
        externalBusy={tipiSubmitting}
        onBusyChange={handleHistoryBusy}
        onSalaryMutationSuccess={onPersonelUpdated}
      />
    </div>
  );
}
