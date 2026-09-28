import logoDesktop from "../../assets/brand/logo-header2.svg";
import logoMobile from "../../assets/brand/logo-header2-mobile-header.svg";

type HeroProps = {
  title: string;
  userLabel?: string | null;
  subeLabel?: string | null;
  /** Taşıt-style compact PERSONEL shell header (logo + title only). */
  variant?: "default" | "personel-shell";
};

export function Hero({ title, userLabel, subeLabel, variant = "default" }: HeroProps) {
  const trimmedUserLabel = userLabel?.trim() ?? "";
  const trimmedSubeLabel = subeLabel?.trim() ?? "";
  const showUserLabel = trimmedUserLabel.length > 0;
  const personelShell = variant === "personel-shell";

  const heroClassName = [
    "hero",
    personelShell ? "hero--personel-shell" : "",
    showUserLabel ? "hero-with-session" : ""
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <section className={heroClassName}>
      <div className="hero-logo">
        <picture>
          <source media="(max-width: 640px)" srcSet={logoMobile} />
          <img src={logoDesktop} alt="MEDISA" />
        </picture>
        {showUserLabel ? (
          <div className="hero-session-meta" aria-live="polite">
            <span
              className="hero-session-user"
              data-testid="hero-session-user"
              title={trimmedUserLabel}
            >
              {trimmedUserLabel}
            </span>
          </div>
        ) : null}
      </div>
      <div className="hero-title-stack">
        <h1>{title}</h1>
        {personelShell ? (
          <p className="hero-panel-subtitle" data-testid="hero-panel-subtitle">
            KULLANICI PANELİ
          </p>
        ) : null}
        <div className="animated-line" aria-hidden="true" />
      </div>
      {trimmedSubeLabel ? (
        <span
          className="hero-session-sube"
          data-testid="hero-session-sube"
          title={trimmedSubeLabel}
        >
          {trimmedSubeLabel}
        </span>
      ) : null}
      <div className="hero-spacer" aria-hidden="true" />
    </section>
  );
}
