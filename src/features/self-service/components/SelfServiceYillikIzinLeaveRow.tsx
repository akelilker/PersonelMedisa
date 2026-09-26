type Props = {
  text: string;
  loading?: boolean;
  disabled?: boolean;
  onOpen: () => void;
};

export function SelfServiceYillikIzinLeaveRow({ text, loading, disabled, onOpen }: Props) {
  if (loading) {
    return (
      <div className="pm-leave-row pm-leave-row--loading" data-testid="personel-leave-row" aria-busy="true">
        <span className="pm-leave-row__text">İzin bilgisi yükleniyor…</span>
      </div>
    );
  }

  return (
    <button
      type="button"
      className="pm-leave-row"
      data-testid="personel-leave-row"
      disabled={disabled}
      onClick={onOpen}
    >
      <span className="pm-leave-row__text">{text}</span>
      <span className="pm-leave-row__chevron" aria-hidden="true">›</span>
    </button>
  );
}
