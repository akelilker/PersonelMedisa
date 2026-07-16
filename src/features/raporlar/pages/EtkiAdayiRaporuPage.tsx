import { useEffect, useMemo, useState } from "react";
import { downloadBildirimEtkiRaporCsv } from "../../../api/bildirim-etki-rapor.api";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";
import { useBildirimEtkiRapor } from "../../../hooks/useBildirimEtkiRapor";
import { currentMonthParts } from "../../../lib/donem-kapanis/display";
import type { IdOption } from "../../../types/referans";
import {
  EtkiAdayiRaporFiltreleri,
  type EtkiAdayiRaporFilterState
} from "../components/etki-adayi/EtkiAdayiRaporFiltreleri";
import { EtkiAdayiRaporTablosu } from "../components/etki-adayi/EtkiAdayiRaporTablosu";

const INITIAL_FILTERS: EtkiAdayiRaporFilterState = {
  ay: currentMonthParts().ay,
  subeId: "",
  departmanId: "",
  personelId: "",
  state: "",
  conflictCode: "",
  etkiTuru: "",
  uygulamaModu: "",
  kararTuru: ""
};

export function EtkiAdayiRaporuPage() {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const canExport = hasPermission("puantaj.bildirim_etki.rapor.export");

  const [filters, setFilters] = useState<EtkiAdayiRaporFilterState>(INITIAL_FILTERS);
  const [subeOptions, setSubeOptions] = useState<IdOption[]>([]);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [isExporting, setIsExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const apiFilters = useMemo(() => {
    const subeId = filters.subeId ? Number.parseInt(filters.subeId, 10) : null;
    if (!subeId || !filters.ay) {
      return null;
    }

    const departmanId = filters.departmanId.trim() ? Number.parseInt(filters.departmanId, 10) : undefined;
    const personelId = filters.personelId.trim() ? Number.parseInt(filters.personelId, 10) : undefined;

    return {
      ay: filters.ay,
      sube_id: subeId,
      ...(departmanId && Number.isFinite(departmanId) ? { departman_id: departmanId } : {}),
      ...(personelId && Number.isFinite(personelId) ? { personel_id: personelId } : {}),
      ...(filters.state ? { state: filters.state } : {}),
      ...(filters.conflictCode.trim() ? { conflict_code: filters.conflictCode.trim() } : {}),
      ...(filters.etkiTuru.trim() ? { etki_turu: filters.etkiTuru.trim() } : {}),
      ...(filters.uygulamaModu.trim() ? { uygulama_modu: filters.uygulamaModu.trim() } : {}),
      ...(filters.kararTuru.trim() ? { karar_turu: filters.kararTuru.trim() } : {})
    };
  }, [filters]);

  const { rows, summary, page, totalPages, hasNextPage, hasPrevPage, isLoading, errorMessage, hasSearched, load } =
    useBildirimEtkiRapor({
      enabled: Boolean(apiFilters),
      filters: apiFilters,
      autoRun: false
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

  async function handleSubmit() {
    await load(1, apiFilters);
  }

  async function handleExport() {
    if (!apiFilters) {
      return;
    }

    setIsExporting(true);
    setExportError(null);
    try {
      await downloadBildirimEtkiRaporCsv(apiFilters);
    } catch (error) {
      setExportError(error instanceof Error ? error.message : "CSV indirilemedi.");
    } finally {
      setIsExporting(false);
    }
  }

  return (
    <section className="yonetim-page etki-adayi-rapor-page" data-testid="etki-adayi-rapor-page">
      <div className="yonetim-header-row">
        <h2>Etki Adayı Raporu</h2>
        <p className="raporlar-aylik-lead">
          Onaylı bildirim puantaj etki adaylarının dönem raporu; Koru/Revize kararları puantaj ekranından yönetilir.
        </p>
      </div>

      <EtkiAdayiRaporFiltreleri
        filters={filters}
        subeOptions={subeOptions}
        departmanOptions={departmanOptions}
        isLoading={isLoading}
        canExport={canExport}
        isExporting={isExporting}
        onChange={(patch) => setFilters((prev) => ({ ...prev, ...patch }))}
        onSubmit={() => void handleSubmit()}
        onExport={() => void handleExport()}
      />

      {exportError ? <p className="yonetim-error">{exportError}</p> : null}

      {isLoading ? <LoadingState label="Etki adayı raporu yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void handleSubmit()} /> : null}
      {!isLoading && !errorMessage && hasSearched && rows.length === 0 ? (
        <EmptyState title="Kayıt bulunamadı" message="Seçili filtrelere uygun etki adayı satırı yok." />
      ) : null}

      {!isLoading && !errorMessage && rows.length > 0 ? (
        <EtkiAdayiRaporTablosu
          rows={rows}
          summary={summary}
          page={page}
          totalPages={totalPages}
          hasNextPage={hasNextPage}
          hasPrevPage={hasPrevPage}
          onPageChange={(nextPage) => void load(nextPage, apiFilters)}
        />
      ) : null}
    </section>
  );
}
