import { Outlet, useLocation } from "react-router-dom";
import { AppFooter } from "../components/footer/AppFooter";
import { AppHeader } from "../components/shell/AppHeader";

export function AppShell() {
  const { pathname } = useLocation();
  const isLoginRoute = pathname === "/login";

  return (
    <div className="app-container app-shell">
      {!isLoginRoute && <AppHeader />}
      <main className="content-wrap">
        <Outlet />
      </main>
      <AppFooter />
    </div>
  );
}

