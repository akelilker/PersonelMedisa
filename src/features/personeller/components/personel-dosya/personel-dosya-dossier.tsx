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
      <span className="personel-dosya-field-label">
        {missing ? "⚠ " : ""}
        {label}
      </span>
      <strong className={valueClassName ?? "personel-dosya-field-value"}>{value}</strong>
      {missing ? <span className="personel-dosya-field-missing-hint">Bu bilgi eksik</span> : null}
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
      <span className="personel-dosya-record-label">
        {missing ? "⚠ " : ""}
        {label}
      </span>
      <span className="personel-dosya-record-value">{value}</span>
      {missing ? <span className="personel-dosya-field-missing-hint">Bu bilgi eksik</span> : null}
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
