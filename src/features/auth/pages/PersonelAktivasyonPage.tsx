import { useEffect, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import { ApiRequestError } from "../../../api/api-client";
import {
  completePersonelActivation,
  fetchPersonelActivationStatus
} from "../../../api/personel-activation.api";
import {
  clearPersonelActivationLocationHash,
  extractPersonelActivationTokenFromHash
} from "../personel-aktivasyon-token";

const INVALID_LINK_MESSAGE =
  "Aktivasyon bağlantısı geçersiz veya süresi dolmuş. Yönetici veya İK ile iletişime geçerek yeni bağlantı isteyin.";

function mapStatusReasonToMessage(reason: string | undefined): string {
  if (reason === "expired" || reason === "revoked" || reason === "invalid" || reason === "consumed") {
    return INVALID_LINK_MESSAGE;
  }
  if (reason === "personel_inactive") {
    return "Personel kaydı aktif değil. Yönetici veya İK ile iletişime geçin.";
  }
  return INVALID_LINK_MESSAGE;
}

export function PersonelAktivasyonPage() {
  const [token, setToken] = useState<string | null>(null);
  const [tokenChecked, setTokenChecked] = useState(false);
  const [tokenValid, setTokenValid] = useState(false);
  const [newPassword, setNewPassword] = useState("");
  const [confirmPassword, setConfirmPassword] = useState("");
  const [showPassword, setShowPassword] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [activated, setActivated] = useState(false);

  useEffect(() => {
    document.body.classList.add("login-page");
    const meta = document.createElement("meta");
    meta.name = "referrer";
    meta.content = "no-referrer";
    document.head.appendChild(meta);

    return () => {
      document.body.classList.remove("login-page");
      meta.remove();
    };
  }, []);

  useEffect(() => {
    const captured = extractPersonelActivationTokenFromHash(window.location.hash);
    clearPersonelActivationLocationHash();
    setToken(captured);

    if (!captured) {
      setTokenChecked(true);
      setTokenValid(false);
      setFormError(INVALID_LINK_MESSAGE);
      return;
    }

    let cancelled = false;
    void (async () => {
      try {
        const status = await fetchPersonelActivationStatus(captured);
        if (cancelled) {
          return;
        }
        setTokenValid(status.valid);
        if (!status.valid) {
          setFormError(mapStatusReasonToMessage(status.reason));
        }
      } catch {
        if (!cancelled) {
          setTokenValid(false);
          setFormError(INVALID_LINK_MESSAGE);
        }
      } finally {
        if (!cancelled) {
          setTokenChecked(true);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, []);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!token || isSubmitting) {
      return;
    }

    setFormError(null);
    if (newPassword.length < 8) {
      setFormError("Yeni şifre en az 8 karakter olmalıdır.");
      return;
    }
    if (newPassword !== confirmPassword) {
      setFormError("Yeni şifre tekrarı eşleşmiyor.");
      return;
    }

    setIsSubmitting(true);
    try {
      const result = await completePersonelActivation({
        token,
        newPassword,
        newPasswordConfirmation: confirmPassword
      });
      if (result.activated) {
        setActivated(true);
        setToken(null);
        setNewPassword("");
        setConfirmPassword("");
      } else {
        setFormError(result.message ?? INVALID_LINK_MESSAGE);
      }
    } catch (error) {
      if (error instanceof ApiRequestError) {
        const code = error.code ?? "";
        if (
          code === "ACTIVATION_LINK_INVALID" ||
          /gecersiz|süresi|suresi|geçersiz|expired|revoked/i.test(error.message)
        ) {
          setFormError(INVALID_LINK_MESSAGE);
        } else {
          setFormError(error.message);
        }
      } else {
        setFormError(error instanceof Error ? error.message : "Aktivasyon tamamlanamadı.");
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  if (activated) {
    return (
      <section className="auth-login" aria-label="Personel aktivasyon" data-testid="personel-aktivasyon-page">
        <div className="auth-login-stage">
          <div className="auth-login-form">
            <p className="auth-success" role="status">
              Hesabınız başarıyla etkinleştirildi.
            </p>
            <Link to="/login" className="universal-btn-save" data-testid="personel-aktivasyon-login-link">
              Giriş Yap
            </Link>
          </div>
        </div>
      </section>
    );
  }

  return (
    <section className="auth-login" aria-label="Personel aktivasyon" data-testid="personel-aktivasyon-page">
      <div className="auth-login-stage">
        <form className="auth-login-form" onSubmit={(event) => void handleSubmit(event)}>
          <p className="auth-field">
            <span>Personel hesap aktivasyonu</span>
          </p>

          {!tokenChecked ? <p className="auth-muted">Bağlantı doğrulanıyor…</p> : null}

          {tokenChecked && tokenValid ? (
            <>
              <label className="auth-field">
                <span>Yeni Şifre</span>
                <input
                  type={showPassword ? "text" : "password"}
                  name="new_password"
                  autoComplete="new-password"
                  value={newPassword}
                  onChange={(event) => setNewPassword(event.target.value)}
                  minLength={8}
                  required
                />
              </label>

              <label className="auth-field">
                <span>Yeni Şifre Tekrar</span>
                <input
                  type={showPassword ? "text" : "password"}
                  name="new_password_confirmation"
                  autoComplete="new-password"
                  value={confirmPassword}
                  onChange={(event) => setConfirmPassword(event.target.value)}
                  minLength={8}
                  required
                />
              </label>

              <label className="auth-field auth-field-inline">
                <input
                  type="checkbox"
                  checked={showPassword}
                  onChange={(event) => setShowPassword(event.target.checked)}
                />
                <span>Şifreyi göster</span>
              </label>
            </>
          ) : null}

          {formError ? (
            <p className="auth-error" role="alert">
              {formError}
            </p>
          ) : null}

          {tokenChecked && tokenValid ? (
            <button
              type="submit"
              className="universal-btn-save"
              disabled={isSubmitting || newPassword.length === 0 || confirmPassword.length === 0}
            >
              {isSubmitting ? "Kaydediliyor…" : "Hesabı Etkinleştir"}
            </button>
          ) : null}

          {tokenChecked && !tokenValid ? (
            <Link to="/login" className="universal-btn-save">
              Giriş Sayfasına Dön
            </Link>
          ) : null}
        </form>
      </div>
    </section>
  );
}
