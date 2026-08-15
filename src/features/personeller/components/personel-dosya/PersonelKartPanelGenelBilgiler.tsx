import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { getPersonelMissingFieldKeys, type PersonelMissingFieldKey } from "../../personel-missing-info";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";
import { formatDetailValue, formatIsoDateDetail, formatReferenceValue } from "./personel-dosya-format-utils";
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
  isActive: boolean;
  onOpenSurecHistory?: () => void;
}) {
  const missingKeys = getPersonelMissingFieldKeys(personel);

  function displayValue(key: PersonelMissingFieldKey, value: string): string {
    return missingKeys.has(key) ? "Bilgi girilmemiş" : value;
  }

  return (
    <div className="personel-dosya-sections">
      <DossierSection
        title="Kimlik ve İletişim"
        description="Temel kimlik, iletişim ve lokasyon verileri bu dosyada salt okunur izlenir."
      >
        <DossierRecord
          label="T.C. Kimlik No"
          value={displayValue("tc_kimlik_no", formatDetailValue(personel.tc_kimlik_no))}
          missing={missingKeys.has("tc_kimlik_no")}
        />
        <DossierRecord
          label="Telefon"
          value={displayValue("telefon", formatDetailValue(personel.telefon))}
          missing={missingKeys.has("telefon")}
        />
        <DossierRecord
          label="Doğum Tarihi"
          value={displayValue("dogum_tarihi", formatIsoDateDetail(personel.dogum_tarihi))}
          missing={missingKeys.has("dogum_tarihi")}
        />
        <DossierRecord label="Doğum Yeri" value={formatDetailValue(personel.dogum_yeri)} />
        <DossierRecord label="Kan Grubu" value={formatDetailValue(personel.kan_grubu)} />
        <DossierRecord label="Şube" value={formatReferenceValue(personel.sube_adi, personel.sube_id)} />
      </DossierSection>

      <DossierSection
        title="Organizasyon ve Acil Durum"
        description="Bağlı organizasyon, yönetim hattı ve acil durum bilgileri burada tutulur."
      >
        <DossierRecord
          label="SGK İşvereni"
          value={formatReferenceValue(personel.sgk_isveren_adi, personel.sgk_isveren_id)}
        />
        <DossierRecord
          label="Çalışma Lokasyonu"
          value={formatReferenceValue(personel.calisma_lokasyonu_adi, personel.calisma_lokasyonu_id)}
        />
        <DossierRecord
          label="Departman"
          value={displayValue(
            "departman_id",
            formatReferenceValue(personel.departman_adi, personel.departman_id)
          )}
          missing={missingKeys.has("departman_id")}
        />
        <DossierRecord
          label="Bölüm"
          value={displayValue("bolum_id", formatReferenceValue(personel.bolum_adi, personel.bolum_id))}
          missing={missingKeys.has("bolum_id")}
        />
        <DossierRecord
          label="Birim"
          value={displayValue("birim_id", formatReferenceValue(personel.birim_adi, personel.birim_id))}
          missing={missingKeys.has("birim_id")}
        />
        <DossierRecord
          label="Unvan"
          value={displayValue("gorev_id", formatReferenceValue(personel.gorev_adi, personel.gorev_id))}
          missing={missingKeys.has("gorev_id")}
        />
        <DossierRecord
          label="Pozisyon"
          value={formatReferenceValue(personel.pozisyon_adi, personel.pozisyon_id)}
        />
        <DossierRecord
          label="Personel Tipi"
          value={displayValue(
            "personel_tipi_id",
            formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)
          )}
          missing={missingKeys.has("personel_tipi_id")}
        />
        <DossierRecord label="Bağlı Amir" value={formatReferenceValue(personel.bagli_amir_adi, personel.bagli_amir_id)} />
        <DossierRecord label="Acil Durum Kişisi" value={formatDetailValue(personel.acil_durum_kisi)} />
        <DossierRecord label="Acil Durum Telefonu" value={formatDetailValue(personel.acil_durum_telefon)} />
        {!String(personel.acil_durum_kisi ?? "").trim() || !String(personel.acil_durum_telefon ?? "").trim() ? (
          <DossierRecord
            label="Acil Durum Bilgisi"
            value="Bilgi eksik — Kayıt ve Süreç → Genel üzerinden tamamlanabilir (import/bordro engeli değildir)."
          />
        ) : null}
        <DossierRecord label="Pasiflik Etiketi" value={formatDetailValue(personel.pasiflik_durumu_etiketi)} />
      </DossierSection>

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
