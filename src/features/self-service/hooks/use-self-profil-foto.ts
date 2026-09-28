import { useCallback, useEffect, useState } from "react";
import { shouldPreferDemoApi } from "../../../api/api-client";
import {
  fetchSelfProfilFoto,
  selfProfilFotoSrc,
  uploadSelfProfilFoto
} from "../../../api/self-product.api";

export function useSelfProfilFoto() {
  const [photoSrc, setPhotoSrc] = useState<string | null>(null);
  const [photoMessage, setPhotoMessage] = useState<string | null>(null);

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      setPhotoSrc(null);
      return;
    }
    let cancelled = false;
    void fetchSelfProfilFoto()
      .then((photo) => {
        if (!cancelled) {
          setPhotoSrc(selfProfilFotoSrc(photo));
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
  }, []);

  const onPhotoSelected = useCallback(async (file: File | undefined) => {
    if (!file) {
      return;
    }
    const bytes = await file.arrayBuffer();
    const binary = new Uint8Array(bytes);
    let raw = "";
    for (let index = 0; index < binary.length; index += 1) {
      raw += String.fromCharCode(binary[index]);
    }
    try {
      const saved = await uploadSelfProfilFoto(btoa(raw));
      setPhotoSrc(selfProfilFotoSrc(saved));
      setPhotoMessage(saved.has_photo ? "Fotoğraf güncellendi." : "Fotoğraf kaydedilemedi.");
    } catch {
      setPhotoMessage("Fotoğraf kaydedilemedi.");
    }
  }, []);

  return { photoSrc, photoMessage, onPhotoSelected, setPhotoMessage };
}
