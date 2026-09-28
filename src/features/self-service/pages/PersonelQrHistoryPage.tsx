import { useCallback, useEffect, useMemo, useState } from "react";
import { isApiRequestError } from "../../../api/api-client";
import { fetchMeFazlaCalisma, fetchMePuantaj } from "../../../api/me.api";
import { fetchMeQrHareketleri } from "../../../api/qr.api";
import { LoadingState } from "../../../components/states/LoadingState";
import type { MePuantajGun, MeQrAttendanceEvent, MeQrHistoryDay, MeQrHistoryDayEvent } from "../../../types/self-service";
import { SelfServiceFactList, type SelfServiceFact } from "../components/SelfServiceFactList";
import {
  buildHistoryDayWorkFacts,
  buildHistoryMonthSummary
} from "../personel-self-history-summary";
import { formatSelfServiceClock } from "../self-service-datetime";
import {
  AttendanceCorrectionRequestModal,
  type AttendanceCorrectionEventType
} from "../components/AttendanceCorrectionRequestModal";
import { BackgroundlessNoticeModal } from "../components/BackgroundlessNoticeModal";

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

type CorrectDraft = {
  eventId: number;
  eventType: AttendanceCorrectionEventType;
  currentTime: string;
};

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
  const byDate = new Map<string, MeQrHistoryDayEvent[]>();

  const sorted = [...items].sort((a, b) => {
    const cmp = a.occurred_at.localeCompare(b.occurred_at);
    if (cmp !== 0) return cmp;
    return a.id - b.id;
  });

  for (const item of sorted) {
    const date = istanbulLocalDate(item.occurred_at);
    const time = formatSelfServiceClock(item.occurred_at);
    if (!byDate.has(date)) {
      byDate.set(date, []);
    }
    byDate.get(date)!.push({
      id: item.id,
      event_type: item.event_type,
      time,
      occurred_at: item.occurred_at,
      status: null,
      correction_allowed: false,
      pending_correction: null
    });
  }

  return [...byDate.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([date, events]) => {
      const giris = [...events].reverse().find((e) => e.event_type === "GIRIS") ?? null;
      const cikis = [...events].reverse().find((e) => e.event_type === "CIKIS") ?? null;
      const statusLines: string[] = [];
      if (!giris) {
        statusLines.push("Giriş Kaydı Bulunamadı.");
      }
      if (!cikis) {
        statusLines.push("Çıkış Kaydı Bulunamadı.");
      }
      return {
        date,
        has_events: events.length > 0,
        events,
        giris,
        cikis,
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

function PencilIcon() {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M12 20h9" />
      <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" />
    </svg>
  );
}

function canShowHistoryCorrection(event: MeQrHistoryDayEvent | null | undefined): boolean {
  return Boolean(event && event.correction_allowed === true && !event.pending_correction);
}

function eventLabel(event: MeQrHistoryDayEvent): string {
  return event.event_type === "CIKIS" ? "Çıkış" : "Giriş";
}

function dayEvents(day: MeQrHistoryDay | null): MeQrHistoryDayEvent[] {
  if (!day) {
    return [];
  }
  if (day.events && day.events.length > 0) {
    return day.events;
  }
  const fallback: MeQrHistoryDayEvent[] = [];
  if (day.giris) {
    fallback.push({ ...day.giris, event_type: day.giris.event_type ?? "GIRIS" });
  }
  if (day.cikis) {
    fallback.push({ ...day.cikis, event_type: day.cikis.event_type ?? "CIKIS" });
  }
  return fallback;
}

export function PersonelQrHistoryPage() {
  const now = new Date();
  const [viewYear, setViewYear] = useState(now.getFullYear());
  const [viewMonth, setViewMonth] = useState(now.getMonth() + 1);
  const [selectedDate, setSelectedDate] = useState<string | null>(null);
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const [correctDraft, setCorrectDraft] = useState<CorrectDraft | null>(null);
  const [notice, setNotice] = useState<{ title: string; body: string } | null>(null);
  const [monthFacts, setMonthFacts] = useState<SelfServiceFact[]>([]);
  const [puantajByDate, setPuantajByDate] = useState<Map<string, MePuantajGun>>(new Map());
  const [aylikOnayliMi, setAylikOnayliMi] = useState(false);

  const loadMonth = useCallback(async (year: number, month: number) => {
    setStatus({ kind: "loading" });
    const { from, to } = monthRange(year, month);
    try {
      const history = await fetchMeQrHareketleri({ from, to });
      const days =
        history.days && history.days.length > 0
          ? history.days
          : deriveDaysFromItems(history.items);
      const [puantaj, fazla] = await Promise.all([
        fetchMePuantaj({ from, to }).catch(() => null),
        fetchMeFazlaCalisma({ from, to }).catch(() => null)
      ]);
      setAylikOnayliMi(Boolean(puantaj?.ozet.aylik_onayli_mi));
      setMonthFacts(
        buildHistoryMonthSummary({
          ozet: puantaj?.ozet ?? null,
          fazlaDonemDakika: fazla?.donem_ozet ? fazla.donem_ozet.fazla_calisma_dakika_toplam : null,
          puantajItems: puantaj?.items ?? [],
          qrDays: days
        })
      );
      setPuantajByDate(new Map((puantaj?.items ?? []).map((item) => [item.tarih, item])));
      setStatus({ kind: "ready", from: history.from, to: history.to, days });
    } catch (error) {
      const unbound =
        isApiRequestError(error) &&
        (error.code === "SELF_SERVICE_BINDING_REQUIRED" || error.code === "FORBIDDEN");
      setStatus({
        kind: "error",
        message: unbound
          ? "Personel bağlantınız yok veya giriş / çıkış geçmişi bu hesap için kapalı."
          : "Giriş / Çıkış Geçmişi Yüklenemedi. Tekrar Deneyin."
      });
      setMonthFacts([]);
      setPuantajByDate(new Map());
      setAylikOnayliMi(false);
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
  const selectedEvents = dayEvents(selectedDay);
  const selectedWorkFacts = selectedDate
    ? buildHistoryDayWorkFacts(
        puantajByDate.get(selectedDate) ?? null,
        daysByDate.get(selectedDate) ?? null,
        aylikOnayliMi
      )
    : [];

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

      {monthFacts.length > 0 ? (
        <SelfServiceFactList rows={monthFacts} testId="qr-history-month-summary" />
      ) : null}

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
          const hasEvents = Boolean(
            dayData?.has_events ||
              (dayData?.events && dayData.events.length > 0) ||
              dayData?.giris ||
              dayData?.cikis
          );
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
          {selectedWorkFacts.length > 0 ? (
            <SelfServiceFactList rows={selectedWorkFacts} testId="qr-history-day-work" />
          ) : null}
          {selectedEvents.length > 0 ? (
            <ul className="qr-history-timeline" data-testid="qr-history-event-timeline">
              {selectedEvents.map((event) => {
                const type = (event.event_type === "CIKIS" ? "CIKIS" : "GIRIS") as AttendanceCorrectionEventType;
                const isCanonicalGiris = selectedDay?.giris?.id === event.id;
                const isCanonicalCikis = selectedDay?.cikis?.id === event.id;
                return (
                  <li key={event.id} className="qr-history-event-row" data-testid={`history-event-${event.id}`}>
                    <div className="qr-history-event-main">
                      <span
                        className="qr-history-event-time"
                        data-testid={
                          isCanonicalGiris
                            ? "history-giris-time"
                            : isCanonicalCikis
                              ? "history-cikis-time"
                              : undefined
                        }
                      >
                        {event.time}
                      </span>
                      <span className="qr-history-event-label">{eventLabel(event)}</span>
                    </div>
                    {event.pending_correction ? (
                      <span
                        className="qr-history-pending"
                        data-testid={
                          isCanonicalGiris
                            ? "history-giris-pending"
                            : isCanonicalCikis
                              ? "history-cikis-pending"
                              : `history-event-${event.id}-pending`
                        }
                      >
                        Bekliyor
                      </span>
                    ) : null}
                    {canShowHistoryCorrection(event) ? (
                      <button
                        type="button"
                        className="pm-box-pencil"
                        data-testid={
                          isCanonicalGiris
                            ? "history-giris-correct"
                            : isCanonicalCikis
                              ? "history-cikis-correct"
                              : `history-event-${event.id}-correct`
                        }
                        aria-label={`${eventLabel(event)} düzeltme talebi`}
                        onClick={() => {
                          setCorrectDraft({
                            eventId: event.id,
                            eventType: type,
                            currentTime: event.time
                          });
                        }}
                      >
                        <PencilIcon />
                      </button>
                    ) : null}
                    {event.status?.label ? (
                      <p
                        className="qr-history-event-status"
                        data-testid={
                          isCanonicalGiris
                            ? "history-giris-status"
                            : isCanonicalCikis
                              ? "history-cikis-status"
                              : undefined
                        }
                      >
                        {event.status.label}
                      </p>
                    ) : null}
                  </li>
                );
              })}
            </ul>
          ) : (
            <p className="self-service-muted">Bu gün için kayıt yok.</p>
          )}

          {selectedDay && selectedDay.status_lines.length > 0 && selectedEvents.length === 0 ? (
            <ul className="qr-history-status-lines">
              {selectedDay.status_lines.map((line) => (
                <li key={line}>{line}</li>
              ))}
            </ul>
          ) : null}
        </section>
      ) : (
        <p className="self-service-muted qr-history-hint">Detay için bir gün seçin.</p>
      )}

      <AttendanceCorrectionRequestModal
        open={correctDraft !== null}
        eventId={correctDraft?.eventId ?? 0}
        eventType={correctDraft?.eventType ?? "GIRIS"}
        initialTime={correctDraft?.currentTime ?? ""}
        onClose={() => setCorrectDraft(null)}
        onSuccess={(message) => {
          setNotice({ title: "Düzeltme Talebi", body: message });
          void loadMonth(viewYear, viewMonth);
        }}
        onError={(message) => {
          setNotice({ title: "Düzeltme Talebi", body: message });
        }}
      />

      <BackgroundlessNoticeModal
        open={notice !== null}
        title={notice?.title ?? ""}
        body={notice?.body ?? ""}
        onClose={() => setNotice(null)}
        testId="history-notice-modal"
      />
    </section>
  );
}
