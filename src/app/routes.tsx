import { Link, Navigate, Outlet, Route, Routes } from "react-router-dom";
import { AppShell } from "./AppShell";
import { AuthGuard } from "./auth-guard";
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
import {
  BILDIRIM_DETAIL_ALLOWED_ROLES,
  FINANS_ALLOWED_ROLES,
  HAFTALIK_KAPANIS_ALLOWED_ROLES,
  PERSONEL_DETAIL_ALLOWED_ROLES,
  PUANTAJ_ALLOWED_ROLES,
  RAPORLAR_ALLOWED_ROLES,
  SUREC_DETAIL_ALLOWED_ROLES
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
      <Link to="/personeller">Personellere don</Link>
    </section>
  );
}

function UnauthorizedPage() {
  return (
    <section className="states-page">
      <h2>Yetkisiz Erisim</h2>
      <p>Bu modulu gormek icin yeterli yetkin yok.</p>
      <Link to="/personeller">Izinli sayfalara don</Link>
    </section>
  );
}

export function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/yetkisiz" element={<UnauthorizedPage />} />

      <Route
        element={
          <AuthGuard>
            <AppLayout />
          </AuthGuard>
        }
      >
        <Route index element={<Navigate to="/personeller" replace />} />
        <Route path="personeller" element={<PersonellerPage />} />
        <Route
          path="personeller/:personelId"
          element={
            <AuthGuard allowedRoles={PERSONEL_DETAIL_ALLOWED_ROLES}>
              <PersonelDetayPage />
            </AuthGuard>
          }
        />
        <Route path="surecler" element={<SurecTakipPage />} />
        <Route
          path="surecler/:surecId"
          element={
            <AuthGuard allowedRoles={SUREC_DETAIL_ALLOWED_ROLES}>
              <SurecDetayPage />
            </AuthGuard>
          }
        />
        <Route path="bildirimler" element={<BildirimlerPage />} />
        <Route
          path="bildirimler/:bildirimId"
          element={
            <AuthGuard allowedRoles={BILDIRIM_DETAIL_ALLOWED_ROLES}>
              <BildirimDetayPage />
            </AuthGuard>
          }
        />
        <Route
          path="puantaj"
          element={
            <AuthGuard allowedRoles={PUANTAJ_ALLOWED_ROLES}>
              <GunlukPuantajPage />
            </AuthGuard>
          }
        />
        <Route
          path="haftalik-kapanis"
          element={
            <AuthGuard allowedRoles={HAFTALIK_KAPANIS_ALLOWED_ROLES}>
              <HaftalikKapanisPage />
            </AuthGuard>
          }
        />
        <Route
          path="raporlar"
          element={
            <AuthGuard allowedRoles={RAPORLAR_ALLOWED_ROLES}>
              <RaporlarPage />
            </AuthGuard>
          }
        />
        <Route
          path="finans"
          element={
            <AuthGuard allowedRoles={FINANS_ALLOWED_ROLES}>
              <FinansPage />
            </AuthGuard>
          }
        />
      </Route>

      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}
