type UniversalButtonGroupProps = {
  onSave?: () => void;
  onCancel?: () => void;
  saveLabel?: string;
  cancelLabel?: string;
};

export function UniversalButtonGroup({
  onSave,
  onCancel,
  saveLabel = "Kaydet",
  cancelLabel = "Vazgec"
}: UniversalButtonGroupProps) {
  return (
    <div className="universal-btn-group">
      <button type="button" className="universal-btn-save" onClick={onSave}>
        {saveLabel}
      </button>
      <button type="button" className="universal-btn-cancel" onClick={onCancel}>
        {cancelLabel}
      </button>
    </div>
  );
}
