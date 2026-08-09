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
          <ul
            className="raporlar-panel-nav-group-items"
            aria-labelledby={`raporlar-nav-group-${group.id}-label`}
          >
            {group.items.map((item) => {
              const active = isRaporlarNavItemActive(item, surface);
              return (
                <li key={item.id}>
                  <Link
                    to={buildRaporlarNavHrefForItem(item)}
                    aria-current={active ? "page" : undefined}
                    data-testid={item.testId}
                    className={active ? "is-active" : undefined}
                  >
                    {item.label}
                  </Link>
                </li>
              );
            })}
          </ul>
        </div>
      ))}
    </nav>
  );
}
