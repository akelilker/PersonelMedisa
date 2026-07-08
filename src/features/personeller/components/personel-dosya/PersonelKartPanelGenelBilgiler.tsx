import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";
import { formatDetailValue, formatIsoDateDetail, formatReferenceValue } from "./personel-dosya-format-utils";
import { PersonelIzinOzetSection } from "./PersonelIzinOzetSection";
import { PersonelPuantajOzetSection } from "./PersonelPuantajOzetSection";

export function PersonelKartPanelGenelBilgiler({
  personel,
  surecler,
  canViewPuantaj,
  canViewRevizyon,
  canViewFinans,
  isActive,
  onOpenSurecHistory
}: {
  personel: Personel;
  surecler: Surec[];
  canViewPuantaj: boolean;
  canViewRevizyon: boolean;
  canViewFinans: boolean;
  isActive: boolean;
  onOpenSurecHistory?: () => void;
}) {
  return (
    <div className="personel-dosya-sections">
      <DossierSection
        title="Kimlik ve İletişim"
        description="Temel kimlik, iletişim ve lokasyon verileri bu dosyada salt okunur izlenir."
      >
        <DossierRecord label="T.C. Kimlik No" value={formatDetailValue(personel.tc_kimlik_no)} />
        <DossierRecord label="Telefon" value={formatDetailValue(personel.telefon)} />
        <DossierRecord label="Doğum Tarihi" value={formatIsoDateDetail(personel.dogum_tarihi)} />
        <DossierRecord label="Doğum Yeri" value={formatDetailValue(personel.dogum_yeri)} />
        <DossierRecord label="Kan Grubu" value={formatDetailValue(personel.kan_grubu)} />
        <DossierRecord label="Şube" value={formatReferenceValue(personel.sube_adi, personel.sube_id)} />
      </DossierSection>

      <DossierSection
        title="Organizasyon ve Acil Durum"
        description="Bağlı organizasyon, yönetim hattı ve acil durum bilgileri burada tutulur."
      >
        <DossierRecord
          label="Personel Tipi"
          value={formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)}
        />
        <DossierRecord label="Bağlı Amir" value={formatReferenceValue(personel.bagli_amir_adi, personel.bagli_amir_id)} />
        <DossierRecord label="Acil Durum Kişisi" value={formatDetailValue(personel.acil_durum_kisi)} />
        <DossierRecord label="Acil Durum Telefonu" value={formatDetailValue(personel.acil_durum_telefon)} />
        <DossierRecord label="Pasiflik Etiketi" value={formatDetailValue(personel.pasiflik_durumu_etiketi)} />
      </DossierSection>

      <PersonelPuantajOzetSection
        personel={personel}
        canViewPuantaj={canViewPuantaj}
        canViewRevizyon={canViewRevizyon}
        canViewFinans={canViewFinans}
        isActive={isActive}
      />

      <PersonelIzinOzetSection
        personel={personel}
        surecler={surecler}
        onOpenSurecHistory={onOpenSurecHistory}
      />
    </div>
  );
}
