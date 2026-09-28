import { useEffect, useState } from "react";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { fetchMe, fetchMeYillikIzinBakiye } from "../../../api/me.api";
import {
  fetchSelfProfilFoto,
  selfProfilFotoSrc,
  uploadSelfProfilFoto
} from "../../../api/self-product.api";
import { PersonelSelfPortrait } from "../components/PersonelSelfPortrait";
import { LoadingState } from "../../../components/states/LoadingState";
import { formatIsoDateDetail } from "../../../lib/display/iso-date-format";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { MeIdentity } from "../../../types/self-service";
import { SelfServiceFactList, type SelfServiceFact } from "../components/SelfServiceFactList";
import { buildPersonelSelfIdentityView } from "../personel-self-identity-view";

type Status =
  | { kind: "loading" }
  | { kind: "ready"; rows: SelfServiceFact[] }
  | { kind: "error"; message: string };

function present(value: string | null | undefined): string | null {
  const trimmed = value?.trim();
  return trimmed ? trimmed : null;
}

function buildRows(me: MeIdentity, iseGiris: string | null): SelfServiceFact[] {
  const personel = me.personel;
  const identity = buildPersonelSelfIdentityView(me);
  const rows: SelfServiceFact[] = [];
  const adSoyad = identity?.adSoyad;
  if (adSoyad) {
    rows.push({ label: "Ad soyad", value: adSoyad, testId: "personel-profil-ad-soyad" });
  }
  const labeled: Array<[string, string | null, string]> = [
    ["Şube", present(personel.sube_ad), "personel-profil-sube"],
    ["Bölüm", present(personel.bolum_ad), "personel-profil-bolum"],
    ["Birim", present(personel.birim_ad), "personel-profil-birim"],
    ["Görev", present(personel.gorev_ad), "personel-profil-gorev"],
    ["Departman", present(personel.departman_ad), "personel-profil-departman"]
  ];
  for (const [label, value, testId] of labeled) {
    if (value) {
      rows.push({ label, value, testId });
    }
  }
  if (iseGiris) {
    const formatted = formatIsoDateDetail(iseGiris);
    if (formatted !== "-") {
      rows.push({ label: "İşe giriş", value: formatted, testId: "personel-profil-ise-giris" });
    }
  }
  return rows;
}

export function PersonelSelfServiceProfilPage() {
  const { hasPermission } = useRoleAccess();
  const canViewIseGiris = hasPermission("self_service.yillik_izin.view");
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const [photoSrc, setPhotoSrc] = useState<string | null>(null);
  const [photoName, setPhotoName] = useState("");
  const [photoMessage, setPhotoMessage] = useState<string | null>(null);

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      setStatus({ kind: "error", message: "Demo modda profil yok." });
      return;
    }
    let cancelled = false;
    void (async () => {
      try {
        const me = await fetchMe();
        let iseGiris: string | null = null;
        if (canViewIseGiris) {
          try {
            const bakiye = await fetchMeYillikIzinBakiye();
            iseGiris = bakiye.ise_giris_tarihi?.trim() || null;
          } catch {
            iseGiris = null;
          }
        }
        if (!cancelled) {
          const rows = buildRows(me, iseGiris);
          setPhotoName(me.personel.ad_soyad || me.ad_soyad);
          setStatus(
            rows.length > 0
              ? { kind: "ready", rows }
              : { kind: "error", message: "Profil bilgisi bulunamadı." }
          );
        }
      } catch (cause) {
        if (cancelled) return;
        const closed =
          isApiRequestError(cause) &&
          (cause.code === "SELF_SERVICE_BINDING_REQUIRED" || cause.code === "FORBIDDEN");
        setStatus({
          kind: "error",
          message: closed ? "Profil bu hesap için kapalı." : "Profil yüklenemedi. Tekrar deneyin."
        });
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [canViewIseGiris]);

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      setPhotoSrc(null);
      return;
    }
    let cancelled = false;
    void fetchSelfProfilFoto()
      .then((photo) => {
        if (!cancelled) setPhotoSrc(selfProfilFotoSrc(photo));
      })
      .catch(() => {
        if (!cancelled) setPhotoSrc(null);
      });
    return () => {
      cancelled = true;
    };
  }, []);

  async function onPhotoSelected(file: File | undefined) {
    if (!file) return;
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
  }

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-profil-page">
      {photoName ? <PersonelSelfPortrait name={photoName} photoSrc={photoSrc} /> : null}
      <label className="self-service-muted">
        Profil fotoğrafı
        <input
          type="file"
          accept="image/jpeg,image/png,image/webp"
          data-testid="personel-profil-foto-input"
          onChange={(event) => {
            const file = event.target.files?.[0];
            void onPhotoSelected(file);
          }}
        />
      </label>
      {photoMessage ? <p className="self-service-muted">{photoMessage}</p> : null}
      {status.kind === "loading" ? <LoadingState label="Profil yükleniyor..." /> : null}
      {status.kind === "ready" ? (
        <SelfServiceFactList rows={status.rows} testId="personel-profil-facts" />
      ) : null}
      {status.kind === "error" ? (
        <p className="self-service-muted" data-testid="personel-profil-status">
          {status.message}
        </p>
      ) : null}
    </section>
  );
}
