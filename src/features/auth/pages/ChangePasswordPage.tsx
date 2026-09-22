import { FormEvent, useEffect, useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import { changePassword } from "../../../api/auth.api";
import { ApiRequestError } from "../../../api/api-client";
import { useAuth } from "../../../state/auth.store";

export function ChangePasswordPage() {
  const { session, isAuthenticated, clearMustChangePassword } = useAuth();
  const navigate = useNavigate();
  const [currentPassword, setCurrentPassword] = useState("");
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    document.body.classList.add("login-page");

    return () => {
      document.body.classList.remove("login-page");
    };
  }, []);

  if (!isAuthenticated || !session) {
    return <Navigate to="/login" replace />;
  }

  if (session.must_change_password !== true) {
    return <Navigate to="/" replace />;
  }

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setError(null);
    if (newPassword.length < 8) {
      setError("Yeni şifre en az 8 karakter olmalıdır.");
      return;
    }
    if (newPassword !== confirmPassword) {
      setError("Yeni şifre tekrarı eşleşmiyor.");
      return;
    }
    setSubmitting(true);
    try {
      await changePassword({ currentPassword, newPassword });
      clearMustChangePassword();
      navigate("/", { replace: true });
    } catch (err) {
      const message =
        err instanceof ApiRequestError
          ? err.message
          : err instanceof Error
            ? err.message
            : "Şifre güncellenemedi.";
      setError(message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="auth-login" aria-label="Şifre Belirleme" data-testid="change-password-page">
      <div className="auth-login-stage">
        <div className="auth-login-container">
          <h1 className="auth-change-password-title">Şifre Belirleme</h1>
          <p className="auth-muted auth-change-password-lead">
            Devam etmek için yeni şifrenizi belirleyin.
          </p>
          <form className="auth-login-form auth-change-password-form" onSubmit={(e) => void handleSubmit(e)}>
            <label className="auth-field-row">
              <span>Mevcut / geçici şifre</span>
              <input
                type="password"
                name="current_password"
                autoComplete="current-password"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                required
              />
            </label>
            <label className="auth-field-row">
              <span>Yeni şifre</span>
              <input
                type="password"
                name="new_password"
                autoComplete="new-password"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                minLength={8}
                required
              />
            </label>
            <label className="auth-field-row">
              <span>Yeni şifre (tekrar)</span>
              <input
                type="password"
                name="confirm_password"
                autoComplete="new-password"
                value={confirmPassword}
                onChange={(e) => setConfirmPassword(e.target.value)}
                minLength={8}
                required
              />
            </label>
            {error ? (
              <p className="auth-error" role="alert">
                {error}
              </p>
            ) : null}
            <button type="submit" className="universal-btn-save" disabled={submitting}>
              {submitting ? "Kaydediliyor…" : "Şifreyi Kaydet"}
            </button>
          </form>
        </div>
      </div>
    </section>
  );
}
