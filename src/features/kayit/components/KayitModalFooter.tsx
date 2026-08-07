type KayitModalFooterModel = {
  primaryLabel: string;
  primaryFormId: string;
  primaryDisabled: boolean;
  secondaryLabel?: string;
  onSecondaryClick?: () => void;
};

type KayitModalFooterProps = {
  model: KayitModalFooterModel | null;
};

export type { KayitModalFooterModel };

export function KayitModalFooter({ model }: KayitModalFooterProps) {
  if (!model) {
    return null;
  }

  return (
    <div
      className="universal-btn-group workspace-form-actions modal-footer-actions"
      data-testid="kayit-modal-footer"
    >
      <button
        type="submit"
        form={model.primaryFormId}
        className="universal-btn-save"
        disabled={model.primaryDisabled}
        data-testid="kayit-modal-footer-primary"
      >
        {model.primaryLabel}
      </button>
      {model.secondaryLabel && model.onSecondaryClick ? (
        <button
          type="button"
          className="universal-btn-cancel"
          onClick={model.onSecondaryClick}
          data-testid="kayit-modal-footer-secondary"
        >
          {model.secondaryLabel}
        </button>
      ) : null}
    </div>
  );
}
