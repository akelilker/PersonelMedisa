import { useEffect, type MutableRefObject } from "react";
import { Link } from "react-router-dom";
import {
  isSecondaryModuleActive,
  type SecondaryModuleDef
} from "../../lib/shell/secondary-module-nav";

export type ShellModuleMenuProps = {
  isOpen: boolean;
  modules: SecondaryModuleDef[];
  pathname: string;
  onToggle: () => void;
  onClose: () => void;
  onNavigate: () => void;
  toggleRef: MutableRefObject<HTMLButtonElement | null>;
  menuId: string;
  toggleTestId: string;
  navTestId: string;
  linkTestIdPrefix: string;
  className?: string;
};

function focusToggle(toggleRef: MutableRefObject<HTMLButtonElement | null>) {
  toggleRef.current?.focus();
}

export function ShellModuleMenu({
  isOpen,
  modules,
  pathname,
  onToggle,
  onClose,
  onNavigate,
  toggleRef,
  menuId,
  toggleTestId,
  navTestId,
  linkTestIdPrefix,
  className
}: ShellModuleMenuProps) {
  useEffect(() => {
    if (!isOpen) {
      return;
    }

    function handleEscapeCapture(event: KeyboardEvent) {
      if (event.key !== "Escape") {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
      onClose();
      focusToggle(toggleRef);
    }

    document.addEventListener("keydown", handleEscapeCapture, true);
    return () => {
      document.removeEventListener("keydown", handleEscapeCapture, true);
    };
  }, [isOpen, onClose, toggleRef]);

  if (modules.length === 0) {
    return null;
  }

  return (
    <div className={["modules-menu-wrap", className].filter(Boolean).join(" ")}>
      <button
        ref={(node) => {
          toggleRef.current = node;
        }}
        type="button"
        className="icon-btn modules-menu-toggle"
        data-testid={toggleTestId}
        aria-label="Modüller"
        aria-expanded={isOpen}
        aria-controls={menuId}
        onClick={onToggle}
      >
        <span className="modules-menu-toggle-label" aria-hidden="true">
          Modüller
        </span>
        <svg
          className="modules-menu-toggle-icon"
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
          <rect x="3" y="3" width="7" height="7" rx="1" />
          <rect x="14" y="3" width="7" height="7" rx="1" />
          <rect x="3" y="14" width="7" height="7" rx="1" />
          <rect x="14" y="14" width="7" height="7" rx="1" />
        </svg>
      </button>

      <div id={menuId} className={`settings-dropdown modules-dropdown${isOpen ? " open" : ""}`}>
        <nav aria-label="İkincil modüller" data-testid={navTestId}>
          {modules.map((module) => {
            const active = isSecondaryModuleActive(pathname, module.id);
            return (
              <Link
                key={module.id}
                to={module.to}
                data-testid={`${linkTestIdPrefix}${module.id}`}
                className={["modules-nav-link", active ? "is-active" : ""].filter(Boolean).join(" ")}
                aria-current={active ? "page" : undefined}
                onClick={onNavigate}
              >
                {module.label}
              </Link>
            );
          })}
        </nav>
      </div>
    </div>
  );
}
