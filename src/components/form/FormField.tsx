import type { ReactNode } from "react";

export type FormFieldOption = { value: string; label: string };

type FormFieldBase = {
  label: string;
  name: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
  placeholder?: string;
  disabled?: boolean;
};

type FormFieldAsInput = FormFieldBase & {
  as?: "input";
  type?: "text" | "date" | "tel" | "number" | "month" | "time";
  min?: number | string;
  step?: string;
  rows?: never;
  selectOptions?: never;
  placeholderOption?: never;
};

type FormFieldAsSelect = FormFieldBase & {
  as: "select";
  selectOptions: FormFieldOption[];
  placeholderOption?: FormFieldOption;
  type?: never;
  min?: never;
  step?: never;
  rows?: never;
};

type FormFieldAsTextarea = FormFieldBase & {
  as: "textarea";
  rows?: number;
  type?: never;
  min?: never;
  step?: never;
  selectOptions?: never;
  placeholderOption?: never;
};

export type FormFieldProps = FormFieldAsInput | FormFieldAsSelect | FormFieldAsTextarea;

export function FormField(props: FormFieldProps) {
  const { label, name, value, onChange, required = false, placeholder, disabled = false } = props;

  let control: ReactNode;

  if (props.as === "textarea") {
    control = (
      <textarea
        id={name}
        name={name}
        className="form-input"
        rows={props.rows ?? 3}
        required={required}
        placeholder={placeholder}
        disabled={disabled}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  } else if (props.as === "select") {
    control = (
      <select
        id={name}
        name={name}
        className="form-input"
        required={required}
        disabled={disabled}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        {props.placeholderOption ? (
          <option value={props.placeholderOption.value}>{props.placeholderOption.label}</option>
        ) : null}
        {props.selectOptions.map((opt, index) => (
          <option key={`${name}-${index}-${opt.value}`} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
    );
  } else {
    control = (
      <input
        id={name}
        name={name}
        type={props.type ?? "text"}
        className="form-input"
        required={required}
        placeholder={placeholder}
        disabled={disabled}
        min={props.min}
        step={props.step}
        value={value}
        onChange={(event) => onChange(event.target.value)}
      />
    );
  }

  return (
    <div className="form-section">
      <label className="form-label" htmlFor={name}>
        {label}
      </label>
      {control}
    </div>
  );
}
