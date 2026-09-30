import { Link } from "react-router-dom";
import {
  buildRaporlarNavHrefForItem,
  buildVisibleRaporlarNavGroups,
  getDefaultRaporlarNavItemForGroup,
  getVisibleRaporlarNavItemsInGroup,
  isRaporlarNavItemActive,
  resolveRaporlarGroupForSurface,
  type RaporlarNavVisibility,
  type RaporlarSurfaceId
} from "../raporlar-ia";

export function RaporlarGroupedNav({
  surface,
  visibility
}: {
  surface: RaporlarSurfaceId;
  visibility: RaporlarNavVisibility;
}) {
  const groups = buildVisibleRaporlarNavGroups(visibility);
  const activeGroupId = resolveRaporlarGroupForSurface(surface);
  const activeGroupItems = getVisibleRaporlarNavItemsInGroup(activeGroupId, visibility);

  return (
    <div className="raporlar-ia-nav" data-testid="raporlar-panel-nav">
      <nav
        className="raporlar-ia-nav-groups"
        aria-label="Rapor modül grupları"
        data-testid="raporlar-ia-nav-groups"
      >
        {groups.map((group) => {
          const isActiveGroup = group.id === activeGroupId;
          const defaultItem = getDefaultRaporlarNavItemForGroup(group.id, visibility);
          if (!defaultItem) {
            return null;
          }

          if (isActiveGroup) {
            return (
              <span
                key={group.id}
                className="raporlar-ia-nav-group-tab is-active"
                data-testid={`raporlar-nav-group-${group.id}`}
                aria-current="true"
              >
                {group.label}
              </span>
            );
          }

          return (
            <Link
              key={group.id}
              to={buildRaporlarNavHrefForItem(defaultItem)}
              className="raporlar-ia-nav-group-tab"
              data-testid={`raporlar-nav-group-${group.id}`}
            >
              {group.label}
            </Link>
          );
        })}
      </nav>

      {activeGroupItems.length > 0 ? (
        <nav
          className="raporlar-ia-nav-surfaces"
          aria-label={`${groups.find((group) => group.id === activeGroupId)?.label ?? "Rapor"} yüzeyleri`}
          data-testid={`raporlar-ia-nav-surfaces-${activeGroupId}`}
        >
          {activeGroupItems.map((item) => {
            const active = isRaporlarNavItemActive(item, surface);
            return (
              <Link
                key={item.id}
                to={buildRaporlarNavHrefForItem(item)}
                aria-current={active ? "page" : undefined}
                data-testid={item.testId}
                className={`raporlar-ia-nav-surface-tab${active ? " is-active" : ""}`}
              >
                {item.label}
              </Link>
            );
          })}
        </nav>
      ) : null}
    </div>
  );
}
