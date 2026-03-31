import { Link } from "react-router-dom";

type BackBarProps = {
  to: string;
  label: string;
};

export function BackBar({ to, label }: BackBarProps) {
  return (
    <div className="universal-back-bar">
      <Link to={to} className="universal-back-btn" aria-label={label}>
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
        <span className="universal-back-label">{label}</span>
      </Link>
    </div>
  );
}
