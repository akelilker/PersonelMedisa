import { useEffect, useMemo, useState } from "react";
import { ApiRequestError } from "../../../api/api-client";
import { downloadDonemKapanisPreflightCsv } from "../../../api/donem-kapanis.api";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import { muhurleAylikPuantaj } from "../../../api/puantaj.api";
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

  useEffect(() => {
    const sessionSubeler = (session?.sube_list ?? []).map((sube) => ({ id: sube.id, label: sube.ad }));
    if (sessionSubeler.length > 0) {
      setSubeOptions(sessionSubeler);
    }
    const activeSubeId = session?.active_sube_id;
    if (activeSubeId) {
      setFilters((prev) => ({ ...prev, subeId: String(activeSubeId) }));
    }

    void (async () => {
      try {
        const yonetimSubeler = await fetchYonetimSubeleri();
        if (yonetimSubeler.length > 0) {
          setSubeOptions(yonetimSubeler.map((sube) => ({ id: sube.id, label: sube.ad })));
        }
      } catch {
        /* session sube_list fallback */
      }
      const departmanlar = await fetchDepartmanOptions();
      setDepartmanOptions(departmanlar);
    })();
  }, [session?.active_sube_id, session?.sube_list]);

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

  async function handleSeal() {
    if (!parsedAy || !subeId || sealDisabled) {
      return;
    }

    setIsSealing(true);
    setActionMessage(null);
    setActionError(null);

    try {
      const result = await muhurleAylikPuantaj({ yil: parsedAy.yil, ay: parsedAy.ay });
      setActionMessage(
        `Dönem mühürlendi (${result.donem}). ${result.muhurlenen_kayit_sayisi} kayıt mühürlendi.`
      );
      await refetch();
      await refetchAudits();
    } catch (error) {
      if (error instanceof ApiRequestError && error.code === "PERIOD_CLOSE_BLOCKED") {
        setActionError("Kapanış engellendi: açık engelleyici kayıtlar var.");
      } else {
        setActionError(error instanceof Error ? error.message : "Dönem mühürlenemedi.");
      }
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
            onClick={() => void handleSeal()}
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
    </section>
  );
}
