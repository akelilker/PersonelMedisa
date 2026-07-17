import { useEffect, useMemo, useState } from "react";
import { ApiRequestError } from "../../../api/api-client";
import {
  calculateMaasHesaplamaSnapshot,
  cancelMaasHesaplamaCalistirma,
  cancelMaasHesaplamaSnapshot,
  createMaasHesaplamaSnapshot,
  fetchMaasHesaplamaAdayDetail,
  fetchMaasHesaplamaAdayKalemler,
  fetchMaasHesaplamaCalistirmaAdaylari,
  fetchMaasHesaplamaSnapshotDetail,
  upsertMaasHesaplamaDevir,
  type MaasHesaplamaAday,
  type MaasHesaplamaCalistirma,
  type MaasHesaplamaIssue,
  type MaasHesaplamaKalem,
  type MaasHesaplamaSnapshotDetail
} from "../../../api/maas-hesaplama.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useMaasHesaplama, type MaasHesaplamaFilterState } from "../../../hooks/useMaasHesaplama";
import { currentMonthParts, parseAyValue } from "../../../lib/donem-kapanis/display";
import { useAuth } from "../../../state/auth.store";
import type { IdOption } from "../../../types/referans";

const INITIAL_FILTERS: MaasHesaplamaFilterState = {
  ay: currentMonthParts().ay,
  subeId: ""
};

function shortHash(value: string | null | undefined): string {
  if (!value) {
    return "—";
  }
  return value.length <= 12 ? value : `${value.slice(0, 12)}…`;
}

function issuesBySeverity(items: MaasHesaplamaIssue[], severity: MaasHesaplamaIssue["severity"]) {
  return items.filter((item) => item.severity === severity);
}

function formatMoney(value: number | null | undefined): string {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return "—";
  }
  return `${new Intl.NumberFormat("tr-TR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} TL`;
}

function firstNumber(...values: Array<number | null | undefined>): number | null {
  const found = values.find((value): value is number => typeof value === "number" && Number.isFinite(value));
  return found ?? null;
}

function adayName(aday: MaasHesaplamaAday): string {
  return aday.personel_ad_soyad ?? aday.personel_adi ?? `Personel #${aday.personel_id}`;
}

function calistirmaLabel(calistirma: MaasHesaplamaCalistirma): string {
  return `#${calistirma.id} · ${calistirma.state} · rev ${calistirma.revision_no}`;
}

function parseDecimal(value: string): number | null {
  const parsed = Number.parseFloat(value.replace(",", "."));
  return Number.isFinite(parsed) ? parsed : null;
}

export function MaasHesaplamaMerkeziPage() {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const canManage = hasPermission("maas_hesaplama.manage");
  const canViewAdaylari = hasPermission("maas_hesaplama_adaylari.view");
  const canManageAdaylari = hasPermission("maas_hesaplama_adaylari.manage");

  const [filters, setFilters] = useState<MaasHesaplamaFilterState>(INITIAL_FILTERS);
  const [subeOptions, setSubeOptions] = useState<IdOption[]>([]);
  const [isCreating, setIsCreating] = useState(false);
  const [isCancelling, setIsCancelling] = useState(false);
  const [isCalculating, setIsCalculating] = useState(false);
  const [isCancellingCalistirma, setIsCancellingCalistirma] = useState(false);
  const [isLoadingAdaylar, setIsLoadingAdaylar] = useState(false);
  const [isSavingDevir, setIsSavingDevir] = useState(false);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [selectedDetail, setSelectedDetail] = useState<MaasHesaplamaSnapshotDetail | null>(null);
  const [selectedCalistirmaId, setSelectedCalistirmaId] = useState<number | null>(null);
  const [adaylar, setAdaylar] = useState<MaasHesaplamaAday[]>([]);
  const [selectedAday, setSelectedAday] = useState<MaasHesaplamaAday | null>(null);
  const [selectedAdayKalemler, setSelectedAdayKalemler] = useState<MaasHesaplamaKalem[]>([]);
  const [cancelNeden, setCancelNeden] = useState("");
  const [cancelCalistirmaNeden, setCancelCalistirmaNeden] = useState("");
  const [devirForm, setDevirForm] = useState({
    personelId: "",
    matrah: "",
    vergi: ""
  });

  const parsedAy = parseAyValue(filters.ay);
  const subeId = filters.subeId ? Number.parseInt(filters.subeId, 10) : null;

  const {
    preflight,
    snapshots,
    audits,
    calculationPreflight,
    calistirmalar,
    devirler,
    isLoading,
    errorMessage,
    calculationErrorMessage,
    refetch
  } = useMaasHesaplama({
    enabled: Boolean(parsedAy && subeId),
    filters,
    yil: parsedAy?.yil ?? currentMonthParts().yil,
    ay: parsedAy?.ay ?? currentMonthParts().ayNum,
    subeId: Number.isFinite(subeId) ? subeId : null,
    loadCalculationCandidates: canViewAdaylari
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
      ? allowedSubeKey
          .split(",")
          .map((value) => Number.parseInt(value, 10))
          .filter((id) => Number.isFinite(id))
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
    })();
  }, [session?.active_sube_id, sessionSubeKey, allowedSubeKey]);

  const createDisabled = useMemo(() => {
    if (!preflight || !canManage || isCreating) {
      return true;
    }
    if (!preflight.muhur || preflight.blocker_count > 0) {
      return true;
    }
    if (preflight.existing_snapshot?.source_changed) {
      return true;
    }
    return false;
  }, [preflight, canManage, isCreating]);

  const activeSnapshot = snapshots.find((item) => item.state === "OLUSTURULDU") ?? null;
  const activeCalistirma =
    calistirmalar.find((item) => item.id === selectedCalistirmaId) ??
    calistirmalar.find((item) => item.state !== "IPTAL") ??
    calistirmalar[0] ??
    null;
  const blockers = issuesBySeverity(preflight?.items ?? [], "BLOCKER");
  const warnings = issuesBySeverity(preflight?.items ?? [], "WARNING");
  const infos = issuesBySeverity(preflight?.items ?? [], "INFO");
  const calcBlockers = issuesBySeverity(calculationPreflight?.items ?? [], "BLOCKER");
  const calcWarnings = issuesBySeverity(calculationPreflight?.items ?? [], "WARNING");
  const calcInfos = issuesBySeverity(calculationPreflight?.items ?? [], "INFO");
  const calculateDisabled =
    !activeSnapshot ||
    !calculationPreflight ||
    !calculationPreflight.hesaplanabilir_mi ||
    !canManageAdaylari ||
    isCalculating;

  useEffect(() => {
    const nextCalistirma =
      calistirmalar.find((item) => item.id === selectedCalistirmaId) ??
      calistirmalar.find((item) => item.state !== "IPTAL") ??
      calistirmalar[0] ??
      null;
    const nextId = nextCalistirma?.id ?? null;
    if (selectedCalistirmaId !== nextId) {
      setSelectedCalistirmaId(nextId);
    }
  }, [calistirmalar, selectedCalistirmaId]);

  useEffect(() => {
    if (!canViewAdaylari || !activeCalistirma) {
      setAdaylar([]);
      setSelectedAday(null);
      setSelectedAdayKalemler([]);
      return;
    }
    let cancelled = false;
    setIsLoadingAdaylar(true);
    void fetchMaasHesaplamaCalistirmaAdaylari(activeCalistirma.id)
      .then((items) => {
        if (!cancelled) {
          setAdaylar(items);
          setSelectedAday(null);
          setSelectedAdayKalemler([]);
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setAdaylar([]);
          setActionError(error instanceof Error ? error.message : "Aday listesi alınamadı.");
        }
      })
      .finally(() => {
        if (!cancelled) {
          setIsLoadingAdaylar(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [activeCalistirma, canViewAdaylari]);

  async function handleCreate() {
    if (!parsedAy || !subeId || !preflight || createDisabled) {
      return;
    }
    const confirmed = window.confirm(
      "Bu işlem hesaplama girdilerini değişmez snapshot olarak kaydeder. Devam edilsin mi?"
    );
    if (!confirmed) {
      return;
    }

    setIsCreating(true);
    setActionMessage(null);
    setActionError(null);
    try {
      const result = await createMaasHesaplamaSnapshot({
        sube_id: subeId,
        yil: parsedAy.yil,
        ay: parsedAy.ay,
        expected_preflight_hash: preflight.preflight_hash
      });
      setActionMessage(
        result.idempotent
          ? `Mevcut snapshot döndürüldü (#${result.snapshot.id}).`
          : `Snapshot oluşturuldu (#${result.snapshot.id}, rev ${result.snapshot.revision_no}).`
      );
      await refetch();
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setActionError(`${error.code}: ${error.message}`);
      } else {
        setActionError(error instanceof Error ? error.message : "Snapshot oluşturulamadı.");
      }
      await refetch();
    } finally {
      setIsCreating(false);
    }
  }

  async function handleCancel() {
    if (!activeSnapshot || !canManage || !cancelNeden.trim()) {
      setActionError("İptal için neden zorunludur.");
      return;
    }
    setIsCancelling(true);
    setActionMessage(null);
    setActionError(null);
    try {
      const result = await cancelMaasHesaplamaSnapshot(activeSnapshot.id, cancelNeden.trim());
      setActionMessage(
        result.idempotent
          ? `Snapshot zaten iptaldi (#${result.snapshot.id}).`
          : `Snapshot iptal edildi (#${result.snapshot.id}).`
      );
      setCancelNeden("");
      setSelectedDetail(null);
      await refetch();
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setActionError(`${error.code}: ${error.message}`);
      } else {
        setActionError(error instanceof Error ? error.message : "Snapshot iptal edilemedi.");
      }
    } finally {
      setIsCancelling(false);
    }
  }

  async function handleOpenDetail(snapshotId: number) {
    setActionError(null);
    try {
      const detail = await fetchMaasHesaplamaSnapshotDetail(snapshotId, canManage);
      setSelectedDetail(detail);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : "Snapshot detayı alınamadı.");
    }
  }

  async function handleCalculate() {
    if (!activeSnapshot || !calculationPreflight || calculateDisabled) {
      return;
    }
    const confirmed = window.confirm("Aktif snapshot için maaş hesaplama adayları üretilecek. Devam edilsin mi?");
    if (!confirmed) {
      return;
    }
    setIsCalculating(true);
    setActionMessage(null);
    setActionError(null);
    try {
      const result = await calculateMaasHesaplamaSnapshot({
        snapshot_id: activeSnapshot.id,
        expected_calculation_input_hash: calculationPreflight.calculation_input_hash,
        engine_version: calculationPreflight.engine_version
      });
      setActionMessage(
        result.idempotent
          ? `Mevcut hesaplama döndürüldü (#${result.calistirma?.id ?? "?"}).`
          : `Hesaplama çalıştırıldı (#${result.calistirma?.id ?? "?"}).`
      );
      await refetch();
    } catch (error) {
      if (error instanceof ApiRequestError) {
        setActionError(`${error.code}: ${error.message}`);
      } else {
        setActionError(error instanceof Error ? error.message : "Hesaplama çalıştırılamadı.");
      }
      await refetch();
    } finally {
      setIsCalculating(false);
    }
  }

  async function handleCancelCalistirma() {
    if (!activeCalistirma || !canManageAdaylari || !cancelCalistirmaNeden.trim()) {
      setActionError("Çalıştırma iptali için neden zorunludur.");
      return;
    }
    setIsCancellingCalistirma(true);
    setActionMessage(null);
    setActionError(null);
    try {
      await cancelMaasHesaplamaCalistirma(activeCalistirma.id, cancelCalistirmaNeden.trim());
      setActionMessage(`Çalıştırma iptal edildi (#${activeCalistirma.id}).`);
      setCancelCalistirmaNeden("");
      await refetch();
    } catch (error) {
      setActionError(error instanceof Error ? error.message : "Çalıştırma iptal edilemedi.");
    } finally {
      setIsCancellingCalistirma(false);
    }
  }

  async function handleOpenAday(adayId: number) {
    setActionError(null);
    try {
      const [detail, kalemler] = await Promise.all([
        fetchMaasHesaplamaAdayDetail(adayId),
        fetchMaasHesaplamaAdayKalemler(adayId)
      ]);
      setSelectedAday(detail);
      setSelectedAdayKalemler(kalemler);
    } catch (error) {
      setActionError(error instanceof Error ? error.message : "Aday kalemleri alınamadı.");
    }
  }

  async function handleSaveDevir() {
    if (!parsedAy || !subeId || !canManageAdaylari) {
      return;
    }
    const personelId = Number.parseInt(devirForm.personelId, 10);
    const matrah = parseDecimal(devirForm.matrah);
    const vergi = parseDecimal(devirForm.vergi);
    if (!Number.isFinite(personelId) || personelId <= 0 || matrah === null || vergi === null) {
      setActionError("Devir için personel, matrah ve vergi sayısal olmalıdır.");
      return;
    }
    setIsSavingDevir(true);
    setActionMessage(null);
    setActionError(null);
    try {
      await upsertMaasHesaplamaDevir({
        personel_id: personelId,
        sube_id: subeId,
        yil: parsedAy.yil,
        ay: parsedAy.ay,
        onceki_kumulatif_gelir_vergisi_matrahi: matrah,
        onceki_kumulatif_gelir_vergisi: vergi,
        kaynak: "MANUEL"
      });
      setActionMessage(`Devir kaydedildi (personel #${personelId}).`);
      setDevirForm({ personelId: "", matrah: "", vergi: "" });
      await refetch();
    } catch (error) {
      setActionError(error instanceof Error ? error.message : "Devir kaydedilemedi.");
    } finally {
      setIsSavingDevir(false);
    }
  }

  return (
    <section className="yonetim-page donem-kapanis-page" data-testid="maas-hesaplama-merkezi">
      <div className="yonetim-header-row">
        <h2>Maaş Hesaplama Merkezi</h2>
        <p className="raporlar-aylik-lead">
          Mühürlü dönem için kaynak preflight’ı, değişmez snapshot, hesaplama adayları ve devir yönetimi.
          Muhasebe onayı ve PDF üretimi bu ekranda yoktur.
        </p>
      </div>

      <form
        className="form-filter-panel"
        data-testid="maas-hesaplama-filters"
        onSubmit={(event) => {
          event.preventDefault();
          void refetch();
        }}
      >
        <div className="form-field-grid">
          <FormField
            label="Ay"
            name="maas-hesaplama-ay"
            type="month"
            value={filters.ay}
            onChange={(value) => setFilters((prev) => ({ ...prev, ay: value }))}
          />
          <FormField
            as="select"
            label="Şube"
            name="maas-hesaplama-sube"
            value={filters.subeId}
            onChange={(value) => setFilters((prev) => ({ ...prev, subeId: value }))}
            selectOptions={[
              { value: "", label: "Şube seçin" },
              ...subeOptions.map((sube) => ({ value: String(sube.id), label: sube.label }))
            ]}
          />
        </div>
        <div className="form-actions-row">
          <button type="submit" className="universal-btn-save" data-testid="maas-hesaplama-submit">
            Preflight getir
          </button>
        </div>
      </form>

      {canManage ? (
        <div className="form-actions-row">
          <button
            type="button"
            className="universal-btn-save"
            data-testid="maas-hesaplama-create"
            disabled={createDisabled}
            onClick={() => void handleCreate()}
          >
            {isCreating ? "Snapshot oluşturuluyor…" : "Snapshot oluştur"}
          </button>
        </div>
      ) : null}

      {actionMessage ? (
        <p className="yonetim-success" data-testid="maas-hesaplama-action-success">
          {actionMessage}
        </p>
      ) : null}
      {actionError ? (
        <p className="yonetim-error" data-testid="maas-hesaplama-action-error">
          {actionError}
        </p>
      ) : null}

      {isLoading ? <LoadingState label="Maaş hesaplama preflight yükleniyor…" /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} /> : null}

      {!isLoading && preflight ? (
        <>
          <div className="kapanis-ozet-grid" data-testid="maas-hesaplama-ozet">
            <article className="kapanis-ozet-card">
              <h3>Mühür</h3>
              <p data-testid="maas-hesaplama-muhur-durum">
                {preflight.muhur
                  ? `#${preflight.muhur.id} / ${preflight.muhur.durum} (${preflight.muhur.muhurlenen_kayit_sayisi} kayıt)`
                  : "Mühür yok"}
              </p>
            </article>
            <article className="kapanis-ozet-card">
              <h3>Hazırlık</h3>
              <p>
                Blocker {preflight.blocker_count} · Warning {preflight.warning_count} · Info{" "}
                {preflight.info_count}
              </p>
              <p data-testid="maas-hesaplama-olusturulabilir">
                Snapshot oluşturulabilir: {preflight.snapshot_olusturulabilir_mi ? "Evet" : "Hayır"}
              </p>
            </article>
            <article className="kapanis-ozet-card">
              <h3>Hash</h3>
              <p data-testid="maas-hesaplama-preflight-hash">{shortHash(preflight.preflight_hash)}</p>
              <p data-testid="maas-hesaplama-source-hash">{shortHash(preflight.source_hash)}</p>
            </article>
            <article className="kapanis-ozet-card">
              <h3>Mevcut snapshot</h3>
              <p data-testid="maas-hesaplama-existing-snapshot">
                {preflight.existing_snapshot
                  ? `#${preflight.existing_snapshot.id} rev ${preflight.existing_snapshot.revision_no}${
                      preflight.existing_snapshot.source_changed ? " (kaynak değişti)" : ""
                    }`
                  : "Yok"}
              </p>
            </article>
          </div>

          <section className="kapanis-issue-section" data-testid="maas-hesaplama-issues">
            <h3>Preflight maddeleri</h3>
            {[...blockers, ...warnings, ...infos].map((item) => (
              <article
                key={`${item.severity}-${item.code}-${item.personel_id ?? "x"}-${item.record_id ?? "y"}`}
                className={`kapanis-issue kapanis-issue--${item.severity.toLowerCase()}`}
                data-testid={`maas-hesaplama-issue-${item.code}`}
              >
                <strong>
                  [{item.severity}] {item.code}
                </strong>
                <p>{item.message}</p>
                {item.personel_adi ? <p>Personel: {item.personel_adi}</p> : null}
              </article>
            ))}
          </section>

          <section data-testid="maas-hesaplama-personel-table">
            <h3>Personel kaynak hazırlık</h3>
            <div className="table-scroll">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Personel</th>
                    <th>İstihdam aralığı</th>
                    <th>Ücret segmenti</th>
                    <th>Puantaj</th>
                    <th>Finans</th>
                    <th>Hazırlık</th>
                    <th>Blocker/Warning</th>
                  </tr>
                </thead>
                <tbody>
                  {(preflight.personel_summary ?? []).map((row) => (
                    <tr key={row.personel_id} data-testid={`maas-hesaplama-personel-${row.personel_id}`}>
                      <td>{row.ad_soyad}</td>
                      <td>
                        {row.istihdam_baslangic} … {row.istihdam_bitis}
                      </td>
                      <td>{row.ucret_segment_sayisi}</td>
                      <td>{row.puantaj_kayit_sayisi}</td>
                      <td>{row.finans_kalem_sayisi}</td>
                      <td>{row.hazir_mi ? "Hazır" : "Engelli"}</td>
                      <td>
                        {row.blocker_count}/{row.warning_count}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <section data-testid="maas-hesaplama-source-summary">
            <h3>Kaynak özeti</h3>
            <pre className="code-block">{JSON.stringify(preflight.source_summary ?? {}, null, 2)}</pre>
          </section>

          <section data-testid="maas-hesaplama-snapshots">
            <h3>Snapshot / revision</h3>
            {snapshots.length === 0 ? <p>Bu dönem için snapshot yok.</p> : null}
            <ul>
              {snapshots.map((snapshot) => (
                <li key={snapshot.id}>
                  <button
                    type="button"
                    className="linkish-button"
                    data-testid={`maas-hesaplama-snapshot-${snapshot.id}`}
                    onClick={() => void handleOpenDetail(snapshot.id)}
                  >
                    #{snapshot.id} · {snapshot.state} · rev {snapshot.revision_no} ·{" "}
                    {snapshot.personel_sayisi} personel · {snapshot.girdi_sayisi} girdi ·{" "}
                    {shortHash(snapshot.snapshot_hash)}
                  </button>
                </li>
              ))}
            </ul>

            {activeSnapshot && canManage ? (
              <div className="form-field-grid">
                <FormField
                  label="İptal nedeni"
                  name="maas-hesaplama-iptal-neden"
                  value={cancelNeden}
                  onChange={setCancelNeden}
                />
                <div className="form-actions-row">
                  <button
                    type="button"
                    className="universal-btn-danger"
                    data-testid="maas-hesaplama-cancel"
                    disabled={isCancelling || !cancelNeden.trim()}
                    onClick={() => void handleCancel()}
                  >
                    {isCancelling ? "İptal ediliyor…" : "Aktif snapshot’ı iptal et"}
                  </button>
                </div>
              </div>
            ) : null}
          </section>

          {selectedDetail ? (
            <section data-testid="maas-hesaplama-snapshot-detail">
              <h3>Snapshot detay #{selectedDetail.id}</h3>
              <p>
                State: {selectedDetail.state} · Rev: {selectedDetail.revision_no} · Personel:{" "}
                {selectedDetail.personel_sayisi} · Girdi: {selectedDetail.girdi_sayisi}
              </p>
              <p data-testid="maas-hesaplama-detail-source-hash">
                source_hash: {shortHash(selectedDetail.source_hash)}
              </p>
              <p data-testid="maas-hesaplama-detail-snapshot-hash">
                snapshot_hash: {shortHash(selectedDetail.snapshot_hash)}
              </p>
              {selectedDetail.hash_dogrulama ? (
                <p data-testid="maas-hesaplama-hash-dogrulama">
                  Hash doğrulama: {selectedDetail.hash_dogrulama.dogrulandi ? "OK" : "FAIL"}
                </p>
              ) : null}
              {selectedDetail.girdi_ozet ? (
                <pre className="code-block">{JSON.stringify(selectedDetail.girdi_ozet, null, 2)}</pre>
              ) : null}
              <p className="muted-note">Snapshot payload düzenleme API’si yoktur; değerler salt okunur.</p>
            </section>
          ) : null}

          {activeSnapshot && canViewAdaylari ? (
            <>
              <section data-testid="maas-hesaplama-calc-preflight">
                <h3>Hesaplama preflight</h3>
                {calculationErrorMessage ? (
                  <p className="yonetim-error" data-testid="maas-hesaplama-calc-error">
                    {calculationErrorMessage}
                  </p>
                ) : null}
                {calculationPreflight ? (
                  <>
                    <div className="kapanis-ozet-grid">
                      <article className="kapanis-ozet-card">
                        <h3>Hesaplanabilir</h3>
                        <p data-testid="maas-hesaplama-calc-ready">
                          {calculationPreflight.hesaplanabilir_mi ? "Evet" : "Hayır"}
                        </p>
                        <p>
                          Blocker {calculationPreflight.blocker_count} · Warning{" "}
                          {calculationPreflight.warning_count} · Info {calculationPreflight.info_count}
                        </p>
                      </article>
                      <article className="kapanis-ozet-card">
                        <h3>Girdi hash</h3>
                        <p data-testid="maas-hesaplama-calc-input-hash">
                          {shortHash(calculationPreflight.calculation_input_hash)}
                        </p>
                        <p>Kaynak: {shortHash(calculationPreflight.source_hash)}</p>
                      </article>
                      <article className="kapanis-ozet-card">
                        <h3>Set hash</h3>
                        <p>Parametre: {shortHash(calculationPreflight.parameter_set_hash)}</p>
                        <p>Devir: {shortHash(calculationPreflight.carryover_set_hash)}</p>
                      </article>
                      <article className="kapanis-ozet-card">
                        <h3>Motor</h3>
                        <p>{calculationPreflight.engine_version}</p>
                        <p>{calculationPreflight.contract_version}</p>
                      </article>
                    </div>

                    <section className="kapanis-issue-section" data-testid="maas-hesaplama-calc-issues">
                      {[...calcBlockers, ...calcWarnings, ...calcInfos].map((item) => (
                        <article
                          key={`calc-${item.severity}-${item.code}-${item.personel_id ?? "x"}-${item.record_id ?? "y"}`}
                          className={`kapanis-issue kapanis-issue--${item.severity.toLowerCase()}`}
                          data-testid={`maas-hesaplama-calc-issue-${item.code}`}
                        >
                          <strong>
                            [{item.severity}] {item.code}
                          </strong>
                          <p>{item.message}</p>
                        </article>
                      ))}
                    </section>

                    {canManageAdaylari ? (
                      <div className="form-actions-row">
                        <button
                          type="button"
                          className="universal-btn-save"
                          data-testid="maas-hesaplama-calc-run"
                          disabled={calculateDisabled}
                          onClick={() => void handleCalculate()}
                        >
                          {isCalculating ? "Hesaplanıyor…" : "Hesapla"}
                        </button>
                      </div>
                    ) : null}
                  </>
                ) : null}
              </section>

              <section data-testid="maas-hesaplama-calc-calistirmalar">
                <h3>Hesaplama çalıştırmaları</h3>
                {calistirmalar.length === 0 ? <p>Bu dönem için hesaplama çalıştırması yok.</p> : null}
                <div className="table-scroll">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Çalıştırma</th>
                        <th>Durum</th>
                        <th>Aday</th>
                        <th>Net</th>
                        <th>Brüt</th>
                        <th>Hash</th>
                      </tr>
                    </thead>
                    <tbody>
                      {calistirmalar.map((calistirma) => (
                        <tr key={calistirma.id} data-testid={`maas-hesaplama-calc-run-${calistirma.id}`}>
                          <td>
                            <button
                              type="button"
                              className="linkish-button"
                              onClick={() => setSelectedCalistirmaId(calistirma.id)}
                            >
                              {calistirmaLabel(calistirma)}
                            </button>
                          </td>
                          <td>{calistirma.state}</td>
                          <td>{calistirma.aday_sayisi ?? calistirma.personel_sayisi ?? "—"}</td>
                          <td>{formatMoney(calistirma.toplam_net)}</td>
                          <td>{formatMoney(calistirma.toplam_brut)}</td>
                          <td>{shortHash(calistirma.result_hash)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {activeCalistirma && canManageAdaylari ? (
                  <div className="form-field-grid">
                    <FormField
                      label="Çalıştırma iptal nedeni"
                      name="maas-hesaplama-calc-cancel-neden"
                      value={cancelCalistirmaNeden}
                      onChange={setCancelCalistirmaNeden}
                    />
                    <div className="form-actions-row">
                      <button
                        type="button"
                        className="universal-btn-danger"
                        data-testid="maas-hesaplama-calc-cancel"
                        disabled={
                          isCancellingCalistirma ||
                          !cancelCalistirmaNeden.trim() ||
                          activeCalistirma.state === "IPTAL"
                        }
                        onClick={() => void handleCancelCalistirma()}
                      >
                        {isCancellingCalistirma ? "İptal ediliyor…" : "Çalıştırmayı iptal et"}
                      </button>
                    </div>
                  </div>
                ) : null}
              </section>

              <section data-testid="maas-hesaplama-aday-table">
                <h3>Hesaplama adayları</h3>
                {isLoadingAdaylar ? <LoadingState label="Adaylar yükleniyor…" /> : null}
                {!isLoadingAdaylar && adaylar.length === 0 ? <p>Seçili çalıştırma için aday yok.</p> : null}
                <div className="table-scroll">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Personel</th>
                        <th>Durum</th>
                        <th>Net</th>
                        <th>Brüt</th>
                        <th>GV</th>
                        <th>SGK</th>
                        <th>Kalem</th>
                      </tr>
                    </thead>
                    <tbody>
                      {adaylar.map((aday) => {
                        const net = firstNumber(aday.net_ucret, aday.net);
                        const brut = firstNumber(aday.brut_ucret, aday.brut);
                        const gv = firstNumber(aday.gelir_vergisi, aday.gv);
                        const sgk = firstNumber(aday.sgk_primi, aday.sgk, aday.toplam_isci_sgk);
                        return (
                          <tr key={aday.id} data-testid={`maas-hesaplama-aday-row-${aday.id}`}>
                            <td>{adayName(aday)}</td>
                            <td>{aday.state ?? "—"}</td>
                            <td>{formatMoney(net)}</td>
                            <td>{formatMoney(brut)}</td>
                            <td>{formatMoney(gv)}</td>
                            <td>{formatMoney(sgk)}</td>
                            <td>
                              <button
                                type="button"
                                className="linkish-button"
                                data-testid={`maas-hesaplama-aday-open-${aday.id}`}
                                onClick={() => void handleOpenAday(aday.id)}
                              >
                                Aç
                              </button>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              </section>

              {selectedAday ? (
                <section data-testid="maas-hesaplama-aday-kalemler">
                  <h3>Aday kalemleri · {adayName(selectedAday)}</h3>
                  <div className="table-scroll">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>Kod</th>
                          <th>Ad</th>
                          <th>Kategori</th>
                          <th>Tutar</th>
                          <th>Matrah</th>
                        </tr>
                      </thead>
                      <tbody>
                        {selectedAdayKalemler.map((kalem) => (
                          <tr key={kalem.id} data-testid={`maas-hesaplama-aday-kalem-${kalem.id}`}>
                            <td>{kalem.kalem_kodu ?? kalem.kod ?? "—"}</td>
                            <td>{kalem.kalem_adi ?? kalem.ad ?? "—"}</td>
                            <td>{kalem.kategori ?? kalem.tur ?? "—"}</td>
                            <td>{formatMoney(kalem.tutar)}</td>
                            <td>{formatMoney(kalem.matrah)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>
              ) : null}

              <section data-testid="maas-hesaplama-devir-list">
                <h3>Bordro devirleri</h3>
                <div className="table-scroll">
                  <table className="data-table">
                    <thead>
                      <tr>
                        <th>Personel</th>
                        <th>Matrah</th>
                        <th>Vergi</th>
                        <th>Kaynak</th>
                      </tr>
                    </thead>
                    <tbody>
                      {devirler.map((devir) => (
                        <tr key={devir.id} data-testid={`maas-hesaplama-devir-row-${devir.id}`}>
                          <td>{devir.personel_ad_soyad ?? `Personel #${devir.personel_id}`}</td>
                          <td>{formatMoney(devir.onceki_kumulatif_gelir_vergisi_matrahi)}</td>
                          <td>{formatMoney(devir.onceki_kumulatif_gelir_vergisi)}</td>
                          <td>{devir.kaynak ?? "—"}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {canManageAdaylari ? (
                  <form
                    className="form-filter-panel"
                    data-testid="maas-hesaplama-devir-form"
                    onSubmit={(event) => {
                      event.preventDefault();
                      void handleSaveDevir();
                    }}
                  >
                    <div className="form-field-grid">
                      <FormField
                        label="Personel ID"
                        name="maas-hesaplama-devir-personel"
                        type="number"
                        min={1}
                        value={devirForm.personelId}
                        onChange={(value) => setDevirForm((prev) => ({ ...prev, personelId: value }))}
                      />
                      <FormField
                        label="Önceki GV matrahı"
                        name="maas-hesaplama-devir-matrah"
                        type="number"
                        step="0.01"
                        value={devirForm.matrah}
                        onChange={(value) => setDevirForm((prev) => ({ ...prev, matrah: value }))}
                      />
                      <FormField
                        label="Önceki GV"
                        name="maas-hesaplama-devir-vergi"
                        type="number"
                        step="0.01"
                        value={devirForm.vergi}
                        onChange={(value) => setDevirForm((prev) => ({ ...prev, vergi: value }))}
                      />
                    </div>
                    <div className="form-actions-row">
                      <button
                        type="submit"
                        className="universal-btn-save"
                        data-testid="maas-hesaplama-devir-save"
                        disabled={isSavingDevir}
                      >
                        {isSavingDevir ? "Kaydediliyor…" : "Devir kaydet"}
                      </button>
                    </div>
                  </form>
                ) : null}
              </section>
            </>
          ) : null}

          <section data-testid="maas-hesaplama-audits">
            <h3>Audit</h3>
            <div className="table-scroll">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Aksiyon</th>
                    <th>Sonuç</th>
                    <th>Actor</th>
                    <th>Rol</th>
                    <th>Tarih</th>
                    <th>Hash</th>
                    <th>B/W</th>
                  </tr>
                </thead>
                <tbody>
                  {audits.map((audit) => (
                    <tr key={audit.id} data-testid={`maas-hesaplama-audit-${audit.id}`}>
                      <td>{audit.aksiyon}</td>
                      <td>{audit.sonuc}</td>
                      <td>{audit.actor_id ?? "—"}</td>
                      <td>{audit.actor_rol ?? "—"}</td>
                      <td>{audit.created_at}</td>
                      <td>{shortHash(audit.result_hash ?? audit.request_hash)}</td>
                      <td>
                        {audit.blocker_count}/{audit.warning_count}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>
        </>
      ) : null}
    </section>
  );
}
