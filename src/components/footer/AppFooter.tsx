import { useEffect, useState } from "react";
import footerLogo from "../../assets/brand/logo-footer.svg";

export function AppFooter() {
  const [isDimmed, setIsDimmed] = useState(true);
  const [isDelayed, setIsDelayed] = useState(false);
  const [online, setOnline] = useState(() =>
    typeof navigator !== "undefined" ? navigator.onLine : true
  );

  const systemStatus: "ready" | "error" = online ? "ready" : "error";

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

  useEffect(() => {
    function onOnline() {
      setOnline(true);
    }
    function onOffline() {
      setOnline(false);
    }

    window.addEventListener("online", onOnline);
    window.addEventListener("offline", onOffline);
    return () => {
      window.removeEventListener("online", onOnline);
      window.removeEventListener("offline", onOffline);
    };
  }, []);

  const footerStateClasses = [isDimmed ? "dimmed" : "", isDelayed ? "delayed" : ""]
    .filter(Boolean)
    .join(" ");
  const statusClassName = `status ${systemStatus === "ready" ? "status-ready" : "status-error"}`;
  const statusLabel = systemStatus === "ready" ? "Sistem Hazır" : "Bağlantı Yok";

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
