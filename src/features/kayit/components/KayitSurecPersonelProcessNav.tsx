import type { PersonelSurecTab } from "../kayit-surec-constants";

type KayitSurecPersonelProcessNavProps = {
  tabs: Array<{ id: PersonelSurecTab; label: string }>;
  activeTab: PersonelSurecTab;
  locked: boolean;
  onSelect: (tabId: PersonelSurecTab) => void;
};

export function KayitSurecPersonelProcessNav({
  tabs,
  activeTab,
  locked,
  onSelect
}: KayitSurecPersonelProcessNavProps) {
  return (
    <nav
      className="surec-person-tabs surec-person-process-nav"
      role="tablist"
      aria-label="Personel süreç navigasyonu"
      data-testid="kayit-surec-person-process-nav"
    >
      {tabs.map((tab) => {
        const isActive = activeTab === tab.id;

        return (
          <button
            key={tab.id}
            type="button"
            role="tab"
            data-testid={`kayit-surec-subtab-${tab.id}`}
            aria-selected={isActive}
            aria-disabled={locked && !isActive}
            disabled={locked && !isActive}
            className={`surec-person-tab${isActive ? " is-active" : ""}`}
            onClick={() => onSelect(tab.id)}
          >
            {tab.label}
          </button>
        );
      })}
    </nav>
  );
}
