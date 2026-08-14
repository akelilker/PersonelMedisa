export const PERSONEL_DOSYA_TABS = [
  { id: "genel-bilgiler", label: "Genel" },
  { id: "egitim-belgeler", label: "Eğitim / Belgeler" },
  { id: "disiplin", label: "Disiplin" },
  { id: "zimmet-envanter", label: "Zimmet" },
  { id: "surec-gecmisi", label: "Süreç Geçmişi" }
] as const;

export type PersonelDosyaTabId = (typeof PERSONEL_DOSYA_TABS)[number]["id"];

export function PersonelDosyaTabList({
  activeTab,
  onTabChange,
  directoryOnly = false
}: {
  activeTab: PersonelDosyaTabId;
  onTabChange: (tabId: PersonelDosyaTabId) => void;
  directoryOnly?: boolean;
}) {
  const tabs = directoryOnly
    ? PERSONEL_DOSYA_TABS.filter((tab) => tab.id === "genel-bilgiler" || tab.id === "egitim-belgeler")
    : PERSONEL_DOSYA_TABS;

  return (
    <div className="personel-kart-tablist" role="tablist" aria-label="Personel kartı sekmeleri">
      {tabs.map((tab) => (
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
        </button>
      ))}
    </div>
  );
}
