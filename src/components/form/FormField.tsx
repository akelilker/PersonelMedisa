import type {
  CompositionEventHandler,
  FormEventHandler,
  KeyboardEventHandler,
  ReactNode
} from "react";
import { AppSelect } from "./AppSelect";
import { AppDatePicker } from "./AppDatePicker";

export type FormFieldOption = { value: string; label: string };

type FormFieldBase = {
  label: string;
  /** Görsel olarak etiketsiz alanlar: etiket yalnız ekran okuyucuya kalır. */
  labelHidden?: boolean;
  name: string;
  value: string;
  onChange: (value: string) => void;
  required?: boolean;
  placeholder?: string;
  disabled?: boolean;
};

type FormFieldAsInput = FormFieldBase & {
  as?: "input";
  type?: "text" | "date" | "tel" | "number" | "month" | "time" | "password" | "search";
  onInvalid?: FormEventHandler<HTMLInputElement>;
  onKeyDown?: KeyboardEventHandler<HTMLInputElement>;
  onCompositionStart?: CompositionEventHandler<HTMLInputElement>;
  onCompositionEnd?: CompositionEventHandler<HTMLInputElement>;
  autoComplete?: string;
  maxLength?: number;
  dataTestId?: string;
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
  const {
    label,
    labelHidden = false,
    name,
    value,
    onChange,
    required = false,
    placeholder,
    disabled = false
  } = props;

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
      <AppSelect
        name={name}
        value={value}
        onChange={onChange}
        options={props.selectOptions}
        placeholderOption={props.placeholderOption}
        required={required}
        disabled={disabled}
        ariaLabel={label}
      />
    );
  } else if (props.type === "date") {
    // Tarih alanları kanonik temalı takvim owner'ından gelir; native beyaz
    // browser input'u ve native picker popup'ı kullanılmaz. Wire formatı ISO kalır.
    control = (
      <AppDatePicker
        name={name}
        value={value}
        onChange={onChange}
        placeholder={placeholder}
        required={required}
        disabled={disabled}
        min={typeof props.min === "string" ? props.min : undefined}
        dataTestId={props.dataTestId}
        onInvalid={props.onInvalid}
      />
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
        maxLength={props.maxLength}
        autoComplete={props.autoComplete}
        data-testid={props.dataTestId}
        value={value}
        onChange={(event) => onChange(event.target.value)}
        onInvalid={props.onInvalid}
        onKeyDown={props.onKeyDown}
        onCompositionStart={props.onCompositionStart}
        onCompositionEnd={props.onCompositionEnd}
      />
    );
  }

  return (
    <div className="form-section">
      <label className={labelHidden ? "form-label visually-hidden" : "form-label"} htmlFor={name}>
        {label}
      </label>
      {control}
    </div>
  );
}
