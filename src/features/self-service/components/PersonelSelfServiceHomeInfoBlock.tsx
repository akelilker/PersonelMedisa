import type { PersonelSelfServiceHomeInfoView } from "../personel-self-service-home-view";

type Props = {
  view: PersonelSelfServiceHomeInfoView;
  onOpenIzinModal?: () => void;
};

export function PersonelSelfServiceHomeInfoBlock({ view, onOpenIzinModal }: Props) {
  return (
    <div className="pm-self-home-info" data-testid="personel-self-home-info">
      <dl className="pm-self-home-info__rows">
        {view.rows.map((row) => (
          <div className="pm-self-home-info__row" key={row.label}>
            <dt>{row.label}</dt>
            <dd data-testid={row.testId}>{row.value}</dd>
          </div>
        ))}
      </dl>
      {view.izinModalRow && onOpenIzinModal ? (
        <button
          type="button"
          className="pm-leave-row pm-self-home-info__izin-row"
          data-testid="personel-leave-row"
          onClick={onOpenIzinModal}
        >
          <span className="pm-leave-row__text">{view.izinModalRow.text}</span>
          <span className="pm-leave-row__chevron" aria-hidden="true">›</span>
        </button>
      ) : null}
    </div>
  );
}
