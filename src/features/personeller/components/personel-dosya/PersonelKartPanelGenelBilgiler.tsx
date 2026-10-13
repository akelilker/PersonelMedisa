import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { PersonelHesapOnboardingPanel } from "../../../yonetim/components/PersonelHesapOnboardingPanel";
import { DossierSection } from "./personel-dosya-dossier";
import { PersonelIzinOzetSection } from "./PersonelIzinOzetSection";
import { PersonelUcretGecmisiSection } from "./PersonelUcretGecmisiSection";

export function PersonelKartPanelGenelBilgiler({
  personel,
  surecler,
  canViewUcret,
  canManageUcret,
  canManageAccountOnboarding = false,
  isActive,
  onOpenSurecHistory
}: {
  personel: Personel;
  surecler: Surec[];
  canViewUcret: boolean;
  canManageUcret: boolean;
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

      {canViewUcret ? (
        <PersonelUcretGecmisiSection
          personel={personel}
          canManageUcret={canManageUcret}
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
