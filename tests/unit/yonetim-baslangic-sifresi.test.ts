import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { afterEach, describe, expect, it, vi } from "vitest";

const { apiRequestMock } = vi.hoisted(() => ({
  apiRequestMock: vi.fn()
}));

vi.mock("../../src/api/api-client", async (importOriginal) => {
  const actual = await importOriginal<typeof import("../../src/api/api-client")>();
  return {
    ...actual,
    apiRequest: apiRequestMock
  };
});

const { resetYonetimKullaniciBaslangicSifresi } = await import("../../src/api/yonetim.api");

function read(path: string): string {
  return readFileSync(resolve(path), "utf8");
}

const PAGE = "src/features/yonetim/pages/YonetimPaneliPage.tsx";

describe("yonetim user modal: derived initial password model", () => {
  afterEach(() => {
    apiRequestMock.mockReset();
  });

  it("has no password input and no demo default in the admin form", () => {
    const page = read(PAGE);
    expect(page).not.toContain("demo123");
    expect(page).not.toContain('type="password"');
    expect(page).not.toContain("yonetim-kullanici-password");
    expect(page).not.toContain("Geçici Şifre");
    expect(page).not.toContain("payload.password");
  });

  it("tells the user on create that the password comes from the name", () => {
    const page = read(PAGE);
    expect(page).toContain("yonetim-baslangic-sifresi-hint");
    expect(page).toContain("ad soyad bilgisinden şirket kuralıyla");
  });

  it("offers a confirmed reset action on the existing-user editor", () => {
    const page = read(PAGE);
    expect(page).toContain("yonetim-kullanici-sifre-sifirla");
    expect(page).toContain("yonetim-kullanici-sifre-sifirla-confirm");
    expect(page).toContain("Başlangıç Şifresine Sıfırla");
    expect(page).toContain("resetYonetimKullaniciBaslangicSifresi");
  });

  it("keeps the modal-scoped submit error owner intact", () => {
    const page = read(PAGE);
    expect(page).toContain("const [formErrorMessage, setFormErrorMessage] = useState<string | null>(null)");
    expect(page).toContain('data-testid="yonetim-kullanici-form-error"');
  });

  it("sends only the boolean reset intent, never a password or hash", async () => {
    apiRequestMock.mockResolvedValue({
      data: {
        id: 148,
        username: "zeynepG",
        ad_soyad: "Zeynep Günal",
        kullanici_tipi: "IC_PERSONEL",
        rol: "IK_SORUMLUSU",
        sube_ids: [1, 2, 4, 5, 6],
        varsayilan_sube_id: 1,
        durum: "AKTIF",
        personel_id: 211,
        must_change_password: true
      },
      meta: {},
      errors: []
    });

    await resetYonetimKullaniciBaslangicSifresi(148);

    expect(apiRequestMock).toHaveBeenCalledTimes(1);
    const [path, init] = apiRequestMock.mock.calls[0] as [string, RequestInit];
    expect(path).toBe("/yonetim/kullanicilar/148");
    expect(init.method).toBe("PUT");
    const body = JSON.parse(String(init.body)) as Record<string, unknown>;
    expect(body).toEqual({ baslangic_sifresine_sifirla: true });
  });

  it("keeps the production bundle sources free of demo credentials", () => {
    const clientContract = read("src/lib/yonetim/kullanici-api-contract.ts");
    const api = read("src/api/yonetim.api.ts");
    expect(clientContract).not.toContain("demo123");
    expect(api).not.toContain("demo123");
  });
});
