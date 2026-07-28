// @vitest-environment jsdom

import { useState } from "react";
import "@testing-library/jest-dom/vitest";
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppActionDialog } from "../../src/components/modal/AppActionDialog";
import { AppModal } from "../../src/components/modal/AppModal";

const BASE_PROPS = {
  open: true,
  title: "Kaydı İptal Et",
  description: "Bu kayıt iptal edilecektir.",
  confirmLabel: "İptal Et",
  testId: "action-dialog",
  onConfirm: () => undefined,
  onCancel: () => undefined
} as const;

describe("AppActionDialog", () => {
  afterEach(() => {
    cleanup();
    document.body.classList.remove("modal-open");
    delete document.body.dataset.modalOpenCount;
    vi.restoreAllMocks();
  });

  it("open=false iken render etmez", () => {
    render(<AppActionDialog {...BASE_PROPS} open={false} />);
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("başlığı görünür ve dialog adı olarak ilişkilendirir", async () => {
    render(<AppActionDialog {...BASE_PROPS} />);
    expect(await screen.findByRole("dialog", { name: "Kaydı İptal Et" })).toBeVisible();
    expect(screen.getByTestId("action-dialog-title")).toHaveTextContent("Kaydı İptal Et");
  });

  it("açıklamayı görünür ve aria-describedby ile ilişkilendirir", async () => {
    render(<AppActionDialog {...BASE_PROPS} />);
    const dialog = await screen.findByRole("dialog", { name: "Kaydı İptal Et" });
    const description = screen.getByTestId("action-dialog-description");

    expect(description).toHaveTextContent("Bu kayıt iptal edilecektir.");
    expect(dialog).toHaveAttribute("aria-describedby", description.id);
  });

  it("ilk odağı güvenli Vazgeç düğmesine verir", async () => {
    render(<AppActionDialog {...BASE_PROPS} />);
    const cancel = await screen.findByRole("button", { name: "Vazgeç" });
    await waitFor(() => expect(cancel).toHaveFocus());
  });

  it("Tab odağı dialog içinde tutar", async () => {
    render(<AppActionDialog {...BASE_PROPS} />);
    const dialog = await screen.findByRole("dialog");
    await waitFor(() => expect(screen.getByRole("button", { name: "Vazgeç" })).toHaveFocus());

    fireEvent.keyDown(document, { key: "Tab" });
    expect(dialog).toContainElement(document.activeElement as HTMLElement);
  });

  it("Shift+Tab odağı dialog içinde tutar", async () => {
    render(<AppActionDialog {...BASE_PROPS} />);
    const dialog = await screen.findByRole("dialog");
    await waitFor(() => expect(screen.getByRole("button", { name: "Vazgeç" })).toHaveFocus());

    fireEvent.keyDown(document, { key: "Tab", shiftKey: true });
    expect(dialog).toContainElement(document.activeElement as HTMLElement);
  });

  it("Escape ile onCancel çağırır", async () => {
    const onCancel = vi.fn();
    render(<AppActionDialog {...BASE_PROPS} onCancel={onCancel} />);
    await screen.findByRole("dialog");

    fireEvent.keyDown(document, { key: "Escape" });
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("Vazgeç düğmesi onCancel çağırır", async () => {
    const onCancel = vi.fn();
    render(<AppActionDialog {...BASE_PROPS} onCancel={onCancel} />);

    fireEvent.click(await screen.findByRole("button", { name: "Vazgeç" }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("onay düğmesi onConfirm çağırır", async () => {
    const onConfirm = vi.fn();
    render(<AppActionDialog {...BASE_PROPS} onConfirm={onConfirm} />);

    fireEvent.click(await screen.findByRole("button", { name: "İptal Et" }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it("hızlı çift onayı tek çağrıya kilitler", async () => {
    let resolveConfirm: (() => void) | undefined;
    const onConfirm = vi.fn(
      () =>
        new Promise<void>((resolve) => {
          resolveConfirm = resolve;
        })
    );
    render(<AppActionDialog {...BASE_PROPS} onConfirm={onConfirm} />);
    const confirm = await screen.findByRole("button", { name: "İptal Et" });

    fireEvent.click(confirm);
    fireEvent.click(confirm);
    expect(onConfirm).toHaveBeenCalledTimes(1);

    await act(async () => resolveConfirm?.());
  });

  it("isSubmitting sırasında onay düğmesini disabled yapar", async () => {
    render(<AppActionDialog {...BASE_PROPS} isSubmitting />);
    expect(await screen.findByRole("button", { name: "İptal Et" })).toBeDisabled();
  });

  it("submit sırasında cancel ve Escape kapanmasını engeller", async () => {
    const onCancel = vi.fn();
    render(<AppActionDialog {...BASE_PROPS} isSubmitting onCancel={onCancel} />);
    const cancel = await screen.findByRole("button", { name: "Vazgeç" });

    expect(cancel).toBeDisabled();
    expect(screen.queryByRole("button", { name: "Kapat" })).toBeNull();
    fireEvent.keyDown(document, { key: "Escape" });
    expect(onCancel).not.toHaveBeenCalled();
  });

  it("submit sırasında busy label gösterir", async () => {
    render(<AppActionDialog {...BASE_PROPS} isSubmitting submitLabel="İptal ediliyor..." />);
    expect(await screen.findByRole("button", { name: "İptal ediliyor..." })).toBeDisabled();
  });

  it("controlled textarea alanını label ile render eder", async () => {
    render(
      <AppActionDialog
        {...BASE_PROPS}
        field={{ label: "İptal açıklaması", value: "", onChange: () => undefined }}
      />
    );
    expect(await screen.findByRole("textbox", { name: "İptal açıklaması" })).toHaveValue("");
  });

  it("textarea değişimini field owner'a iletir", async () => {
    const onChange = vi.fn();
    render(
      <AppActionDialog
        {...BASE_PROPS}
        field={{ label: "İptal açıklaması", value: "", onChange }}
      />
    );

    fireEvent.change(await screen.findByRole("textbox", { name: "İptal açıklaması" }), {
      target: { value: "Yeni açıklama" }
    });
    expect(onChange).toHaveBeenCalledWith("Yeni açıklama");
  });

  it("required alan boşken onayı disabled yapar", async () => {
    render(
      <AppActionDialog
        {...BASE_PROPS}
        field={{ label: "Gerekçe", value: "", onChange: () => undefined, required: true }}
      />
    );
    expect(await screen.findByRole("button", { name: "İptal Et" })).toBeDisabled();
  });

  it("required alan whitespace iken onayı disabled yapar", async () => {
    render(
      <AppActionDialog
        {...BASE_PROPS}
        field={{ label: "Gerekçe", value: "   ", onChange: () => undefined, required: true }}
      />
    );
    expect(await screen.findByRole("button", { name: "İptal Et" })).toBeDisabled();
  });

  it("field ve action hatalarını erişilebilir alert olarak sunar", async () => {
    render(
      <AppActionDialog
        {...BASE_PROPS}
        errorMessage="API işlemi başarısız."
        errorTestId="action-error"
        field={{
          label: "Gerekçe",
          value: "metin",
          onChange: () => undefined,
          errorMessage: "Alan hatası."
        }}
      />
    );
    await screen.findByRole("dialog");
    expect(screen.getAllByRole("alert")).toHaveLength(2);
    expect(screen.getByTestId("action-error")).toHaveTextContent("API işlemi başarısız.");
    expect(screen.getByTestId("action-dialog-error")).toHaveTextContent("API işlemi başarısız.");
  });

  it("kapanınca odağı tetikleyiciye geri verir", async () => {
    function Harness() {
      const [open, setOpen] = useState(false);
      return (
        <>
          <button type="button" onClick={() => setOpen(true)}>
            Aç
          </button>
          <AppActionDialog {...BASE_PROPS} open={open} onCancel={() => setOpen(false)} />
        </>
      );
    }

    render(<Harness />);
    const opener = screen.getByRole("button", { name: "Aç" });
    opener.focus();
    fireEvent.click(opener);
    const cancel = await screen.findByRole("button", { name: "Vazgeç" });
    await waitFor(() => expect(cancel).toHaveFocus());
    fireEvent.click(cancel);
    await waitFor(() => expect(opener).toHaveFocus());
  });

  it("nested kullanımda Escape yalnız topmost action dialogu kapatır", async () => {
    const closeOuter = vi.fn();
    const closeInner = vi.fn();
    render(
      <>
        <AppModal title="Dış Modal" onClose={closeOuter}>
          Dış içerik
        </AppModal>
        <AppActionDialog {...BASE_PROPS} onCancel={closeInner} />
      </>
    );
    await screen.findByRole("dialog", { name: "Kaydı İptal Et" });

    fireEvent.keyDown(document, { key: "Escape" });
    expect(closeInner).toHaveBeenCalledTimes(1);
    expect(closeOuter).not.toHaveBeenCalled();
  });

  it("reject sonrası submit kilidini açar ve retry sağlar", async () => {
    const onConfirm = vi
      .fn<() => Promise<void>>()
      .mockRejectedValueOnce(new Error("ilk hata"))
      .mockResolvedValueOnce();
    render(<AppActionDialog {...BASE_PROPS} onConfirm={onConfirm} />);
    const confirm = await screen.findByRole("button", { name: "İptal Et" });

    fireEvent.click(confirm);
    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(1));
    fireEvent.click(confirm);
    await waitFor(() => expect(onConfirm).toHaveBeenCalledTimes(2));
  });

  it("textarea Enter yeni satır davranışını korur ve confirm etmez", async () => {
    const onConfirm = vi.fn();
    render(
      <AppActionDialog
        {...BASE_PROPS}
        onConfirm={onConfirm}
        field={{ label: "Açıklama", value: "satır", onChange: () => undefined }}
      />
    );
    const textarea = await screen.findByRole("textbox", { name: "Açıklama" });

    fireEvent.keyDown(textarea, { key: "Enter" });
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("pending promise sırasında unmount state-update uyarısı üretmez", async () => {
    let resolveConfirm: (() => void) | undefined;
    const consoleError = vi.spyOn(console, "error").mockImplementation(() => undefined);
    const view = render(
      <AppActionDialog
        {...BASE_PROPS}
        onConfirm={() =>
          new Promise<void>((resolve) => {
            resolveConfirm = resolve;
          })
        }
      />
    );

    fireEvent.click(await screen.findByRole("button", { name: "İptal Et" }));
    view.unmount();
    await act(async () => resolveConfirm?.());

    expect(
      consoleError.mock.calls.some((call) => String(call[0]).includes("state update"))
    ).toBe(false);
  });
});
