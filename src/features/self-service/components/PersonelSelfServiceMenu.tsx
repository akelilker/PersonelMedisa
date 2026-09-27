import { Link } from "react-router-dom";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { PERSONEL_SELF_MENU } from "../personel-self-service-menu";

export function PersonelSelfServiceMenu() {
  const { hasPermission } = useRoleAccess();

  return (
    <nav className="pm-self-menu" aria-label="Personel menüsü" data-testid="personel-self-menu">
      {PERSONEL_SELF_MENU.map((item) => {
        const enabled = hasPermission(item.permission);
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
            </button>
          );
        }
        return (
          <Link key={item.id} to={item.to} className="pm-self-menu__item" data-testid={item.testId}>
            {item.label}
          </Link>
        );
      })}
    </nav>
  );
}
