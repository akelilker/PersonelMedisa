import { useEffect, useState } from "react";
import { fetchPersonelProfilFoto } from "../../../api/self-product.api";

/** Personel kartı fotoğrafı. Kayıt yoksa veya okunamazsa null (baş harf yer tutucusu). */
export function usePersonelProfilFoto(personelId: number) {
  const [photoSrc, setPhotoSrc] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setPhotoSrc(null);
    void fetchPersonelProfilFoto(personelId)
      .then((src) => {
        if (!cancelled) {
          setPhotoSrc(src);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setPhotoSrc(null);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [personelId]);

  return photoSrc;
}
