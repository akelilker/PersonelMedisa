import { Link } from "react-router-dom";
import {
  buildRaporlarNavHrefForItem,
  buildVisibleRaporlarNavGroups,
  isRaporlarNavItemActive,
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

  return (
    <nav className="raporlar-panel-nav raporlar-panel-nav--grouped" aria-label="Rapor panelleri" data-testid="raporlar-panel-nav">
      {groups.map((group) => (
        <div
          key={group.id}
          className="raporlar-panel-nav-group"
          data-testid={`raporlar-nav-group-${group.id}`}
        >
          <p className="raporlar-panel-nav-group-label" id={`raporlar-nav-group-${group.id}-label`}>
            {group.label}
          </p>
          <div
            className="raporlar-panel-nav-group-items"
            role="list"
            aria-labelledby={`raporlar-nav-group-${group.id}-label`}
          >
            {group.items.map((item) => {
              const active = isRaporlarNavItemActive(item, surface);
              return (
                <Link
                  key={item.id}
                  role="listitem"
                  to={buildRaporlarNavHrefForItem(item)}
                  aria-current={active ? "page" : undefined}
                  data-testid={item.testId}
                  className={active ? "is-active" : undefined}
                >
                  {item.label}
                </Link>
              );
            })}
          </div>
        </div>
      ))}
    </nav>
  );
}
