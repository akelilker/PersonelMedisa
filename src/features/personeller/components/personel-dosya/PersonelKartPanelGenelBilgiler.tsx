import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { PersonelHesapOnboardingPanel } from "../../../yonetim/components/PersonelHesapOnboardingPanel";
import { DossierSection } from "./personel-dosya-dossier";
import { PersonelIzinOzetSection } from "./PersonelIzinOzetSection";
import { PersonelPuantajOzetSection } from "./PersonelPuantajOzetSection";
import { PersonelUcretGecmisiSection } from "./PersonelUcretGecmisiSection";
import { PersonelBordroKapsamSection } from "./PersonelBordroKapsamSection";
import { PersonelQrHistorySection } from "./PersonelQrHistorySection";

export function PersonelKartPanelGenelBilgiler({
  personel,
  surecler,
  canViewPuantaj,
  canViewRevizyon,
  canCreateRevizyon = false,
  canViewFinans,
  canViewBordro = false,
  canViewUcret,
  canManageUcret,
  canViewBordroKapsam = false,
  canManageBordroKapsam = false,
  canApproveBordroKapsam = false,
  canManageAccountOnboarding = false,
  isActive,
  onOpenSurecHistory
}: {
  personel: Personel;
  surecler: Surec[];
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  canCreateRevizyon?: boolean;
  canViewFinans: boolean;
  canViewBordro?: boolean;
  canViewUcret: boolean;
  canManageUcret: boolean;
  canViewBordroKapsam?: boolean;
  canManageBordroKapsam?: boolean;
  canApproveBordroKapsam?: boolean;
  canManageAccountOnboarding?: boolean;
  isActive: boolean;
  onOpenSurecHistory?: () => void;
}) {
  return (
    <div
      className="personel-dosya-sections personel-dosya-zone personel-dosya-zone--operasyon"
      data-testid="personel-dosya-zone-operasyon"
      data-zone="operasyon"
    >
      <div className="personel-dosya-zone-head">
        <h3 className="personel-dosya-zone-title">Operasyonel özetler</h3>
      </div>

      {canManageAccountOnboarding ? (
        <DossierSection
          title="Personel hesabı"
          description="Başlangıç şifresiyle hesap oluşturma ve zorunlu ilk giriş şifre değişimi Yönetici / İK tarafından buradan yönetilir."
        >
          <PersonelHesapOnboardingPanel
            personelId={personel.id}
            ad={personel.ad}
            soyad={personel.soyad}
            personelAktif={personel.aktif_durum === "AKTIF"}
          />
        </DossierSection>
      ) : null}

      <PersonelPuantajOzetSection
        personel={personel}
        canViewPuantaj={canViewPuantaj}
        canViewRevizyon={canViewRevizyon}
        canCreateRevizyon={canCreateRevizyon}
        canViewFinans={canViewFinans}
        canViewBordro={canViewBordro}
        isActive={isActive}
      />

      {canViewPuantaj ? <PersonelQrHistorySection personel={personel} /> : null}

      {canViewUcret ? (
        <PersonelUcretGecmisiSection
          personel={personel}
          canManageUcret={canManageUcret}
          isActive={isActive}
        />
      ) : null}

      {canViewBordroKapsam ? (
        <PersonelBordroKapsamSection
          personel={personel}
          canManage={canManageBordroKapsam}
          canApprove={canApproveBordroKapsam}
          isActive={isActive}
        />
      ) : null}

      <PersonelIzinOzetSection
        personel={personel}
        surecler={surecler}
        onOpenSurecHistory={onOpenSurecHistory}
      />
    </div>
  );
}
