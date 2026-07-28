import { useEffect, useMemo, useState } from "react";
import { ApiRequestError } from "../../../api/api-client";
import { downloadDonemKapanisPreflightCsv } from "../../../api/donem-kapanis.api";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import { muhurleAylikPuantaj } from "../../../api/puantaj.api";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";
import {
  useDonemKapanisPreflight,
  type DonemKapanisFilterState
} from "../../../hooks/useDonemKapanisPreflight";
import { currentMonthParts, parseAyValue } from "../../../lib/donem-kapanis/display";
import type { DonemKapanisIssue } from "../../../api/donem-kapanis.api";
import type { IdOption } from "../../../types/referans";
import { DonemKapanisFiltreleri } from "../components/donem-kapanis/DonemKapanisFiltreleri";
import { DonemDurumBandi } from "../components/donem-kapanis/DonemDurumBandi";
import { KapanisAuditPaneli } from "../components/donem-kapanis/KapanisAuditPaneli";
import { KapanisIssueListesi } from "../components/donem-kapanis/KapanisIssueListesi";
import { KapanisOzetKartlari } from "../components/donem-kapanis/KapanisOzetKartlari";
import { KapanisPersonelDetayModal } from "../components/donem-kapanis/KapanisPersonelDetayModal";

const DONEM_MUHUR_ONAY_MESAJI =
  "Seçili dönem mühürlenecek. Mühür sonrası puantaj kayıtları düzenlenemez. Devam edilsin mi?";

const INITIAL_FILTERS: DonemKapanisFilterState = {
  ay: currentMonthParts().ay,
  subeId: "",
  departmanId: "",
  personelId: ""
};

export function DonemKapanisMerkeziPage() {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const canExport = hasPermission("puantaj.donem_kapanis.export");
  const canMuhurle = hasPermission("puantaj.muhurle");

  const [filters, setFilters] = useState<DonemKapanisFilterState>(INITIAL_FILTERS);
  const [subeOptions, setSubeOptions] = useState<IdOption[]>([]);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [selectedIssue, setSelectedIssue] = useState<DonemKapanisIssue | null>(null);
  const [isExporting, setIsExporting] = useState(false);
  const [isSealing, setIsSealing] = useState(false);
  const [pendingSealConfirm, setPendingSealConfirm] = useState(false);
  const [sealDialogError, setSealDialogError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const parsedAy = parseAyValue(filters.ay);
  const subeId = filters.subeId ? Number.parseInt(filters.subeId, 10) : null;

  const {
    summary,
    audits,
    isLoading,
    isAuditsLoading,
    errorMessage,
    auditsErrorMessage,
    buildParams,
    refetch,
    refetchAudits
  } = useDonemKapanisPreflight({
    enabled: Boolean(parsedAy && subeId),
    filters,
    yil: parsedAy?.yil ?? currentMonthParts().yil,
    ay: parsedAy?.ay ?? currentMonthParts().ayNum,
    subeId: Number.isFinite(subeId) ? subeId : null
  });

  const sessionSubeKey = (session?.sube_list ?? []).map((sube) => `${sube.id}:${sube.ad}`).join("|");
  const allowedSubeKey = (session?.user?.sube_ids ?? []).join(",");

  useEffect(() => {
    const sessionSubeler = (session?.sube_list ?? []).map((sube) => ({ id: sube.id, label: sube.ad }));
    if (sessionSubeler.length > 0) {
      setSubeOptions(sessionSubeler);
    }
    const activeSubeId = session?.active_sube_id;
    if (activeSubeId) {
      setFilters((prev) =>
        prev.subeId === String(activeSubeId) ? prev : { ...prev, subeId: String(activeSubeId) }
      );
    }

    const allowedSubeIds = allowedSubeKey
      ? allowedSubeKey.split(",").map((value) => Number.parseInt(value, 10)).filter((id) => Number.isFinite(id))
      : [];

    void (async () => {
      try {
        const yonetimSubeler = await fetchYonetimSubeleri();
        if (yonetimSubeler.length > 0) {
          const scoped =
            allowedSubeIds.length > 0
              ? yonetimSubeler.filter((sube) => allowedSubeIds.includes(sube.id))
              : yonetimSubeler;
          if (scoped.length > 0) {
            setSubeOptions(scoped.map((sube) => ({ id: sube.id, label: sube.ad })));
          }
        }
      } catch {
        /* session sube_list fallback */
      }
      const departmanlar = await fetchDepartmanOptions();
      setDepartmanOptions(departmanlar);
    })();
  }, [session?.active_sube_id, sessionSubeKey, allowedSubeKey]);

  const sealDisabled = useMemo(() => {
    if (!summary || !canMuhurle || isSealing) {
      return true;
    }
    if (summary.muhur_state === "MUHURLENDI" || summary.donem_state === "MUHURLU") {
      return true;
    }
    return summary.blocker_count > 0 || !summary.kapanabilir_mi;
  }, [summary, canMuhurle, isSealing]);

  async function handleExport() {
    const params = buildParams();
    if (!params) {
      return;
    }

    setIsExporting(true);
    setActionError(null);
    try {
      await downloadDonemKapanisPreflightCsv(params);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : "CSV indirilemedi.");
    } finally {
      setIsExporting(false);
    }
  }

  function openSealDialog() {
    if (!parsedAy || !subeId || sealDisabled) {
      return;
    }
    setSealDialogError(null);
    setPendingSealConfirm(true);
  }

  function closeSealDialog() {
    if (isSealing) {
      return;
    }
    setPendingSealConfirm(false);
    setSealDialogError(null);
  }

  async function confirmSeal() {
    if (!parsedAy || !subeId || sealDisabled) {
      return;
    }

    setIsSealing(true);
    setActionMessage(null);
    setActionError(null);
    setSealDialogError(null);

    try {
      const result = await muhurleAylikPuantaj({ yil: parsedAy.yil, ay: parsedAy.ay });
      setActionMessage(
        `Dönem mühürlendi (${result.donem}). ${result.muhurlenen_kayit_sayisi} kayıt mühürlendi.`
      );
      setPendingSealConfirm(false);
      await refetch();
      await refetchAudits();
    } catch (error) {
      const message =
        error instanceof ApiRequestError && error.code === "PERIOD_CLOSE_BLOCKED"
          ? "Kapanış engellendi: açık engelleyici kayıtlar var."
          : error instanceof Error
            ? error.message
            : "Dönem mühürlenemedi.";
      setSealDialogError(message);
      setActionError(message);
      await refetch();
      await refetchAudits();
    } finally {
      setIsSealing(false);
    }
  }

  return (
    <section className="yonetim-page donem-kapanis-page" data-testid="donem-kapanis-merkezi">
      <div className="yonetim-header-row">
        <h2>Dönem Kapanış Merkezi</h2>
        <p className="raporlar-aylik-lead">
          Ay sonu ön kontrol, engelleyici/uyarı listesi, audit geçmişi ve mühürleme.
        </p>
      </div>

      <DonemKapanisFiltreleri
        filters={filters}
        subeOptions={subeOptions}
        departmanOptions={departmanOptions}
        isLoading={isLoading}
        canExport={canExport}
        isExporting={isExporting}
        onChange={(patch) => setFilters((prev) => ({ ...prev, ...patch }))}
        onSubmit={() => void refetch()}
        onExport={() => void handleExport()}
      />

      {canMuhurle ? (
        <div className="form-actions-row">
          <button
            type="button"
            className="universal-btn-save"
            data-testid="donem-kapanis-muhurle"
            disabled={sealDisabled}
            onClick={openSealDialog}
          >
            {isSealing ? "Mühürleniyor…" : "Dönemi mühürle"}
          </button>
        </div>
      ) : null}

      {actionMessage ? (
        <p className="yonetim-success" data-testid="donem-kapanis-action-success">
          {actionMessage}
        </p>
      ) : null}
      {actionError ? (
        <p className="yonetim-error" data-testid="donem-kapanis-action-error">
          {actionError}
        </p>
      ) : null}

      {isLoading ? <LoadingState label="Dönem kapanış özeti yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void refetch()} /> : null}

      {!isLoading && !errorMessage && summary ? (
        <>
          <DonemDurumBandi summary={summary} />
          <KapanisOzetKartlari summary={summary} />
          <KapanisIssueListesi
            blockers={summary.blockers}
            warnings={summary.warnings}
            infos={summary.infos}
            onShowItems={setSelectedIssue}
          />
          <KapanisAuditPaneli
            audits={audits}
            isLoading={isAuditsLoading}
            errorMessage={auditsErrorMessage}
            onRetry={() => void refetchAudits()}
          />
        </>
      ) : null}

      <KapanisPersonelDetayModal
        issue={selectedIssue}
        params={buildParams()}
        onClose={() => setSelectedIssue(null)}
      />

      {pendingSealConfirm ? (
        <AppActionDialog
          open
          testId="donem-kapanis-muhur-action-dialog"
          title="Dönemi Mühürle"
          description={DONEM_MUHUR_ONAY_MESAJI}
          confirmLabel="Mühürle"
          submitLabel="Mühürleniyor…"
          destructive
          isSubmitting={isSealing}
          errorMessage={sealDialogError}
          onConfirm={confirmSeal}
          onCancel={closeSealDialog}
        />
      ) : null}
    </section>
  );
}
