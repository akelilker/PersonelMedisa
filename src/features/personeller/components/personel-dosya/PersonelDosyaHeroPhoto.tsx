import { PersonelSelfPortrait } from "../../../self-service/components/PersonelSelfPortrait";
import { usePersonelProfilFoto } from "../../hooks/use-personel-profil-foto";

/** Masaüstünde görünen yuvarlak fotoğraf. Mobilde CSS ile akıştan çıkar. */
export function PersonelDosyaHeroPhoto({ personelId, name }: { personelId: number; name: string }) {
  const photoSrc = usePersonelProfilFoto(personelId);
  return (
    <div className="personel-dosya-hero-photo" data-testid="personel-dosya-hero-photo">
      <PersonelSelfPortrait name={name} photoSrc={photoSrc} />
    </div>
  );
}
