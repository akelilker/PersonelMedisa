import type { Personel } from "../../../../types/personel";
import { isPersonelMaasMissing } from "../../personel-create-utils";
import { PersonelDosyaKayitMirrorFields } from "./PersonelDosyaKayitMirrorFields";

export function PersonelDosyaGenelUst({
  personel,
  canViewUcret
}: {
  personel: Personel;
  canViewUcret: boolean;
}) {
  return (
    <section
      className="personel-dosya-genel-ust personel-dosya-zone personel-dosya-zone--ozluk"
      data-testid="personel-dosya-genel-ust"
      data-zone="ozluk"
    >
      <div className="personel-dosya-zone-head">
        <h3 className="personel-dosya-zone-title">Özlük / kayıt özeti</h3>
      </div>

      <PersonelDosyaKayitMirrorFields personel={personel} />

      {canViewUcret && isPersonelMaasMissing(personel.maas_tutari, personel.net_maas_tutari) ? (
        <p className="personel-dosya-maas-alert" data-testid="personel-maas-eksik-uyari">
          Maaş bilgisi eksik.
        </p>
      ) : null}
    </section>
  );
}
