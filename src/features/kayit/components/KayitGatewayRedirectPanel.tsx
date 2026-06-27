type KayitGatewayRedirectPanelProps = {
  infoMessage: string;
  actionLabel: string;
  onReturn: () => void;
  onClose: () => void;
};

export function KayitGatewayRedirectPanel({
  infoMessage,
  actionLabel,
  onReturn,
  onClose
}: KayitGatewayRedirectPanelProps) {
  return (
    <>
      <p className="workspace-success">{infoMessage}</p>
      <div className="universal-btn-group workspace-form-actions">
        <button type="button" className="universal-btn-save" onClick={onReturn}>
          {actionLabel}
        </button>
        <button type="button" className="universal-btn-cancel" onClick={onClose}>
          Kapat
        </button>
      </div>
    </>
  );
}
