import type { ReactNode } from "react";

type AppModalProps = {
  title: string;
  children?: ReactNode;
  onClose?: () => void;
};

export function AppModal({ title, children, onClose }: AppModalProps) {
  return (
    <div className="modal-overlay">
      <div className="modal-container">
        <div className="modal-header">
          <h2>{title}</h2>
          {onClose ? (
            <button type="button" className="modal-close-btn" onClick={onClose} aria-label="Kapat">
              Kapat
            </button>
          ) : null}
        </div>
        <div className="modal-body">{children}</div>
      </div>
    </div>
  );
}
