import { useMemo } from "react";

type PersonelDosyaActionItem = {
  id: string;
  label: string;
  onSelect: () => void;
};

export function PersonelDosyaActionControls({
  items,
  isActionMenuOpen,
  onToggleActionMenu
}: {
  items: PersonelDosyaActionItem[];
  isActionMenuOpen: boolean;
  onToggleActionMenu: () => void;
}) {
  if (items.length === 0) {
    return null;
  }

  if (items.length === 1) {
    const item = items[0];
    return (
      <div className="personel-dosya-actions-row" data-testid="personel-dosya-actions-row">
        <div className="personel-dosya-actions-spacer" aria-hidden="true" />
        <button
          type="button"
          className="universal-btn-aux"
          data-testid={`personel-dosya-action-${item.id}`}
          onClick={item.onSelect}
        >
          {item.label}
        </button>
      </div>
    );
  }

  return (
    <div className="personel-dosya-actions-row" data-testid="personel-dosya-actions-row">
      <div className="personel-dosya-actions-spacer" aria-hidden="true" />
      <div className="personel-dosya-action-host">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={onToggleActionMenu}
          aria-haspopup="menu"
          aria-expanded={isActionMenuOpen}
        >
          İşlemler
        </button>
        <div className={`settings-dropdown personel-dosya-action-menu${isActionMenuOpen ? " open" : ""}`}>
          {items.map((item) => (
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
    const items: PersonelDosyaActionItem[] = [];

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

  return (
    <PersonelDosyaActionControls
      items={actionItems}
      isActionMenuOpen={isActionMenuOpen}
      onToggleActionMenu={onToggleActionMenu}
    />
  );
}
