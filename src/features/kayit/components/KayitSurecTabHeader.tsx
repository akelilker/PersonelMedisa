import type { KayitTab } from "../../../components/main-menu/MainMenu";

type KayitSurecTabHeaderProps = {
  activeTab: KayitTab;
  onTabChange: (tab: KayitTab) => void;
};

export function KayitSurecTabHeader({ activeTab, onTabChange }: KayitSurecTabHeaderProps) {
  return (
    <div className="kayit-workspace-tabs" role="tablist" aria-label="Kayıt ve süreç sekmeleri">
      <button
        type="button"
        data-testid="kayit-tab-yeni-kayit"
        className={`kayit-workspace-tab${activeTab === "yeni-kayit" ? " is-active" : ""}`}
        aria-selected={activeTab === "yeni-kayit"}
        onClick={() => onTabChange("yeni-kayit")}
      >
        Kayıt
      </button>
      <button
        type="button"
        data-testid="kayit-tab-surec"
        className={`kayit-workspace-tab${activeTab === "surec" ? " is-active" : ""}`}
        aria-selected={activeTab === "surec"}
        onClick={() => onTabChange("surec")}
      >
        Süreç
      </button>
    </div>
  );
}
