import { useRef } from "react";
import type { PersonelSelfIdentityView } from "../personel-self-identity-view";
import { PersonelSelfPortrait } from "./PersonelSelfPortrait";

type Props = {
  view: PersonelSelfIdentityView;
  photoSrc?: string | null;
  onPhotoSelected?: (file: File | undefined) => void;
};

export function PersonelSelfServiceIdentity({
  view,
  photoSrc = null,
  onPhotoSelected
}: Props) {
  const fileInputRef = useRef<HTMLInputElement | null>(null);

  function openPhotoPicker() {
    if (!onPhotoSelected) {
      return;
    }
    fileInputRef.current?.click();
  }

  return (
    <div className="pm-self-identity pm-self-identity--home" data-testid="personel-self-identity">
      <div className="pm-self-identity__text">
        <p className="pm-self-identity__name">{view.adSoyad}</p>
        <p className="pm-self-identity__fact" data-testid="personel-self-identity-sicil">
          Sicil No. {view.sicil}
        </p>
        <p className="pm-self-identity__fact" data-testid="personel-self-identity-ise-giris">
          İşe Giriş {view.iseGiris}
        </p>
        <p className="pm-self-identity__fact" data-testid="personel-self-identity-kidem">
          Çalışılan Süre {view.calismaSuresi}
        </p>
        {view.subeGorev ? (
          <p className="pm-self-identity__org" data-testid="personel-self-identity-org">
            {view.subeGorev}
          </p>
        ) : null}
      </div>
      {onPhotoSelected ? (
        <>
          <button
            type="button"
            className="pm-self-identity__photo-btn"
            data-testid="personel-self-identity-photo-btn"
            aria-label="Profil fotoğrafını değiştir"
            onClick={openPhotoPicker}
          >
            <PersonelSelfPortrait name={view.adSoyad} photoSrc={photoSrc} />
          </button>
          <input
            ref={fileInputRef}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="pm-self-identity__photo-input"
            data-testid="personel-self-identity-photo-input"
            tabIndex={-1}
            aria-hidden="true"
            onChange={(event) => {
              const file = event.target.files?.[0];
              void onPhotoSelected(file);
              event.target.value = "";
            }}
          />
        </>
      ) : (
        <PersonelSelfPortrait name={view.adSoyad} photoSrc={photoSrc} />
      )}
    </div>
  );
}
