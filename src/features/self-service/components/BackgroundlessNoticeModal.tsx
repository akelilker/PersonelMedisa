import { useEffect, useId, useRef, type ReactNode } from "react";
import { createPortal } from "react-dom";

type Props = {
  open: boolean;
  title: string;
  body: string;
  infoTooltip?: string;
  primaryLabel?: string;
  secondaryLabel?: string;
  onPrimary?: () => void;
  onSecondary?: () => void;
  onClose: () => void;
  testId?: string;
  children?: ReactNode;
};

/**
 * Backgroundless centered notice: border + text, blurred backdrop.
 * Matches homepage notification visual contract.
 */
export function BackgroundlessNoticeModal({
  open,
  title,
  body,
  infoTooltip,
  primaryLabel,
  secondaryLabel,
  onPrimary,
  onSecondary,
  onClose,
  testId = "backgroundless-notice",
  children
}: Props) {
  const titleId = useId();
  const dialogRef = useRef<HTMLDivElement | null>(null);

  useEffect(() => {
    if (!open) return;
    const prev = document.body.style.overflow;
    document.body.style.overflow = "hidden";
    document.body.classList.add("modal-open");
    dialogRef.current?.focus();
    return () => {
      document.body.style.overflow = prev;
      document.body.classList.remove("modal-open");
    };
  }, [open]);

  if (!open) return null;

  return createPortal(
    <div
      className="pm-notice-backdrop"
      role="presentation"
      data-testid={`${testId}-backdrop`}
      onMouseDown={(event) => {
        if (event.target === event.currentTarget) {
          onClose();
        }
      }}
    >
      <div
        ref={dialogRef}
        className="pm-notice-surface"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
        data-testid={testId}
      >
        <div className="pm-notice-title-row">
          <h2 id={titleId}>{title}</h2>
          {infoTooltip ? (
            <button
              type="button"
              className="pm-info-icon"
              aria-label={infoTooltip}
              title={infoTooltip}
              data-testid={`${testId}-info`}
            >
              ⓘ
            </button>
          ) : null}
        </div>
        <p className="pm-notice-body">{body}</p>
        {children ? <div className="pm-notice-children">{children}</div> : null}
        <div className="pm-notice-actions">
          {secondaryLabel && onSecondary ? (
            <button type="button" className="pm-notice-btn" onClick={onSecondary} data-testid={`${testId}-secondary`}>
              {secondaryLabel}
            </button>
          ) : null}
          {primaryLabel && onPrimary ? (
            <button type="button" className="pm-notice-btn pm-notice-btn--primary" onClick={onPrimary} data-testid={`${testId}-primary`}>
              {primaryLabel}
            </button>
          ) : (
            <button type="button" className="pm-notice-btn pm-notice-btn--primary" onClick={onClose} data-testid={`${testId}-close`}>
              Tamam
            </button>
          )}
        </div>
      </div>
    </div>,
    document.body
  );
}
