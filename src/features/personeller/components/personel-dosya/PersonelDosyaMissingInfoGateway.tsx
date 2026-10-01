import type { Personel } from "../../../../types/personel";
import { getPersonelMissingFields } from "../../personel-missing-info";

export function PersonelDosyaMissingInfoGateway({
  personel,
  onOpenMissingInfo
}: {
  personel: Personel;
  onOpenMissingInfo?: (targetTab: "genel" | "pozisyon") => void;
}) {
  const missingFields = getPersonelMissingFields(personel);
  if (missingFields.length === 0) {
    return null;
  }

  const primaryField = missingFields[0];
  const label =
    missingFields.length === 1 && primaryField
      ? `Eksik bilgi: ${primaryField.label}. Tamamlamak için tıklayınız.`
      : primaryField
        ? `${missingFields.length} eksik bilgi (ilk: ${primaryField.label}). Tamamlamak için tıklayınız.`
        : `${missingFields.length} Eksik Bilgi Mevcut. Tamamlamak İçin Tıklayınız.`;

  if (!onOpenMissingInfo) {
    return (
      <p className="personel-dosya-missing-gateway is-readonly" data-testid="personel-eksik-bilgi-ozeti" role="status">
        {missingFields.length} Eksik Bilgi Mevcut.
      </p>
    );
  }

  return (
    <div className="personel-dosya-missing-gateway-wrap" data-testid="personel-eksik-bilgi-ozeti">
      <button
        type="button"
        className="personel-dosya-missing-gateway"
        data-testid="personel-eksik-bilgi-tamamla"
        onClick={() => onOpenMissingInfo(missingFields[0]?.editTarget ?? "genel")}
      >
        {label}
      </button>
    </div>
  );
}
