import { useLocation, useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../hooks/use-role-access";
import type { AppPermission } from "../../lib/authorization/role-permissions";
import { MAIN_MENU_ITEMS, type MainMenuConfigItem } from "./main-menu-config";

export type KayitTab = "yeni-kayit" | "surec";

type MainMenuProps = {
  onKayitOpen: (tab: KayitTab) => void;
};

function itemIsAllowed(
  item: MainMenuConfigItem,
  hasPermission: (permission: AppPermission) => boolean,
  hasAnyPermission: (permissions: AppPermission[]) => boolean
): boolean {
  if (item.requiredAnyPermissions && item.requiredAnyPermissions.length > 0) {
    return hasAnyPermission(item.requiredAnyPermissions);
  }

  if ("requiredPermission" in item && item.requiredPermission) {
    return hasPermission(item.requiredPermission);
  }

  return true;
}

function itemIsActive(item: MainMenuConfigItem, pathname: string): boolean {
  if (item.kind === "modal") {
    return pathname.startsWith(item.activePathPrefix);
  }

  return pathname === item.route || pathname.startsWith(`${item.route}/`);
}

export function MainMenu({ onKayitOpen }: MainMenuProps) {
  const location = useLocation();
  const navigate = useNavigate();
  const { hasAnyPermission, hasPermission } = useRoleAccess();
  const { pathname } = location;

  const menuItems = MAIN_MENU_ITEMS.filter((item) => {
    const allowed = itemIsAllowed(item, hasPermission, hasAnyPermission);
    if (item.visibilityMode === "hide") {
      return allowed;
    }

    return true;
  });

  return (
    <nav id="main-menu" className="menu-container" aria-label="Ana omurga modulleri">
      {menuItems.map((item) => {
        const allowed = itemIsAllowed(item, hasPermission, hasAnyPermission);
        const isActive = itemIsActive(item, pathname);

        return (
          <button
            key={item.testId}
            type="button"
            className={`menu-btn${isActive ? " is-active" : ""}`}
            aria-current={isActive ? "page" : undefined}
            data-testid={item.testId}
            onClick={() => {
              if (item.kind === "modal") {
                const tab: KayitTab = pathname.startsWith("/surecler") ? "surec" : "yeni-kayit";
                onKayitOpen(tab);
                return;
              }

              navigate(item.route);
            }}
            disabled={!allowed}
          >
            <div className="ttl">{item.label}</div>
          </button>
        );
      })}
    </nav>
  );
}
