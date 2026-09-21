import type { ReactNode } from "react";
import { PERSONEL_DOSYA_MISSING_FIELD_HINT } from "./personel-dosya-missing-copy";

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
      {missing ? (
        <span className="personel-dosya-field-missing-hint">{PERSONEL_DOSYA_MISSING_FIELD_HINT}</span>
      ) : null}
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
      {missing ? (
        <span className="personel-dosya-field-missing-hint">{PERSONEL_DOSYA_MISSING_FIELD_HINT}</span>
      ) : null}
    </div>
  );
}

export function DossierSection({
  title,
  description,
  children,
  denseGrid = false
}: {
  title: string;
  description?: string;
  children: ReactNode;
  denseGrid?: boolean;
}) {
  return (
    <section
      className={`personel-dosya-section${denseGrid ? " personel-dosya-section--dense-grid" : ""}`}
    >
      <div className="personel-dosya-section-head">
        <h3>{title}</h3>
        {description ? <p>{description}</p> : null}
      </div>
      <div className="personel-dosya-record-list">{children}</div>
    </section>
  );
}
