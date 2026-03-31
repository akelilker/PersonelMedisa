import { useEffect, type ReactNode } from "react";

type AppModalProps = {
  title: string;
  children?: ReactNode;
  onClose?: () => void;
};

export function AppModal({ title, children, onClose }: AppModalProps) {
  useEffect(() => {
    const body = document.body;
    const currentOpenCount = Number.parseInt(body.dataset.modalOpenCount ?? "0", 10) || 0;
    const nextOpenCount = currentOpenCount + 1;
    body.dataset.modalOpenCount = String(nextOpenCount);
    body.classList.add("modal-open");

    return () => {
      const activeOpenCount = Number.parseInt(body.dataset.modalOpenCount ?? "0", 10) || 0;
      const remainingOpenCount = Math.max(0, activeOpenCount - 1);
      body.dataset.modalOpenCount = String(remainingOpenCount);

      if (remainingOpenCount === 0) {
        body.classList.remove("modal-open");
      }
    };
  }, []);

  useEffect(() => {
    const handleClose = onClose;
    if (!handleClose) {
      return;
    }

    function handleEscape(event: KeyboardEvent) {
      if (event.key === "Escape") {
        handleClose?.();
      }
    }

    document.addEventListener("keydown", handleEscape);
    return () => {
      document.removeEventListener("keydown", handleEscape);
    };
  }, [onClose]);

  return (
    <div
      className="modal-overlay open"
      onMouseDown={(event) => {
        if (onClose && event.target === event.currentTarget) {
          onClose();
        }
      }}
    >
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
