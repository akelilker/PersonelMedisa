import logoDesktop from "../../assets/brand/logo-header2.svg";
import logoMobile from "../../assets/brand/logo-header2-mobile-header.svg";

type HeroProps = {
  title: string;
  userLabel?: string | null;
};

export function Hero({ title, userLabel }: HeroProps) {
  const trimmedUserLabel = userLabel?.trim() ?? "";
  const showUserLabel = trimmedUserLabel.length > 0;

  return (
    <section className={`hero${showUserLabel ? " hero-with-session" : ""}`}>
      <div className="hero-logo">
        <picture>
          <source media="(max-width: 640px)" srcSet={logoMobile} />
          <img src={logoDesktop} alt="MEDISA" />
        </picture>
        {showUserLabel ? (
          <div className="hero-session-meta" aria-live="polite">
            <span className="hero-session-user">{trimmedUserLabel}</span>
          </div>
        ) : null}
      </div>
      <h1>{title}</h1>
      <div className="hero-spacer" aria-hidden="true" />
      <div className="animated-line" aria-hidden="true" />
    </section>
  );
}
