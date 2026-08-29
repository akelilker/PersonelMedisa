import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";

const root = process.cwd();
const page = readFileSync(
  resolve(root, "src/features/yonetim/pages/YonetimPaneliPage.tsx"),
  "utf8"
);

/** Body of a top-level `function name(...)` / `async function name(...)` declaration. */
function functionBody(source: string, name: string): string {
  const start = source.search(new RegExp(`(?:async\\s+)?function\\s+${name}\\s*\\(`));
  expect(start, `${name} bulunamadi`).toBeGreaterThan(-1);
  const open = source.indexOf("{", start);
  let depth = 0;
  for (let i = open; i < source.length; i += 1) {
    if (source[i] === "{") depth += 1;
    if (source[i] === "}") {
      depth -= 1;
      if (depth === 0) return source.slice(open, i + 1);
    }
  }
  throw new Error(`${name} govdesi kapanmadi`);
}

describe("yonetim panel modal error visibility", () => {
  it("keeps submit errors in a separate state from the page-level load error", () => {
    expect(page).toContain("const [formErrorMessage, setFormErrorMessage] = useState<string | null>(null)");
    // The page-level ErrorState hides the list and renders behind an open modal,
    // so it must stay reserved for loadPanel failures.
    expect(functionBody(page, "loadPanel")).toContain("setErrorMessage(null)");
    expect(page).toContain("{!isLoading && errorMessage ? <ErrorState");
  });

  it("routes user editor submit failures to the in-modal error", () => {
    const body = functionBody(page, "handleKullaniciSubmit");
    expect(body).toContain("setFormErrorMessage(null)");
    expect(body).toContain('setFormErrorMessage("Bu personel kaydı başka bir kullanıcıya bağlı.")');
    expect(body).toContain('setFormErrorMessage(error instanceof Error ? error.message : "Kullanıcı kaydı kaydedilemedi.")');
    expect(body).not.toContain("setErrorMessage(");
  });

  it("routes branch editor and inline departman failures to the in-modal error", () => {
    const sube = functionBody(page, "handleSubeSubmit");
    expect(sube).toContain("setFormErrorMessage(null)");
    expect(sube).toContain('setFormErrorMessage(error instanceof Error ? error.message : "Şube tanımı kaydedilemedi.")');
    expect(sube).not.toContain("setErrorMessage(");

    const departman = functionBody(page, "handleDepartmanAdd");
    expect(departman).toContain('setFormErrorMessage(error instanceof Error ? error.message : "Departman eklenemedi.")');
    expect(departman).not.toContain("setErrorMessage(");
  });

  it("renders the submit error inside both editor modals as an alert", () => {
    expect(page).toContain('data-testid="yonetim-kullanici-form-error"');
    expect(page).toContain('data-testid="yonetim-sube-form-error"');
    const alerts = page.match(/className="yonetim-inline-error" role="alert"/g) ?? [];
    expect(alerts.length).toBeGreaterThanOrEqual(3);

    // Both blocks must sit inside the form, above the submit row.
    const kullaniciError = page.indexOf('data-testid="yonetim-kullanici-form-error"');
    const kullaniciSubmit = page.indexOf('data-testid="yonetim-kullanici-kaydet"');
    expect(kullaniciError).toBeLessThan(kullaniciSubmit);

    const subeError = page.indexOf('data-testid="yonetim-sube-form-error"');
    const subeSubmit = page.indexOf('data-testid="yonetim-sube-kaydet"');
    expect(subeError).toBeLessThan(subeSubmit);
  });

  it("clears the in-modal error when an editor is opened or closed", () => {
    for (const name of [
      "resetKullaniciEditor",
      "resetSubeEditor",
      "openYeniKullaniciForm",
      "openKullaniciEditor",
      "openYeniSubeForm",
      "openSubeEditor"
    ]) {
      expect(functionBody(page, name), name).toContain("setFormErrorMessage(null)");
    }
  });
});
