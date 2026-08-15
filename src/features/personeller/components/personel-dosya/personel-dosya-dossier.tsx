import type { ReactNode } from "react";

export function DossierField({
  label,
  value,
  valueClassName,
  missing = false
}: {
  label: string;
  value: string;
  valueClassName?: string;
  missing?: boolean;
}) {
  return (
    <div className={`personel-dosya-field${missing ? " is-missing" : ""}`} data-missing={missing ? "true" : undefined}>
      <span className="personel-dosya-field-label">{label}</span>
      <strong className={valueClassName ?? "personel-dosya-field-value"}>{value}</strong>
    </div>
  );
}

export function DossierRecord({
  label,
  value,
  missing = false
}: {
  label: string;
  value: string;
  missing?: boolean;
}) {
  return (
    <div className={`personel-dosya-record${missing ? " is-missing" : ""}`} data-missing={missing ? "true" : undefined}>
      <span className="personel-dosya-record-label">{label}</span>
      <span className="personel-dosya-record-value">{value}</span>
    </div>
  );
}

export function DossierSection({
  title,
  description,
  children
}: {
  title: string;
  description?: string;
  children: ReactNode;
}) {
  return (
    <section className="personel-dosya-section">
      <div className="personel-dosya-section-head">
        <h3>{title}</h3>
        {description ? <p>{description}</p> : null}
      </div>
      <div className="personel-dosya-record-list">{children}</div>
    </section>
  );
}
