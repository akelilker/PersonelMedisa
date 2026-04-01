import { useMemo, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { createHaftalikKapanis } from "../../../api/haftalik-kapanis.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { HaftalikKapanisSonuc } from "../../../types/haftalik-kapanis";

type KapanisFormState = {
  haftaBaslangic: string;
  haftaBitis: string;
  departmanId: string;
};

function toDateInputValue(date: Date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${year}-${month}-${day}`;
}

function getCurrentWeekRange(baseDate: Date): { start: string; end: string } {
  const date = new Date(baseDate);
  const weekDay = (date.getDay() + 6) % 7;

  const monday = new Date(date);
  monday.setDate(date.getDate() - weekDay);

  const sunday = new Date(monday);
  sunday.setDate(monday.getDate() + 6);

  return {
    start: toDateInputValue(monday),
    end: toDateInputValue(sunday)
  };
}

function parseOptionalPositiveInt(value: string) {
  const trimmed = value.trim();
  if (!trimmed) {
    return undefined;
  }

  const number = Number.parseInt(trimmed, 10);
  if (Number.isNaN(number) || number <= 0) {
    throw new Error("Departman ID pozitif sayi olmalidir.");
  }

  return number;
}

function readResultString(result: HaftalikKapanisSonuc, keys: string[]): string | null {
  for (const key of keys) {
    const value = result[key];
    if (typeof value === "string" && value.trim()) {
      return value;
    }
  }

  return null;
}

function readResultNumber(result: HaftalikKapanisSonuc, keys: string[]): number | null {
  for (const key of keys) {
    const value = result[key];
    if (typeof value === "number" && Number.isFinite(value)) {
      return value;
    }

    if (typeof value === "string" && value.trim()) {
      const parsed = Number.parseFloat(value);
      if (Number.isFinite(parsed)) {
        return parsed;
      }
    }
  }

  return null;
}

export function HaftalikKapanisPage() {
  const { hasPermission } = useRoleAccess();
  const canCloseWeek = hasPermission("haftalik-kapanis.close");

  const defaultWeek = useMemo(() => getCurrentWeekRange(new Date()), []);
  const [form, setForm] = useState<KapanisFormState>({
    haftaBaslangic: defaultWeek.start,
    haftaBitis: defaultWeek.end,
    departmanId: ""
  });
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [result, setResult] = useState<HaftalikKapanisSonuc | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    if (!canCloseWeek) {
      setErrorMessage("Bu hafta icin kapanis alma yetkin bulunmuyor.");
      return;
    }

    setErrorMessage(null);
    setIsSubmitting(true);

    try {
      if (!form.haftaBaslangic || !form.haftaBitis) {
        throw new Error("Hafta baslangic ve bitis tarihi zorunludur.");
      }

      if (form.haftaBaslangic > form.haftaBitis) {
        throw new Error("Hafta baslangic tarihi bitis tarihinden buyuk olamaz.");
      }

      const response = await createHaftalikKapanis({
        hafta_baslangic: form.haftaBaslangic,
        hafta_bitis: form.haftaBitis,
        departman_id: parseOptionalPositiveInt(form.departmanId)
      });

      setResult(response);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Haftalik kapanis olusturulamadi.");
      setResult(null);
    } finally {
      setIsSubmitting(false);
    }
  }

  const resultState = result ? readResultString(result, ["state", "durum"]) : null;
  const resultId = result ? readResultNumber(result, ["id", "kapanis_id", "snapshot_id"]) : null;
  const resultPersonelCount = result
    ? readResultNumber(result, ["personel_sayisi", "personel_count", "calisan_sayisi"])
    : null;

  return (
    <section className="kapanis-page">
      <div className="kapanis-header-row">
        <h2>Haftalik Kapanis</h2>
      </div>

      <form className="form-filter-panel" onSubmit={handleSubmit}>
        <div className="form-field-grid">
          <FormField
            label="Hafta Baslangic"
            name="kapanis-bas"
            type="date"
            value={form.haftaBaslangic}
            onChange={(value) => setForm((prev) => ({ ...prev, haftaBaslangic: value }))}
            required
          />
          <FormField
            label="Hafta Bitis"
            name="kapanis-bitis"
            type="date"
            value={form.haftaBitis}
            onChange={(value) => setForm((prev) => ({ ...prev, haftaBitis: value }))}
            required
          />
          <FormField
            label="Departman ID (Opsiyonel)"
            name="kapanis-departman"
            type="number"
            min={1}
            value={form.departmanId}
            onChange={(value) => setForm((prev) => ({ ...prev, departmanId: value }))}
            placeholder="Tum departmanlar icin bos birak"
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux" disabled={!canCloseWeek || isSubmitting}>
            {isSubmitting ? "Kapanis Aliniyor..." : "Haftayi Kapat"}
          </button>
        </div>
      </form>

      {errorMessage ? <ErrorState message={errorMessage} /> : null}

      {!canCloseWeek ? (
        <p className="kapanis-readonly-note">Bu modulu sadece goruntuleme yetkin var.</p>
      ) : null}

      {result ? (
        <div className="kapanis-result-card">
          <p>
            <strong>Kapanis ID:</strong> {resultId ?? "-"}
          </p>
          <p>
            <strong>Durum:</strong> {resultState ?? "-"}
          </p>
          <p>
            <strong>Hafta:</strong> {readResultString(result, ["hafta_baslangic"]) ?? form.haftaBaslangic} /{" "}
            {readResultString(result, ["hafta_bitis"]) ?? form.haftaBitis}
          </p>
          <p>
            <strong>Departman ID:</strong>{" "}
            {readResultNumber(result, ["departman_id"]) ?? (form.departmanId || "-")}
          </p>
          <p>
            <strong>Etkilenen Personel:</strong> {resultPersonelCount ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/puantaj">Gunluk puantaja git</Link>
        <Link to="/">Ana ekrana don</Link>
      </div>
    </section>
  );
}
