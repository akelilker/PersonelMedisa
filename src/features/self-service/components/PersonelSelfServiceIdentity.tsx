import type { PersonelSelfIdentityView } from "../personel-self-identity-view";
import { PersonelSelfPortrait } from "./PersonelSelfPortrait";

type Props = {
  view: PersonelSelfIdentityView;
  photoSrc?: string | null;
};

export function PersonelSelfServiceIdentity({ view, photoSrc = null }: Props) {
  return (
    <div className="pm-self-identity" data-testid="personel-self-identity">
      <PersonelSelfPortrait name={view.adSoyad} photoSrc={photoSrc} />
      <div className="pm-self-identity__text">
        <p className="pm-self-identity__name">{view.adSoyad}</p>
        {view.organization.length > 0 ? (
          <p className="pm-self-identity__org">{view.organization.join(" · ")}</p>
        ) : null}
      </div>
    </div>
  );
}
