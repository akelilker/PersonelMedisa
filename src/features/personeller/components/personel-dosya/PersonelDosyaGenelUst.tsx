import { formatAktifDurumLabel, formatCalisanKapsamiLabel } from "../../../../lib/display/enum-display";
import type { Personel } from "../../../../types/personel";
import { isPersonelMaasMissing } from "../../personel-create-utils";
import {
  getPersonelMissingFields,
  type PersonelMissingFieldKey
} from "../../personel-missing-info";
import { DossierField } from "./personel-dosya-dossier";
import { formatDetailValue, formatIsoDateDetail, formatReferenceValue } from "./personel-dosya-format-utils";

const MISSING_VALUE = "Bilgi girilmemiş";

export function PersonelDosyaGenelUst({
  personel,
  canViewUcret
}: {
  personel: Personel;
  canViewUcret: boolean;
}) {
  const durumLabel =
    personel.aktif_durum === "PASIF"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
        ? formatDetailValue(personel.pasiflik_durumu_etiketi)
        : formatAktifDurumLabel(personel.aktif_durum)
      : formatAktifDurumLabel(personel.aktif_durum);
  const missingFields = getPersonelMissingFields(personel);
  const missingKeys = new Set(missingFields.map((field) => field.key));
  const fullName = [personel.ad, personel.soyad].filter(Boolean).join(" ") || "-";

  function fieldValue(key: PersonelMissingFieldKey, value: string): string {
    return missingKeys.has(key) ? MISSING_VALUE : value;
  }

  return (
    <section className="personel-dosya-genel-ust" data-testid="personel-dosya-genel-ust">
      <div className="personel-dosya-hero-grid">
        <DossierField label="Ad/Soyad" value={fullName} />
        <DossierField
          label="Çalışan Kapsamı"
          value={formatCalisanKapsamiLabel(personel.calisan_kapsami ?? "IC_PERSONEL")}
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
          <DossierField label="Bilgi" value={personel.info_only_notice} />
        ) : null}
        <DossierField
          label="Sicil No"
          value={fieldValue("sicil_no", formatDetailValue(personel.sicil_no))}
          missing={missingKeys.has("sicil_no")}
        />
        <DossierField
          label="Departman"
          value={fieldValue("departman_id", formatReferenceValue(personel.departman_adi, personel.departman_id))}
          missing={missingKeys.has("departman_id")}
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
          label="Unvan"
          value={fieldValue("gorev_id", formatReferenceValue(personel.gorev_adi, personel.gorev_id))}
          missing={missingKeys.has("gorev_id")}
        />
        <DossierField label="Pozisyon" value={formatReferenceValue(personel.pozisyon_adi, personel.pozisyon_id)} />
        <DossierField
          label="Personel Tipi"
          value={fieldValue(
            "personel_tipi_id",
            formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)
          )}
          missing={missingKeys.has("personel_tipi_id")}
        />
        <DossierField
          label="Çalışma Durumu"
          value={durumLabel}
          valueClassName={
            personel.aktif_durum === "PASIF"
              ? "personel-dosya-field-value personel-dosya-field-value--danger"
              : "personel-dosya-field-value"
          }
        />
        <DossierField
          label="İşe Giriş Tarihi"
          value={fieldValue("ise_giris_tarihi", formatIsoDateDetail(personel.ise_giris_tarihi))}
          missing={missingKeys.has("ise_giris_tarihi")}
        />
      </div>

      {canViewUcret && isPersonelMaasMissing(personel.maas_tutari, personel.net_maas_tutari) ? (
        <p className="personel-dosya-maas-alert" data-testid="personel-maas-eksik-uyari">
          Maaş bilgisi eksik.
        </p>
      ) : null}
    </section>
  );
}
