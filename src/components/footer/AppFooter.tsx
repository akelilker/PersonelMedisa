import footerLogo from "../../assets/brand/logo-footer.svg";

export function AppFooter() {
  return (
    <footer id="app-footer">
      <div className="footer-content">
        <span className="version">v0.1</span>
        <span className="brand">
          <img src={footerLogo} alt="MEDISA" />
        </span>
        <span className="status status-ready">
          <span className="status-dot" aria-hidden="true" />
          Sistem Hazir
        </span>
      </div>
    </footer>
  );
}
