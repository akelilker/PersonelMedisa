export const PERSONEL_DOSYA_TABS = [
  { id: "genel-bilgiler", label: "Genel Bilgiler" },
  { id: "puantaj", label: "Puantaj" },
  { id: "izin-devamsizlik", label: "İzin / Devamsızlık" },
  { id: "zimmet-envanter", label: "Zimmet & Envanter" },
  { id: "surec-gecmisi", label: "Süreç Geçmişi" }
] as const;

export type PersonelDosyaTabId = (typeof PERSONEL_DOSYA_TABS)[number]["id"];

export function PersonelDosyaTabList({
  activeTab,
  onTabChange
}: {
  activeTab: PersonelDosyaTabId;
  onTabChange: (tabId: PersonelDosyaTabId) => void;
}) {
  return (
    <div className="personel-kart-tablist" role="tablist" aria-label="Personel dosyası sekmeleri">
      {PERSONEL_DOSYA_TABS.map((tab) => (
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
