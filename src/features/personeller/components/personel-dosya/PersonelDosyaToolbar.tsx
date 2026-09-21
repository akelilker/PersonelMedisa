export function PersonelDosyaToolbar({
  onOpenHistory,
  onOpenDocuments,
  onPrint
}: {
  onOpenHistory: () => void;
  onOpenDocuments: () => void;
  onPrint: () => void;
}) {
  return (
    <div
      className="personel-dosya-toolbar vehicle-detail-toolbar"
      data-testid="personel-dosya-toolbar"
      role="toolbar"
      aria-label="Personel kartı kısayolları"
    >
      <div className="personel-dosya-toolbar-spacer detail-toolbar-left" aria-hidden="true" />
      <div className="personel-dosya-toolbar-actions toolbar-right">
        <button
          type="button"
          className="personel-dosya-toolbar-btn vehicle-history-btn"
          data-testid="personel-dosya-toolbar-tarihce"
          onClick={onOpenHistory}
        >
          <span className="personel-dosya-toolbar-btn-label">Tarihçe</span>
        </button>
        <button
          type="button"
          className="personel-dosya-toolbar-btn vehicle-ruhsat-btn"
          data-testid="personel-dosya-toolbar-belge"
          onClick={onOpenDocuments}
        >
          <span className="personel-dosya-toolbar-btn-label">Belge</span>
        </button>
        <button
          type="button"
          className="personel-dosya-toolbar-btn vehicle-print-btn"
          data-testid="personel-dosya-toolbar-yazdir"
          onClick={onPrint}
        >
          <span className="personel-dosya-toolbar-btn-label">Yazdır</span>
        </button>
      </div>
    </div>
  );
}
