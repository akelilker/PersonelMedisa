import { NavLink } from "react-router-dom";
import { useAuth } from "../../state/auth.store";
import "./AppHeader.css";

export function AppHeader() {
  const { session, logout } = useAuth();

  return (
    <header className="app-header">
      <div className="app-header__brand">
        {/* Logo veya Şirket Adı Buraya Gelecek */}
        <h1 className="app-header__title">PersonelMedisa</h1>
      </div>
      <nav className="app-header__nav">
        <NavLink to="/surecler" className="btn btn-secondary">
          Kayıt ve Süreçler
        </NavLink>
        <NavLink to="/personeller" className="btn btn-secondary">
          Personel Kartları
        </NavLink>
        <NavLink to="/raporlar" className="btn btn-secondary">
          Raporlar
        </NavLink>
      </nav>
      {session ? (
        <div className="app-header__user-menu">
          <span className="app-header__user-name">{session.user.ad_soyad}</span>
          <button onClick={logout} className="btn-danger">
            Çıkış Yap
          </button>
        </div>
      ) : null}
    </header>
  );
}
