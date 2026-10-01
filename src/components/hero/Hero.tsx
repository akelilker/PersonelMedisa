import { useEffect, useState } from "react";
import logoDesktop from "../../assets/brand/logo-header2.svg";
import logoMobile from "../../assets/brand/logo-header2-mobile-header.svg";

/** Taşıt `.user-panel-link` dinlenme opaklığı; geçiş `color 0.2s, opacity 0.2s`. */
const PANEL_TITLE_DIM_DELAY_MS = 4000;

type HeroProps = {
  title: string;
  userLabel?: string | null;
  subeLabel?: string | null;
  /** Taşıt-style compact PERSONEL shell header (logo + title only). */
  variant?: "default" | "personel-shell";
  /** Second title line. Defaults on for the PERSONEL shell. */
  showPanelSubtitle?: boolean;
};

export function Hero({
  title,
  userLabel,
  subeLabel,
  variant = "default",
  showPanelSubtitle
}: HeroProps) {
  const trimmedUserLabel = userLabel?.trim() ?? "";
  const trimmedSubeLabel = subeLabel?.trim() ?? "";
  const showUserLabel = trimmedUserLabel.length > 0;
  const personelShell = variant === "personel-shell";
  const panelSubtitle = showPanelSubtitle ?? personelShell;
  const [titlesDimmed, setTitlesDimmed] = useState(false);

  useEffect(() => {
    if (!panelSubtitle) {
      setTitlesDimmed(false);
      return;
    }
    setTitlesDimmed(false);
    const timer = window.setTimeout(() => setTitlesDimmed(true), PANEL_TITLE_DIM_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [panelSubtitle]);

  const heroClassName = [
    "hero",
    personelShell ? "hero--personel-shell" : "",
    panelSubtitle ? "hero--panel-subtitle" : "",
    titlesDimmed ? "hero--titles-dimmed" : "",
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
        <h1 tabIndex={panelSubtitle ? 0 : undefined}>{title}</h1>
        {panelSubtitle ? (
          <p className="hero-panel-subtitle" data-testid="hero-panel-subtitle" tabIndex={0}>
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
