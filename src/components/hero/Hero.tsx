type HeroProps = {
  title: string;
};

export function Hero({ title }: HeroProps) {
  return (
    <section className="hero">
      <div className="hero-logo" aria-hidden="true" />
      <h1>{title}</h1>
      <div className="hero-spacer" aria-hidden="true" />
      <div className="animated-line" aria-hidden="true" />
    </section>
  );
}
