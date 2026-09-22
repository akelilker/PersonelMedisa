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
    <section className="personel-dosya-genel-ust" data-testid="personel-dosya-genel-ust">
      <PersonelDosyaKayitMirrorFields personel={personel} />

      {canViewUcret && isPersonelMaasMissing(personel.maas_tutari, personel.net_maas_tutari) ? (
        <p className="personel-dosya-maas-alert" data-testid="personel-maas-eksik-uyari">
          Maaş bilgisi eksik.
        </p>
      ) : null}
    </section>
  );
}
