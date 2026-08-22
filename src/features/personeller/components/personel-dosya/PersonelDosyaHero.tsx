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

export function PersonelDosyaHero({
  personel,
  canViewUcret,
  onOpenMissingInfo
}: {
  personel: Personel;
  /** Ücret görme yetkisi olmayan roller maaş eksikliği bilgisini de görmemeli. */
  canViewUcret: boolean;
  onOpenMissingInfo?: (targetTab: "genel" | "pozisyon") => void;
}) {
  const durumLabel =
    personel.aktif_durum === "PASIF"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
        ? formatDetailValue(personel.pasiflik_durumu_etiketi)
        : formatAktifDurumLabel(personel.aktif_durum)
      : formatAktifDurumLabel(personel.aktif_durum);
  const sicil = formatDetailValue(personel.sicil_no);
  const departman = formatReferenceValue(personel.departman_adi, personel.departman_id);
  const gorev = formatReferenceValue(personel.gorev_adi, personel.gorev_id);
  const heroSummary = [sicil !== "-" ? `Sicil ${sicil}` : null, departman !== "-" ? departman : null, gorev !== "-" ? gorev : null]
    .filter((part): part is string => part != null)
    .join(" / ");
  const missingFields = getPersonelMissingFields(personel);
  const missingKeys = new Set(missingFields.map((field) => field.key));

  function fieldValue(key: PersonelMissingFieldKey, value: string): string {
    return missingKeys.has(key) ? MISSING_VALUE : value;
  }

  return (
    <section className="personel-dosya-hero">
      <div className="personel-dosya-hero-head">
        <div className="personel-dosya-hero-copy">
          <p className="personel-dosya-kicker">Personel kartı</p>
          <h3>
            {[personel.ad, personel.soyad].filter(Boolean).join(" ")}
          </h3>
          <p className="personel-dosya-sub">{heroSummary || "Kurumsal personel kaydı"}</p>
        </div>
        <div className={`personel-dosya-status${personel.aktif_durum === "PASIF" ? " is-passive" : ""}`}>
          <span className="personel-dosya-status-dot" aria-hidden="true" />
          <span>{durumLabel}</span>
        </div>
      </div>

      {missingFields.length > 0 ? (
        <div className="personel-dosya-completeness-summary" data-testid="personel-eksik-bilgi-ozeti" role="status">
          <span className="personel-dosya-missing-count">
            {missingFields.length} eksik bilgi
          </span>
          {onOpenMissingInfo ? (
            <button
              type="button"
              className="universal-btn-aux personel-dosya-missing-action"
              data-testid="personel-eksik-bilgi-tamamla"
              onClick={() => onOpenMissingInfo(missingFields[0]?.editTarget ?? "genel")}
            >
              Kayıt ve Süreç'te tamamla
            </button>
          ) : null}
        </div>
      ) : null}

      <div className="personel-dosya-hero-grid">
        <DossierField label="Ad" value={personel.ad} />
        <DossierField label="Soyad" value={formatDetailValue(personel.soyad)} />
        <DossierField
          label="Çalışan Kapsamı"
          value={formatCalisanKapsamiLabel(personel.calisan_kapsami ?? "IC_PERSONEL")}
        />
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
          value={fieldValue("bolum_id", formatReferenceValue(personel.bolum_adi, personel.bolum_id))}
          missing={missingKeys.has("bolum_id")}
        />
        <DossierField
          label="Birim"
          value={fieldValue("birim_id", formatReferenceValue(personel.birim_adi, personel.birim_id))}
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
