import type { Personel } from "../../../../types/personel";
import { PersonelBordroKapsamSection } from "./PersonelBordroKapsamSection";
import { PersonelPuantajOzetSection } from "./PersonelPuantajOzetSection";

/**
 * Onaylı karar (t873): Personel Kartı > Puantaj sekmesi. Puantaj ve bordro blokları burada;
 * QR giriş/çıkış hareketleri kişi kartında gösterilmez (veri ve backend korunur).
 */
export function PersonelKartPanelPuantaj({
  personel,
  canViewPuantaj,
  canViewRevizyon,
  canCreateRevizyon = false,
  canViewFinans,
  canViewBordro = false,
  canViewBordroKapsam = false,
  canManageBordroKapsam = false,
  canApproveBordroKapsam = false,
  isActive
}: {
  personel: Personel;
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  canCreateRevizyon?: boolean;
  canViewFinans: boolean;
  canViewBordro?: boolean;
  canViewBordroKapsam?: boolean;
  canManageBordroKapsam?: boolean;
  canApproveBordroKapsam?: boolean;
  isActive: boolean;
}) {
  return (
    <div
      className="personel-dosya-sections personel-dosya-zone personel-dosya-zone--puantaj"
      data-testid="personel-dosya-zone-puantaj"
      data-zone="puantaj"
    >
      <PersonelPuantajOzetSection
        personel={personel}
        canViewPuantaj={canViewPuantaj}
        canViewRevizyon={canViewRevizyon}
        canCreateRevizyon={canCreateRevizyon}
        canViewFinans={canViewFinans}
        canViewBordro={canViewBordro}
        isActive={isActive}
      />
      {canViewBordroKapsam ? (
        <PersonelBordroKapsamSection
          personel={personel}
          canManage={canManageBordroKapsam}
          canApprove={canApproveBordroKapsam}
          isActive={isActive}
        />
      ) : null}
    </div>
  );
}
