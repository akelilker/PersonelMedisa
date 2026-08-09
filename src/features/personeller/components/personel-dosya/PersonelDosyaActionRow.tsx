import { useMemo } from "react";

export function PersonelDosyaActionRow({
  canAccessSurecler,
  canCreateSurec,
  isActionMenuOpen,
  onToggleActionMenu,
  onCloseActionMenu,
  onOpenSurecModal,
  onOpenSurecHistory
}: {
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isActionMenuOpen: boolean;
  onToggleActionMenu: () => void;
  onCloseActionMenu: () => void;
  onOpenSurecModal: () => void;
  onOpenSurecHistory: () => void;
}) {
  const actionItems = useMemo(() => {
    const items: Array<{ id: string; label: string; onSelect: () => void }> = [];

    if (canCreateSurec) {
      items.push({
        id: "surecte-islem-yap",
        label: "Süreçte İşlem Yap",
        onSelect: () => {
          onCloseActionMenu();
          onOpenSurecModal();
        }
      });
    } else if (canAccessSurecler) {
      items.push({
        id: "surec-gecmisi",
        label: "Süreç Geçmişini Aç",
        onSelect: () => {
          onCloseActionMenu();
          onOpenSurecHistory();
        }
      });
    }

    return items;
  }, [
    canAccessSurecler,
    canCreateSurec,
    onCloseActionMenu,
    onOpenSurecHistory,
    onOpenSurecModal
  ]);

  if (actionItems.length === 0) {
    return null;
  }

  return (
    <div className="personel-dosya-actions-row">
      <div className="personel-dosya-actions-spacer" aria-hidden="true" />
      <div className="personel-dosya-action-host">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={onToggleActionMenu}
          aria-expanded={isActionMenuOpen}
        >
          Islemler
        </button>
        <div className={`settings-dropdown personel-dosya-action-menu${isActionMenuOpen ? " open" : ""}`}>
          {actionItems.map((item) => (
            <button
              key={item.id}
              type="button"
              data-testid={`personel-dosya-action-${item.id}`}
              onClick={item.onSelect}
            >
              {item.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
