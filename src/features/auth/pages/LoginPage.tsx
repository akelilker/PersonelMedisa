import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Navigate, useLocation, useNavigate } from "react-router-dom";
import { useAuth } from "../../../state/auth.store";

type LoginLocationState = {
  from?: string;
};

export function LoginPage() {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [rememberMe, setRememberMe] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const { isAuthenticated, login } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  const redirectPath = useMemo(() => {
    const state = location.state as LoginLocationState | null;
    return state?.from ?? "/";
  }, [location.state]);

  useEffect(() => {
    document.body.classList.add("login-page");

    return () => {
      document.body.classList.remove("login-page");
    };
  }, []);

  if (isAuthenticated) {
    return <Navigate to={redirectPath} replace />;
  }

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (isSubmitting) {
      return;
    }

    setFormError(null);
    setIsSubmitting(true);

    try {
      await login({
        username: username.trim(),
        password
      });
      navigate(redirectPath, { replace: true });
    } catch (error) {
      setFormError(error instanceof Error ? error.message : "Giriş sırasında bir hata oluştu.");
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <section className="auth-login" aria-label="Giriş">
      <div className="auth-login-stage">
        <form className="auth-login-form" onSubmit={handleLogin}>
          <label className="auth-field">
            <span>Kullanıcı Adı</span>
            <input
              type="text"
              name="username"
              autoComplete="username"
              value={username}
              onChange={(event) => setUsername(event.target.value)}
              required
            />
          </label>

          <label className="auth-field">
            <span>Şifre</span>
            <input
              type="password"
              name="password"
              autoComplete="current-password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              required
            />
          </label>

          <label className="auth-field auth-field-inline">
            <input
              type="checkbox"
              name="rememberMe"
              checked={rememberMe}
              onChange={(event) => setRememberMe(event.target.checked)}
            />
            <span>Beni hatırla</span>
          </label>

          {formError ? <p className="auth-error">{formError}</p> : null}

          <button
            type="submit"
            className="universal-btn-save"
            disabled={isSubmitting || username.trim().length === 0 || password.length === 0}
          >
            {isSubmitting ? "Giriş Yapılıyor..." : "Giriş Yap"}
          </button>
        </form>
      </div>
    </section>
  );
}
