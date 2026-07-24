// @vitest-environment jsdom

import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppModal } from "../../src/components/modal/AppModal";

function collectTabCycle(dialog: HTMLElement, steps = 12): string[] {
  const names: string[] = [];

  for (let index = 0; index < steps; index += 1) {
    fireEvent.keyDown(document, { key: "Tab", shiftKey: false });
    const active = document.activeElement;
    if (!(active instanceof HTMLElement) || !dialog.contains(active) || active === dialog) {
      names.push(active === dialog ? "[dialog]" : "[outside]");
      continue;
    }

    names.push(active.getAttribute("aria-label") || active.textContent?.trim() || active.tagName);
  }

  return names;
}

describe("AppModal dialog accessibility", () => {
  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
  });

  it("exposes dialog semantics labelled by the title", async () => {
    render(
      <AppModal title="Personel Kartı" onClose={() => undefined}>
        <button type="button">İç aksiyon</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Personel Kartı" });
    expect(dialog.getAttribute("aria-modal")).toBe("true");
    const labelledBy = dialog.getAttribute("aria-labelledby");
    expect(labelledBy).toBeTruthy();
    expect(document.getElementById(labelledBy ?? "")?.textContent).toBe("Personel Kartı");
  });

  it("selects an explicit data-modal-initial-focus target", async () => {
    render(
      <AppModal title="Explicit Focus" onClose={() => undefined}>
        <button type="button">Önce</button>
        <input data-modal-initial-focus="true" aria-label="İsim" />
        <button type="button">Sonra</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Explicit Focus" });
    const target = screen.getByLabelText("İsim");

    await waitFor(() => {
      expect(document.activeElement).toBe(target);
    });
    expect(dialog.contains(document.activeElement)).toBe(true);
  });

  it("preserves React autoFocus when no explicit initial-focus is set", async () => {
    render(
      <AppModal title="AutoFocus" onClose={() => undefined}>
        <button type="button">Önce</button>
        <input autoFocus aria-label="Otomatik" />
        <button type="button">Sonra</button>
      </AppModal>
    );

    await screen.findByRole("dialog", { name: "AutoFocus" });
    const target = screen.getByLabelText("Otomatik");

    await waitFor(() => {
      expect(document.activeElement).toBe(target);
    });
  });

  it("focuses the dialog container when there is no explicit or autoFocus target", async () => {
    render(
      <AppModal title="Dialog Focus" onClose={() => undefined}>
        <button type="button">Birinci</button>
        <button type="button">İkinci</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Dialog Focus" });

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });
  });

  it("does not auto-focus the first focusable Sil button", async () => {
    render(
      <AppModal title="Silme" onClose={() => undefined}>
        <button type="button">Sil</button>
        <button type="button">İptal</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Silme" });
    const sil = screen.getByRole("button", { name: "Sil" });

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });
    expect(document.activeElement).not.toBe(sil);
  });

  it("Tab from dialog moves to the first focusable and Shift+Tab to the last", async () => {
    render(
      <AppModal title="Trap" onClose={() => undefined}>
        <button type="button">Birinci</button>
        <button type="button">İkinci</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Trap" });
    // DOM order: header close is first focusable; body "İkinci" is last.
    const first = screen.getByRole("button", { name: "Kapat" });
    const last = screen.getByRole("button", { name: "İkinci" });

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    fireEvent.keyDown(document, { key: "Tab", shiftKey: false });
    expect(document.activeElement).toBe(first);

    fireEvent.keyDown(document, { key: "Tab", shiftKey: true });
    expect(document.activeElement).toBe(last);

    last.focus();
    fireEvent.keyDown(document, { key: "Tab", shiftKey: false });
    expect(document.activeElement).toBe(first);

    first.focus();
    fireEvent.keyDown(document, { key: "Tab", shiftKey: true });
    expect(document.activeElement).toBe(last);
  });

  it("gives initial focus only to the topmost nested dialog", async () => {
    render(
      <>
        <AppModal title="Dış Modal" onClose={() => undefined}>
          <button type="button">Dış aksiyon</button>
        </AppModal>
        <AppModal title="İç Modal" onClose={() => undefined}>
          <button type="button" data-modal-initial-focus="true">
            İç hedef
          </button>
        </AppModal>
      </>
    );

    const outer = await screen.findByRole("dialog", { name: "Dış Modal" });
    const inner = await screen.findByRole("dialog", { name: "İç Modal" });
    const innerTarget = screen.getByRole("button", { name: "İç hedef" });

    await waitFor(() => {
      expect(document.activeElement).toBe(innerTarget);
    });
    expect(document.activeElement).not.toBe(outer);
    expect(inner.contains(document.activeElement)).toBe(true);
  });

  it("restores focus on close", async () => {
    const opener = document.createElement("button");
    opener.type = "button";
    opener.textContent = "Aç";
    document.body.appendChild(opener);
    opener.focus();

    function Harness({ open }: { open: boolean }) {
      return open ? (
        <AppModal title="Odak Testi" onClose={() => undefined}>
          <button type="button">Birinci</button>
        </AppModal>
      ) : null;
    }

    const view = render(<Harness open />);
    const dialog = await screen.findByRole("dialog", { name: "Odak Testi" });

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    view.rerender(<Harness open={false} />);

    await waitFor(() => {
      expect(document.activeElement).toBe(opener);
    });

    opener.remove();
  });

  it("Escape closes only the topmost nested modal", async () => {
    const onCloseOuter = vi.fn();
    const onCloseInner = vi.fn();

    render(
      <>
        <AppModal title="Dış Modal" onClose={onCloseOuter}>
          <button type="button">Dış aksiyon</button>
        </AppModal>
        <AppModal title="İç Modal" onClose={onCloseInner}>
          <button type="button">İç aksiyon</button>
        </AppModal>
      </>
    );

    await screen.findByRole("dialog", { name: "İç Modal" });
    fireEvent.keyDown(document, { key: "Escape" });

    expect(onCloseInner).toHaveBeenCalledTimes(1);
    expect(onCloseOuter).not.toHaveBeenCalled();
  });

  it("returns focus to the topmost dialog when focus moves outside", async () => {
    const outside = document.createElement("button");
    outside.type = "button";
    outside.textContent = "Dışarı";
    document.body.appendChild(outside);

    render(
      <AppModal title="Containment" onClose={() => undefined}>
        <button type="button">İç</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Containment" });
    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    outside.focus();

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    outside.remove();
  });

  it("nested containment returns focus to the top dialog only", async () => {
    render(
      <>
        <AppModal title="Dış Modal" onClose={() => undefined}>
          <button type="button">Dış aksiyon</button>
        </AppModal>
        <AppModal title="İç Modal" onClose={() => undefined}>
          <button type="button">İç aksiyon</button>
        </AppModal>
      </>
    );

    const outer = await screen.findByRole("dialog", { name: "Dış Modal" });
    const inner = await screen.findByRole("dialog", { name: "İç Modal" });
    const outerAction = screen.getByRole("button", { name: "Dış aksiyon" });

    await waitFor(() => {
      expect(document.activeElement).toBe(inner);
    });

    outerAction.focus();

    await waitFor(() => {
      expect(document.activeElement).toBe(inner);
    });
    expect(document.activeElement).not.toBe(outer);
    expect(document.activeElement).not.toBe(outerAction);
  });

  it("excludes hidden, aria-hidden, display:none, disabled and tabindex=-1 from Tab cycle", async () => {
    render(
      <AppModal title="Exclusion" onClose={() => undefined}>
        <button type="button">Görünür</button>
        <div hidden>
          <button type="button">HiddenAttr</button>
        </div>
        <div aria-hidden="true">
          <button type="button">AriaHidden</button>
        </div>
        <div style={{ display: "none" }}>
          <button type="button">DisplayNone</button>
        </div>
        <button type="button" disabled>
          Disabled
        </button>
        <button type="button" tabIndex={-1}>
          NegativeTab
        </button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Exclusion" });
    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    const cycle = collectTabCycle(dialog, 8);
    expect(cycle).toContain("Görünür");
    expect(cycle).toContain("Kapat");
    expect(cycle).not.toContain("HiddenAttr");
    expect(cycle).not.toContain("AriaHidden");
    expect(cycle).not.toContain("DisplayNone");
    expect(cycle).not.toContain("Disabled");
    expect(cycle).not.toContain("NegativeTab");
  });

  it("includes visible contenteditable in the Tab cycle", async () => {
    render(
      <AppModal title="Editable" onClose={() => undefined}>
        <div contentEditable="true" tabIndex={0} aria-label="Düzenlenebilir" />
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Editable" });
    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    const cycle = collectTabCycle(dialog, 6);
    expect(cycle).toContain("Düzenlenebilir");
  });

  it("falls back to dialog when explicit initial-focus target is disabled", async () => {
    render(
      <AppModal title="Unsafe Disabled" onClose={() => undefined}>
        <button type="button" data-modal-initial-focus="true" disabled>
          Disabled hedef
        </button>
        <button type="button">Diğer</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Unsafe Disabled" });

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });
    expect(document.activeElement).not.toBe(screen.getByRole("button", { name: "Disabled hedef" }));
  });

  it("falls back to dialog when explicit initial-focus target is hidden", async () => {
    render(
      <AppModal title="Unsafe Hidden" onClose={() => undefined}>
        <div hidden>
          <button type="button" data-modal-initial-focus="true">
            Hidden hedef
          </button>
        </div>
        <button type="button">Diğer</button>
      </AppModal>
    );

    const dialog = await screen.findByRole("dialog", { name: "Unsafe Hidden" });

    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });
  });

  it("restores focus by matching id when the opener node is replaced", async () => {
    function Harness({ open, openerKey }: { open: boolean; openerKey: string }) {
      return (
        <>
          <button id="modal-opener" type="button">
            {openerKey}
          </button>
          {open ? (
            <AppModal title="ID Restore" onClose={() => undefined}>
              <button type="button">İç</button>
            </AppModal>
          ) : null}
        </>
      );
    }

    const view = render(<Harness open={false} openerKey="Eski" />);
    const oldOpener = screen.getByRole("button", { name: "Eski" });
    oldOpener.focus();

    view.rerender(<Harness open openerKey="Eski" />);
    const dialog = await screen.findByRole("dialog", { name: "ID Restore" });
    await waitFor(() => {
      expect(document.activeElement).toBe(dialog);
    });

    view.rerender(<Harness open openerKey="Yeni" />);
    expect(screen.getByRole("button", { name: "Yeni" }).id).toBe("modal-opener");

    view.rerender(<Harness open={false} openerKey="Yeni" />);

    await waitFor(() => {
      expect(document.activeElement).toBe(screen.getByRole("button", { name: "Yeni" }));
    });
  });
});
