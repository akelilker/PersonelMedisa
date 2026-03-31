import { useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { fetchRapor } from "../../../api/raporlar.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
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

export function RaporlarPage() {
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

      <form className="module-filter-form" onSubmit={handleSubmit}>
        <div className="module-filter-grid">
          <label className="module-filter-field">
            <span>Rapor Turu</span>
            <select
              value={form.raporTipi}
              onChange={(event) =>
                setForm((prev) => ({ ...prev, raporTipi: event.target.value as RaporTipi }))
              }
            >
              {RAPOR_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="module-filter-field">
            <span>Personel ID</span>
            <input
              type="number"
              min={1}
              value={form.personelId}
              onChange={(event) => setForm((prev) => ({ ...prev, personelId: event.target.value }))}
            />
          </label>

          <label className="module-filter-field">
            <span>Departman ID</span>
            <input
              type="number"
              min={1}
              value={form.departmanId}
              onChange={(event) => setForm((prev) => ({ ...prev, departmanId: event.target.value }))}
            />
          </label>

          <label className="module-filter-field">
            <span>Baslangic Tarihi</span>
            <input
              type="date"
              value={form.baslangicTarihi}
              onChange={(event) =>
                setForm((prev) => ({ ...prev, baslangicTarihi: event.target.value }))
              }
            />
          </label>

          <label className="module-filter-field">
            <span>Bitis Tarihi</span>
            <input
              type="date"
              value={form.bitisTarihi}
              onChange={(event) => setForm((prev) => ({ ...prev, bitisTarihi: event.target.value }))}
            />
          </label>

          <label className="module-filter-field">
            <span>Aktiflik</span>
            <select
              value={form.aktiflik}
              onChange={(event) =>
                setForm((prev) => ({ ...prev, aktiflik: event.target.value as RaporAktiflik }))
              }
            >
              <option value="tum">Tum</option>
              <option value="aktif">Aktif</option>
              <option value="pasif">Pasif</option>
            </select>
          </label>
        </div>

        <div className="module-filter-actions">
          <button type="submit" className="state-action-btn" disabled={isLoading}>
            Raporu Calistir
          </button>
          <button type="button" className="state-action-btn" onClick={handleClear} disabled={isLoading}>
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
