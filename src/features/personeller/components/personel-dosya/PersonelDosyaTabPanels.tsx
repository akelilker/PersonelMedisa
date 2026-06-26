import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import type { Zimmet } from "../../../../types/zimmet";
import { PersonelDosyaTabList, type PersonelDosyaTabId } from "./PersonelDosyaTabs";
import { PersonelIzinDevamsizlikPanel } from "./PersonelIzinDevamsizlikPanel";
import { PersonelKartPanelGenelBilgiler } from "./PersonelKartPanelGenelBilgiler";
import { PersonelPuantajPanel } from "./PersonelPuantajPanel";
import { PersonelSurecGecmisiPanel } from "./PersonelSurecGecmisiPanel";
import { PersonelZimmetEnvanterPanel } from "./PersonelZimmetEnvanterPanel";

export type PersonelDosyaTabPanelsProps = {
  activeTab: PersonelDosyaTabId;
  onTabChange: (tabId: PersonelDosyaTabId) => void;
  personel: Personel;
  surecler: Surec[];
  zimmetler: Zimmet[];
  isSurecHistoryLoading: boolean;
  surecHistoryErrorMessage: string | null;
  isZimmetHistoryLoading: boolean;
  zimmetHistoryErrorMessage: string | null;
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  canCreateZimmet: boolean;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  onOpenZimmetCreate: () => void;
  onOpenCreateSurecModal: () => void;
};

export function PersonelDosyaTabPanels({
  activeTab,
  onTabChange,
  personel,
  surecler,
  zimmetler,
  isSurecHistoryLoading,
  surecHistoryErrorMessage,
  isZimmetHistoryLoading,
  zimmetHistoryErrorMessage,
  canViewPuantaj,
  canViewRevizyon,
  canCreateZimmet,
  canAccessSurecler,
  canCreateSurec,
  onOpenZimmetCreate,
  onOpenCreateSurecModal
}: PersonelDosyaTabPanelsProps) {
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
        <PersonelKartPanelGenelBilgiler personel={personel} />
      </div>

      <div
        id="personel-kart-panel-puantaj"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-puantaj"
        hidden={activeTab !== "puantaj"}
      >
        <PersonelPuantajPanel
          personel={personel}
          canViewPuantaj={canViewPuantaj}
          canViewRevizyon={canViewRevizyon}
          isActive={activeTab === "puantaj"}
        />
      </div>

      <div
        id="personel-kart-panel-izin-devamsizlik"
        role="tabpanel"
        className="personel-kart-panel"
        aria-labelledby="personel-kart-tab-izin-devamsizlik"
        hidden={activeTab !== "izin-devamsizlik"}
      >
        <PersonelIzinDevamsizlikPanel personel={personel} surecler={surecler} />
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
          zimmetler={zimmetler}
          onOpenCreateModal={onOpenCreateSurecModal}
        />
      </div>
    </>
  );
}
