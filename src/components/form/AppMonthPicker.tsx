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
  measurePickerPanel,
  PICKER_MONTH_PANEL_MIN_WIDTH
} from "./app-picker-layer";
import {
  MONTH_DISPLAY_PLACEHOLDER,
  TURKISH_MONTH_NAMES,
  buildYearRange,
  currentMonthIso,
  formatIsoMonthToDisplay,
  isIsoMonthString,
  isMonthWithinRange,
  resolveInitialMonthPickerParts,
  shiftMonth,
  toIsoMonth
} from "../../lib/tarih/date-picker-utils";

/**
 * PersonelMedisa kanonik ay (month picker) owner'ı.
 *
 * Kontrat:
 * - Native `<input type="month">` görünmez DEĞER sahibidir: `name`, `id`,
 *   `required`, `min`/`max`, `data-testid` ve `onChange`/`onInvalid` kontratı
 *   aynen korunur; wire formatı `yyyy-mm` kalır.
 * - Görünen format `Eylül 2026`; boşken kanonik placeholder.
 * - Panel AppDatePicker / AppSelect ile aynı picker layer kontratını paylaşır.
 */

export type AppMonthPickerProps = {
  /** ISO `yyyy-mm` ("" = boş). Wire formatı değişmez. */
  value: string;
  onChange: (value: string) => void;
  name?: string;
  id?: string;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  className?: string;
  dataTestId?: string;
  min?: string;
  max?: string;
  step?: string;
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  onInvalid?: FormEventHandler<HTMLInputElement>;
};

type MonthPanelView = "months" | "years";

const YEAR_GRID_COLUMN_COUNT = 4;

export function AppMonthPicker({
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
  step,
  open,
  onOpenChange,
  onInvalid
}: AppMonthPickerProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const reactId = useId();
  const controlId = id ?? name ?? `app-month-${reactId}`;
  const panelId = `${controlId}-panel`;

  const [internalOpen, setInternalOpen] = useState(false);
  const isOpen = open ?? internalOpen;
  const [view, setView] = useState<MonthPanelView>("months");
  const initialParts = resolveInitialMonthPickerParts(value);
  const [viewYear, setViewYear] = useState(initialParts.year);
  const [viewMonth, setViewMonth] = useState(initialParts.month);

  const displayValue = formatIsoMonthToDisplay(value);
  const thisMonth = currentMonthIso();
  const yearRange = useMemo(() => buildYearRange(viewYear, { pastYears: 110, futureYears: 20 }), [viewYear]);

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
      setView("months");
      if (restoreFocus) {
        triggerRef.current?.focus({ preventScroll: true });
      }
    },
    [setOpen]
  );

  const syncViewToValue = useCallback((isoMonth: string) => {
    const parts = resolveInitialMonthPickerParts(isoMonth);
    setViewYear(parts.year);
    setViewMonth(parts.month);
  }, []);

  const openPanel = useCallback(() => {
    if (disabled) {
      return;
    }
    const base = isIsoMonthString(value) ? value : thisMonth;
    setView("months");
    syncViewToValue(base);
    setOpen(true);
  }, [disabled, setOpen, syncViewToValue, thisMonth, value]);

  const commit = useCallback(
    (next: string | null) => {
      onChange(next ?? "");
      closePanel(true);
    },
    [closePanel, onChange]
  );

  const commitMonth = useCallback(
    (year: number, month: number) => {
      const iso = toIsoMonth({ year, month });
      if (!iso || !isMonthWithinRange(iso, min, max)) {
        return;
      }
      commit(iso);
    },
    [commit, max, min]
  );

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
      const geometry = measurePickerPanel(root, panel, {
        minPanelWidth: PICKER_MONTH_PANEL_MIN_WIDTH
      });
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
        ? `[data-month-year="${viewYear}"]`
        : `[data-month-cell="${viewYear}-${viewMonth}"]`;

    const target = panel.querySelector<HTMLButtonElement>(selector);
    target?.focus({ preventScroll: view === "years" });
    if (view === "years" && typeof target?.scrollIntoView === "function") {
      target.scrollIntoView({ block: "center" });
    }
  }, [isOpen, view, viewMonth, viewYear]);

  const handlePanelKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLDivElement>) => {
      const { key } = event;

      if (key === "Escape") {
        event.preventDefault();
        event.stopPropagation();
        closePanel(true);
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
    [closePanel, view, viewMonth, viewYear]
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

  const handleThisMonth = useCallback(() => {
    if (isMonthWithinRange(thisMonth, min, max)) {
      syncViewToValue(thisMonth);
      setView("months");
      commit(thisMonth);
      return;
    }

    syncViewToValue(isIsoMonthString(value) ? value : thisMonth);
    setView("months");
  }, [commit, max, min, syncViewToValue, thisMonth, value]);

  const stepYear = useCallback((delta: number) => {
    setViewYear((prev) => prev + delta);
  }, []);

  const placeholderText = placeholder ?? MONTH_DISPLAY_PLACEHOLDER;
  const isPlaceholderVisible = displayValue === "";

  return (
    <div
      ref={rootRef}
      className={["app-month-picker", isOpen ? "is-open" : "", disabled ? "is-disabled" : "", className ?? ""]
        .filter(Boolean)
        .join(" ")}
    >
      <button
        ref={triggerRef}
        type="button"
        className="app-month-picker-trigger form-input"
        data-app-month-trigger="1"
        aria-label="Ay seçiciyi aç"
        aria-haspopup="dialog"
        aria-expanded={isOpen}
        aria-controls={isOpen ? panelId : undefined}
        disabled={disabled}
        onClick={() => (isOpen ? closePanel(false) : openPanel())}
        onKeyDown={handleTriggerKeyDown}
      >
        <span className={`app-month-picker-value${isPlaceholderVisible ? " is-placeholder" : ""}`}>
          {displayValue || placeholderText}
        </span>
        <svg
          className="app-month-picker-icon"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.75"
          strokeLinecap="round"
          strokeLinejoin="round"
          aria-hidden="true"
          focusable="false"
        >
          <rect x="3" y="4.5" width="18" height="17" rx="2" />
          <path d="M16 2.5v4M8 2.5v4M3 10.5h18" />
        </svg>
      </button>

      <input
        id={controlId}
        name={name}
        type="month"
        className="app-month-picker-native"
        value={value}
        min={min}
        max={max}
        step={step}
        required={required}
        disabled={disabled}
        tabIndex={-1}
        data-testid={dataTestId}
        onChange={(event) => onChange(event.target.value)}
        onInvalid={onInvalid}
      />

      {isOpen ? (
        <button
          type="button"
          className="app-month-picker-scrim"
          data-app-month-scrim="1"
          tabIndex={-1}
          aria-label="Ay seçiciyi kapat"
          onPointerDown={(event) => {
            event.preventDefault();
            closePanel(false);
          }}
        />
      ) : null}

      {isOpen ? (
        <div
          ref={panelRef}
          id={panelId}
          className="app-picker-panel app-month-panel"
          data-app-month-panel="1"
          data-view={view}
          onKeyDown={handlePanelKeyDown}
        >
          {view === "months" ? (
            <>
              <div className="app-date-head">
                <button type="button" className="app-date-nav" aria-label="Önceki yıl" onClick={() => stepYear(-1)}>
                  ‹
                </button>
                <button
                  type="button"
                  className="app-date-head-btn"
                  data-month-ref="year"
                  aria-label="Yıl seç"
                  onClick={() => setView("years")}
                >
                  {viewYear}
                </button>
                <button type="button" className="app-date-nav" aria-label="Sonraki yıl" onClick={() => stepYear(1)}>
                  ›
                </button>
              </div>

              <div className="app-date-grid app-date-grid--months">
                {TURKISH_MONTH_NAMES.map((name, index) => {
                  const iso = toIsoMonth({ year: viewYear, month: index });
                  const isSelected = isIsoMonthString(value) && value === iso;
                  const monthClass = ["app-date-cell", "app-date-cell--month", isSelected ? "is-selected" : ""]
                    .filter(Boolean)
                    .join(" ");

                  return (
                    <button
                      key={name}
                      type="button"
                      className={monthClass}
                      data-month-cell={`${viewYear}-${index}`}
                      aria-pressed={isSelected}
                      disabled={!iso || !isMonthWithinRange(iso, min, max)}
                      onClick={() => commitMonth(viewYear, index)}
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
                  data-month-ref="months"
                  aria-label="Ayları göster"
                  onClick={() => setView("months")}
                >
                  Aylar
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
                      data-month-year={year}
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
            <button type="button" className="app-date-action" data-month-this="1" onClick={handleThisMonth}>
              Bu Ay
            </button>
            {!required ? (
              <button type="button" className="app-date-action" data-month-clear="1" onClick={() => commit("")}>
                Temizle
              </button>
            ) : null}
          </div>
        </div>
      ) : null}
    </div>
  );
}
