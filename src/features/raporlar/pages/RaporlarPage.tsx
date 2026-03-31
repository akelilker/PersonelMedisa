import { useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { fetchRapor } from "../../../api/raporlar.api";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useAppDataRevision } from "../../../data/data-manager";
import type { ModuleFilterBase } from "../../../lib/filters/module-filter-schema";
import {
  downloadReportCsv,
  printCurrentReportWindow
} from "../../../reports/export-report";
import { generateReport, type ReportEngineRow, type ReportEngineType } from "../../../reports/report-engine";
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
  { value: "personel-ozet", label: "Personel Ozet" },
  { value: "izin", label: "Izin" },
  { value: "devamsizlik", label: "Devamsizlik" },
  { value: "tesvik", label: "Tesvik" },
  { value: "ceza", label: "Ceza" },
  { value: "ekstra-prim", label: "Ekstra Prim" },
  { value: "is-kazasi", label: "Is Kazasi" },
  { value: "bildirim", label: "Bildirim" }
];

function parseOptionalPositiveInt(value: string): number | undefined {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const parsed = Number.parseInt(trimmed, 10);
  if (Number.isNaN(parsed) || parsed <= 0) {
    throw new Error("Personel ve departman alanlari pozitif sayi olmalidir.");
  }

  return parsed;
}

function formatCellValue(value: unknown): string {
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
  { value: "personel-ozet", label: "Personel ozeti (onbellek)" },
  { value: "izin-durumu", label: "Izin durumu (onbellek)" },
  { value: "puantaj", label: "Puantaj (onbellek)" },
  { value: "finans", label: "Finans (onbellek, 1. sayfa)" }
];

export function RaporlarPage() {
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

  const columns = useMemo(() => collectColumns(rows), [rows]);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      if (form.baslangicTarihi && form.bitisTarihi && form.baslangicTarihi > form.bitisTarihi) {
        throw new Error("Baslangic tarihi bitis tarihinden buyuk olamaz.");
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
      setErrorMessage(error instanceof Error ? error.message : "Rapor verisi alinamadi.");
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
    <section className="raporlar-page">
      <div className="raporlar-header-row">
        <h2>Raporlar</h2>
      </div>

      <div className="raporlar-engine-card">
        <h3 className="raporlar-engine-title">Onbellek rapor motoru</h3>
        <p className="raporlar-engine-hint">
          Ham veri mevcut onbellekte; ek ag cagrisi yapilmaz. Sube, header&apos;daki aktif sube ile uyumludur.
        </p>
        <div className="form-field-grid">
          <FormField
            as="select"
            label="Motor turu"
            name="engine-turu"
            value={engineType}
            onChange={(value) => setEngineType(value as ReportEngineType)}
            selectOptions={ENGINE_OPTIONS}
          />
          <FormField
            label="Personel ID (bos = tumu)"
            name="engine-personel"
            type="number"
            min={1}
            value={enginePersonelId}
            onChange={(value) => setEnginePersonelId(value)}
          />
          <FormField
            label="Durum (bos = tumu)"
            name="engine-durum"
            value={engineDurum}
            onChange={(value) => setEngineDurum(value)}
            placeholder="ORN: AKTIF, TAMAMLANDI"
          />
          <FormField
            label="Tarih bas (yyyy-mm-dd)"
            name="engine-bas"
            type="date"
            value={engineBas}
            onChange={(value) => setEngineBas(value)}
          />
          <FormField
            label="Tarih bit (yyyy-mm-dd)"
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
            onClick={() => {
              printCurrentReportWindow(`Rapor: ${engineType}`, engineColumns, engineRows);
            }}
          >
            Yazdir / PDF
          </button>
        </div>
        {engineRows.length === 0 ? (
          <p className="raporlar-engine-empty">Bu tur icin onbellekte satir yok; ilgili modulu bir kez acin.</p>
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
                      <td key={`${index}-${column}`}>{formatCellValue(row[column])}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <form className="form-filter-panel" onSubmit={handleSubmit}>
        <div className="form-field-grid">
          <FormField
            as="select"
            label="Rapor Turu"
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
            label="Baslangic Tarihi"
            name="rapor-bas"
            type="date"
            value={form.baslangicTarihi}
            onChange={(value) => setForm((prev) => ({ ...prev, baslangicTarihi: value }))}
          />
          <FormField
            label="Bitis Tarihi"
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
              { value: "tum", label: "Tum" },
              { value: "aktif", label: "Aktif" },
              { value: "pasif", label: "Pasif" }
            ]}
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux" disabled={isLoading}>
            Raporu Calistir
          </button>
          <button type="button" className="universal-btn-aux" onClick={handleClear} disabled={isLoading}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Rapor verileri yukleniyor..." /> : null}

      {!isLoading && errorMessage ? <ErrorState message={errorMessage} /> : null}

      {!isLoading && !errorMessage && hasSearched && rows.length === 0 ? (
        <EmptyState title="Rapor verisi yok" message="Bu filtrede gosterilecek kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && rows.length > 0 ? (
        <div className="raporlar-result-card">
          <p>
            <strong>Toplam Kayit:</strong> {total ?? rows.length}
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
                      <td key={`${index}-${column}`}>{formatCellValue(row[column])}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/finans">Finans modulune git</Link>
        <Link to="/personeller">Personellere don</Link>
      </div>
    </section>
  );
}
