import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import type { Zimmet } from "../../../../types/zimmet";
import { PersonelBelgelerPanel } from "./PersonelBelgelerPanel";
import { PersonelDisiplinPanel } from "./PersonelDisiplinPanel";
import { PersonelDosyaTabList, type PersonelDosyaTabId } from "./PersonelDosyaTabs";
import { PersonelKartPanelGenelBilgiler } from "./PersonelKartPanelGenelBilgiler";
import { PersonelSurecGecmisiPanel } from "./PersonelSurecGecmisiPanel";
import { PersonelZimmetEnvanterPanel } from "./PersonelZimmetEnvanterPanel";

export type PersonelDosyaTabPanelsProps = {
  activeTab: PersonelDosyaTabId;
  onTabChange: (tabId: PersonelDosyaTabId) => void;
  personel: Personel;
  surecler: Surec[];
  surecHistoryHasMore: boolean;
  zimmetler: Zimmet[];
  zimmetHistoryHasMore: boolean;
  isSurecHistoryLoading: boolean;
  surecHistoryErrorMessage: string | null;
  isZimmetHistoryLoading: boolean;
  zimmetHistoryErrorMessage: string | null;
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  canCreateRevizyon?: boolean;
  canCreateZimmet: boolean;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  canViewFinans: boolean;
  canViewUcret: boolean;
  canManageUcret: boolean;
  onOpenZimmetCreate: () => void;
  onOpenCreateSurecModal: () => void;
};

export function PersonelDosyaTabPanels({
  activeTab,
  onTabChange,
  personel,
  surecler,
  surecHistoryHasMore,
  zimmetler,
  zimmetHistoryHasMore,
  isSurecHistoryLoading,
  surecHistoryErrorMessage,
  isZimmetHistoryLoading,
  zimmetHistoryErrorMessage,
  canViewPuantaj,
  canViewRevizyon,
  canCreateRevizyon = false,
  canCreateZimmet,
  canAccessSurecler,
  canCreateSurec,
  canViewFinans,
  canViewUcret,
  canManageUcret,
  onOpenZimmetCreate,
  onOpenCreateSurecModal
}: PersonelDosyaTabPanelsProps) {
  function handleOpenSurecHistory() {
    onTabChange("surec-gecmisi");
  }

  return (
    <>
      <PersonelDosyaTabList activeTab={activeTab} onTabChange={onTabChange} />

      <div
        id="personel-kart-panel-genel-bilgiler"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-genel-bilgiler"
        hidden={activeTab !== "genel-bilgiler"}
      >
        <PersonelKartPanelGenelBilgiler
          personel={personel}
          surecler={surecler}
          canViewPuantaj={canViewPuantaj}
          canViewRevizyon={canViewRevizyon}
          canCreateRevizyon={canCreateRevizyon}
          canViewFinans={canViewFinans}
          canViewUcret={canViewUcret}
          canManageUcret={canManageUcret}
          isActive={activeTab === "genel-bilgiler"}
          onOpenSurecHistory={handleOpenSurecHistory}
        />
      </div>

      <div
        id="personel-kart-panel-egitim-belgeler"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-egitim-belgeler"
        hidden={activeTab !== "egitim-belgeler"}
      >
        <PersonelBelgelerPanel personel={personel} isActive={activeTab === "egitim-belgeler"} />
      </div>

      <div
        id="personel-kart-panel-disiplin"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-disiplin"
        hidden={activeTab !== "disiplin"}
      >
        <PersonelDisiplinPanel
          personel={personel}
          surecler={surecler}
          isActive={activeTab === "disiplin"}
          isSurecHistoryLoading={isSurecHistoryLoading}
          surecHistoryErrorMessage={surecHistoryErrorMessage}
          canViewFinans={canViewFinans}
          canAccessSurecler={canAccessSurecler}
          onOpenSurecHistory={handleOpenSurecHistory}
        />
      </div>

      <div
        id="personel-kart-panel-zimmet-envanter"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-zimmet-envanter"
        hidden={activeTab !== "zimmet-envanter"}
      >
        <PersonelZimmetEnvanterPanel
          canCreateZimmet={canCreateZimmet}
          isLoading={isZimmetHistoryLoading}
          errorMessage={zimmetHistoryErrorMessage}
          zimmetler={zimmetler}
          zimmetHistoryHasMore={zimmetHistoryHasMore}
          onOpenCreateModal={onOpenZimmetCreate}
        />
      </div>

      <div
        id="personel-kart-panel-surec-gecmisi"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-surec-gecmisi"
        hidden={activeTab !== "surec-gecmisi"}
      >
        <PersonelSurecGecmisiPanel
          personel={personel}
          canAccessSurecler={canAccessSurecler}
          canCreateSurec={canCreateSurec}
          isLoading={isSurecHistoryLoading}
          errorMessage={surecHistoryErrorMessage}
          surecler={surecler}
          surecHistoryHasMore={surecHistoryHasMore}
          zimmetler={zimmetler}
          onOpenCreateModal={onOpenCreateSurecModal}
        />
      </div>
    </>
  );
}
