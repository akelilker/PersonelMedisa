/** @vitest-environment jsdom */
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { PersonelDosyaActionRow } from "../../src/features/personeller/components/personel-dosya/PersonelDosyaActionRow";

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
    expect(screen.getByTestId("personel-dosya-action-surecte-islem-yap")).toBeTruthy();

    fireEvent.click(screen.getByTestId("personel-dosya-action-surecte-islem-yap"));
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
  });
});
