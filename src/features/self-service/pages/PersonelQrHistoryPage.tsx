import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { isApiRequestError } from "../../../api/api-client";
import { fetchMeQrHareketleri } from "../../../api/qr.api";
import { LoadingState } from "../../../components/states/LoadingState";
import type { MeQrAttendanceEvent, MeQrHistoryDay } from "../../../types/self-service";
import { formatSelfServiceClock } from "../self-service-datetime";

const WEEKDAYS = ["Pzt", "Sal", "Çar", "Per", "Cum", "Cmt", "Paz"] as const;

type Status =
  | { kind: "loading" }
  | {
      kind: "ready";
      from: string;
      to: string;
      days: MeQrHistoryDay[];
    }
  | { kind: "error"; message: string };

function parseYmd(ymd: string): { year: number; month: number; day: number } {
  const [y, m, d] = ymd.split("-").map((part) => Number.parseInt(part, 10));
  return { year: y, month: m, day: d };
}

function formatYmd(year: number, month: number, day: number): string {
  return `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function istanbulLocalDate(iso: string): string {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Europe/Istanbul",
    year: "numeric",
    month: "2-digit",
    day: "2-digit"
  }).format(new Date(iso));
}

function deriveDaysFromItems(items: MeQrAttendanceEvent[]): MeQrHistoryDay[] {
  const byDate = new Map<
    string,
    { giris: MeQrHistoryDay["giris"]; cikis: MeQrHistoryDay["cikis"]; girisUtc: string; cikisUtc: string }
  >();

  for (const item of items) {
    const date = istanbulLocalDate(item.occurred_at);
    const time = formatSelfServiceClock(item.occurred_at);
    if (!byDate.has(date)) {
      byDate.set(date, { giris: null, cikis: null, girisUtc: "", cikisUtc: "" });
    }
    const row = byDate.get(date)!;
    if (item.event_type === "GIRIS") {
      if (!row.giris || item.occurred_at < row.girisUtc) {
        row.giris = { id: item.id, time, occurred_at: item.occurred_at, status: null };
        row.girisUtc = item.occurred_at;
      }
    } else if (item.event_type === "CIKIS") {
      if (!row.cikis || item.occurred_at > row.cikisUtc) {
        row.cikis = { id: item.id, time, occurred_at: item.occurred_at, status: null };
        row.cikisUtc = item.occurred_at;
      }
    }
  }

  return [...byDate.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, row]) => {
      const statusLines: string[] = [];
      if (!row.giris) {
        statusLines.push("Giriş Kaydı Bulunamadı.");
      }
      if (!row.cikis) {
        statusLines.push("Çıkış Kaydı Bulunamadı.");
      }
      return {
        date,
        has_events: row.giris != null || row.cikis != null,
        giris: row.giris,
        cikis: row.cikis,
        status_lines: statusLines
      };
    });
}

function monthRange(year: number, month: number): { from: string; to: string } {
  const from = formatYmd(year, month, 1);
  const lastDay = new Date(year, month, 0).getDate();
  const to = formatYmd(year, month, lastDay);
  return { from, to };
}

function formatMonthTitle(year: number, month: number): string {
  const label = new Intl.DateTimeFormat("tr-TR", { month: "long", year: "numeric" }).format(
    new Date(year, month - 1, 1)
  );
  return label.charAt(0).toUpperCase() + label.slice(1);
}

function formatDetailDate(ymd: string): string {
  const { year, month, day } = parseYmd(ymd);
  return new Intl.DateTimeFormat("tr-TR", {
    day: "numeric",
    month: "long",
    year: "numeric",
    weekday: "long"
  }).format(new Date(year, month - 1, day));
}

export function PersonelQrHistoryPage() {
  const now = new Date();
  const [viewYear, setViewYear] = useState(now.getFullYear());
  const [viewMonth, setViewMonth] = useState(now.getMonth() + 1);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [status, setStatus] = useState<Status>({ kind: "loading" });

  const loadMonth = useCallback(async (year: number, month: number) => {
    setStatus({ kind: "loading" });
    const { from, to } = monthRange(year, month);
    try {
      const history = await fetchMeQrHareketleri({ from, to });
      const days =
        history.days && history.days.length > 0
          ? history.days
          : deriveDaysFromItems(history.items);
      setStatus({ kind: "ready", from: history.from, to: history.to, days });
    } catch (error) {
      const unbound =
        isApiRequestError(error) &&
        (error.code === "SELF_SERVICE_BINDING_REQUIRED" || error.code === "FORBIDDEN");
      setStatus({
        kind: "error",
        message: unbound
          ? "Personel bağlantınız yok veya QR hareketleri bu hesap için kapalı."
          : "QR hareketleri yüklenemedi. Tekrar deneyin."
      });
    }
  }, []);

  useEffect(() => {
    void loadMonth(viewYear, viewMonth);
  }, [loadMonth, viewYear, viewMonth]);

  const daysByDate = useMemo(() => {
    if (status.kind !== "ready") {
      return new Map<string, MeQrHistoryDay>();
    }
    return new Map(status.days.map((day) => [day.date, day]));
  }, [status]);

  const calendarCells = useMemo(() => {
    const first = new Date(viewYear, viewMonth - 1, 1);
    const lastDay = new Date(viewYear, viewMonth, 0).getDate();
    const startOffset = (first.getDay() + 6) % 7;
    const cells: Array<{ date: string | null; dayNum: number | null }> = [];
    for (let i = 0; i < startOffset; i += 1) {
      cells.push({ date: null, dayNum: null });
    }
    for (let day = 1; day <= lastDay; day += 1) {
      cells.push({ date: formatYmd(viewYear, viewMonth, day), dayNum: day });
    }
    return cells;
  }, [viewMonth, viewYear]);

  const selectedDay = selectedDate ? daysByDate.get(selectedDate) ?? null : null;

  function shiftMonth(delta: number) {
    const cursor = new Date(viewYear, viewMonth - 1 + delta, 1);
    setViewYear(cursor.getFullYear());
    setViewMonth(cursor.getMonth() + 1);
    setSelectedDate(null);
  }

  if (status.kind === "loading") {
    return <LoadingState label="Giriş / çıkış geçmişi yükleniyor..." />;
  }

  if (status.kind === "error") {
    return (
      <section className="states-page state-error" data-testid="personel-qr-history-error">
        <p>{status.message}</p>
        <Link to="/">Özet</Link>
      </section>
    );
  }

  return (
    <section className="personel-mobile-shell qr-history-page" data-testid="personel-qr-history-page">
      <div className="qr-history-toolbar" data-testid="qr-history-calendar">
        <button
          type="button"
          className="qr-history-nav"
          aria-label="Önceki ay"
          data-testid="qr-history-month-prev"
          onClick={() => shiftMonth(-1)}
        >
          ‹
        </button>
        <p className="qr-history-month">{formatMonthTitle(viewYear, viewMonth)}</p>
        <button
          type="button"
          className="qr-history-nav"
          aria-label="Sonraki ay"
          data-testid="qr-history-month-next"
          onClick={() => shiftMonth(1)}
        >
          ›
        </button>
      </div>

      <div className="qr-history-weekdays" aria-hidden="true">
        {WEEKDAYS.map((label) => (
          <span key={label} className="qr-history-weekday">
            {label}
          </span>
        ))}
      </div>

      <div className="qr-history-grid" role="grid" aria-label="Ay takvimi">
        {calendarCells.map((cell, index) => {
          if (!cell.date || cell.dayNum == null) {
            return <div key={`empty-${index}`} className="qr-history-cell qr-history-cell--empty" />;
          }
          const dayData = daysByDate.get(cell.date);
          const hasEvents = Boolean(dayData?.has_events || dayData?.giris || dayData?.cikis);
          const isSelected = selectedDate === cell.date;
          return (
            <button
              key={cell.date}
              type="button"
              data-testid={`qr-history-day-${cell.date}`}
              className={[
                "qr-history-cell",
                hasEvents ? "qr-history-cell--has-events" : "",
                isSelected ? "qr-history-cell--selected" : ""
              ]
                .filter(Boolean)
                .join(" ")}
              aria-pressed={isSelected}
              onClick={() => setSelectedDate(cell.date)}
            >
              <span className="qr-history-day-num">{cell.dayNum}</span>
              {hasEvents ? <span className="qr-history-day-dot" aria-hidden="true" /> : null}
            </button>
          );
        })}
      </div>

      {selectedDate ? (
        <section className="qr-history-detail" data-testid="qr-history-day-detail">
          <h3 className="qr-history-detail-date">{formatDetailDate(selectedDate)}</h3>
          {selectedDay ? (
            <>
              <dl className="qr-history-detail-dl">
                <div>
                  <dt>Giriş</dt>
                  <dd>{selectedDay.giris?.time ?? "—"}</dd>
                </div>
                <div>
                  <dt>Çıkış</dt>
                  <dd>{selectedDay.cikis?.time ?? "—"}</dd>
                </div>
              </dl>
              {selectedDay.status_lines.length > 0 ? (
                <ul className="qr-history-status-lines">
                  {selectedDay.status_lines.map((line) => (
                    <li key={line}>{line}</li>
                  ))}
                </ul>
              ) : null}
              {selectedDay.giris?.status?.label ? (
                <p className="qr-history-event-status">Giriş: {selectedDay.giris.status.label}</p>
              ) : null}
              {selectedDay.cikis?.status?.label ? (
                <p className="qr-history-event-status">Çıkış: {selectedDay.cikis.status.label}</p>
              ) : null}
            </>
          ) : (
            <p className="self-service-muted">Bu gün için kayıt yok.</p>
          )}
        </section>
      ) : (
        <p className="self-service-muted qr-history-hint">Detay için bir gün seçin.</p>
      )}

      <nav className="pm-secondary-nav" aria-label="QR sayfa bağlantıları">
        <Link to="/">Özet</Link>
        <Link to="/self/qr-okut">QR Okut</Link>
      </nav>
    </section>
  );
}
