import logoDesktop from "../../assets/brand/logo-header2.svg";
import logoMobile from "../../assets/brand/logo-header2-mobile-header.svg";

type HeroProps = {
  title: string;
  userName?: string | null;
  subeLabel?: string | null;
};

export function Hero({ title, userName, subeLabel }: HeroProps) {
  const trimmedUserName = userName?.trim() ?? "";
  const trimmedSubeLabel = subeLabel?.trim() ?? "";
  const showSessionMeta = trimmedUserName.length > 0;

  return (
    <section className={`hero${showSessionMeta ? " hero-with-session" : ""}`}>
      <div className="hero-logo">
        <picture>
          <source media="(max-width: 640px)" srcSet={logoMobile} />
          <img src={logoDesktop} alt="MEDISA" />
        </picture>
        {showSessionMeta ? (
          <div className="hero-session-meta" aria-live="polite">
            <span className="hero-session-user">{trimmedUserName}</span>
            {trimmedSubeLabel ? <span className="hero-session-sube">{trimmedSubeLabel}</span> : null}
          </div>
        ) : null}
      </div>
      <h1>{title}</h1>
      <div className="hero-spacer" aria-hidden="true" />
      <div className="animated-line" aria-hidden="true" />
    </section>
  );
}
