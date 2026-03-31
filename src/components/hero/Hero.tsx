import logoDesktop from "../../assets/brand/logo-header2.svg";
import logoMobile from "../../assets/brand/logo-header2-mobile-header.svg";

type HeroProps = {
  title: string;
};

export function Hero({ title }: HeroProps) {
  return (
    <section className="hero">
      <div className="hero-logo">
        <picture>
          <source media="(max-width: 640px)" srcSet={logoMobile} />
          <img src={logoDesktop} alt="MEDISA" />
        </picture>
      </div>
      <h1>{title}</h1>
      <div className="hero-spacer" aria-hidden="true" />
      <div className="animated-line" aria-hidden="true" />
    </section>
  );
}
