type FormFieldProps = {
  label: string;
  name: string;
  type?: "text" | "date" | "tel";
  required?: boolean;
  placeholder?: string;
};

export function FormField({
  label,
  name,
  type = "text",
  required = false,
  placeholder
}: FormFieldProps) {
  return (
    <div className="form-section">
      <label className="form-label" htmlFor={name}>
        {label}
      </label>
      <input
        id={name}
        name={name}
        type={type}
        required={required}
        placeholder={placeholder}
        className="form-input"
      />
    </div>
  );
}
