/**
 * Kanonik takvim (AppDatePicker) tarih yardımcıları.
 *
 * Kontrat:
 * - Serialize/parse tel formatı: ISO `yyyy-mm-dd` (native date input ve backend
 *   payload'ı ile aynı). Bu dosya wire formatını değiştirmez.
 * - Kullanıcıya görünen format: `gg.aa.yyyy`.
 * - Parse toleransı: `gg.aa.yyyy`, `gg/aa/yyyy`, `gg-aa-yyyy`, `yyyy-mm-dd`.
 * - Gerçek takvim doğrulaması burada: 31.02.1990 → null.
 *
 * Saf (yan etkisiz) tutulur; component yalnız bu owner'ı tüketir.
 */

/** Görünen boş değer metni (native `gg.aa.yyyy` replikası yerine kanonik placeholder). */
export const DATE_DISPLAY_PLACEHOLDER = "gg.aa.yyyy";

const ISO_DATE_PATTERN = /^(\d{4})-(\d{2})-(\d{2})$/;
const DISPLAY_DATE_PATTERN = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/;

export const TURKISH_MONTH_NAMES = [
  "Ocak",
  "Şubat",
  "Mart",
  "Nisan",
  "Mayıs",
  "Haziran",
  "Temmuz",
  "Ağustos",
  "Eylül",
  "Ekim",
  "Kasım",
  "Aralık"
] as const;

/** Pazartesi başlangıçlı kısa gün adları (TR takvim düzeni). */
export const TURKISH_WEEKDAY_SHORT_NAMES = ["Pt", "Sa", "Ça", "Pe", "Cu", "Ct", "Pz"] as const;

export type DateParts = {
  year: number;
  /** 0 tabanlı ay (0 = Ocak). */
  month: number;
  day: number;
};

function pad2(value: number): string {
  return String(value).padStart(2, "0");
}

export function daysInMonth(year: number, month: number): number {
  return new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
}

function isValidParts(parts: DateParts): boolean {
  if (!Number.isInteger(parts.year) || parts.year < 1000 || parts.year > 9999) {
    return false;
  }
  if (!Number.isInteger(parts.month) || parts.month < 0 || parts.month > 11) {
    return false;
  }
  if (!Number.isInteger(parts.day) || parts.day < 1 || parts.day > daysInMonth(parts.year, parts.month)) {
    return false;
  }

  return true;
}

export function toIsoDate(parts: DateParts): string | null {
  if (!isValidParts(parts)) {
    return null;
  }

  return `${parts.year}-${pad2(parts.month + 1)}-${pad2(parts.day)}`;
}

/** ISO `yyyy-mm-dd` → parça; geçersiz/eksik değerde null. */
export function parseIsoDate(value: string | null | undefined): DateParts | null {
  if (typeof value !== "string") {
    return null;
  }

  const match = ISO_DATE_PATTERN.exec(value.trim());
  if (!match) {
    return null;
  }

  const parts: DateParts = {
    year: Number.parseInt(match[1]!, 10),
    month: Number.parseInt(match[2]!, 10) - 1,
    day: Number.parseInt(match[3]!, 10)
  };

  return isValidParts(parts) ? parts : null;
}

export function isIsoDateString(value: string | null | undefined): boolean {
  return parseIsoDate(value) !== null;
}

/** ISO → görünen `gg.aa.yyyy` (boş/geçersizde ""). */
export function formatIsoToDisplay(value: string | null | undefined): string {
  const parts = parseIsoDate(value);
  if (!parts) {
    return "";
  }

  return `${pad2(parts.day)}.${pad2(parts.month + 1)}.${parts.year}`;
}

/** Kullanıcı girişi (iso ya da gg.aa.yyyy) → ISO; geçersizde null. */
export function parseDisplayToIso(raw: string | null | undefined): string | null {
  if (typeof raw !== "string") {
    return null;
  }

  const trimmed = raw.trim();
  if (!trimmed) {
    return null;
  }

  const iso = parseIsoDate(trimmed);
  if (iso) {
    return toIsoDate(iso);
  }

  const match = DISPLAY_DATE_PATTERN.exec(trimmed);
  if (!match) {
    return null;
  }

  return toIsoDate({
    year: Number.parseInt(match[3]!, 10),
    month: Number.parseInt(match[2]!, 10) - 1,
    day: Number.parseInt(match[1]!, 10)
  });
}

export function todayIso(): string {
  const now = new Date();

  return toIsoDate({ year: now.getFullYear(), month: now.getMonth(), day: now.getDate() }) ?? "1970-01-01";
}

/** Panel açılış ayı/yılı: değer varsa o, yoksa bugün. */
export function resolveInitialPickerParts(value: string | null | undefined): DateParts {
  const parts = parseIsoDate(value);
  if (parts) {
    return parts;
  }

  return parseIsoDate(todayIso()) ?? { year: 2000, month: 0, day: 1 };
}

export function shiftMonth(year: number, month: number, delta: number): { year: number; month: number } {
  const base = new Date(Date.UTC(year, month + delta, 1));

  return { year: base.getUTCFullYear(), month: base.getUTCMonth() };
}

export function addDaysIso(value: string, delta: number): string {
  const parts = parseIsoDate(value);
  if (!parts) {
    return value;
  }

  const shifted = new Date(Date.UTC(parts.year, parts.month, parts.day + delta));

  return (
    toIsoDate({
      year: shifted.getUTCFullYear(),
      month: shifted.getUTCMonth(),
      day: shifted.getUTCDate()
    }) ?? value
  );
}

export function addYearsIso(value: string, delta: number): string {
  const parts = parseIsoDate(value);
  if (!parts) {
    return value;
  }

  return (
    toIsoDate({ year: parts.year + delta, month: parts.month, day: parts.day }) ??
    toIsoDate({
      year: parts.year + delta,
      month: parts.month,
      day: daysInMonth(parts.year + delta, parts.month)
    }) ??
    value
  );
}

export type MonthCell = { iso: string; day: number; inMonth: boolean };

/** Pazartesi başlangıçlı 6x7 gün matrisi (sabit yükseklik → panel zıplamaz). */
export function buildMonthMatrix(year: number, month: number): MonthCell[] {
  const firstWeekday = new Date(Date.UTC(year, month, 1)).getUTCDay();
  const mondayOffset = (firstWeekday + 6) % 7;
  const cells: MonthCell[] = [];

  for (let index = 0; index < 42; index += 1) {
    const shifted = new Date(Date.UTC(year, month, 1 - mondayOffset + index));
    const iso = toIsoDate({
      year: shifted.getUTCFullYear(),
      month: shifted.getUTCMonth(),
      day: shifted.getUTCDate()
    });

    if (!iso) {
      continue;
    }

    cells.push({
      iso,
      day: shifted.getUTCDate(),
      inMonth: shifted.getUTCMonth() === month && shifted.getUTCFullYear() === year
    });
  }

  return cells;
}

/** Doğum tarihi gibi uzun aralıklar için azalan yıl listesi (uzak yıla hızlı geçiş). */
export function buildYearRange(
  anchorYear: number,
  options: { pastYears?: number; futureYears?: number } = {}
): number[] {
  const pastYears = options.pastYears ?? 110;
  const futureYears = options.futureYears ?? 20;
  const start = anchorYear + futureYears;
  const end = Math.max(1800, anchorYear - pastYears);
  const years: number[] = [];

  for (let year = start; year >= end; year -= 1) {
    years.push(year);
  }

  return years;
}

export function formatMonthYearLabel(year: number, month: number): string {
  return `${TURKISH_MONTH_NAMES[month] ?? ""} ${year}`.trim();
}

/** ISO değeri verilen sınırlar içinde mi (sınır yoksa serbest). */
export function isIsoWithinRange(value: string, min?: string, max?: string): boolean {
  if (!isIsoDateString(value)) {
    return false;
  }
  if (min && isIsoDateString(min) && value < min) {
    return false;
  }
  if (max && isIsoDateString(max) && value > max) {
    return false;
  }

  return true;
}
