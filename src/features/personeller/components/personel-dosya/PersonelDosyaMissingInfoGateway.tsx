import type { Personel } from "../../../../types/personel";
import { getPersonelMissingFields, type PersonelMissingFieldKey } from "../../personel-missing-info";

/**
 * t869: Eksik bilgi uyarısı eksik alanları adıyla listeler; her ad tıklanınca doğru sekme
 * (Genel veya Görev / Organizasyon) açılır ve o alan kırmızı çerçeveyle odaklanır.
 */
export function PersonelDosyaMissingInfoGateway({
  personel,
  onOpenMissingInfo
}: {
  personel: Personel;
  onOpenMissingInfo?: (targetTab: "genel" | "pozisyon", fieldKey?: PersonelMissingFieldKey) => void;
}) {
  const missingFields = getPersonelMissingFields(personel);
  if (missingFields.length === 0) {
    return null;
  }

  const heading =
    missingFields.length === 1 ? "Eksik Bilgi:" : `${missingFields.length} Eksik Bilgi:`;

  if (!onOpenMissingInfo) {
    return (
      <p className="personel-dosya-missing-gateway personel-dosya-missing-gateway--tek-satir is-readonly" data-testid="personel-eksik-bilgi-ozeti" role="status">
        {heading} {missingFields.map((field) => field.label).join(", ")}
      </p>
    );
  }

  return (
    <div className="personel-dosya-missing-gateway-wrap personel-dosya-missing-gateway--tek-satir" data-testid="personel-eksik-bilgi-ozeti">
      <p className="personel-dosya-missing-gateway personel-dosya-missing-gateway--tek-satir" data-testid="personel-eksik-bilgi-tamamla">
        <span>{heading} </span>
        {missingFields.map((field, index) => (
          <span key={field.key}>
            <button
              type="button"
              className="personel-dosya-missing-gateway-link"
              data-testid={`personel-eksik-bilgi-alan-${field.key}`}
              data-edit-target={field.editTarget}
              onClick={() => onOpenMissingInfo(field.editTarget, field.key)}
            >
              {field.label}
            </button>
            {index < missingFields.length - 1 ? ", " : null}
          </span>
        ))}
        <span className="personel-dosya-missing-gateway-hint"> (Tamamlamak İçin Alana Tıklayın)</span>
      </p>
    </div>
  );
}
