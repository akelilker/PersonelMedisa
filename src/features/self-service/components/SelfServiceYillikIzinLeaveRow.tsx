type Props = {
  text: string;
  onOpen: () => void;
};

export function SelfServiceYillikIzinLeaveRow({ text, onOpen }: Props) {
  return (
    <button
      type="button"
      className="pm-leave-row"
      data-testid="personel-leave-row"
      onClick={onOpen}
    >
      <span className="pm-leave-row__text">{text}</span>
      <span className="pm-leave-row__chevron" aria-hidden="true">›</span>
    </button>
  );
}
