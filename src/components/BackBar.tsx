import type { ReactNode } from "react";
import { Link } from "react-router-dom";

type BackBarProps = {
  to: string;
  label: string;
  testId?: string;
  endContent?: ReactNode;
};

export function BackBarChevronIcon() {
  return (
    <svg
      className="back-icon-svg"
      xmlns="http://www.w3.org/2000/svg"
      width="18"
      height="18"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M19 12H5" />
      <path d="m12 19-7-7 7-7" />
    </svg>
  );
}

export function BackBar({ to, label, testId, endContent }: BackBarProps) {
  return (
    <div className={`universal-back-bar${endContent ? " has-end-content" : ""}`}>
      <Link to={to} className="universal-back-btn" aria-label={label} data-testid={testId}>
        <BackBarChevronIcon />
        <span className="universal-back-label">{label}</span>
      </Link>
      {endContent ? <div className="universal-back-bar-end">{endContent}</div> : null}
    </div>
  );
}

type ModalBackButtonProps = {
  label: string;
  onClick: () => void;
  testId?: string;
};

/** Modal header back control — same icon/label contract as BackBar (button variant for in-modal nav). */
export function ModalBackButton({ label, onClick, testId }: ModalBackButtonProps) {
  return (
    <button
      type="button"
      className="modal-back-btn universal-back-btn"
      onClick={onClick}
      aria-label={label}
      data-testid={testId}
    >
      <BackBarChevronIcon />
      <span className="modal-back-btn-label universal-back-label">{label}</span>
    </button>
  );
}
