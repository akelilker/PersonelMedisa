import { Link, Outlet, Route, Routes } from "react-router-dom";
import { AppShell } from "./AppShell";
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
import { YonetimPaneliPage } from "../features/yonetim/pages/YonetimPaneliPage";
import { AylikKapanisOzetiPage } from "../features/yonetim/pages/AylikKapanisOzetiPage";
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
      <h2>Sayfa Bulunamadı</h2>
      <p>İstediğin ekran bulunamadı. Lütfen geçerli bir modüle geç.</p>
      <Link to="/">Ana ekrana dön</Link>
    </section>
  );
}

function UnauthorizedPage() {
  return (
    <section className="states-page">
      <h2>Yetkisiz Erişim</h2>
      <p>Bu modülü görmek için yeterli yetkin yok.</p>
      <Link to="/">Ana ekrana dön</Link>
    </section>
  );
}

export function AppRoutes() {
  return (
    <Routes>
      <Route element={<AppLayout />}>
        <Route path="/login" element={<LoginPage />} />
      </Route>

      <Route path="/yetkisiz" element={<UnauthorizedPage />} />
      <Route path="/internal/diagnostics" element={<InternalDiagnosticsPage />} />

      <Route
        element={
          <ProtectedRoute>
            <AppLayout />
          </ProtectedRoute>
        }
      >
        <Route index element={<></>} />
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
        <Route
          path="bildirimler"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.bildirimlerPage}>
              <BildirimlerPage />
            </ProtectedRoute>
          }
        />
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
        <Route
          path="yonetim-paneli"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.yonetimPaneliPage}>
              <YonetimPaneliPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="aylik-kapanis-ozeti"
          element={
            <ProtectedRoute requirePermission={ROUTE_PERMISSION.aylikOzetPage}>
              <AylikKapanisOzetiPage />
            </ProtectedRoute>
          }
        />
      </Route>

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}
