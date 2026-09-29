import { Link } from "react-router-dom";
import { useRoleAccess } from "../../../hooks/use-role-access";
import {
  PERSONEL_SELF_HOME_DOCK_MENU,
  type PersonelSelfMenuItem
} from "../personel-self-service-menu";

const DOCK_ICON_PROPS = {
  xmlns: "http://www.w3.org/2000/svg",
  width: 28,
  height: 28,
  viewBox: "0 0 24 24",
  fill: "none",
  stroke: "currentColor",
  strokeWidth: 2,
  strokeLinecap: "round" as const,
  strokeLinejoin: "round" as const,
  "aria-hidden": true
};

function PersonelSelfDockIcon({ id }: { id: PersonelSelfMenuItem["id"] }) {
  switch (id) {
    case "gecmis":
      return (
        <svg {...DOCK_ICON_PROPS}>
          <path d="M3 12a9 9 0 1 0 3-6.7" />
          <path d="M3 4v5h5" />
          <circle cx="12" cy="12" r="3" />
          <path d="M12 10v2l1.2.8" />
        </svg>
      );
    case "izinlerim":
      return (
        <svg {...DOCK_ICON_PROPS}>
          <rect x="3" y="5" width="18" height="16" rx="2" />
          <path d="M16 3v4M8 3v4M3 10h18" />
        </svg>
      );
    case "talepler":
      return (
        <svg {...DOCK_ICON_PROPS}>
          <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2" />
          <rect x="9" y="3" width="6" height="4" rx="1" />
          <path d="M9 12h6M9 16h6" />
        </svg>
      );
    case "fazla-mesai":
      return (
        <svg {...DOCK_ICON_PROPS}>
          <circle cx="12" cy="12" r="8" />
          <path d="M12 8v4l2.5 1.5" />
          <path d="M17 7h3v3" />
        </svg>
      );
    default:
      return null;
  }
}

export function PersonelSelfServiceMenu({ anomalyCount = 0 }: { anomalyCount?: number }) {
  const { hasPermission } = useRoleAccess();
  const unresolvedCount = anomalyCount > 0 ? anomalyCount : 0;

  return (
    <nav
      className="pm-self-dock"
      aria-label="Personel alt menüsü"
      data-testid="personel-self-menu"
    >
      {PERSONEL_SELF_HOME_DOCK_MENU.map((item) => {
        const enabled = hasPermission(item.permission);
        const content = (
          <>
            <span className="pm-self-dock__icon-wrap">
              <PersonelSelfDockIcon id={item.id} />
              {item.id === "talepler" && unresolvedCount > 0 ? (
                <span className="pm-self-dock__count" data-testid="personel-talepler-anomaly-count">
                  {unresolvedCount}
                </span>
              ) : null}
            </span>
            <span className="pm-self-dock__label">{item.label}</span>
          </>
        );
        if (!enabled) {
          return (
            <button
              key={item.id}
              type="button"
              className="pm-self-dock__item"
              data-testid={item.testId}
              disabled
              aria-disabled="true"
            >
              {content}
            </button>
          );
        }
        return (
          <Link key={item.id} to={item.to} className="pm-self-dock__item" data-testid={item.testId}>
            {content}
          </Link>
        );
      })}
    </nav>
  );
}
