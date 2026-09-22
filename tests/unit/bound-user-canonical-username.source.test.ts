import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { afterEach, describe, expect, it, vi } from "vitest";
import { buildPersonelUsernameFromNames } from "../../src/features/yonetim/personelUsernameFromNames";
import { resolveBoundUserCanonicalUsernameView } from "../../src/lib/yonetim/bound-user-canonical-username";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

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

const {
  fixYonetimKullaniciCanonicalUsername,
  resetYonetimKullaniciBaslangicSifresi
} = await import("../../src/api/yonetim.api");

type ClassifyRow = {
  user_id: number;
  username: string;
  rol: string;
  user_durum: string;
  personel_id: number;
  personel_row_id: number | null;
  personel_ad: string | null;
  personel_soyad: string | null;
  personel_aktif_durum: string | null;
};

function phpClassify(row: ClassifyRow, taken: Record<string, number>): Record<string, unknown> {
  const code = [
    "require 'api/src/bootstrap.php';",
    "$in = json_decode(stream_get_contents(STDIN), true);",
    "$out = Medisa\\Api\\Services\\Auth\\BoundUserCanonicalUsernameReconciliationService::classifyBoundRow($in['row'], $in['taken']);",
    "echo json_encode($out, JSON_UNESCAPED_UNICODE);"
  ].join(" ");
  const result = spawnSync("php", ["-r", code], {
    encoding: "utf8",
    cwd: process.cwd(),
    input: JSON.stringify({ row, taken })
  });
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php classify failed");
  }
  return JSON.parse((result.stdout || "").trim()) as Record<string, unknown>;
}

describe("bound user canonical username reconciliation", () => {
  afterEach(() => {
    apiRequestMock.mockReset();
  });

  it("scans all personel-bound users, not only PERSONEL role", () => {
    const service = read("api/src/Services/Auth/BoundUserCanonicalUsernameReconciliationService.php");
    const firstLogin = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");

    expect(service).toContain("WHERE u.personel_id IS NOT NULL");
    expect(service).not.toContain("WHERE u.rol = 'PERSONEL'");
    expect(firstLogin).toContain("WHERE u.rol = 'PERSONEL'");
    expect(service).toContain("CLASS_MISMATCH");
    expect(service).toContain("collision_blockers");
    expect(service).toContain("passive_users");
    expect(service).toContain("passive_personel");
    expect(service).not.toContain("UPDATE users");
    expect(service).not.toContain("activation");
  });

  it("detects match for bound PERSONEL and mismatch for manager roles with legacy sicil username", () => {
    const personelMatch = phpClassify(
      {
        user_id: 10,
        username: "sercanT",
        rol: "PERSONEL",
        user_durum: "AKTIF",
        personel_id: 50,
        personel_row_id: 50,
        personel_ad: "Sercan",
        personel_soyad: "TOPUZ",
        personel_aktif_durum: "AKTIF"
      },
      { sercant: 10 }
    );
    expect(personelMatch.classification).toBe("match");
    expect(personelMatch.expected_username).toBe("sercanT");
    expect(personelMatch.fixable).toBe(false);

    const birimMismatch = phpClassify(
      {
        user_id: 11,
        username: "017",
        rol: "BIRIM_AMIRI",
        user_durum: "AKTIF",
        personel_id: 51,
        personel_row_id: 51,
        personel_ad: "Sercan",
        personel_soyad: "TOPUZ",
        personel_aktif_durum: "AKTIF"
      },
      { "017": 11 }
    );
    expect(birimMismatch.classification).toBe("mismatch");
    expect(birimMismatch.expected_username).toBe("sercanT");
    expect(birimMismatch.rol).toBe("BIRIM_AMIRI");
    expect(birimMismatch.fixable).toBe(true);

    const bolumMismatch = phpClassify(
      {
        user_id: 12,
        username: "018",
        rol: "BOLUM_YONETICISI",
        user_durum: "AKTIF",
        personel_id: 52,
        personel_row_id: 52,
        personel_ad: "Ayşe",
        personel_soyad: "Yılmaz",
        personel_aktif_durum: "AKTIF"
      },
      { "018": 12 }
    );
    expect(bolumMismatch.classification).toBe("mismatch");
    expect(bolumMismatch.expected_username).toBe(buildPersonelUsernameFromNames("Ayşe", "Yılmaz"));
    expect(bolumMismatch.rol).toBe("BOLUM_YONETICISI");
    expect(bolumMismatch.fixable).toBe(true);
  });

  it("blocks fix when canonical username collides with another account", () => {
    const collided = phpClassify(
      {
        user_id: 20,
        username: "017",
        rol: "BIRIM_AMIRI",
        user_durum: "AKTIF",
        personel_id: 60,
        personel_row_id: 60,
        personel_ad: "Sercan",
        personel_soyad: "TOPUZ",
        personel_aktif_durum: "AKTIF"
      },
      { "017": 20, sercant: 99 }
    );
    expect(collided.classification).toBe("mismatch");
    expect(collided.collision).toBe(true);
    expect(collided.collision_user_id).toBe(99);
    expect(collided.fixable).toBe(false);
  });

  it("classifies passive user and passive bound personel separately", () => {
    const passiveUser = phpClassify(
      {
        user_id: 30,
        username: "017",
        rol: "PERSONEL",
        user_durum: "PASIF",
        personel_id: 70,
        personel_row_id: 70,
        personel_ad: "Sercan",
        personel_soyad: "TOPUZ",
        personel_aktif_durum: "AKTIF"
      },
      { "017": 30 }
    );
    expect(passiveUser.classification).toBe("user_not_active");

    const passivePersonel = phpClassify(
      {
        user_id: 31,
        username: "017",
        rol: "PERSONEL",
        user_durum: "AKTIF",
        personel_id: 71,
        personel_row_id: 71,
        personel_ad: "Sercan",
        personel_soyad: "TOPUZ",
        personel_aktif_durum: "PASIF"
      },
      { "017": 31 }
    );
    expect(passivePersonel.classification).toBe("bound_personel_not_active");
    expect(passivePersonel.fixable).toBe(false);
  });

  it("keeps initial-password reset free of silent username mutation and forces must_change_password", () => {
    const controller = read("api/src/Controllers/YonetimController.php");
    const resetStart = controller.indexOf("} elseif ($resetToInitial) {");
    const resetSlice = controller.slice(resetStart, resetStart + 600);

    expect(resetStart).toBeGreaterThan(-1);
    expect(resetSlice).toContain("assertUsernameCanonicalForInitialPasswordReset");
    expect(resetSlice).toContain("InitialPassword::requireHashForName");
    expect(resetSlice).not.toContain("$username =");
    expect(controller).toContain("must_change_password = 1");
    expect(controller).toContain("canonical_username_duzelt");
    expect(controller).toContain("parseCanonicalUsernameFixIntent");
    expect(controller).toContain("requireCanonicalUsernameFix");
  });

  it("sends dedicated fix intent without password fields", async () => {
    apiRequestMock.mockResolvedValue({
      data: {
        id: 11,
        username: "sercanT",
        ad_soyad: "Sercan TOPUZ",
        kullanici_tipi: "IC_PERSONEL",
        rol: "BIRIM_AMIRI",
        sube_ids: [1],
        varsayilan_sube_id: 1,
        durum: "AKTIF",
        personel_id: 51,
        must_change_password: false
      },
      meta: {},
      errors: []
    });

    await fixYonetimKullaniciCanonicalUsername(11);

    const [, init] = apiRequestMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(String(init.body)) as Record<string, unknown>;
    expect(body).toEqual({ canonical_username_duzelt: true });
    expect(body).not.toHaveProperty("password");
    expect(body).not.toHaveProperty("baslangic_sifresine_sifirla");
  });

  it("keeps baslangic sifresi reset payload username-free", async () => {
    apiRequestMock.mockResolvedValue({
      data: {
        id: 11,
        username: "sercanT",
        ad_soyad: "Sercan TOPUZ",
        kullanici_tipi: "IC_PERSONEL",
        rol: "BIRIM_AMIRI",
        sube_ids: [1],
        varsayilan_sube_id: 1,
        durum: "AKTIF",
        personel_id: 51,
        must_change_password: true
      },
      meta: {},
      errors: []
    });

    await resetYonetimKullaniciBaslangicSifresi(11);
    const [, init] = apiRequestMock.mock.calls[0] as [string, RequestInit];
    const body = JSON.parse(String(init.body)) as Record<string, unknown>;
    expect(body).toEqual({ baslangic_sifresine_sifirla: true });
    expect(body).not.toHaveProperty("username");
    expect(body).not.toHaveProperty("canonical_username_duzelt");
  });

  it("exposes FE mismatch view and Yönetim UI warning + fix action", () => {
    const match = resolveBoundUserCanonicalUsernameView({
      actualUsername: "sercanT",
      personelAd: "Sercan",
      personelSoyad: "TOPUZ",
      personelAktifDurum: "AKTIF"
    });
    expect(match?.mismatch).toBe(false);

    const mismatch = resolveBoundUserCanonicalUsernameView({
      actualUsername: "017",
      personelAd: "Sercan",
      personelSoyad: "TOPUZ",
      personelAktifDurum: "AKTIF"
    });
    expect(mismatch?.mismatch).toBe(true);
    expect(mismatch?.expectedUsername).toBe("sercanT");
    expect(mismatch?.canOfferFix).toBe(true);

    const page = read("src/features/yonetim/pages/YonetimPaneliPage.tsx");
    expect(page).toContain("yonetim-canonical-username-expected");
    expect(page).toContain("Bu kullanıcı adı canonical personel şablonuyla uyuşmuyor.");
    expect(page).toContain("yonetim-kullanici-canonical-username-duzelt");
    expect(page).toContain("yonetim-baslangic-sifresi-username-guard");
    expect(page).toContain("fixYonetimKullaniciCanonicalUsername");
    expect(page).toContain("boundCanonicalUsernameView?.mismatch");
  });

  it("does not revive activation-link routes or APIs", () => {
    const router = read("api/src/Router.php");
    const endpoints = read("src/api/endpoints.ts");
    const routes = read("src/app/routes.tsx");
    const scanCli = read("api/bin/bound-user-canonical-username-scan.php");

    expect(router).not.toContain("personel-activation");
    expect(router).not.toContain("aktivasyon-yenile");
    expect(endpoints).not.toContain("Aktivasyon");
    expect(routes).not.toContain("personel-aktivasyon");
    expect(scanCli).toContain("BoundUserCanonicalUsernameReconciliationService::scan");
    expect(scanCli).not.toContain("UPDATE");
    expect(scanCli).not.toContain("apply");
  });
});
