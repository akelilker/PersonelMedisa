import { useId, useRef } from "react";
import { FormField } from "../form/FormField";
import { AppModal } from "./AppModal";

export type AppActionDialogField = {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
  required?: boolean;
  helpText?: string;
  rows?: number;
  errorMessage?: string | null;
  testId?: string;
};

type AppActionDialogProps = {
  open: boolean;
  title: string;
  description?: string;
  confirmLabel: string;
  cancelLabel?: string;
  destructive?: boolean;
  isSubmitting?: boolean;
  submitLabel?: string;
  field?: AppActionDialogField;
  errorMessage?: string | null;
  errorTestId?: string;
  testId?: string;
  onConfirm: () => void | Promise<void>;
  onCancel: () => void;
};

export function AppActionDialog({
  open,
  title,
  description,
  confirmLabel,
  cancelLabel = "Vazgeç",
  destructive = false,
  isSubmitting = false,
  submitLabel,
  field,
  errorMessage,
  errorTestId,
  testId,
  onConfirm,
  onCancel
}: AppActionDialogProps) {
  const descriptionId = useId();
  const submitLockRef = useRef(false);
  const requiredFieldIsEmpty = Boolean(field?.required && !field.value.trim());

  if (!open) {
    return null;
  }

  async function handleConfirm() {
    if (isSubmitting || requiredFieldIsEmpty || submitLockRef.current) {
      return;
    }

    submitLockRef.current = true;
    try {
      await onConfirm();
    } catch {
      // Error presentation and retry state remain controlled by the action owner.
    } finally {
      submitLockRef.current = false;
    }
  }

  const guardedCancel = isSubmitting ? undefined : onCancel;

  return (
    <AppModal
      title={title}
      ariaDescribedBy={description ? descriptionId : undefined}
      titleTestId={testId ? `${testId}-title` : undefined}
      onClose={guardedCancel}
      footer={
        <div className="universal-btn-group modal-footer-actions app-action-dialog-actions">
          <button
            type="button"
            className="universal-btn-aux"
            data-modal-initial-focus="true"
            data-testid={testId ? `${testId}-cancel` : undefined}
            disabled={isSubmitting}
            onClick={onCancel}
          >
            {cancelLabel}
          </button>
          <button
            type="button"
            className={destructive ? "universal-btn-cancel" : "universal-btn-save"}
            data-testid={testId ? `${testId}-confirm` : undefined}
            disabled={isSubmitting || requiredFieldIsEmpty}
            onClick={() => void handleConfirm()}
          >
            {isSubmitting && submitLabel ? submitLabel : confirmLabel}
          </button>
        </div>
      }
    >
      <div data-testid={testId}>
        {description ? (
          <p id={descriptionId} data-testid={testId ? `${testId}-description` : undefined}>
            {description}
          </p>
        ) : null}
        {field ? (
          <div data-testid={field.testId}>
            <FormField
              as="textarea"
              label={field.label}
              name={testId ? `${testId}-input` : "app-action-dialog-input"}
              value={field.value}
              onChange={field.onChange}
              placeholder={field.placeholder}
              required={field.required}
              disabled={isSubmitting}
              rows={field.rows}
            />
            {field.helpText ? <p>{field.helpText}</p> : null}
            {field.errorMessage ? (
              <p className="workspace-error" role="alert">
                {field.errorMessage}
              </p>
            ) : null}
          </div>
        ) : null}
        {errorMessage ? (
          <p className="workspace-error" role="alert" data-testid={errorTestId}>
            <span data-testid={testId ? `${testId}-error` : undefined}>{errorMessage}</span>
          </p>
        ) : null}
      </div>
    </AppModal>
  );
}
