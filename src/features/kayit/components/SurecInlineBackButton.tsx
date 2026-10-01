import { BackBarChevronIcon } from "../../../components/BackBar";

type SurecInlineBackButtonProps = {
  label: string;
  onClick: () => void;
  testId?: string;
};

/** In-modal süreç drill-in back (same visual contract as BackBar, button variant). */
export function SurecInlineBackButton({ label, onClick, testId }: SurecInlineBackButtonProps) {
  return (
    <div className="universal-back-bar surec-inline-back-bar">
      <button type="button" className="universal-back-btn" onClick={onClick} aria-label={label} data-testid={testId}>
        <BackBarChevronIcon />
        <span className="universal-back-label">{label}</span>
      </button>
    </div>
  );
}
