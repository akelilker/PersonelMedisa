import { useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { fetchRapor } from "../../../api/raporlar.api";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import {
  dataCacheKeys,
  getActiveSube,
  getAppData,
  getCacheEntry,
  useAppDataRevision
} from "../../../data/data-manager";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { formatReportCellValue } from "../../../lib/display/enum-display";
import type { ModuleFilterBase } from "../../../lib/filters/module-filter-schema";
import { downloadReportCsv, printCurrentReportWindow } from "../../../reports/export-report";
import { generateReport, type ReportEngineRow, type ReportEngineType } from "../../../reports/report-engine";
import { hesaplaAylikKapanisListesi } from "../../../services/dashboard-rapor-servisi";
import type { PaginatedResult } from "../../../types/api";
import type { Personel } from "../../../types/personel";
import type { GunlukPuantaj } from "../../../types/puantaj";
import type { RaporAktiflik, RaporFiltreleri, RaporSatiri, RaporTipi } from "../../../types/rapor";

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
  { value: "bildirim", label: "Bildirim" }
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

function collectEngineColumns(rows: ReportEngineRow[]): string[] {
  const keys = new Set<string>();
  for (const row of rows) {
    for (const key of Object.keys(row)) {
      keys.add(key);
    }
  }
  return Array.from(keys);
}

const ENGINE_OPTIONS: Array<{ value: ReportEngineType; label: string }> = [
  { value: "personel-ozet", label: "Personel özeti (önbellek)" },
  { value: "izin-durumu", label: "İzin durumu (önbellek)" },
  { value: "puantaj", label: "Puantaj (önbellek)" },
  { value: "finans", label: "Finans (önbellek, 1. sayfa)" }
];

function readCachedKapanisPersoneller(subeId: number | null): Personel[] {
  const key = dataCacheKeys.personellerList(subeId, "", "tum", "", "", 1);
  return getCacheEntry<PaginatedResult<Personel>>(key)?.items ?? [];
}

function readCachedPuantajKayitlari(): GunlukPuantaj[] {
  const cache = getAppData().cache;
  const kayitlar: GunlukPuantaj[] = [];

  for (const key of Object.keys(cache)) {
    if (!key.startsWith("puantaj:")) {
      continue;
    }

    const row = cache[key]?.data as GunlukPuantaj | null | undefined;
    if (!row || typeof row !== "object") {
      continue;
    }

    kayitlar.push(row);
  }

  return kayitlar;
}

function formatKapanisEksikGunNedeni(value: string | null) {
  return value && value.trim() ? value : "-";
}

export function RaporlarPage() {
  const { hasPermission } = useRoleAccess();
  const canViewAylikOzet = hasPermission("aylik-ozet.view");
  const cacheRevision = useAppDataRevision();
  const [engineType, setEngineType] = useState<ReportEngineType>("personel-ozet");
  const [enginePersonelId, setEnginePersonelId] = useState("");
  const [engineDurum, setEngineDurum] = useState("");
  const [engineBas, setEngineBas] = useState("");
  const [engineBit, setEngineBit] = useState("");

  const engineFilters = useMemo<ModuleFilterBase>(() => {
    const trimmed = enginePersonelId.trim();
    let personel_id: number | null = null;
    if (trimmed !== "") {
      const n = Number.parseInt(trimmed, 10);
      personel_id = Number.isFinite(n) ? n : null;
    }
    const dr = engineDurum.trim();
    return {
      personel_id,
      durum: dr !== "" ? dr : null,
      date_range:
        engineBas.trim() !== "" || engineBit.trim() !== ""
          ? { bas: engineBas.trim(), bit: engineBit.trim() }
          : undefined
    };
  }, [enginePersonelId, engineDurum, engineBas, engineBit]);

  const engineRows = useMemo(
    () => generateReport(engineType, engineFilters),
    [engineType, engineFilters, cacheRevision]
  );
  const engineColumns = useMemo(() => collectEngineColumns(engineRows), [engineRows]);

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
  const [appliedFilters, setAppliedFilters] = useState<RaporFiltreleri | null>(null);

  const columns = useMemo(() => collectColumns(rows), [rows]);
  const aylikKapanisRows = useMemo(() => {
    if (!canViewAylikOzet || !appliedFilters) {
      return [];
    }

    return hesaplaAylikKapanisListesi(
      readCachedKapanisPersoneller(getActiveSube()),
      readCachedPuantajKayitlari(),
      {
        personel_id: appliedFilters.personel_id,
        departman_id: appliedFilters.departman_id,
        aktiflik: appliedFilters.aktiflik,
        baslangic_tarihi: appliedFilters.baslangic_tarihi,
        bitis_tarihi: appliedFilters.bitis_tarihi
      }
    );
  }, [appliedFilters, cacheRevision, canViewAylikOzet]);

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
      setAppliedFilters(filters);

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
    setAppliedFilters(null);
  }

  return (
    <section className="raporlar-page">
      <div className="raporlar-header-row">
        <h2>Raporlar</h2>
        {canViewAylikOzet ? (
          <Link to="/aylik-kapanis-ozeti" data-testid="link-aylik-ozet">
            Aylık Kapanış Özeti
          </Link>
        ) : null}
      </div>

      <div className="raporlar-source-card">
        <p className="raporlar-source-title">Resmi rapor kaynağı backend&apos;dir.</p>
        <p className="raporlar-source-hint">
          Bu form `/api/raporlar/*` endpoint&apos;lerinden veri çeker. Aşağıdaki önbellek aracı yalnızca yardımcı
          inceleme ve demo/offline kullanım içindir.
        </p>
      </div>

      <form className="form-filter-panel" onSubmit={handleSubmit}>
        <div className="form-field-grid">
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
            label="Departman ID"
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

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux" disabled={isLoading} data-testid="raporlar-submit-run">
            Raporu Çalıştır
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

      {isLoading ? <LoadingState label="Rapor verileri yükleniyor..." /> : null}

      {!isLoading && errorMessage ? <ErrorState message={errorMessage} /> : null}

      {!isLoading && !errorMessage && hasSearched && rows.length === 0 ? (
        <EmptyState title="Rapor verisi yok" message="Bu filtrede gösterilecek kayıt bulunamadı." />
      ) : null}

      {!isLoading && !errorMessage && rows.length > 0 ? (
        <div className="raporlar-result-card" data-testid="raporlar-resmi-sonuc">
          <p>
            <strong>Toplam Kayıt:</strong> {total ?? rows.length}
          </p>
          <div className="raporlar-table-wrap">
            <table className="raporlar-table">
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

      {canViewAylikOzet ? (
        <div className="raporlar-result-card raporlar-kapanis-card" data-testid="raporlar-aylik-kapanis">
          <div className="raporlar-kapanis-header">
            <div>
              <h3>Aylik Kapanis Ozeti</h3>
              <p>Personel puantaj kapanisini tek listede SGK prim gunuyle birlikte gosterir.</p>
            </div>
          </div>

          {!hasSearched ? (
            <p className="raporlar-engine-empty">Filtreleri calistirdiginda aylik kapanis listesi burada gorunecek.</p>
          ) : aylikKapanisRows.length === 0 ? (
            <EmptyState title="Kapanis verisi yok" message="Bu filtrede gosterilecek personel puantaj kapanisi bulunamadi." />
          ) : (
            <div className="raporlar-table-wrap" data-testid="raporlar-aylik-kapanis-table">
              <table className="raporlar-table">
                <thead>
                  <tr>
                    <th>Personel Adi</th>
                    <th>Donem</th>
                    <th>SGK Prim Gunu</th>
                    <th>Eksik Gun Sayisi</th>
                    <th>Eksik Gun Nedeni</th>
                  </tr>
                </thead>
                <tbody>
                  {aylikKapanisRows.map((row) => (
                    <tr key={`${row.personel_id}-${row.donem}`}>
                      <td>{row.personel_adi}</td>
                      <td>{row.donem}</td>
                      <td>{row.sgk_prim_gun}</td>
                      <td>{row.eksik_gun_sayisi}</td>
                      <td>{formatKapanisEksikGunNedeni(row.eksik_gun_nedeni_kodu)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      ) : null}

      <div className="raporlar-engine-card">
        <h3 className="raporlar-engine-title">Yardımcı önbellek aracı</h3>
        <p className="raporlar-engine-hint">
          Bu bölüm ağ çağrısı yapmaz; yalnızca bu cihazdaki önbelleği okur. Resmi rapor yerine geçmez.
        </p>
        <div className="form-field-grid">
          <FormField
            as="select"
            label="Motor türü"
            name="engine-turu"
            value={engineType}
            onChange={(value) => setEngineType(value as ReportEngineType)}
            selectOptions={ENGINE_OPTIONS}
          />
          <FormField
            label="Personel ID (boş = tümü)"
            name="engine-personel"
            type="number"
            min={1}
            value={enginePersonelId}
            onChange={(value) => setEnginePersonelId(value)}
          />
          <FormField
            label="Durum (boş = tümü)"
            name="engine-durum"
            value={engineDurum}
            onChange={(value) => setEngineDurum(value)}
            placeholder="Örn: AKTİF, TAMAMLANDI"
          />
          <FormField
            label="Tarih başlangıç (yyyy-mm-dd)"
            name="engine-bas"
            type="date"
            value={engineBas}
            onChange={(value) => setEngineBas(value)}
          />
          <FormField
            label="Tarih bitiş (yyyy-mm-dd)"
            name="engine-bit"
            type="date"
            value={engineBit}
            onChange={(value) => setEngineBit(value)}
          />
        </div>
        <div className="form-actions-row">
          <button
            type="button"
            className="universal-btn-aux"
            disabled={engineRows.length === 0}
            data-testid="raporlar-engine-csv"
            onClick={() => {
              downloadReportCsv(`rapor-${engineType}.csv`, engineColumns, engineRows);
            }}
          >
            CSV indir
          </button>
          <button
            type="button"
            className="universal-btn-aux"
            disabled={engineRows.length === 0}
            data-testid="raporlar-engine-print"
            onClick={() => {
              printCurrentReportWindow(`Rapor: ${engineType}`, engineColumns, engineRows);
            }}
          >
            Yazdır / PDF
          </button>
        </div>
        {engineRows.length === 0 ? (
          <p className="raporlar-engine-empty">
            Bu tür için önbellekte satır yok; ilgili modülü en az bir kez açın.
          </p>
        ) : (
          <div className="raporlar-table-wrap raporlar-engine-table">
            <table className="raporlar-table">
              <thead>
                <tr>
                  {engineColumns.map((column) => (
                    <th key={column}>{column}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {engineRows.map((row, index) => (
                  <tr key={index}>
                    {engineColumns.map((column) => (
                      <td key={`${index}-${column}`}>{formatCellValue(column, row[column])}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <div className="module-links">
        <Link to="/finans" data-testid="link-raporlar-finans">
          Finans modülüne git
        </Link>
        <Link to="/" data-testid="link-raporlar-home">
          Ana ekrana dön
        </Link>
      </div>
    </section>
  );
}
