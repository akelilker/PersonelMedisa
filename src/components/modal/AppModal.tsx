import { useEffect, useRef, type ReactNode } from "react";
import { createPortal } from "react-dom";

type AppModalProps = {
  title: string;
  children?: ReactNode;
  footer?: ReactNode;
  onClose?: () => void;
};

function getModalPortalRoot(): HTMLElement | null {
  if (typeof document === "undefined") {
    return null;
  }

  return document.body;
}

export function AppModal({ title, children, footer, onClose }: AppModalProps) {
  const overlayRef = useRef<HTMLDivElement | null>(null);

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
    if (!onClose) {
      return;
    }

    const close = onClose;

    function handleEscape(event: KeyboardEvent) {
      if (event.key !== "Escape") {
        return;
      }

      const node = overlayRef.current;
      if (!node) {
        return;
      }

      const stack = document.querySelectorAll(".modal-overlay.open");
      const top = stack.item(stack.length - 1);
      if (top !== node) {
        return;
      }

      event.preventDefault();
      close();
    }

    document.addEventListener("keydown", handleEscape);
    return () => {
      document.removeEventListener("keydown", handleEscape);
    };
  }, [onClose]);

  const portalRoot = getModalPortalRoot();

  const modalTree = (
    <div
      ref={overlayRef}
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
              X
            </button>
          ) : null}
        </div>
        <div className="modal-body">{children}</div>
        {footer ? <div className="modal-footer">{footer}</div> : null}
      </div>
    </div>
  );

  if (!portalRoot) {
    return null;
  }

  return createPortal(modalTree, portalRoot);
}
