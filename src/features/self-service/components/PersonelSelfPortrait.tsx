type Props = {
  name: string;
  photoSrc: string | null;
};

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  return parts
    .slice(0, 2)
    .map((part) => part.charAt(0).toLocaleUpperCase("tr-TR"))
    .join("");
}

/** Real portrait or a letter mark. Never a stock face image. */
export function PersonelSelfPortrait({ name, photoSrc }: Props) {
  if (photoSrc) {
    return (
      <img className="pm-self-portrait" src={photoSrc} alt="" data-testid="personel-self-photo" />
    );
  }
  const mark = initials(name);
  if (!mark) {
    return null;
  }
  return (
    <span className="pm-self-portrait pm-self-portrait--empty" data-testid="personel-self-photo-fallback" aria-hidden="true">
      {mark}
    </span>
  );
}
