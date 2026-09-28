import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { fetchSelfDuyurular } from "../../../api/self-product.api";
import { shouldPreferDemoApi } from "../../../api/api-client";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { PERSONEL_SELF_MENU } from "../personel-self-service-menu";

export function PersonelSelfServiceMenu() {
  const { hasPermission } = useRoleAccess();
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    if (shouldPreferDemoApi() || !hasPermission("self_service.view")) {
      setUnread(0);
      return;
    }
    let cancelled = false;
    void fetchSelfDuyurular()
      .then((result) => {
        if (!cancelled) {
          setUnread(result.unread_count);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setUnread(0);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [hasPermission]);

  return (
    <nav className="pm-self-menu" aria-label="Personel menüsü" data-testid="personel-self-menu">
      {PERSONEL_SELF_MENU.map((item) => {
        const enabled = hasPermission(item.permission);
        const badge =
          item.id === "duyurular" && unread > 0 ? (
            <span className="pm-self-menu__badge" data-testid="personel-duyuru-badge">
              {unread}
            </span>
          ) : null;
        if (!enabled) {
          return (
            <button
              key={item.id}
              type="button"
              className="pm-self-menu__item"
              data-testid={item.testId}
              disabled
              aria-disabled="true"
            >
              {item.label}
              {badge}
            </button>
          );
        }
        return (
          <Link key={item.id} to={item.to} className="pm-self-menu__item" data-testid={item.testId}>
            {item.label}
            {badge}
          </Link>
        );
      })}
    </nav>
  );
}
