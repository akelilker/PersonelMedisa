import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type ChangeEvent as ReactChangeEvent,
  type KeyboardEvent as ReactKeyboardEvent,
  type MouseEvent as ReactMouseEvent
} from "react";
import { activateAppPicker, applyPickerPanelGeometry, deactivateAppPicker, measurePickerPanel } from "./app-picker-layer";

/**
 * PersonelMedisa kanonik seçim owner'ı.
 *
 * Taşıt Yönetim Sistemi referansı (`medisa-owner-select` / `medisa-boxed-select`) davranışı
 * React tarafında tek owner olarak uygulanır:
 * - Native `<select>` görünmez değer sahibidir; etiket ilişkisi, `required`, `name`,
 *   `data-testid` ve tüm `onChange` kontratı aynen korunur (business logic değişmez).
 * - Görsel alan (trigger + chevron) ve seçenek kartları bu owner'ın sorumluluğundadır.
 * - Seçenek paneli picker'ın kendi alt ağacında (in-place absolute) render edilir: blur
 *   dışında kalır, scroll container ile birlikte hareket eder ve modal'a scope'lu
 *   otomasyon sorguları (`getByRole("option")`, `#<name>-panel`) çalışmaya devam eder.
 * - Picker açıkken blur tek shared kontratla uygulanır (bkz. app-picker-layer.ts).
 * - Placeholder yalnız trigger metnidir ve native `<select>` içinde boş değer
 *   olarak kalır; panelde İKİNCİ bir "Seçiniz" kartı olarak tekrar etmez.
 *   Opsiyonel alanda açık temizleme ihtiyacı kanonik temizleme satırı ile
 *   çözülür (`data-app-select-clear`), placeholder hack'i ile değil.
 * - Panel geometrisi (flip/max-height/yatay clamp) tek owner'dan gelir
 *   (`measurePickerPanel` / `applyPickerPanelGeometry`).
 */

export type AppSelectOption = { value: string; label: string };

export type AppSelectProps = {
  value: string;
  onChange: (value: string) => void;
  options: AppSelectOption[];
  /** Native select `name` (form değeri ve etiket ilişkisi bu elemanda kalır). */
  name?: string;
  /** Native select `id`; verilmezse `name` kullanılır. */
  id?: string;
  placeholderOption?: AppSelectOption;
  required?: boolean;
  disabled?: boolean;
  /** Görsel alan (native select değil) için ek class. */
  className?: string;
  /** Native select üzerine yazılır; mevcut test/otomasyon kontratı korunur. */
  dataTestId?: string;
  /** Kontrollü açık durum. Verilmezse owner kendi state'ini yönetir. */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
  /** Erişilebilirlik: listbox adı. */
  ariaLabel?: string;
  /** Searchable varyant: panelin üstünde arama alanı gösterir. */
  searchable?: boolean;
  /** Kontrollü arama değeri (ör. toolbar arama alanı ile tek state paylaşımı). */
  searchValue?: string;
  onSearchValueChange?: (value: string) => void;
  searchPlaceholder?: string;
  searchLabel?: string;
  searchInputTestId?: string;
  /** false: seçenek listesi caller tarafından filtrelenmiş gelir (iş mantığı caller'da kalır). */
  filterOptions?: boolean;
  noResultsText?: string;
  /** Açılışta arama alanına odaklan (touch cihazlarda otomatik atlanır). */
  autoFocusSearch?: boolean;
};

type PanelPlacement = "above" | "below";

/** Opsiyonel alanın kanonik temizleme satırı etiketi (placeholder metni tekrar etmez). */
const CLEAR_SELECTION_LABEL = "Seçimi temizle";

type ActivePickerHandle = { close: () => void };

/** Aynı anda tek kanonik picker açık kalır (state bozulmasını önleyen registry). */
let activePickerCloser: ActivePickerHandle | null = null;

function clamp(value: number, min: number, max: number) {
  return Math.min(Math.max(value, min), max);
}

function moveIndex(current: number, delta: number, length: number) {
  if (length <= 0) {
    return -1;
  }

  if (current < 0) {
    return delta > 0 ? 0 : length - 1;
  }

  return clamp(current + delta, 0, length - 1);
}

/** Türkçe uyumlu, aksan/kılasör farkını yok sayan arama normalizasyonu (canonical owner). */
function normalizeSearchText(value: string) {
  return value
    .toLocaleLowerCase("tr-TR")
    .replace(/ı/g, "i")
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .trim();
}

function isTouchLikeDevice() {
  return typeof window !== "undefined" && typeof window.matchMedia === "function"
    ? window.matchMedia("(hover: none)").matches
    : false;
}

function optionElementId(panelId: string, index: number) {
  return `${panelId}-option-${index}`;
}

export function AppSelect({
  value,
  onChange,
  options,
  name,
  id,
  placeholderOption,
  required = false,
  disabled = false,
  className,
  dataTestId,
  open,
  onOpenChange,
  ariaLabel,
  searchable = false,
  searchValue,
  onSearchValueChange,
  searchPlaceholder,
  searchLabel,
  searchInputTestId,
  filterOptions,
  noResultsText,
  autoFocusSearch = true
}: AppSelectProps) {
  const rootRef = useRef<HTMLDivElement>(null);
  const selectRef = useRef<HTMLSelectElement>(null);
  const panelRef = useRef<HTMLDivElement>(null);
  const searchRef = useRef<HTMLInputElement>(null);
  const controlId = id ?? name;
  const panelId = `${controlId ?? "app-select"}-panel`;

  const [internalOpen, setInternalOpen] = useState(false);
  const [internalSearch, setInternalSearch] = useState("");
  const isSearchControlled = searchValue !== undefined;
  const searchQuery = isSearchControlled ? searchValue : internalSearch;
  const shouldFilterOptions = searchable && (filterOptions ?? true);
  const isOpen = open ?? internalOpen;
  const [activeIndex, setActiveIndex] = useState(-1);
  const [panelPlacement, setPanelPlacement] = useState<PanelPlacement>("below");

  const allOptions = useMemo(
    () => (placeholderOption ? [placeholderOption, ...options] : options),
    [options, placeholderOption]
  );
  const selectedIndex = allOptions.findIndex((option) => option.value === value);
  const selectedOption = selectedIndex >= 0 ? allOptions[selectedIndex] : null;
  const isPlaceholderSelected = placeholderOption ? value === placeholderOption.value : false;
  /**
   * Görünen metin placeholder mı: placeholder opsiyonu seçili ya da eşleşen opsiyon yok.
   * Böylece boş durumdaki "Seçiniz" placeholder görünümünde kalır, seçili adla karışmaz.
   */
  const showsPlaceholderText = isPlaceholderSelected || !selectedOption;

  /**
   * Panel kartları: placeholder KART olarak render edilmez (trigger'da görünür).
   * Opsiyonel alanda değer seçiliyken açık temizleme satırı eklenir.
   */
  const panelItems = useMemo<AppSelectOption[]>(() => {
    const optionCards = placeholderOption
      ? allOptions.filter((option) => option.value !== placeholderOption.value)
      : allOptions;

    if (placeholderOption && !required && !isPlaceholderSelected) {
      return [{ value: placeholderOption.value, label: CLEAR_SELECTION_LABEL }, ...optionCards];
    }

    return optionCards;
  }, [allOptions, isPlaceholderSelected, placeholderOption, required]);

  const activeOptionIndex = panelItems.findIndex((option) => option.value === value);

  const visibleOptions = useMemo(() => {
    if (!shouldFilterOptions) {
      return panelItems;
    }

    const query = normalizeSearchText(searchQuery ?? "");
    if (!query) {
      return panelItems;
    }

    return panelItems.filter((option) => normalizeSearchText(option.label).includes(query));
  }, [panelItems, searchQuery, shouldFilterOptions]);

  const setSearchQuery = useCallback(
    (next: string) => {
      if (!isSearchControlled) {
        setInternalSearch(next);
      }

      onSearchValueChange?.(next);
    },
    [isSearchControlled, onSearchValueChange]
  );

  const setOpen = useCallback(
    (next: boolean) => {
      if (open === undefined) {
        setInternalOpen(next);
      }

      onOpenChange?.(next);
    },
    [onOpenChange, open]
  );

  /** Registry handle'ı render'lar arasında sabit kalır; her render'da güncel kapanışı taşır. */
  const selfHandle = useRef<ActivePickerHandle>({ close: () => undefined });
  selfHandle.current.close = () => setOpen(false);

  const closePanel = useCallback(
    (restoreFocus = false) => {
      if (activePickerCloser === selfHandle.current) {
        activePickerCloser = null;
      }

      setOpen(false);

      if (restoreFocus) {
        selectRef.current?.focus({ preventScroll: true });
      }
    },
    [setOpen]
  );

  const openPanel = useCallback(() => {
    if (disabled) {
      return;
    }

    if (activePickerCloser && activePickerCloser !== selfHandle.current) {
      activePickerCloser.close();
    }

    activePickerCloser = selfHandle.current;
    setActiveIndex(activeOptionIndex >= 0 ? activeOptionIndex : 0);
    setOpen(true);
    selectRef.current?.focus({ preventScroll: true });
  }, [disabled, setOpen, activeOptionIndex]);

  const commit = useCallback(
    (next: string | undefined) => {
      if (next === undefined) {
        closePanel(true);
        return;
      }

      const select = selectRef.current;
      if (select && select.value !== next) {
        select.value = next;
      }

      onChange(next);
      closePanel(true);
    },
    [closePanel, onChange]
  );

  const measurePanel = useCallback(() => {
    const root = rootRef.current;
    const panel = panelRef.current;
    if (!root || !panel) {
      return;
    }

    // Geometri tek kanonik owner'dan gelir: flip + max-height + yatay clamp.
    const geometry = measurePickerPanel(root, panel);
    setPanelPlacement(geometry.placement);
    applyPickerPanelGeometry(panel, geometry);
  }, []);

  // Blur kontratı: açıkken picker dışındaki yüzey bulanır, kapanınca tamamen kalkar.
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

  // Searchable: açılışta arama alanına odaklan (touch cihazda klavye açılmasın).
  useLayoutEffect(() => {
    if (!isOpen || !searchable || !autoFocusSearch || isTouchLikeDevice()) {
      return;
    }

    const frame = window.requestAnimationFrame(() => {
      searchRef.current?.focus({ preventScroll: true });
    });

    return () => window.cancelAnimationFrame(frame);
  }, [autoFocusSearch, isOpen, searchable]);

  // Filtre sonrası aktif satır aralık dışına düşerse listenin başına dön.
  useEffect(() => {
    if (!isOpen || !searchable) {
      return;
    }

    setActiveIndex((prev) => (prev >= visibleOptions.length ? (visibleOptions.length > 0 ? 0 : -1) : prev));
  }, [isOpen, searchable, visibleOptions.length]);

  useLayoutEffect(() => {
    if (!isOpen) {
      return;
    }

    measurePanel();
    const frame = window.requestAnimationFrame(measurePanel);
    window.addEventListener("resize", measurePanel);
    window.addEventListener("scroll", measurePanel, true);

    return () => {
      window.cancelAnimationFrame(frame);
      window.removeEventListener("resize", measurePanel);
      window.removeEventListener("scroll", measurePanel, true);
    };
  }, [isOpen, measurePanel, allOptions.length]);

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

  const handleSelectChange = useCallback(
    (event: ReactChangeEvent<HTMLSelectElement>) => {
      onChange(event.target.value);
      closePanel(false);
    },
    [closePanel, onChange]
  );

  const handleSelectMouseDown = useCallback(
    (event: ReactMouseEvent<HTMLSelectElement>) => {
      if (disabled) {
        return;
      }

      // Native açılır listeyi engelle; kanonik panel owner olur.
      event.preventDefault();
      selectRef.current?.focus({ preventScroll: true });
    },
    [disabled]
  );

  const handleSelectClick = useCallback(() => {
    if (disabled) {
      return;
    }

    if (isOpen) {
      closePanel(false);
      return;
    }

    openPanel();
  }, [closePanel, disabled, isOpen, openPanel]);

  const handleSelectKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLSelectElement>) => {
      if (disabled) {
        return;
      }

      const { key } = event;

      if (!isOpen) {
        if (key === "ArrowDown" || key === "ArrowUp" || key === "Enter" || key === " ") {
          event.preventDefault();
          openPanel();
          return;
        }

        // Searchable: yazılan ilk karakter aramayı başlatır (native typeahead devre dışı).
        if (searchable && key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
          event.preventDefault();
          setSearchQuery(key);
          openPanel();
        }

        return;
      }

      if (key === "Escape") {
        event.preventDefault();
        // AppModal Escape'i modal kapanışı olarak yorumlamasın.
        event.stopPropagation();
        closePanel(true);
        return;
      }

      if (key === "Tab") {
        closePanel(false);
        return;
      }

      if (key === "ArrowDown") {
        event.preventDefault();
        setActiveIndex((prev) => moveIndex(prev, 1, visibleOptions.length));
        return;
      }

      if (key === "ArrowUp") {
        event.preventDefault();
        setActiveIndex((prev) => moveIndex(prev, -1, visibleOptions.length));
        return;
      }

      if (key === "Home") {
        event.preventDefault();
        setActiveIndex(visibleOptions.length > 0 ? 0 : -1);
        return;
      }

      if (key === "End") {
        event.preventDefault();
        setActiveIndex(visibleOptions.length - 1);
        return;
      }

      if (key === "Enter" || key === " ") {
        event.preventDefault();
        commit(visibleOptions[activeIndex]?.value);
      }
    },
    [activeIndex, closePanel, commit, disabled, isOpen, openPanel, searchable, setSearchQuery, visibleOptions]
  );

  const handleSearchKeyDown = useCallback(
    (event: ReactKeyboardEvent<HTMLInputElement>) => {
      const { key } = event;

      if (key === "Escape") {
        event.preventDefault();
        // AppModal Escape'i modal kapanışı olarak yorumlamasın.
        event.stopPropagation();
        closePanel(true);
        return;
      }

      if (key === "Tab") {
        closePanel(false);
        return;
      }

      if (key === "ArrowDown") {
        event.preventDefault();
        setActiveIndex((prev) => moveIndex(prev, 1, visibleOptions.length));
        return;
      }

      if (key === "ArrowUp") {
        event.preventDefault();
        setActiveIndex((prev) => moveIndex(prev, -1, visibleOptions.length));
        return;
      }

      if (key === "Enter") {
        event.preventDefault();
        commit(visibleOptions[activeIndex]?.value);
        return;
      }

      // Arama metni varken Home/End imleç davranışıdır; boşken liste başı/sonu.
      if (key === "Home" && !(searchQuery ?? "")) {
        event.preventDefault();
        setActiveIndex(visibleOptions.length > 0 ? 0 : -1);
        return;
      }

      if (key === "End" && !(searchQuery ?? "")) {
        event.preventDefault();
        setActiveIndex(visibleOptions.length - 1);
      }
    },
    [activeIndex, closePanel, commit, searchQuery, visibleOptions]
  );

  const rootClassName = ["app-select", isOpen ? "is-open" : "", disabled ? "is-disabled" : "", className ?? ""]
    .filter(Boolean)
    .join(" ");

  return (
    <div ref={rootRef} className={rootClassName} data-app-select="1">
      <select
        ref={selectRef}
        id={controlId}
        name={name}
        className="app-select-native"
        value={value}
        required={required}
        disabled={disabled}
        data-testid={dataTestId}
        aria-expanded={isOpen}
        aria-controls={isOpen ? panelId : undefined}
        aria-activedescendant={isOpen && activeIndex >= 0 ? optionElementId(panelId, activeIndex) : undefined}
        onMouseDown={handleSelectMouseDown}
        onClick={handleSelectClick}
        onKeyDown={handleSelectKeyDown}
        onChange={handleSelectChange}
      >
        {allOptions.map((option, index) => (
          <option key={optionElementId(panelId, index)} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>

      <div className="app-select-trigger form-input" data-app-select-trigger="1" aria-hidden="true">
        <span className={`app-select-trigger-text${showsPlaceholderText ? " is-placeholder" : ""}`}>
          {selectedOption?.label ?? "Seçiniz"}
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
      </div>

      {isOpen ? (
        <div
          ref={panelRef}
          id={panelId}
          className="app-picker-panel"
          data-app-select-panel="1"
          data-placement={panelPlacement}
          role="listbox"
          aria-label={ariaLabel}
          onMouseDown={searchable ? undefined : (event) => event.preventDefault()}
        >
          {searchable ? (
            <div className="app-picker-search" data-app-select-search="1">
              <input
                ref={searchRef}
                className="form-input app-picker-search-input"
                type="search"
                value={searchQuery ?? ""}
                placeholder={searchPlaceholder}
                aria-label={searchLabel ?? searchPlaceholder}
                data-testid={searchInputTestId}
                autoComplete="off"
                onChange={(event) => setSearchQuery(event.target.value)}
                onKeyDown={handleSearchKeyDown}
              />
            </div>
          ) : null}

          {visibleOptions.length === 0 ? (
            <p className="app-picker-empty">{noResultsText ?? "Sonuç bulunamadı"}</p>
          ) : null}

          {visibleOptions.map((option, index) => {
            const isSelected = option.value === value;
            const isClearRow = placeholderOption ? option.value === placeholderOption.value : false;
            const itemId = isClearRow ? `${panelId}-clear` : optionElementId(panelId, index);

            return (
              <button
                key={itemId}
                id={itemId}
                type="button"
                role="option"
                aria-selected={isSelected}
                data-app-select-clear={isClearRow ? "1" : undefined}
                className={[
                  "app-select-option",
                  index === activeIndex ? "is-active" : "",
                  isClearRow ? "is-clear" : ""
                ]
                  .filter(Boolean)
                  .join(" ")}
                onClick={() => commit(option.value)}
              >
                {option.label}
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

export type AppSelectFieldProps = AppSelectProps & { label: string };

/** Standart `.form-section` + etiket sarmalayıcısı (kanonik owner'ın alan biçimi). */
export function AppSelectField({ label, name, id, className, ...rest }: AppSelectFieldProps) {
  const controlId = name ?? id;

  return (
    <div className={["form-section", "app-select-field", className ?? ""].filter(Boolean).join(" ")}>
      <label className="form-label" htmlFor={controlId}>
        {label}
      </label>
      {/* Caller'ın verdiği listbox adı korunur; verilmezse alan etiketi kullanılır. */}
      <AppSelect {...rest} name={name} id={id} ariaLabel={rest.ariaLabel ?? label} />
    </div>
  );
}




