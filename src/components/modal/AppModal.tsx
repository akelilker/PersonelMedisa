import { useEffect, useId, useRef, type ReactNode } from "react";
import { createPortal } from "react-dom";

type AppModalProps = {
  title: string;
  children?: ReactNode;
  footer?: ReactNode;
  onClose?: () => void;
  backLabel?: string;
  onBack?: () => void;
  backTestId?: string;
  className?: string;
  bodyClassName?: string;
  titleVariant?: "default" | "premium";
};

const FOCUSABLE_SELECTOR = [
  "a[href]",
  "button:not([disabled])",
  "textarea:not([disabled])",
  "input:not([disabled]):not([type='hidden'])",
  "select:not([disabled])",
  '[contenteditable="true"]',
  "[tabindex]:not([tabindex='-1'])"
].join(",");

function getModalPortalRoot(): HTMLElement | null {
  if (typeof document === "undefined") {
    return null;
  }

  return document.body;
}

function isJsdomEnvironment(): boolean {
  return typeof navigator !== "undefined" && /jsdom/i.test(navigator.userAgent);
}

function escapeCssIdent(value: string): string {
  if (typeof CSS !== "undefined" && typeof CSS.escape === "function") {
    return CSS.escape(value);
  }

  return value;
}

function isDisabledElement(element: HTMLElement): boolean {
  if (element.hasAttribute("disabled")) {
    return true;
  }

  return "disabled" in element && Boolean((element as HTMLButtonElement).disabled);
}

function isCssHiddenInAncestorChain(element: HTMLElement): boolean {
  let current: HTMLElement | null = element;

  while (current) {
    const style = getComputedStyle(current);
    if (style.display === "none" || style.visibility === "hidden") {
      return true;
    }

    current = current.parentElement;
  }

  return false;
}

/**
 * Production visibility uses layout metrics.
 * JSDOM often reports empty client rects; only that environment may fall back to connected+CSS.
 */
function isElementVisible(element: HTMLElement): boolean {
  if (element.closest("[hidden]") || element.closest('[aria-hidden="true"]')) {
    return false;
  }

  if (isCssHiddenInAncestorChain(element)) {
    return false;
  }

  if (element.getClientRects().length > 0) {
    return true;
  }

  if (isJsdomEnvironment() && element.isConnected) {
    return true;
  }

  return false;
}

function canAcceptProgrammaticFocus(element: HTMLElement): boolean {
  if (isDisabledElement(element)) {
    return false;
  }

  if (!isElementVisible(element)) {
    return false;
  }

  // Dialog containers use tabIndex={-1} intentionally and must remain focusable.
  if (element.getAttribute("role") === "dialog") {
    return true;
  }

  if (element.tabIndex < 0 && element.getAttribute("contenteditable") !== "true") {
    // Native controls remain focusable even when tabIndex is the default platform value.
    const tag = element.tagName;
    if (tag === "A" || tag === "BUTTON" || tag === "INPUT" || tag === "SELECT" || tag === "TEXTAREA") {
      return true;
    }

    return false;
  }

  return true;
}

function isSafeFocusTarget(element: HTMLElement | null): element is HTMLElement {
  return element instanceof HTMLElement && canAcceptProgrammaticFocus(element);
}

function getFocusableElements(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter((element) => {
    if (element.tabIndex < 0) {
      return false;
    }

    if (isDisabledElement(element)) {
      return false;
    }

    if (element.closest("[hidden]") || element.closest('[aria-hidden="true"]')) {
      return false;
    }

    return isElementVisible(element);
  });
}

function isTopmostOverlay(overlay: HTMLElement | null): boolean {
  if (!overlay) {
    return false;
  }

  const stack = document.querySelectorAll(".modal-overlay.open");
  return stack.item(stack.length - 1) === overlay;
}

/** Last focus outside any open modal; survives opener unmount before AppModal effect runs. */
let lastNonModalFocus: HTMLElement | null = null;

if (typeof document !== "undefined") {
  document.addEventListener(
    "focusin",
    (event) => {
      const target = event.target;
      if (!(target instanceof HTMLElement)) {
        return;
      }

      if (target.closest(".modal-overlay.open")) {
        return;
      }

      if (target === document.body || target === document.documentElement) {
        return;
      }

      lastNonModalFocus = target;
    },
    true
  );
}

function restoreFocus(target: HTMLElement | null) {
  if (target?.isConnected && isSafeFocusTarget(target)) {
    target.focus({ preventScroll: true });
    return;
  }

  const id = target?.id;
  if (id) {
    const byId = document.getElementById(id);
    if (isSafeFocusTarget(byId)) {
      byId.focus({ preventScroll: true });
      return;
    }
  }

  const testId = target?.getAttribute("data-testid");
  if (!testId) {
    return;
  }

  const replacement = document.querySelector(`[data-testid="${escapeCssIdent(testId)}"]`);
  if (replacement instanceof HTMLElement && isSafeFocusTarget(replacement)) {
    replacement.focus({ preventScroll: true });
  }
}

export function AppModal({
  title,
  children,
  footer,
  onClose,
  backLabel,
  onBack,
  backTestId,
  className,
  bodyClassName,
  titleVariant = "default"
}: AppModalProps) {
  const overlayRef = useRef<HTMLDivElement | null>(null);
  const dialogRef = useRef<HTMLDivElement | null>(null);
  const previouslyFocusedRef = useRef<HTMLElement | null>(null);
  const onCloseRef = useRef(onClose);
  const focusMountIdRef = useRef(0);
  const titleId = useId();

  onCloseRef.current = onClose;

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
    const overlay = overlayRef.current;
    const dialog = dialogRef.current;
    if (!overlay || !dialog) {
      return;
    }

    const overlayEl = overlay;
    const dialogEl = dialog;
    const mountId = ++focusMountIdRef.current;

    if (previouslyFocusedRef.current === null) {
      const active = document.activeElement;
      if (
        active instanceof HTMLElement &&
        active !== document.body &&
        active !== document.documentElement &&
        !overlayEl.contains(active)
      ) {
        previouslyFocusedRef.current = active;
      } else if (
        lastNonModalFocus &&
        lastNonModalFocus !== document.body &&
        !overlayEl.contains(lastNonModalFocus)
      ) {
        previouslyFocusedRef.current = lastNonModalFocus;
      }
    }

    const focusInitial = () => {
      if (!isTopmostOverlay(overlayEl)) {
        return;
      }

      const explicit = dialogEl.querySelector<HTMLElement>('[data-modal-initial-focus="true"]');
      if (isSafeFocusTarget(explicit)) {
        explicit.focus({ preventScroll: true });
        return;
      }

      const activeElement = document.activeElement;
      if (
        activeElement instanceof HTMLElement &&
        dialogEl.contains(activeElement) &&
        activeElement !== dialogEl &&
        isSafeFocusTarget(activeElement)
      ) {
        // Preserve React autoFocus (or other intentional in-dialog focus).
        return;
      }

      dialogEl.focus({ preventScroll: true });
    };

    const frame = window.requestAnimationFrame(focusInitial);

    function handleFocusIn(event: FocusEvent) {
      if (!isTopmostOverlay(overlayEl)) {
        return;
      }

      const target = event.target;

      if (target instanceof Node && dialogEl.contains(target)) {
        return;
      }

      dialogEl.focus({ preventScroll: true });
    }

    function handleKeyDown(event: KeyboardEvent) {
      if (!isTopmostOverlay(overlayEl)) {
        return;
      }

      if (event.key === "Escape") {
        const close = onCloseRef.current;
        if (!close) {
          return;
        }
        event.preventDefault();
        event.stopPropagation();
        close();
        return;
      }

      if (event.key !== "Tab") {
        return;
      }

      const focusables = getFocusableElements(dialogEl);
      event.preventDefault();

      if (focusables.length === 0) {
        dialogEl.focus({ preventScroll: true });
        return;
      }

      const first = focusables[0];
      const last = focusables[focusables.length - 1];
      const activeElement = document.activeElement;
      const currentIndex = focusables.findIndex((element) => element === activeElement);

      if (event.shiftKey) {
        if (activeElement === dialogEl || currentIndex <= 0 || !dialogEl.contains(activeElement)) {
          last.focus({ preventScroll: true });
          return;
        }

        focusables[currentIndex - 1].focus({ preventScroll: true });
        return;
      }

      if (activeElement === dialogEl || currentIndex === -1 || !dialogEl.contains(activeElement)) {
        first.focus({ preventScroll: true });
        return;
      }

      if (currentIndex >= focusables.length - 1) {
        first.focus({ preventScroll: true });
        return;
      }

      focusables[currentIndex + 1].focus({ preventScroll: true });
    }

    document.addEventListener("focusin", handleFocusIn);
    document.addEventListener("keydown", handleKeyDown);

    return () => {
      window.cancelAnimationFrame(frame);
      document.removeEventListener("focusin", handleFocusIn);
      document.removeEventListener("keydown", handleKeyDown);

      const restoreTarget = previouslyFocusedRef.current;
      // Defer for route remounts; skip StrictMode fake-unmount via mount id.
      window.setTimeout(() => {
        if (focusMountIdRef.current !== mountId) {
          return;
        }
        restoreFocus(restoreTarget);
      }, 0);
    };
  }, []);

  const portalRoot = getModalPortalRoot();

  const modalTree = (
    <div
      ref={overlayRef}
      className="modal-overlay open"
      onMouseDown={(event) => {
        if (onClose && event.target === event.currentTarget && isTopmostOverlay(overlayRef.current)) {
          onClose();
        }
      }}
    >
      <div
        ref={dialogRef}
        className={["modal-container", className].filter(Boolean).join(" ")}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
      >
        <div className="modal-header">
          {onBack && backLabel ? (
            <button type="button" className="modal-back-btn" onClick={onBack} data-testid={backTestId}>
              <span className="modal-back-btn-icon" aria-hidden="true">
                ←
              </span>
              <span className="modal-back-btn-label">{backLabel}</span>
            </button>
          ) : (
            <span className="modal-header-spacer" aria-hidden="true" />
          )}
          <h2 id={titleId} className={titleVariant === "premium" ? "premium-title" : undefined}>
            {title}
          </h2>
          {onClose ? (
            <button type="button" className="modal-close-btn" onClick={onClose} aria-label="Kapat">
              ×
            </button>
          ) : (
            <span className="modal-header-spacer" aria-hidden="true" />
          )}
        </div>
        <div className={["modal-body", bodyClassName].filter(Boolean).join(" ")}>{children}</div>
        {footer ? <div className="modal-footer">{footer}</div> : null}
      </div>
    </div>
  );

  if (!portalRoot) {
    return null;
  }

  return createPortal(modalTree, portalRoot);
}
