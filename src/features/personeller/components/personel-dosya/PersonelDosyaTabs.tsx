import { useCallback, useEffect, useLayoutEffect, useRef, useState } from "react";

export const PERSONEL_DOSYA_TABS = [
  { id: "genel-bilgiler", label: "Genel" },
  { id: "egitim-belgeler", label: "Eğitim / Belgeler" },
  { id: "disiplin", label: "Disiplin" },
  { id: "zimmet-envanter", label: "Zimmet" },
  { id: "surec-gecmisi", label: "Süreç Geçmişi" }
] as const;

export type PersonelDosyaTabId = (typeof PERSONEL_DOSYA_TABS)[number]["id"];

const TAB_SCROLL_EDGE_EPSILON = 2;

function IconChevronLeft() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
      <path
        d="m14 7-5 5 5 5"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

function IconChevronRight() {
  return (
    <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">
      <path
        d="m10 7 5 5-5 5"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
      />
    </svg>
  );
}

export function PersonelDosyaTabList({
  activeTab,
  onTabChange,
  directoryOnly = false,
  missingCounts
}: {
  activeTab: PersonelDosyaTabId;
  onTabChange: (tabId: PersonelDosyaTabId) => void;
  directoryOnly?: boolean;
  missingCounts?: Partial<Record<PersonelDosyaTabId, number>>;
}) {
  const tabs = directoryOnly
    ? PERSONEL_DOSYA_TABS.filter((tab) => tab.id === "genel-bilgiler" || tab.id === "egitim-belgeler")
    : PERSONEL_DOSYA_TABS;

  const listRef = useRef<HTMLDivElement | null>(null);
  const [overflowState, setOverflowState] = useState({
    hasOverflow: false,
    canScrollBack: false,
    canScrollForward: false
  });

  const syncOverflowState = useCallback(() => {
    const node = listRef.current;
    if (!node) {
      return;
    }
    const { scrollWidth, clientWidth, scrollLeft } = node;
    const hasOverflow = scrollWidth - clientWidth > TAB_SCROLL_EDGE_EPSILON;
    setOverflowState({
      hasOverflow,
      canScrollBack: hasOverflow && scrollLeft > TAB_SCROLL_EDGE_EPSILON,
      canScrollForward: hasOverflow && scrollLeft + clientWidth < scrollWidth - TAB_SCROLL_EDGE_EPSILON
    });
  }, []);

  const scrollTabsBy = useCallback((direction: -1 | 1) => {
    const node = listRef.current;
    if (!node) {
      return;
    }
    const delta = Math.max(120, Math.round(node.clientWidth * 0.55));
    node.scrollBy({ left: direction * delta, behavior: "smooth" });
  }, []);

  const scrollActiveTabIntoView = useCallback(() => {
    const node = listRef.current;
    if (!node) {
      return;
    }
    const activeButton = node.querySelector<HTMLElement>(`#personel-kart-tab-${activeTab}`);
    activeButton?.scrollIntoView({ behavior: "smooth", block: "nearest", inline: "nearest" });
  }, [activeTab]);

  useLayoutEffect(() => {
    scrollActiveTabIntoView();
    syncOverflowState();
  }, [activeTab, tabs.length, scrollActiveTabIntoView, syncOverflowState]);

  useEffect(() => {
    const node = listRef.current;
    if (!node) {
      return undefined;
    }

    const handleScroll = () => syncOverflowState();
    node.addEventListener("scroll", handleScroll, { passive: true });

    const resizeObserver = new ResizeObserver(() => {
      syncOverflowState();
      scrollActiveTabIntoView();
    });
    resizeObserver.observe(node);

    syncOverflowState();

    return () => {
      node.removeEventListener("scroll", handleScroll);
      resizeObserver.disconnect();
    };
  }, [scrollActiveTabIntoView, syncOverflowState]);

  return (
    <div
      className={`personel-kart-tab-scroller${overflowState.hasOverflow ? " is-overflowing" : ""}`}
      data-testid="personel-kart-tab-scroller"
    >
      {overflowState.hasOverflow ? (
        <button
          type="button"
          className="personel-kart-tab-scroll-btn personel-kart-tab-scroll-btn--prev"
          data-testid="personel-kart-tab-scroll-prev"
          aria-label="Önceki sekmeler"
          title="Önceki sekmeler"
          disabled={!overflowState.canScrollBack}
          onClick={() => scrollTabsBy(-1)}
        >
          <IconChevronLeft />
        </button>
      ) : null}

      <div
        ref={listRef}
        className="personel-kart-tablist"
        role="tablist"
        aria-label="Personel kartı sekmeleri"
        data-testid="personel-kart-tablist"
      >
        {tabs.map((tab) => {
          const missing = missingCounts?.[tab.id] ?? 0;
          return (
            <button
              key={tab.id}
              type="button"
              role="tab"
              id={`personel-kart-tab-${tab.id}`}
              className={`personel-kart-tab${activeTab === tab.id ? " is-active" : ""}`}
              aria-selected={activeTab === tab.id}
              aria-controls={`personel-kart-panel-${tab.id}`}
              tabIndex={activeTab === tab.id ? 0 : -1}
              onClick={() => onTabChange(tab.id)}
            >
              {tab.label}
              {missing > 0 ? (
                <span className="personel-kart-tab-missing-badge" aria-label={`${missing} eksik bilgi`}>
                  {missing}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      {overflowState.hasOverflow ? (
        <button
          type="button"
          className="personel-kart-tab-scroll-btn personel-kart-tab-scroll-btn--next"
          data-testid="personel-kart-tab-scroll-next"
          aria-label="Sonraki sekmeler"
          title="Sonraki sekmeler"
          disabled={!overflowState.canScrollForward}
          onClick={() => scrollTabsBy(1)}
        >
          <IconChevronRight />
        </button>
      ) : null}
    </div>
  );
}
