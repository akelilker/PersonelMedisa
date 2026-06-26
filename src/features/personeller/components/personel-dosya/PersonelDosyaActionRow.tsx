import { useMemo } from "react";

export function PersonelDosyaActionRow({
  canEditPersonel,
  canCreateZimmet,
  canAccessSurecler,
  canCreateSurec,
  isActionMenuOpen,
  onToggleActionMenu,
  onCloseActionMenu,
  onStartEdit,
  onOpenZimmetCreate,
  onOpenSurecModal,
  onOpenSurecHistory
}: {
  canEditPersonel: boolean;
  canCreateZimmet: boolean;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isActionMenuOpen: boolean;
  onToggleActionMenu: () => void;
  onCloseActionMenu: () => void;
  onStartEdit: () => void;
  onOpenZimmetCreate: () => void;
  onOpenSurecModal: () => void;
  onOpenSurecHistory: () => void;
}) {
  const actionItems = useMemo(() => {
    const items: Array<{ id: string; label: string; onSelect: () => void }> = [];

    if (canCreateSurec) {
      items.push({
        id: "surec-ekle",
        label: "Süreç Ekle",
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

    if (canEditPersonel) {
      items.push({
        id: "duzenle",
        label: "Kartı Düzenle",
        onSelect: () => {
          onCloseActionMenu();
          onStartEdit();
        }
      });
    }

    if (canCreateZimmet) {
      items.push({
        id: "zimmet-ekle",
        label: "Yeni Zimmet Ekle",
        onSelect: () => {
          onCloseActionMenu();
          onOpenZimmetCreate();
        }
      });
    }

    return items;
  }, [
    canAccessSurecler,
    canCreateSurec,
    canCreateZimmet,
    canEditPersonel,
    onCloseActionMenu,
    onOpenSurecHistory,
    onOpenSurecModal,
    onOpenZimmetCreate,
    onStartEdit
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
            <button key={item.id} type="button" onClick={item.onSelect}>
              {item.label}
            </button>
          ))}
        </div>
      </div>
    </div>
  );
}
