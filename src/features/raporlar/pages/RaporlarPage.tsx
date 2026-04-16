import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import { fetchRapor } from "../../../api/raporlar.api";
import {
  bolumOnayiVer,
  fetchAylikKapanisOzeti,
  fetchYonetimSubeleri,
  ustOnayVer
} from "../../../api/yonetim.api";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { formatReportCellValue } from "../../../lib/display/enum-display";
import { downloadReportCsv } from "../../../reports/export-report";
import type { IdOption } from "../../../types/referans";
import type { RaporAktiflik, RaporFiltreleri, RaporSatiri, RaporTipi } from "../../../types/rapor";
import type { AylikBolumOnayDurumu, AylikOzetAggregateState, AylikOzetResponse } from "../../../types/yonetim";

type RaporFormState = {
  raporTipi: RaporTipi;
  personelId: string;
  departmanId: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  aktiflik: RaporAktiflik;
};

const RAPOR_OPTIONS: Array<{ value: RaporTipi; label: string }> = [
  { value: "personel-ozet", label: "Personel Özeti" },
  { value: "izin", label: "İzin" },
  { value: "devamsizlik", label: "Devamsızlık" },
  { value: "tesvik", label: "Teşvik" },
  { value: "ceza", label: "Ceza" },
  { value: "ekstra-prim", label: "Ekstra Prim" },
  { value: "is-kazasi", label: "İş Kazası" },
  { value: "bildirim", label: "Günlük Kayıt" }
];

function parseOptionalPositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const parsed = Number.parseInt(trimmed, 10);
  if (Number.isNaN(parsed) || parsed <= 0) {
    throw new Error("Personel ve departman alanları pozitif sayı olmalıdır.");
  }

  return parsed;
}

function formatCellValue(column: string, value: unknown): string {
  const displayValue = formatReportCellValue(column, value);
  if (displayValue !== null) {
    return displayValue;
  }

  if (value === null || value === undefined) {
    return "-";
  }

  if (typeof value === "string") {
    return value || "-";
  }

  if (typeof value === "number" || typeof value === "boolean") {
    return String(value);
  }

  try {
    return JSON.stringify(value);
  } catch {
    return String(value);
  }
}

function collectColumns(rows: RaporSatiri[]): string[] {
  const keys = new Set<string>();

  for (const row of rows) {
    for (const key of Object.keys(row)) {
      keys.add(key);
      if (keys.size >= 8) {
        return Array.from(keys);
      }
    }
  }

  return Array.from(keys);
}

type AylikFilterState = {
  ay: string;
  subeId: string;
  departmanId: string;
  sadeceRevizeli: boolean;
};

const AYLIK_AGGREGATE_LABELS: Record<AylikOzetAggregateState, string> = {
  BOLUM_ONAYINDA: "Bölüm Onayında",
  BOLUM_ONAYLANDI: "Operasyonel Tamamlandı",
  REVIZE_ISTENDI: "Revize İstendi",
  KAPANDI: "Üst Onay Verildi"
};

const AYLIK_BOLUM_SATIR_LABELS: Record<AylikBolumOnayDurumu, string> = {
  BOLUM_ONAYINDA: "Bölüm Onayında",
  BOLUM_ONAYLANDI: "Bölüm Onaylandı",
  REVIZE_ISTENDI: "Revize İstendi"
};

function aylikCurrentMonthValue() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function aylikToCurrency(value: number) {
  return new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0
  }).format(value);
}

function formatAylikAggregateStateLabel(value: AylikOzetAggregateState) {
  return AYLIK_AGGREGATE_LABELS[value] ?? value;
}

function formatAylikBolumSatirLabel(value: AylikBolumOnayDurumu) {
  return AYLIK_BOLUM_SATIR_LABELS[value] ?? value;
}

function formatAylikBooleanLabel(value: boolean) {
  return value ? "Evet" : "Hayır";
}

function AylikKapanisOzetiSection() {
  const { hasPermission } = useRoleAccess();
  const canReview = hasPermission("aylik-ozet.review");
  const canExecutiveAck = hasPermission("aylik-ozet.executive_ack");

  const [filters, setFilters] = useState<AylikFilterState>({
    ay: aylikCurrentMonthValue(),
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

  async function loadSummary(activeFilters: AylikFilterState) {
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

  async function handleAylikFilterSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setInfoMessage(null);
    await loadSummary(filters);
  }

  async function handleAylikBolumOnayi() {
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

  async function handleAylikUstOnay() {
    if (!result || isActing) {
      return;
    }

    setIsActing(true);
    setErrorMessage(null);
    setInfoMessage(null);

    try {
      const next = await ustOnayVer({
        ay: filters.ay,
        sube_id: filters.subeId ? Number.parseInt(filters.subeId, 10) : undefined,
        departman_id: filters.departmanId ? Number.parseInt(filters.departmanId, 10) : undefined,
        sadece_revizeli: filters.sadeceRevizeli
      });
      setResult(next);
      setInfoMessage("Üst kontrol onayı kaydedildi.");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Üst onay tamamlanamadı.");
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
        "Bağlı Amir": item.bagli_amir_adi,
        "Devamsızlık Gün": item.devamsizlik_gun,
        "Geç Kalma": item.gec_kalma_adet,
        "İzinli Gelmedi": item.izinli_gelmedi,
        "İzinsiz Gelmedi": item.izinsiz_gelmedi,
        Raporlu: item.raporlu,
        "Teşvik Tutarı": item.tesvik_tutari,
        "Ceza Kesinti Tutarı": item.ceza_kesinti_tutari,
        "Bölüm Onay Durumu": formatAylikBolumSatirLabel(item.bolum_onay_durumu),
        "Revize Var Mı": formatAylikBooleanLabel(item.revize_var_mi),
        "Son İşlem": item.son_islem
      })),
    [result?.items]
  );

  return (
    <section
      className="yonetim-page aylik-ozet-page"
      data-testid="aylik-kapanis-ozeti-section"
      aria-label="Aylık kapanış özeti"
    >
      <div className="yonetim-header-row raporlar-aylik-head">
        <h2>Aylık Kapanış Özeti</h2>
        <p className="raporlar-aylik-lead">Ay sonu puantaj ve onay durumunu görüntüleyin; gerekirse Excel ile aktarın.</p>
      </div>

      <form className="form-filter-panel raporlar-aylik-filters" onSubmit={handleAylikFilterSubmit}>
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

        <div className="yonetim-checkbox-section raporlar-aylik-checkbox">
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

        <div className="form-actions-row raporlar-aylik-actions">
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
              onClick={() => void handleAylikBolumOnayi()}
              disabled={isActing || !result || result.items.length === 0}
            >
              Bölüm Onayı Ver
            </button>
          ) : null}
          {canExecutiveAck ? (
            <button
              type="button"
              className="universal-btn-save"
              data-testid="aylik-ozet-ust-onay"
              onClick={() => void handleAylikUstOnay()}
              disabled={
                isActing || !result || result.items.length === 0 || result.state === "KAPANDI"
              }
              title={
                result && result.pending_bolum_onayi > 0
                  ? "Bölüm onayı bekleyen kayıtlar var; yine de üst onay verebilirsiniz."
                  : undefined
              }
            >
              Üst Kontrol Onayı Ver
            </button>
          ) : null}
        </div>
      </form>

      {isLoading ? <LoadingState label="Aylık özet yükleniyor..." /> : null}
      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadSummary(filters)} />
      ) : null}
      {!isLoading && infoMessage ? <p className="yonetim-success">{infoMessage}</p> : null}

      {!isLoading && !errorMessage && result ? (
        <>
          <div className="yonetim-summary-grid">
            <article className="yonetim-summary-card">
              <span>Durum</span>
              <strong>{formatAylikAggregateStateLabel(result.state)}</strong>
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
                {aylikToCurrency(result.summary.toplam_tesvik_tutari)} /{" "}
                {aylikToCurrency(result.summary.toplam_ceza_kesinti_tutari)}
              </strong>
            </article>
          </div>

          {result.pending_bolum_onayi > 0 ? (
            <p className="yonetim-hint raporlar-aylik-hint">
              Bölüm onayı bekleyen {result.pending_bolum_onayi} kayıt var.
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
                  <th>Bağlı Amir</th>
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
                    <td>{item.bagli_amir_adi}</td>
                    <td>{item.devamsizlik_gun}</td>
                    <td>{item.gec_kalma_adet}</td>
                    <td>{item.izinli_gelmedi}</td>
                    <td>{item.izinsiz_gelmedi}</td>
                    <td>{item.raporlu}</td>
                    <td>{aylikToCurrency(item.tesvik_tutari)}</td>
                    <td>{aylikToCurrency(item.ceza_kesinti_tutari)}</td>
                    <td>{formatAylikBolumSatirLabel(item.bolum_onay_durumu)}</td>
                    <td>{formatAylikBooleanLabel(item.revize_var_mi)}</td>
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

export function RaporlarPage() {
  const { hasPermission } = useRoleAccess();
  const canViewAylikOzet = hasPermission("aylik-ozet.view");

  const [form, setForm] = useState<RaporFormState>({
    raporTipi: "personel-ozet",
    personelId: "",
    departmanId: "",
    baslangicTarihi: "",
    bitisTarihi: "",
    aktiflik: "tum"
  });
  const [rows, setRows] = useState<RaporSatiri[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [hasSearched, setHasSearched] = useState(false);

  const columns = useMemo(() => collectColumns(rows), [rows]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      if (form.baslangicTarihi && form.bitisTarihi && form.baslangicTarihi > form.bitisTarihi) {
        throw new Error("Başlangıç tarihi bitiş tarihinden büyük olamaz.");
      }

      const filters: RaporFiltreleri = {
        personel_id: parseOptionalPositiveInt(form.personelId),
        departman_id: parseOptionalPositiveInt(form.departmanId),
        baslangic_tarihi: form.baslangicTarihi || undefined,
        bitis_tarihi: form.bitisTarihi || undefined,
        aktiflik: form.aktiflik
      };
      const result = await fetchRapor(form.raporTipi, filters);
      setRows(result.rows);
      setTotal(result.total);
      setHasSearched(true);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Rapor verisi alınamadı.");
      setRows([]);
      setTotal(null);
    } finally {
      setIsLoading(false);
    }
  }

  function handleClear() {
    setForm({
      raporTipi: "personel-ozet",
      personelId: "",
      departmanId: "",
      baslangicTarihi: "",
      bitisTarihi: "",
      aktiflik: "tum"
    });
    setRows([]);
    setTotal(null);
    setErrorMessage(null);
    setHasSearched(false);
  }

  return (
    <section className="raporlar-page raporlar-page--premium">
      <header className="raporlar-page-head">
        <h2>Raporlar</h2>
        <p className="raporlar-page-lead">Filtreleri kullanarak liste raporlarını ve aylık kapanış özetini görüntüleyin.</p>
      </header>

      {canViewAylikOzet ? <AylikKapanisOzetiSection /> : null}

      <div className="raporlar-standart-panel">
        <h3 className="raporlar-panel-title">Detaylı Liste</h3>
        <form className="form-filter-panel raporlar-standart-form" onSubmit={handleSubmit}>
        <div className="form-field-grid raporlar-standart-grid">
          <FormField
            as="select"
            label="Rapor Türü"
            name="rapor-turu"
            value={form.raporTipi}
            onChange={(value) => setForm((prev) => ({ ...prev, raporTipi: value as RaporTipi }))}
            selectOptions={RAPOR_OPTIONS}
          />
          <FormField
            label="Personel ID"
            name="rapor-personel"
            type="number"
            min={1}
            value={form.personelId}
            onChange={(value) => setForm((prev) => ({ ...prev, personelId: value }))}
          />
          <FormField
            label="Departman"
            name="rapor-departman"
            type="number"
            min={1}
            value={form.departmanId}
            onChange={(value) => setForm((prev) => ({ ...prev, departmanId: value }))}
          />
          <FormField
            label="Başlangıç Tarihi"
            name="rapor-bas"
            type="date"
            value={form.baslangicTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, baslangicTarihi: value }))}
          />
          <FormField
            label="Bitiş Tarihi"
            name="rapor-bitis"
            type="date"
            value={form.bitisTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, bitisTarihi: value }))}
          />
          <FormField
            as="select"
            label="Aktiflik"
            name="rapor-aktiflik"
            value={form.aktiflik}
            onChange={(value) => setForm((prev) => ({ ...prev, aktiflik: value as RaporAktiflik }))}
            selectOptions={[
              { value: "tum", label: "Tüm" },
              { value: "aktif", label: "Aktif" },
              { value: "pasif", label: "Pasif" }
            ]}
          />
        </div>

        <div className="form-actions-row raporlar-standart-actions">
          <button type="submit" className="universal-btn-aux" disabled={isLoading} data-testid="raporlar-submit-run">
            Raporu getir
          </button>
          <button
            type="button"
            className="universal-btn-aux"
            onClick={handleClear}
            disabled={isLoading}
            data-testid="raporlar-clear-filters"
          >
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Rapor hazırlanıyor..." /> : null}

      {!isLoading && errorMessage ? <ErrorState message={errorMessage} /> : null}

      {!isLoading && !errorMessage && hasSearched && rows.length === 0 ? (
        <EmptyState title="Kayıt bulunamadı" message="Seçtiğiniz filtrelere uygun satır yok." />
      ) : null}

      {!isLoading && !errorMessage && rows.length > 0 ? (
        <div className="raporlar-result-card" data-testid="raporlar-resmi-sonuc">
          <p className="raporlar-result-meta">
            <span className="raporlar-result-count">{total ?? rows.length}</span> kayıt listeleniyor
          </p>
          <div className="raporlar-table-wrap raporlar-table-wrap--premium">
            <table className="raporlar-table raporlar-table--premium">
              <thead>
                <tr>
                  {columns.map((column) => (
                    <th key={column}>{column}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((row, index) => (
                  <tr key={index}>
                    {columns.map((column) => (
                      <td key={`${index}-${column}`}>{formatCellValue(column, row[column])}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}
      </div>

      <nav className="raporlar-quick-nav" aria-label="Hızlı bağlantılar">
        <Link to="/finans" data-testid="link-raporlar-finans">
          Finans
        </Link>
        <span className="raporlar-quick-nav-sep" aria-hidden="true">
          ·
        </span>
        <Link to="/" data-testid="link-raporlar-home">
          Ana ekran
        </Link>
      </nav>
    </section>
  );
}
