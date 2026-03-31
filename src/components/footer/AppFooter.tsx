import { useEffect, useState } from "react";
import footerLogo from "../../assets/brand/logo-footer.svg";

export function AppFooter() {
  const [isDimmed, setIsDimmed] = useState(true);
  const [isDelayed, setIsDelayed] = useState(false);
  const systemStatus: "ready" | "error" = "ready";

  useEffect(() => {
    setIsDimmed(true);
    setIsDelayed(false);

    const timeoutId = window.setTimeout(() => {
      setIsDelayed(true);
    }, 4000);

    return () => {
      window.clearTimeout(timeoutId);
    };
  }, []);

  const footerStateClasses = [isDimmed ? "dimmed" : "", isDelayed ? "delayed" : ""]
    .filter(Boolean)
    .join(" ");
  const statusClassName = `status ${systemStatus === "ready" ? "status-ready" : "status-error"}`;
  const statusLabel = systemStatus === "ready" ? "Sistem Hazir" : "Sistem Hata";

  return (
    <footer id="app-footer" className={footerStateClasses || undefined}>
      <div className="footer-content">
        <span className="version">v0.1</span>
        <span className="brand">
          <img src={footerLogo} alt="MEDISA" />
        </span>
        <span className={statusClassName}>
          <span className="status-dot" aria-hidden="true" />
          {statusLabel}
        </span>
      </div>
    </footer>
  );
}
