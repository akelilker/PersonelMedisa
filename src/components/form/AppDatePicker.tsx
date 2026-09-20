import {
  useCallback,
  useEffect,
  useId,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type FormEventHandler,
  type KeyboardEvent as ReactKeyboardEvent
} from "react";
import {
  activateAppPicker,
  applyPickerPanelGeometry,
  deactivateAppPicker,
  measurePickerPanel
} from "./app-picker-layer";
import {
  DATE_DISPLAY_PLACEHOLDER,
  TURKISH_MONTH_NAMES,
  TURKISH_WEEKDAY_SHORT_NAMES,
  addDaysIso,
  buildMonthMatrix,
  buildYearRange,
  formatIsoToDisplay,
  isIsoDateString,
  isIsoWithinRange,
  parseIsoDate,
  resolveInitialPickerParts,
  shiftMonth,
  todayIso
} from "../../lib/tarih/date-picker-utils";

/**
 * PersonelMedisa kanonik takvim (date picker) owner'ı.
 *
 * Kontrat:
 * - Native `<input type="date">` görünmez DEĞER sahibidir: `name`, `id`,
 *   `required`, `min`/`max`, `data-testid` ve `onChange`/`onInvalid` kontratı
 *   aynen korunur; backend'e ISO `yyyy-mm-dd` gider. Tarayıcının native popup'ı
 *   kullanılmaz (pointer-events kapalı, tabIndex -1); takvim kanonik paneldedir.
 * - Görünen format `gg.aa.yyyy`; boşken kanonik placeholder.
 * - Panel AppSelect ile aynı görsel/ölçüm ailesindendir
 *   (`app-picker-panel`, blur layer, viewport clamp, yukarı açılma).
 * - Yıl seçimi: header'da aya/yıla tıklayınca mod değişir; yıllar scrollable
 *   grid'de listelenir → 2026'dan 1980'e tek adımda gidilir.
 */

export type AppDatePickerProps = {
  /** ISO `yyyy-mm-dd` ("" = boş). Wire formatı değişmez. */
  value: string;
  onChange: (value: string) => void;
  name?: string;
  id?: string;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  className?: string;
  /** Native input üzerine yazılır (mevcut otomasyon kontratı). */
  dataTestId?: string;
  /** ISO tarih sınırları; native input ve takvim aynı sınırı uygular. */
  min?: string;
  max?: string;
  /** Kontrollü açık durum. Verilmezse owner kendi state'ini yönetir. */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** Native input `invalid` kontratı (form doğrulama sahipleri için). */
  onInvalid?: FormEventHandler<HTMLInputElement>;
};

type DatePanelView = "days" | "months" | "years";

const YEAR_GRID_COLUMN_COUNT = 4;

export function AppDatePicker({
  value,
  onChange,
  name,
  id,
  placeholder,
  required = false,
  disabled = false,
  className,
  dataTestId,
  min,
  max,
  open,
  onOpenChange,
  onInvalid
}: AppDatePickerProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const reactId = useId();
  const controlId = id ?? name ?? `app-date-${reactId}`;
  const panelId = `${controlId}-panel`;

  const [internalOpen, setInternalOpen] = useState(false);
  const isOpen = open ?? internalOpen;
  const [view, setView] = useState<DatePanelView>("days");
  const [cursorIso, setCursorIso] = useState(() => (isIsoDateString(value) ? value : todayIso()));
  const [viewYear, setViewYear] = useState(() => resolveInitialPickerParts(value).year);
  const [viewMonth, setViewMonth] = useState(() => resolveInitialPickerParts(value).month);

  const displayValue = formatIsoToDisplay(value);
  const today = todayIso();
  const yearRange = useMemo(() => buildYearRange(viewYear, { pastYears: 110, futureYears: 20 }), [viewYear]);
  const monthCells = useMemo(() => buildMonthMatrix(viewYear, viewMonth), [viewMonth, viewYear]);

  const setOpen = useCallback(
    (next: boolean) => {
      if (open === undefined) {
        setInternalOpen(next);
      }
      onOpenChange?.(next);
    },
    [onOpenChange, open]
  );

  const closePanel = useCallback(
    (restoreFocus = false) => {
      setOpen(false);
      setView("days");
      if (restoreFocus) {
        triggerRef.current?.focus({ preventScroll: true });
      }
    },
    [setOpen]
  );

  const syncViewToCursor = useCallback((iso: string) => {
    const parts = parseIsoDate(iso);
    if (!parts) {
      return;
    }
    setCursorIso(iso);
    setViewYear(parts.year);
    setViewMonth(parts.month);
  }, []);

  const openPanel = useCallback(() => {
    if (disabled) {
      return;
    }
    const base = isIsoDateString(value) ? value : today;
    setView("days");
    syncViewToCursor(base);
    setOpen(true);
  }, [disabled, setOpen, syncViewToCursor, today, value]);

  const commit = useCallback(
    (next: string | null) => {
      onChange(next ?? "");
      closePanel(true);
    },
    [closePanel, onChange]
  );

  // Blur kontratı: açıkken picker dışındaki yüzey bulanır (AppSelect ile aynı owner).
  useLayoutEffect(() => {
    if (!isOpen) {
      return;
    }

    const root = rootRef.current;
    if (!root) {
      return;
    }

    activateAppPicker(root);

    return () => {
      deactivateAppPicker(root);
    };
  }, [isOpen]);

  // Panel geometrisi: dikey flip + max-height + yatay clamp (kanonik ölçüm owner'ı).
  useLayoutEffect(() => {
    if (!isOpen) {
      return;
    }

    const root = rootRef.current;
    const panel = panelRef.current;
    if (!root || !panel) {
      return;
    }

    const measure = () => {
      const geometry = measurePickerPanel(root, panel);
      panel.setAttribute("data-placement", geometry.placement);
      applyPickerPanelGeometry(panel, geometry);
    };

    measure();
    const frame = window.requestAnimationFrame(measure);
    window.addEventListener("resize", measure);
    window.addEventListener("scroll", measure, true);

    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener("resize", measure);
      window.removeEventListener("scroll", measure, true);
    };
  }, [isOpen, view, yearRange.length]);

  // Dışarı tıklama: picker ağacı dışındaki ilk pointer hedefi kapatır.
  useEffect(() => {
    if (!isOpen) {
      return;
    }

    function handlePointerDown(event: Event) {
      const target = event.target;
      if (target instanceof Node && rootRef.current?.contains(target)) {
        return;
      }

      closePanel(false);
    }
    document.addEventListener("pointerdown", handlePointerDown, true);
    return () => document.removeEventListener("pointerdown", handlePointerDown, true);
  }, [closePanel, isOpen]);

  // Klavye odağı: aktif hücre her zaman görünür odaklıdır (yıl listesi otomatik kaydırılır).
  useLayoutEffect(() => {
    if (!isOpen) {
      return;
    }

    const panel = panelRef.current;
    if (!panel) {
      return;
    }

    const selector =
      view === "years"
        ? `[data-date-year="${viewYear}"]`
        : view === "months"
          ? `[data-date-month="${viewYear}-${viewMonth}"]`
          : `[data-date-cell="${cursorIso}"]`;

    const target = panel.querySelector<HTMLButtonElement>(selector);
    target?.focus({ preventScroll: view === "years" });
    if (view === "years" && typeof target?.scrollIntoView === "function") {
      target.scrollIntoView({ block: "center" });
    }
  }, [cursorIso, isOpen, view, viewMonth, viewYear]);


  const handlePanelKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLDivElement>) => {
      const { key } = event;

      if (key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        closePanel(true);
        return;
      }

      if (view === "days") {
        if (key === "ArrowLeft") {
          event.preventDefault();
          syncViewToCursor(addDaysIso(cursorIso, -1));
          return;
        }
        if (key === "ArrowRight") {
          event.preventDefault();
          syncViewToCursor(addDaysIso(cursorIso, 1));
          return;
        }
        if (key === "ArrowUp") {
          event.preventDefault();
          syncViewToCursor(addDaysIso(cursorIso, -7));
          return;
        }
        if (key === "ArrowDown") {
          event.preventDefault();
          syncViewToCursor(addDaysIso(cursorIso, 7));
          return;
        }
        if (key === "PageUp" || key === "PageDown") {
          event.preventDefault();
          const next = shiftMonth(viewYear, viewMonth, key === "PageUp" ? -1 : 1);
          setViewYear(next.year);
          setViewMonth(next.month);
        }
        return;
      }

      if (view === "months") {
        if (key === "ArrowLeft" || key === "ArrowRight") {
          event.preventDefault();
          const next = shiftMonth(viewYear, viewMonth, key === "ArrowLeft" ? -1 : 1);
          setViewYear(next.year);
          setViewMonth(next.month);
          return;
        }
        if (key === "ArrowUp" || key === "ArrowDown") {
          event.preventDefault();
          const next = shiftMonth(viewYear, viewMonth, key === "ArrowUp" ? -3 : 3);
          setViewYear(next.year);
          setViewMonth(next.month);
        }
        return;
      }

      if (key === "ArrowLeft" || key === "ArrowRight") {
        event.preventDefault();
        setViewYear((prev) => prev + (key === "ArrowLeft" ? -1 : 1));
        return;
      }
      if (key === "ArrowUp" || key === "ArrowDown") {
        event.preventDefault();
        setViewYear((prev) => prev + (key === "ArrowUp" ? -YEAR_GRID_COLUMN_COUNT : YEAR_GRID_COLUMN_COUNT));
      }
    },
    [closePanel, cursorIso, syncViewToCursor, view, viewMonth, viewYear]
  );

  const handleTriggerKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLButtonElement>) => {
      if (event.key === "Escape" && isOpen) {
        event.preventDefault();
        event.stopPropagation();
        closePanel(true);
        return;
      }

      if (!isOpen && (event.key === "ArrowDown" || event.key === "ArrowUp")) {
        event.preventDefault();
        openPanel();
      }
    },
    [closePanel, isOpen, openPanel]
  );

  const handleToday = useCallback(() => {
    if (isIsoWithinRange(today, min, max)) {
      syncViewToCursor(today);
      setView("days");
      commit(today);
      return;
    }

    syncViewToCursor(isIsoDateString(value) ? value : today);
    setView("days");
  }, [commit, max, min, syncViewToCursor, today, value]);

  const stepMonth = useCallback(
    (delta: number) => {
      const next = shiftMonth(viewYear, viewMonth, delta);
      setViewYear(next.year);
      setViewMonth(next.month);
    },
    [viewMonth, viewYear]
  );

  const placeholderText = placeholder ?? DATE_DISPLAY_PLACEHOLDER;
  const isPlaceholderVisible = displayValue === "";

  return (
    <div
      ref={rootRef}
      className={["app-date-picker", isOpen ? "is-open" : "", disabled ? "is-disabled" : "", className ?? ""]
        .filter(Boolean)
        .join(" ")}
    >
      <button
        ref={triggerRef}
        type="button"
        className="app-date-picker-trigger form-input"
        data-app-date-trigger="1"
        aria-label="Takvimi aç"
        aria-haspopup="dialog"
        aria-expanded={isOpen}
        aria-controls={isOpen ? panelId : undefined}
        disabled={disabled}
        onClick={() => (isOpen ? closePanel(false) : openPanel())}
        onKeyDown={handleTriggerKeyDown}
      >
        <span className={`app-date-picker-value${isPlaceholderVisible ? " is-placeholder" : ""}`}>
          {displayValue || placeholderText}
        </span>
        <svg
          className="app-select-chevron"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          aria-hidden="true"
          focusable="false"
        >
          <path d="M6 9l6 6 6-6" />
        </svg>
      </button>

      {/* Native input değer sahibidir: name/required/min/max ve ISO kontratı burada kalır. */}
      <input
        id={controlId}
        name={name}
        type="date"
        className="app-date-picker-native"
        value={value}
        min={min}
        max={max}
        required={required}
        disabled={disabled}
        tabIndex={-1}
        data-testid={dataTestId}
        onChange={(event) => onChange(event.target.value)}
        onInvalid={onInvalid}
      />

      {isOpen ? (
        <div
          ref={panelRef}
          id={panelId}
          className="app-picker-panel app-date-panel"
          data-app-date-panel="1"
          data-view={view}
          onKeyDown={handlePanelKeyDown}
        >
          {view === "days" ? (
            <>
              <div className="app-date-head">
                <button type="button" className="app-date-nav" aria-label="Önceki ay" onClick={() => stepMonth(-1)}>
                  ‹
                </button>
                <button
                  type="button"
                  className="app-date-head-btn"
                  data-date-ref="month"
                  aria-label="Ay seç"
                  onClick={() => setView("months")}
                >
                  {TURKISH_MONTH_NAMES[viewMonth]}
                </button>
                <button
                  type="button"
                  className="app-date-head-btn"
                  data-date-ref="year"
                  aria-label="Yıl seç"
                  onClick={() => setView("years")}
                >
                  {viewYear}
                </button>
                <button type="button" className="app-date-nav" aria-label="Sonraki ay" onClick={() => stepMonth(1)}>
                  ›
                </button>
              </div>

              <div className="app-date-weekdays" aria-hidden="true">
                {TURKISH_WEEKDAY_SHORT_NAMES.map((name) => (
                  <span key={name} className="app-date-weekday">
                    {name}
                  </span>
                ))}
              </div>

              <div className="app-date-grid">
                {monthCells.map((cell) => {
                  const isSelected = cell.iso === value;
                  const cellClass = [
                    "app-date-cell",
                    cell.inMonth ? "" : "is-outside",
                    isSelected ? "is-selected" : "",
                    cell.iso === today ? "is-today" : ""
                  ]
                    .filter(Boolean)
                    .join(" ");

                  return (
                    <button
                      key={cell.iso}
                      type="button"
                      className={cellClass}
                      data-date-cell={cell.iso}
                      aria-pressed={isSelected}
                      disabled={!isIsoWithinRange(cell.iso, min, max)}
                      onClick={() => commit(cell.iso)}
                    >
                      {cell.day}
                    </button>
                  );
                })}
              </div>
            </>
          ) : null}

          {view === "months" ? (
            <>
              <div className="app-date-head">
                <button
                  type="button"
                  className="app-date-head-btn"
                  data-date-ref="year"
                  aria-label="Yıl seç"
                  onClick={() => setView("years")}
                >
                  {viewYear}
                </button>
                <button
                  type="button"
                  className="app-date-head-btn"
                  data-date-ref="days"
                  aria-label="Gün seç"
                  onClick={() => setView("days")}
                >
                  Günler
                </button>
              </div>

              <div className="app-date-grid app-date-grid--months">
                {TURKISH_MONTH_NAMES.map((name, index) => {
                  const isSelected = index === viewMonth;
                  const monthClass = ["app-date-cell", "app-date-cell--month", isSelected ? "is-selected" : ""]
                    .filter(Boolean)
                    .join(" ");

                  return (
                    <button
                      key={name}
                      type="button"
                      className={monthClass}
                      data-date-month={`${viewYear}-${index}`}
                      aria-pressed={isSelected}
                      onClick={() => {
                        setViewMonth(index);
                        setView("days");
                      }}
                    >
                      {name}
                    </button>
                  );
                })}
              </div>
            </>
          ) : null}

          {view === "years" ? (
            <>
              <div className="app-date-head">
                <button
                  type="button"
                  className="app-date-head-btn"
                  data-date-ref="days"
                  aria-label="Gün seç"
                  onClick={() => setView("days")}
                >
                  Günler
                </button>
              </div>

              <div className="app-date-grid app-date-grid--years">
                {yearRange.map((year) => {
                  const isSelected = year === viewYear;
                  const yearClass = ["app-date-cell", "app-date-cell--year", isSelected ? "is-selected" : ""]
                    .filter(Boolean)
                    .join(" ");

                  return (
                    <button
                      key={year}
                      type="button"
                      className={yearClass}
                      data-date-year={year}
                      aria-pressed={isSelected}
                      onClick={() => {
                        setViewYear(year);
                        setView("months");
                      }}
                    >
                      {year}
                    </button>
                  );
                })}
              </div>
            </>
          ) : null}

          <div className="app-date-foot">
            <button type="button" className="app-date-action" data-date-today="1" onClick={handleToday}>
              Bugün
            </button>
            {!required ? (
              <button type="button" className="app-date-action" data-date-clear="1" onClick={() => commit("")}>
                Temizle
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  );
}
