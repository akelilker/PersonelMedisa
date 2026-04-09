import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import { ayiKapat, bolumOnayiVer, fetchAylikKapanisOzeti, fetchYonetimSubeleri } from "../../../api/yonetim.api";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { downloadReportCsv } from "../../../reports/export-report";
import type { IdOption } from "../../../types/referans";
import type { AylikOzetResponse, AylikOzetState } from "../../../types/yonetim";

type FilterState = {
  ay: string;
  subeId: string;
  departmanId: string;
  sadeceRevizeli: boolean;
};

const AYLIK_STATE_LABELS: Record<AylikOzetState | "KAPANDI", string> = {
  BOLUM_ONAYINDA: "Bölüm Onayında",
  BOLUM_ONAYLANDI: "Bölüm Onaylandı",
  REVIZE_ISTENDI: "Revize İstendi",
  KAPANDI: "Kapandı"
};

function currentMonthValue() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function toCurrency(value: number) {
  return new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
  }).format(value);
}

function formatStateLabel(value: AylikOzetState | "KAPANDI") {
  return AYLIK_STATE_LABELS[value] ?? value;
}

function formatBooleanLabel(value: boolean) {
  return value ? "Evet" : "Hayır";
}

export function AylikKapanisOzetiPage() {
  const { hasPermission } = useRoleAccess();
  const canReview = hasPermission("aylik-ozet.review");
  const canFinalize = hasPermission("aylik-ozet.finalize");

  const [filters, setFilters] = useState<FilterState>({
    ay: currentMonthValue(),
    subeId: "",
    departmanId: "",
    sadeceRevizeli: false
  });
  const [subeOptions, setSubeOptions] = useState<IdOption[]>([]);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [result, setResult] = useState<AylikOzetResponse | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isActing, setIsActing] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [infoMessage, setInfoMessage] = useState<string | null>(null);

  async function loadBaseData() {
    const [subeler, departmanlar] = await Promise.all([fetchYonetimSubeleri(), fetchDepartmanOptions()]);
    setSubeOptions(subeler.map((sube) => ({ id: sube.id, label: sube.ad })));
    setDepartmanOptions(departmanlar);
  }

  async function loadSummary(activeFilters: FilterState) {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const next = await fetchAylikKapanisOzeti({
        ay: activeFilters.ay,
        sube_id: activeFilters.subeId ? Number.parseInt(activeFilters.subeId, 10) : undefined,
        departman_id: activeFilters.departmanId ? Number.parseInt(activeFilters.departmanId, 10) : undefined,
        sadece_revizeli: activeFilters.sadeceRevizeli
      });
      setResult(next);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Aylık özet yüklenemedi.");
      setResult(null);
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void (async () => {
      setIsLoading(true);
      setErrorMessage(null);

      try {
        await loadBaseData();
        await loadSummary(filters);
      } catch (error) {
        setErrorMessage(error instanceof Error ? error.message : "Aylık özet yüklenemedi.");
        setIsLoading(false);
      }
    })();
  }, []);

  async function handleFilterSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setInfoMessage(null);
    await loadSummary(filters);
  }

  async function handleBolumOnayi() {
    if (!result || isActing) {
      return;
    }

    setIsActing(true);
    setErrorMessage(null);
    setInfoMessage(null);

    try {
      const next = await bolumOnayiVer({
        ay: filters.ay,
        sube_id: filters.subeId ? Number.parseInt(filters.subeId, 10) : undefined,
        departman_id: filters.departmanId ? Number.parseInt(filters.departmanId, 10) : undefined,
        sadece_revizeli: filters.sadeceRevizeli
      });
      setResult(next);
      setInfoMessage("Seçili ay için bölüm onayı verildi.");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bölüm onayı tamamlanamadı.");
    } finally {
      setIsActing(false);
    }
  }

  async function handleAyKapat() {
    if (!result || isActing) {
      return;
    }

    setIsActing(true);
    setErrorMessage(null);
    setInfoMessage(null);

    try {
      const next = await ayiKapat({
        ay: filters.ay,
        sube_id: filters.subeId ? Number.parseInt(filters.subeId, 10) : undefined,
        departman_id: filters.departmanId ? Number.parseInt(filters.departmanId, 10) : undefined,
        sadece_revizeli: filters.sadeceRevizeli
      });
      setResult(next);
      setInfoMessage("Seçili ay genel yönetici tarafından kapatıldı.");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Ay kapatılamadı.");
    } finally {
      setIsActing(false);
    }
  }

  const exportRows = useMemo(
    () =>
      (result?.items ?? []).map((item) => ({
        "Ad Soyad": item.ad_soyad,
        "Sicil No": item.sicil_no ?? "-",
        Şube: item.sube,
        Bölüm: item.bolum,
        "Birim Amiri": item.birim_amiri,
        "Devamsızlık Gün": item.devamsizlik_gun,
        "Geç Kalma": item.gec_kalma_adet,
        "İzinli Gelmedi": item.izinli_gelmedi,
        "İzinsiz Gelmedi": item.izinsiz_gelmedi,
        Raporlu: item.raporlu,
        "Teşvik Tutarı": item.tesvik_tutari,
        "Ceza Kesinti Tutarı": item.ceza_kesinti_tutari,
        "Bölüm Onay Durumu": formatStateLabel(item.bolum_onay_durumu),
        "Revize Var Mı": formatBooleanLabel(item.revize_var_mi),
        "Son İşlem": item.son_islem
      })),
    [result?.items]
  );

  return (
    <section className="yonetim-page aylik-ozet-page">
      <div className="yonetim-header-row">
        <p className="yonetim-kicker">Raporlar</p>
        <h2>Aylık Kapanış Özeti</h2>
        <p>Bölüm bazlı kontrol, toplu özet ve ay kapatma akışını buradan yönet.</p>
      </div>

      <form className="form-filter-panel" onSubmit={handleFilterSubmit}>
        <div className="form-field-grid">
          <FormField
            label="Ay"
            name="aylik-ozet-ay"
            type="month"
            value={filters.ay}
            onChange={(value) => setFilters((prev) => ({ ...prev, ay: value }))}
            required
          />
          <FormField
            as="select"
            label="Şube"
            name="aylik-ozet-sube"
            value={filters.subeId}
            onChange={(value) => setFilters((prev) => ({ ...prev, subeId: value }))}
            placeholderOption={{ value: "", label: "Tüm Şubeler" }}
            selectOptions={subeOptions.map((item) => ({ value: String(item.id), label: item.label }))}
          />
          <FormField
            as="select"
            label="Bölüm"
            name="aylik-ozet-bolum"
            value={filters.departmanId}
            onChange={(value) => setFilters((prev) => ({ ...prev, departmanId: value }))}
            placeholderOption={{ value: "", label: "Tüm Bölümler" }}
            selectOptions={departmanOptions.map((item) => ({ value: String(item.id), label: item.label }))}
          />
        </div>

        <div className="yonetim-checkbox-section">
          <label className="yonetim-selection-pill is-selected">
            <input
              type="checkbox"
              checked={filters.sadeceRevizeli}
              onChange={(event) =>
                setFilters((prev) => ({
                  ...prev,
                  sadeceRevizeli: event.target.checked
                }))
              }
            />
            <strong>Sadece revizeli kayıtlar</strong>
          </label>
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux">
            Özeti Getir
          </button>
          <button
            type="button"
            className="universal-btn-aux"
            onClick={() => {
              if (exportRows.length === 0) {
                return;
              }

              downloadReportCsv(`aylik-kapanis-ozeti-${filters.ay}.csv`, Object.keys(exportRows[0]), exportRows);
            }}
          >
            Excel&apos;e Aktar
          </button>
          {canReview ? (
            <button
              type="button"
              className="universal-btn-save"
              data-testid="aylik-ozet-bolum-onay"
              onClick={() => void handleBolumOnayi()}
              disabled={isActing || !result || result.items.length === 0}
            >
              Bölüm Onayı Ver
            </button>
          ) : null}
          {canFinalize ? (
            <button
              type="button"
              className="universal-btn-save"
              data-testid="aylik-ozet-ay-kapat"
              onClick={() => void handleAyKapat()}
              disabled={isActing || !result || result.items.length === 0 || result.pending_bolum_onayi > 0}
            >
              Ayı Kapat
            </button>
          ) : null}
        </div>
      </form>

      {isLoading ? <LoadingState label="Aylık özet yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadSummary(filters)} /> : null}
      {!isLoading && infoMessage ? <p className="yonetim-success">{infoMessage}</p> : null}

      {!isLoading && !errorMessage && result ? (
        <>
          <div className="yonetim-summary-grid">
            <article className="yonetim-summary-card">
              <span>Durum</span>
              <strong>{formatStateLabel(result.state)}</strong>
            </article>
            <article className="yonetim-summary-card">
              <span>Toplam Personel</span>
              <strong>{result.summary.toplam_personel}</strong>
            </article>
            <article className="yonetim-summary-card">
              <span>Toplam Devamsızlık</span>
              <strong>{result.summary.toplam_devamsizlik_gun}</strong>
            </article>
            <article className="yonetim-summary-card">
              <span>Toplam Geç Kalma</span>
              <strong>{result.summary.toplam_gec_kalma}</strong>
            </article>
            <article className="yonetim-summary-card">
              <span>Toplam Raporlu</span>
              <strong>{result.summary.toplam_raporlu}</strong>
            </article>
            <article className="yonetim-summary-card">
              <span>Teşvik / Ceza</span>
              <strong>
                {toCurrency(result.summary.toplam_tesvik_tutari)} / {toCurrency(result.summary.toplam_ceza_kesinti_tutari)}
              </strong>
            </article>
          </div>

          {result.pending_bolum_onayi > 0 ? (
            <p className="yonetim-hint">
              Bölüm onayı bekleyen {result.pending_bolum_onayi} kayıt var. Genel yönetici kapatmadan önce toplu özeti
              inceleyebilir.
            </p>
          ) : null}

          <div className="raporlar-table-wrap yonetim-table-wrap">
            <table className="raporlar-table">
              <thead>
                <tr>
                  <th>Ad Soyad</th>
                  <th>Sicil</th>
                  <th>Şube</th>
                  <th>Bölüm</th>
                  <th>Birim Amiri</th>
                  <th>Devamsızlık</th>
                  <th>Geç Kalma</th>
                  <th>İzinli</th>
                  <th>İzinsiz</th>
                  <th>Raporlu</th>
                  <th>Teşvik</th>
                  <th>Ceza</th>
                  <th>Bölüm Onayı</th>
                  <th>Revize</th>
                  <th>Son İşlem</th>
                  <th>Detay</th>
                </tr>
              </thead>
              <tbody>
                {result.items.map((item) => (
                  <tr key={`${result.ay}-${item.personel_id}`}>
                    <td>{item.ad_soyad}</td>
                    <td>{item.sicil_no ?? "-"}</td>
                    <td>{item.sube}</td>
                    <td>{item.bolum}</td>
                    <td>{item.birim_amiri}</td>
                    <td>{item.devamsizlik_gun}</td>
                    <td>{item.gec_kalma_adet}</td>
                    <td>{item.izinli_gelmedi}</td>
                    <td>{item.izinsiz_gelmedi}</td>
                    <td>{item.raporlu}</td>
                    <td>{toCurrency(item.tesvik_tutari)}</td>
                    <td>{toCurrency(item.ceza_kesinti_tutari)}</td>
                    <td>{formatStateLabel(item.bolum_onay_durumu)}</td>
                    <td>{formatBooleanLabel(item.revize_var_mi)}</td>
                    <td>{item.son_islem}</td>
                    <td>
                      <Link to={`/personeller/${item.personel_id}`}>Detay</Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : null}
    </section>
  );
}
