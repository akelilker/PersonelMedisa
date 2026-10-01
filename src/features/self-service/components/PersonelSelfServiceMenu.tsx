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
          <path d="M5 22h14" />
          <path d="M5 2h14" />
          <path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22" />
          <path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2" />
        </svg>
      );
    case "izinlerim":
      return (
        <svg {...DOCK_ICON_PROPS}>
          <path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16" />
          <rect x="2" y="7" width="20" height="14" rx="2" ry="2" />
        </svg>
      );
    case "talepler":
      return (
        <svg {...DOCK_ICON_PROPS}>
          <path d="M13 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7" />
          <path d="M13 3v4h4" />
          <path d="M8 8h4" />
          <path d="M8 12h3" />
          <path d="m14.5 13.5 5-5 2 2-5 5-3 1 1-3z" />
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
            <span className={`pm-self-dock__icon-wrap pm-self-dock__icon-wrap--${item.id}`}>
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
