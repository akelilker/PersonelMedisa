import type { PersonelSelfIdentityView } from "../personel-self-identity-view";

type Props = {
  view: PersonelSelfIdentityView;
};

export function PersonelSelfServiceIdentity({ view }: Props) {
  return (
    <div className="pm-self-identity" data-testid="personel-self-identity">
      <p className="pm-self-identity__name">{view.adSoyad}</p>
      {view.organization.length > 0 ? (
        <p className="pm-self-identity__org">{view.organization.join(" · ")}</p>
      ) : null}
    </div>
  );
}
