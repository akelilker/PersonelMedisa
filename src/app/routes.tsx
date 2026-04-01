import { Link, Outlet, Route, Routes } from "react-router-dom";
import { AppShell } from "./AppShell";
import { HomeDashboard } from "./HomeDashboard";
import { ProtectedRoute } from "../router/ProtectedRoute";
import { PersonellerPage } from "../features/personeller/pages/PersonellerPage";
import { PersonelDetayPage } from "../features/personeller/pages/PersonelDetayPage";
import { SurecTakipPage } from "../features/surecler/pages/SurecTakipPage";
import { SurecDetayPage } from "../features/surecler/pages/SurecDetayPage";
import { BildirimlerPage } from "../features/bildirimler/pages/BildirimlerPage";
import { BildirimDetayPage } from "../features/bildirimler/pages/BildirimDetayPage";
import { GunlukPuantajPage } from "../features/puantaj/pages/GunlukPuantajPage";
import { HaftalikKapanisPage } from "../features/haftalik-kapanis/pages/HaftalikKapanisPage";
import { RaporlarPage } from "../features/raporlar/pages/RaporlarPage";
import { FinansPage } from "../features/finans/pages/FinansPage";
import { LoginPage } from "../features/auth/pages/LoginPage";
import { InternalDiagnosticsPage } from "./InternalDiagnosticsPage";
import {
  PERSONELLER_LIST_ANY,
  ROUTE_PERMISSION,
  SURECLER_LIST_ANY
} from "../lib/authorization/role-permissions";

function AppLayout() {
  return (
    <AppShell>
      <Outlet />
    </AppShell>
  );
}

function NotFoundPage() {
  return (
    <section className="states-page">
      <h2>Sayfa Bulunamadi</h2>
      <p>Istedigin ekran bulunamadi. Lutfen gecerli bir modula gec.</p>
      <Link to="/">Ana ekrana don</Link>
    </section>
  );
}

function UnauthorizedPage() {
  return (
    <section className="states-page">
      <h2>Yetkisiz Erisim</h2>
      <p>Bu modulu gormek icin yeterli yetkin yok.</p>
      <Link to="/">Ana ekrana don</Link>
    </section>
  );
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/yetkisiz" element={<UnauthorizedPage />} />
      <Route path="/internal/diagnostics" element={<InternalDiagnosticsPage />} />

      <Route
        element={
          <ProtectedRoute>
            <AppLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<HomeDashboard />} />
        <Route
          path="personeller"
          element={
            <ProtectedRoute requireAny={PERSONELLER_LIST_ANY}>
              <PersonellerPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="personeller/:personelId"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.personelDetail}>
              <PersonelDetayPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="surecler"
          element={
            <ProtectedRoute requireAny={SURECLER_LIST_ANY}>
              <SurecTakipPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="surecler/:surecId"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.surecDetail}>
              <SurecDetayPage />
            </ProtectedRoute>
          }
        />
        <Route path="bildirimler" element={<BildirimlerPage />} />
        <Route
          path="bildirimler/:bildirimId"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.bildirimDetail}>
              <BildirimDetayPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="puantaj"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.puantajPage}>
              <GunlukPuantajPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="haftalik-kapanis"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.haftalikKapanisPage}>
              <HaftalikKapanisPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="raporlar"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.raporlarPage}>
              <RaporlarPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="finans"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.finansPage}>
              <FinansPage />
            </ProtectedRoute>
          }
        />
      </Route>

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}
