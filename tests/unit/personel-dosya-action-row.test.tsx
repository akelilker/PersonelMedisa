/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import {
  PersonelDosyaActionControls,
  PersonelDosyaActionRow
} from "../../src/features/personeller/components/personel-dosya/PersonelDosyaActionRow";

afterEach(() => {
  cleanup();
});

describe("PersonelDosyaActionRow", () => {
  it("shows only Süreçte İşlem Yap when canCreateSurec", () => {
    const onOpenSurecModal = vi.fn();

    render(
      <PersonelDosyaActionRow
        canAccessSurecler
        canCreateSurec
        isActionMenuOpen
        onToggleActionMenu={vi.fn()}
        onCloseActionMenu={vi.fn()}
        onOpenSurecModal={onOpenSurecModal}
        onOpenSurecHistory={vi.fn()}
      />
    );

    expect(screen.queryByRole("button", { name: "Kartı Düzenle" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Yeni Zimmet Ekle" })).toBeNull();
    const action = screen.getByTestId("personel-dosya-action-surecte-islem-yap");
    expect(action.textContent).toBe("Süreçte İşlem Yap");
    expect(action.hasAttribute("aria-haspopup")).toBe(false);
    expect(screen.queryByRole("button", { name: "İşlemler" })).toBeNull();

    fireEvent.click(action);
    expect(onOpenSurecModal).toHaveBeenCalledTimes(1);
  });

  it("shows Süreç Geçmişini Aç for view-only surec access", () => {
    render(
      <PersonelDosyaActionRow
        canAccessSurecler
        canCreateSurec={false}
        isActionMenuOpen
        onToggleActionMenu={vi.fn()}
        onCloseActionMenu={vi.fn()}
        onOpenSurecModal={vi.fn()}
        onOpenSurecHistory={vi.fn()}
      />
    );

    expect(screen.getByTestId("personel-dosya-action-surec-gecmisi")).toBeTruthy();
    expect(screen.queryByTestId("personel-dosya-action-surecte-islem-yap")).toBeNull();
    expect(screen.queryByRole("button", { name: "Kartı Düzenle" })).toBeNull();
    expect(screen.getByRole("button", { name: "Süreç Geçmişini Aç" }).hasAttribute("aria-haspopup")).toBe(false);
  });

  it("opens a menu when two actions are available", () => {
    const onToggle = vi.fn();
    const onSurec = vi.fn();
    const onHistory = vi.fn();
    const items = [
      { id: "surecte-islem-yap", label: "Süreçte İşlem Yap", onSelect: onSurec },
      { id: "surec-gecmisi", label: "Süreç Geçmişini Aç", onSelect: onHistory }
    ];

    const view = render(
      <PersonelDosyaActionControls items={items} isActionMenuOpen={false} onToggleActionMenu={onToggle} />
    );

    const menuButton = screen.getByRole("button", { name: "İşlemler" });
    expect(menuButton.getAttribute("aria-haspopup")).toBe("menu");
    fireEvent.click(menuButton);
    expect(onToggle).toHaveBeenCalledTimes(1);

    view.rerender(
      <PersonelDosyaActionControls items={items} isActionMenuOpen onToggleActionMenu={onToggle} />
    );
    expect(screen.getByRole("button", { name: "İşlemler" }).getAttribute("aria-expanded")).toBe("true");
    fireEvent.click(screen.getByTestId("personel-dosya-action-surecte-islem-yap"));
    expect(onSurec).toHaveBeenCalledTimes(1);
  });
});
