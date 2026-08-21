// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppModal } from "../../src/components/modal/AppModal";

describe("AppModal footerPlacement", () => {
  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
  });

  it("keeps fixed footer as sibling outside modal-body by default", async () => {
    render(
      <AppModal
        title="Fixed Footer"
        onClose={() => undefined}
        footer={<button type="button">Kaydet</button>}
      >
        <p>İçerik</p>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Fixed Footer" });
    const body = dialog.querySelector(".modal-body");
    const footers = dialog.querySelectorAll(".modal-footer");
    expect(footers).toHaveLength(1);
    expect(footers[0]?.classList.contains("modal-footer--flow")).toBe(false);
    expect(body?.contains(footers[0] as Node)).toBe(false);
    expect(dialog.contains(footers[0] as Node)).toBe(true);
  });

  it("renders flow footer inside modal-body without fixed sibling", async () => {
    render(
      <AppModal
        title="Flow Footer"
        onClose={() => undefined}
        footerPlacement="flow"
        footer={
          <div data-testid="flow-actions">
            <button type="submit" form="demo-form">
              Kaydet
            </button>
            <button type="button">Vazgeç</button>
          </div>
        }
      >
        <form id="demo-form">
          <input aria-label="Alan" />
        </form>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Flow Footer" });
    const body = dialog.querySelector(".modal-body");
    const flowFooter = dialog.querySelector(".modal-footer.modal-footer--flow");
    const fixedFooters = Array.from(dialog.querySelectorAll(".modal-footer")).filter(
      (el) => !el.classList.contains("modal-footer--flow")
    );

    expect(flowFooter).not.toBeNull();
    expect(body?.contains(flowFooter as Node)).toBe(true);
    expect(fixedFooters).toHaveLength(0);
    expect(screen.getByRole("button", { name: "Kaydet" }).getAttribute("form")).toBe("demo-form");
  });

  it("preserves Vazgeç callback in flow footer", async () => {
    const onCancel = vi.fn();
    render(
      <AppModal
        title="Flow Cancel"
        onClose={() => undefined}
        footerPlacement="flow"
        footer={
          <button type="button" onClick={onCancel}>
            Vazgeç
          </button>
        }
      >
        <p>İçerik</p>
      </AppModal>
    );

    await screen.findByRole("dialog", { name: "Flow Cancel" });
    fireEvent.click(screen.getByRole("button", { name: "Vazgeç" }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });
});
