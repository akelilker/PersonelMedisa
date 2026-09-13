type KayitModalFooterModel = {
  primaryLabel: string;
  primaryFormId: string;
  primaryDisabled: boolean;
  secondaryLabel?: string;
  onSecondaryClick?: () => void;
  /** Shown above Kaydet on Kayıt tab — opens Toplu Kayıt Aktarma. */
  onOpenBulkImport?: () => void;
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
    <div className="kayit-modal-footer-stack" data-testid="kayit-modal-footer">
      {model.onOpenBulkImport ? (
        <p className="kayit-bulk-import-hint">
          <span className="kayit-bulk-import-hint-text">Excel&apos;den Toplu Kayıt Aktarmak İçin </span>
          <button
            type="button"
            className="kayit-bulk-import-link"
            data-testid="kayit-bulk-import-link"
            onClick={model.onOpenBulkImport}
          >
            Tıklayınız.
          </button>
        </p>
      ) : null}
      <div className="universal-btn-group workspace-form-actions modal-footer-actions">
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
    </div>
  );
}
