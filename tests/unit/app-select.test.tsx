// @vitest-environment jsdom

import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { AppSelect, AppSelectField } from "../../src/components/form/AppSelect";
import {
  APP_PICKER_BODY_ATTR,
  APP_PICKER_BLUR_CLASS,
  resetAppPickerOwner
} from "../../src/components/form/app-picker-layer";

const OPTIONS = [
  { value: "1", label: "Merkez" },
  { value: "2", label: "Depolama" }
];

function renderModalShell(control: React.ReactNode) {
  return render(
    <div className="modal-container">
      <div className="modal-header">Kayıt ve Süreç İşlemleri</div>
      <div className="modal-body">
        <div className="form-section" data-testid="other-section">
          <label className="form-label" htmlFor="other-input">
            Diğer
          </label>
          <input id="other-input" className="form-input" />
        </div>
        {control}
      </div>
    </div>
  );
}

function appSelect(overrides: Partial<React.ComponentProps<typeof AppSelect>> = {}) {
  return (
    <div className="form-section">
      <label className="form-label" htmlFor="sube">
        Şube
      </label>
      <AppSelect name="sube" value="" onChange={() => undefined} options={OPTIONS} {...overrides} />
    </div>
  );
}

/** jsdom CSS uygulamadığı için native <option>'lar da role=option olur; sorgular panel içinde kapsanır. */
function appPickerPanel(): HTMLElement {
  const panel = document.querySelector('[data-app-select-panel="1"]');
  if (!(panel instanceof HTMLElement)) {
    throw new Error("Kanonik picker paneli bulunamadı.");
  }

  return panel;
}

afterEach(() => {
  cleanup();
  resetAppPickerOwner();
  document.body.removeAttribute(APP_PICKER_BODY_ATTR);
});

describe("AppSelect canonical picker", () => {
  it("keeps the native select as the labelled value owner", () => {
    renderModalShell(appSelect());

    const select = screen.getByLabelText("Şube");
    expect(select.tagName).toBe("SELECT");
    expect(select.getAttribute("name")).toBe("sube");
    expect(select.getAttribute("aria-expanded")).toBe("false");

    const trigger = document.querySelector('[data-app-select-trigger="1"]');
    expect(trigger).not.toBeNull();
    expect(trigger?.getAttribute("aria-hidden")).toBe("true");
    expect(trigger?.querySelector(".app-select-chevron")).not.toBeNull();
    expect(trigger?.textContent).toContain("Seçiniz");
  });

  it("opens into the shared layer, blurs the rest of the modal and keeps the field sharp", () => {
    renderModalShell(appSelect());
    const select = screen.getByLabelText("Şube");

    fireEvent.click(select);

    expect(select.getAttribute("aria-expanded")).toBe("true");
    expect(document.body.getAttribute(APP_PICKER_BODY_ATTR)).toBe("1");

    const panel = document.querySelector('[data-app-select-panel="1"]');
    expect(panel).not.toBeNull();
    expect(panel?.getAttribute("role")).toBe("listbox");
    // Panel picker'ın kendi ağacında kalır: blur dışında ve modal'a scope'lu sorgularla bulunur.
    expect(panel?.closest(".app-select")).not.toBeNull();

    // Blur: picker dışı yüzey bulanır, picker'ın kendi alanı ve etiketi net kalır.
    expect(document.querySelector(".modal-header")?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(true);
    expect(
      document.querySelector('[data-testid="other-section"]')?.classList.contains(APP_PICKER_BLUR_CLASS)
    ).toBe(true);
    const fieldSection = document.querySelector(".modal-body .form-section:last-child");
    expect(fieldSection?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(false);
    expect(document.querySelector('label[for="sube"]')?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(false);
    expect(document.querySelector(".app-select")?.classList.contains(APP_PICKER_BLUR_CLASS)).toBe(false);
  });

  it("commits the option value, closes and removes the blur", () => {
    const onChange = vi.fn();
    renderModalShell(appSelect({ onChange }));
    const select = screen.getByLabelText("Şube");

    fireEvent.click(select);
    fireEvent.click(within(appPickerPanel()).getByRole("option", { name: "Depolama" }));

    expect(onChange).toHaveBeenCalledWith("2");
    expect(select.getAttribute("aria-expanded")).toBe("false");
    expect(document.body.hasAttribute(APP_PICKER_BODY_ATTR)).toBe(false);
    expect(document.querySelectorAll(`.${APP_PICKER_BLUR_CLASS}`)).toHaveLength(0);
    expect(document.querySelector('[data-app-select-panel="1"]')).toBeNull();
  });

  it("closes on outside click and on Escape without leaking Escape to the modal owner", async () => {
    renderModalShell(appSelect());
    const select = screen.getByLabelText("Şube");
    const modalEscape = vi.fn();
    document.addEventListener("keydown", modalEscape);

    fireEvent.click(select);
    await act(async () => {
      document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    });
    expect(select.getAttribute("aria-expanded")).toBe("false");

    fireEvent.click(select);
    expect(select.getAttribute("aria-expanded")).toBe("true");
    fireEvent.keyDown(select, { key: "Escape" });
    expect(select.getAttribute("aria-expanded")).toBe("false");
    expect(modalEscape).not.toHaveBeenCalled();

    document.removeEventListener("keydown", modalEscape);
  });

  it("supports keyboard navigation and selection", () => {
    const onChange = vi.fn();
    renderModalShell(appSelect({ onChange }));
    const select = screen.getByLabelText("Şube");

    fireEvent.keyDown(select, { key: "ArrowDown" });
    expect(select.getAttribute("aria-expanded")).toBe("true");

    fireEvent.keyDown(select, { key: "ArrowDown" });
    fireEvent.keyDown(select, { key: "ArrowDown" });
    fireEvent.keyDown(select, { key: "Enter" });

    expect(onChange).toHaveBeenCalledWith("2");
    expect(select.getAttribute("aria-expanded")).toBe("false");
  });

  it("closes the previous picker when a second one opens", () => {
    render(
      <div className="modal-container">
        <div className="modal-body">
          {appSelect({ name: "sube", id: "sube" })}
          <div className="form-section">
            <label className="form-label" htmlFor="departman">
              Departman
            </label>
            <AppSelect
              name="departman"
              value=""
              onChange={() => undefined}
              options={OPTIONS}
            />
          </div>
        </div>
      </div>
    );

    const sube = screen.getByLabelText("Şube");
    const departman = screen.getByLabelText("Departman");

    fireEvent.click(sube);
    expect(sube.getAttribute("aria-expanded")).toBe("true");

    fireEvent.click(departman);
    expect(departman.getAttribute("aria-expanded")).toBe("true");
    expect(sube.getAttribute("aria-expanded")).toBe("false");
    expect(document.querySelectorAll('[data-app-select-panel="1"]')).toHaveLength(1);
  });

  it("keeps the native change contract and stays closed when disabled", () => {
    const onChange = vi.fn();
    renderModalShell(appSelect({ onChange }));
    const select = screen.getByLabelText("Şube");

    fireEvent.change(select, { target: { value: "1" } });
    expect(onChange).toHaveBeenCalledWith("1");

    cleanup();
    renderModalShell(appSelect({ disabled: true }));
    const disabledSelect = screen.getByLabelText("Şube");
    fireEvent.click(disabledSelect);
    expect(disabledSelect.getAttribute("aria-expanded")).toBe("false");
  });

  it("renders the field wrapper with a real label, no duplicate placeholder card and aria-controls", () => {
    render(
      <div className="modal-container">
        <div className="modal-body">
          <AppSelectField
            label="Çalışan Kapsamı"
            name="create-calisan-kapsami"
            value=""
            onChange={() => undefined}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            options={[{ value: "IC_PERSONEL", label: "İç Kaynak" }]}
          />
        </div>
      </div>
    );

    const select = screen.getByLabelText("Çalışan Kapsamı");
    expect(select.getAttribute("aria-expanded")).toBe("false");
    fireEvent.click(select);

    // Placeholder yalnız trigger metnidir: panelde ikinci bir "Seçiniz" kartı yoktur.
    expect(document.querySelector('[data-app-select-trigger="1"]')?.textContent).toContain("Seçiniz");
    expect(within(appPickerPanel()).queryByRole("option", { name: "Seçiniz" })).toBeNull();
    expect(within(appPickerPanel()).getByRole("option", { name: "İç Kaynak" })).not.toBeNull();
    // Boş değerde temizleme satırı gösterilmez (temizlenecek seçim yok).
    expect(document.querySelector('[data-app-select-clear="1"]')).toBeNull();
    expect(select.getAttribute("aria-controls")).toBe(
      document.querySelector('[data-app-select-panel="1"]')?.getAttribute("id")
    );
  });

  it("keeps the optional clear capability as a canonical clear row (not a placeholder card)", () => {
    const onChange = vi.fn();
    render(
      <div className="modal-container">
        <div className="modal-body">
          <AppSelectField
            label="Kan Grubu"
            name="create-kan"
            value="A Rh+"
            onChange={onChange}
            placeholderOption={{ value: "", label: "Seçiniz" }}
            options={[{ value: "A Rh+", label: "A Rh+" }]}
          />
        </div>
      </div>
    );

    fireEvent.click(screen.getByLabelText("Kan Grubu"));

    const panel = appPickerPanel();
    expect(within(panel).queryByRole("option", { name: "Seçiniz" })).toBeNull();
    const clearRow = panel.querySelector('[data-app-select-clear="1"]');
    expect(clearRow).not.toBeNull();
    expect(clearRow?.textContent).toBe("Seçimi temizle");

    fireEvent.click(clearRow as HTMLElement);
    expect(onChange).toHaveBeenCalledWith("");
    expect(document.querySelector('[data-app-select-panel="1"]')).toBeNull();
  });

  it("never offers clearing on a required field", () => {
    render(
      <div className="modal-container">
        <div className="modal-body">
          <AppSelectField
            label="Departman"
            name="create-departman"
            value="3"
            onChange={() => undefined}
            required
            placeholderOption={{ value: "", label: "Seçiniz" }}
            options={[{ value: "3", label: "Üretim" }]}
          />
        </div>
      </div>
    );

    fireEvent.click(screen.getByLabelText("Departman"));
    expect(document.querySelector('[data-app-select-clear="1"]')).toBeNull();
    expect(within(appPickerPanel()).getByRole("option", { name: "Üretim" })).not.toBeNull();
  });
});

describe("AppSelect searchable variant", () => {
  const PERSONEL = [
    { value: "1", label: "Ayşe Yılmaz (P-001)" },
    { value: "2", label: "Mehmet Demir (P-002)" }
  ];

  function renderSearchable(props: Partial<React.ComponentProps<typeof AppSelect>> = {}) {
    return renderModalShell(
      <div className="form-section">
        <label className="form-label" htmlFor="surec-personel">
          Personel
        </label>
        <AppSelect
          id="surec-personel"
          value=""
          onChange={() => undefined}
          options={PERSONEL}
          searchable
          searchPlaceholder="Ada, soyada veya sicile göre ara"
          searchInputTestId="kayit-surec-personel-panel-search"
          {...props}
        />
      </div>
    );
  }

  it("panelin üstünde arama alanı gösterir ve combobox semantiğini bozmaz", () => {
    renderSearchable();
    const select = screen.getByLabelText("Personel");
    fireEvent.click(select);

    const search = screen.getByTestId("kayit-surec-personel-panel-search");
    expect(search).not.toBeNull();
    expect(search.closest(".app-picker-panel")).not.toBeNull();
    // Arama alanı textbox'tır: combobox rolü hâlâ tek (native select).
    expect(screen.getAllByRole("combobox")).toHaveLength(1);
  });

  it("yazdıkça filtreler, Enter ile seçer ve blur'u kaldırır", () => {
    const onChange = vi.fn();
    renderSearchable({ onChange });
    const select = screen.getByLabelText("Personel");
    fireEvent.click(select);

    const search = screen.getByTestId("kayit-surec-personel-panel-search");
    fireEvent.change(search, { target: { value: "mehmet" } });

    expect(within(appPickerPanel()).getByRole("option", { name: /Mehmet Demir/ })).not.toBeNull();
    expect(within(appPickerPanel()).queryByRole("option", { name: /Ayşe Yılmaz/ })).toBeNull();

    fireEvent.keyDown(search, { key: "ArrowDown" });
    fireEvent.keyDown(search, { key: "Enter" });

    expect(onChange).toHaveBeenCalledWith("2");
    expect(select.getAttribute("aria-expanded")).toBe("false");
    expect(document.body.hasAttribute(APP_PICKER_BODY_ATTR)).toBe(false);
  });

  it("ESC ile kapanır ve modal owner'a sızdırmaz", () => {
    renderSearchable();
    const select = screen.getByLabelText("Personel");
    const modalEscape = vi.fn();
    document.addEventListener("keydown", modalEscape);

    fireEvent.click(select);
    fireEvent.keyDown(screen.getByTestId("kayit-surec-personel-panel-search"), { key: "Escape" });

    expect(select.getAttribute("aria-expanded")).toBe("false");
    expect(modalEscape).not.toHaveBeenCalled();
    document.removeEventListener("keydown", modalEscape);
  });

  it("kontrollü arama + harici filtre (filterOptions=false) ve boş sonuç metni", () => {
    const onSearchValueChange = vi.fn();
    renderSearchable({
      value: "1",
      options: [],
      filterOptions: false,
      searchValue: "yok",
      onSearchValueChange,
      noResultsText: "Aramaya uygun personel bulunamadı."
    });

    fireEvent.click(screen.getByLabelText("Personel"));

    const search = screen.getByTestId("kayit-surec-personel-panel-search") as HTMLInputElement;
    expect(search.value).toBe("yok");
    expect(within(appPickerPanel()).getByText("Aramaya uygun personel bulunamadı.")).not.toBeNull();

    fireEvent.change(search, { target: { value: "ayşe" } });
    expect(onSearchValueChange).toHaveBeenCalledWith("ayşe");
  });
});
