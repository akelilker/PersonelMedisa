import type { Personel } from "../../../../types/personel";
import {
  getPersonelMissingFields,
  type PersonelMissingFieldKey
} from "../../personel-missing-info";
import { DossierField } from "./personel-dosya-dossier";
import { formatDetailValue, formatIsoDateDetail, formatReferenceValue } from "./personel-dosya-format-utils";

const MISSING_VALUE = "Bilgi girilmemiş";

export function PersonelDosyaKayitMirrorFields({ personel }: { personel: Personel }) {
  const missingFields = getPersonelMissingFields(personel);
  const missingKeys = new Set(missingFields.map((field) => field.key));

  function fieldValue(key: PersonelMissingFieldKey, value: string): string {
    return missingKeys.has(key) ? MISSING_VALUE : value;
  }

  return (
    <div className="personel-dosya-kayit-mirror" data-testid="personel-dosya-kayit-mirror">
      <div className="personel-form-columns">
        <div className="personel-form-column">
          <DossierField
            label="T.C. Kimlik No"
            value={fieldValue("tc_kimlik_no", formatDetailValue(personel.tc_kimlik_no))}
            missing={missingKeys.has("tc_kimlik_no")}
          />
          <DossierField
            label="Doğum Tarihi"
            value={fieldValue("dogum_tarihi", formatIsoDateDetail(personel.dogum_tarihi))}
            missing={missingKeys.has("dogum_tarihi")}
          />
          <DossierField
            label="Telefon"
            value={fieldValue("telefon", formatDetailValue(personel.telefon))}
            missing={missingKeys.has("telefon")}
          />
          <DossierField label="Acil Durum Kişisi" value={formatDetailValue(personel.acil_durum_kisi)} />
          <DossierField label="Acil Durum Telefon" value={formatDetailValue(personel.acil_durum_telefon)} />
          <DossierField label="Doğum Yeri" value={formatDetailValue(personel.dogum_yeri)} />
          <DossierField label="Kan Grubu" value={formatDetailValue(personel.kan_grubu)} />
          <DossierField
            label="Çalışma Lokasyonu"
            value={formatReferenceValue(personel.calisma_lokasyonu_adi, personel.calisma_lokasyonu_id)}
          />
          {personel.calisan_kapsami === "DIS_KAYNAK" ? (
            <DossierField
              label="Organizasyon durumu"
              value={
                personel.org_status === "AKTIF_GECICI_GOREVLENDIRME"
                  ? "Aktif geçici görevlendirme"
                  : personel.org_status === "KALICI_ORGANIZASYON"
                    ? "Kalıcı organizasyon bağlantısı"
                    : "Bağlantısız"
              }
            />
          ) : null}
          {personel.info_only_notice ? (
            <p className="personel-dosya-info-notice" role="status" data-testid="personel-dosya-info-notice">
              {personel.info_only_notice}
            </p>
          ) : null}
        </div>

        <div className="personel-form-column">
          <DossierField
            label="İşe Giriş Tarihi"
            value={fieldValue("ise_giris_tarihi", formatIsoDateDetail(personel.ise_giris_tarihi))}
            missing={missingKeys.has("ise_giris_tarihi")}
          />
          <DossierField
            label="SGK İşveren"
            value={formatReferenceValue(personel.sgk_isveren_adi, personel.sgk_isveren_id)}
          />
          <DossierField
            label="Bağlı Amir"
            value={formatReferenceValue(personel.bagli_amir_adi, personel.bagli_amir_id)}
          />
          <DossierField
            label="Bölüm"
            value={fieldValue(
              "bolum_id",
              formatReferenceValue(personel.bolum_adi, personel.bolum_id, personel.bolum_kisa_kod)
            )}
            missing={missingKeys.has("bolum_id")}
          />
          <DossierField
            label="Birim"
            value={fieldValue(
              "birim_id",
              formatReferenceValue(personel.birim_adi, personel.birim_id, personel.birim_kisa_kod)
            )}
            missing={missingKeys.has("birim_id")}
          />
          <DossierField
            label="Pozisyon"
            value={formatReferenceValue(personel.pozisyon_adi, personel.pozisyon_id)}
          />
          <DossierField
            label="Statü"
            value={fieldValue(
              "personel_tipi_id",
              formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)
            )}
            missing={missingKeys.has("personel_tipi_id")}
          />
        </div>
      </div>
    </div>
  );
}
