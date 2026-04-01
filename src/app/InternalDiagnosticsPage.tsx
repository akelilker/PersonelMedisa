import { Link, Navigate } from "react-router-dom";
import { getAppEnv, getAppVersion } from "../config/app-env";
import { getRecentApiFailures, getRecentClientErrors } from "../logging/error-logger";

export function InternalDiagnosticsPage() {
  const enabled =
    import.meta.env.DEV === true || import.meta.env.VITE_ENABLE_DIAGNOSTICS === "true";

  if (!enabled) {
    return <Navigate to="/" replace />;
  }

  const errors = [...getRecentClientErrors()].slice(-15).reverse();
  const apiFails = [...getRecentApiFailures()].slice(-15).reverse();

  return (
    <section className="states-page">
      <h2>Dahili teshis</h2>
      <p>
        Surum: {getAppVersion()} · Ortam: {getAppEnv()}
      </p>
      <p>
        <Link to="/">Ana ekrana don</Link>
      </p>

      <h3>Son istemci hatalari</h3>
      {errors.length === 0 ? (
        <p>—</p>
      ) : (
        <ul>
          {errors.map((e, i) => (
            <li key={`${e.timestamp}-${i}`}>
              <code>{e.timestamp}</code> — {e.message} — {e.route}
            </li>
          ))}
        </ul>
      )}

      <h3>Son API 5xx kayitlari</h3>
      {apiFails.length === 0 ? (
        <p>—</p>
      ) : (
        <ul>
          {apiFails.map((e, i) => (
            <li key={`${e.timestamp}-${i}`}>
              <code>{e.timestamp}</code> — {e.method} {e.endpoint} — {e.status}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
