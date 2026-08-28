import { execFileSync, spawnSync } from "node:child_process";
import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { afterEach, beforeAll, describe, expect, it, vi } from "vitest";
import { ensureDisposableMariaDbEnv, runPhpMysqlRunner } from "../scripts/disposable-mariadb.mjs";
import { createPersonel } from "../../src/api/personeller.api";
import { buildCreatePersonelPayload } from "../../src/features/personeller/personel-create-utils";
import { personelToEditForm } from "../../src/features/personeller/personel-edit-utils";
import { INITIAL_CREATE_PERSONEL_FORM } from "../../src/hooks/usePersoneller";
import type { Personel } from "../../src/types/personel";

const root = process.cwd();
const pureRunner = resolve(root, "tests/php/PersonelAutoSicilTestRunner.php");
const mysqlRunner = resolve(root, "tests/php/PersonelAutoSicilMysqlTestRunner.php");

function read(path: string): string {
  return readFileSync(resolve(root, path), "utf8");
}

const validForm = {
  ...INITIAL_CREATE_PERSONEL_FORM,
  tcKimlikNo: "12345678901",
  ad: "Zeynep",
  soyad: "Örnek",
  dogumTarihi: "2002-01-01",
  telefon: "05321234567",
  acilDurumKisi: "Yakın Kişi",
  acilDurumTelefon: "05329876543",
  iseGirisTarihi: "2026-08-12",
  subeId: "1",
  departmanId: "3",
  gorevId: "1",
  personelTipiId: "1"
};

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" }
  });
}

describe("otomatik sicil — frontend create sözleşmesi", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  it("create formu sicil girişi istemiyor, bilgilendirme gösteriyor", () => {
    const createFields = read("src/features/personeller/components/PersonelCreateFields.tsx");
    expect(createFields).not.toContain('name="create-sicil"');
    expect(createFields).not.toContain("sicilNo");
    expect(createFields).toContain("Sicil numarası kayıt sırasında otomatik atanacaktır.");

    const formState = read("src/hooks/usePersoneller.ts");
    expect(formState).not.toContain("sicilNo");
  });

  it("create payload sicil göndermiyor", () => {
    const payload = buildCreatePersonelPayload(validForm);
    expect(payload).not.toHaveProperty("sicil_no");
  });

  it("başarılı response'taki atanmış sicil frontend'e taşınıyor", async () => {
    const fetchMock = vi.fn(async () =>
      jsonResponse(
        {
          data: {
            id: 91,
            tc_kimlik_no: "12345678901",
            ad: "Zeynep",
            soyad: "ÖRNEK",
            aktif_durum: "AKTIF",
            sube_id: 1,
            sicil_no: "040",
            ise_giris_tarihi: "2026-08-12"
          },
          meta: {},
          errors: []
        },
        201
      )
    );
    vi.stubGlobal("fetch", fetchMock);

    const created = await createPersonel(buildCreatePersonelPayload(validForm));

    const [, init] = fetchMock.mock.calls[0] as [string, RequestInit];
    expect(JSON.parse(String(init.body))).not.toHaveProperty("sicil_no");
    expect(created.sicil_no).toBe("040");
  });

  it("edit/detay mevcut sicili göstermeye devam ediyor", () => {
    const personel: Personel = {
      id: 91,
      tc_kimlik_no: "12345678901",
      ad: "Zeynep",
      soyad: "ÖRNEK",
      aktif_durum: "AKTIF",
      calisan_kapsami: "IC_PERSONEL",
      sicil_no: "040",
      ise_giris_tarihi: "2026-08-12"
    };

    expect(personelToEditForm(personel).sicilNo).toBe("040");

    const hero = read("src/features/personeller/components/personel-dosya/PersonelDosyaHero.tsx");
    expect(hero).toContain('fieldValue("sicil_no"');

    const inlineEdit = read(
      "src/features/personeller/components/personel-dosya/PersonelInlineEditForm.tsx"
    );
    expect(inlineEdit).toContain("editForm.sicilNo");
  });

  it("demo/mock katmanı gerçek backend davranışını taklit ediyor", () => {
    const demo = read("src/api/mock-demo.ts");
    expect(demo).toContain("demoResolveCreateSicilNo");
    expect(demo).toContain('padStart(3, "0")');
  });
});

describe("otomatik sicil — backend owner sözleşmesi", () => {
  it("canonical allocator transaction ve row lock altında çalışıyor", () => {
    const allocator = read("api/src/Services/Personel/PersonelSicilAllocator.php");
    expect(allocator).toContain("class PersonelSicilAllocator");
    expect(allocator).toContain("personel_sicil_sequence");
    expect(allocator).toContain("FOR UPDATE");
    expect(allocator).toContain("inTransaction()");
    expect(allocator).toContain("GREATEST(next_value, :next_value)");
  });

  it("create owner AUTO sicili yalnız insert transaction'ında tahsis ediyor", () => {
    const createService = read("api/src/Services/Personel/PersonelCreateService.php");
    expect(createService).toContain("PersonelSicilAllocator::isAutoRequest");
    expect(createService).toContain("PersonelSicilAllocator::allocateInTransaction");

    const controller = read("api/src/Controllers/PersonellerController.php");
    expect(controller).toContain("PersonelSicilAllocationException");
    expect(controller).toContain("PersonelSicilAllocator::isAutoRequest");
    // Allocation must stay after the idempotency claim, inside the insert transaction.
    expect(controller.indexOf("claimInTransaction")).toBeLessThan(
      controller.indexOf("PersonelCreateService::insertPersonel")
    );
  });

  it("validator create'te AUTO, import'ta zorunlu sicil kuralını koruyor", () => {
    const validator = read("api/src/Services/Personel/PersonelCanonicalValidator.php");
    expect(validator).toContain("$sicilNo = self::optionalTrimmedString($body, 'sicil_no');");
    expect(validator).toContain("'sicil_no', 'Sicil no zorunludur.'");
  });

  it("frontend'de sıra hesaplama kodu yok", () => {
    const createUtils = read("src/features/personeller/personel-create-utils.ts");
    expect(createUtils).not.toContain("sicil");

    const api = read("src/api/personeller.api.ts");
    expect(api).toContain("sicil_no?: string;");
  });

  it("migration 078 idempotent, fail-closed ve ileri yönlü", () => {
    const migration = read("api/migrations/078_personel_sicil_sequence.sql");
    expect(migration).toContain("CREATE TABLE IF NOT EXISTS personel_sicil_sequence");
    expect(migration).toContain("ON DUPLICATE KEY UPDATE next_value = GREATEST(next_value, @p078_seed)");
    expect(migration).toContain("PACK078_BLOCKER");
    expect(migration).toContain("REGEXP '^[0-9]+$'");
    expect(migration).not.toMatch(/UPDATE\s+personeller\s+SET/i);
    expect(migration).not.toMatch(/\bDROP\s+TABLE\b/i);
  });
});

describe("otomatik sicil — PHP runner'ları", () => {
  it("focused sicil politikası senaryolarını PHP CLI ile çalıştırır", () => {
    const isWindows = process.platform === "win32";
    let phpPath = "php";
    try {
      phpPath = isWindows
        ? execFileSync("where.exe", ["php"], { encoding: "utf8" }).split(/\r?\n/)[0].trim()
        : "php";
    } catch {
      throw new Error("PHP CLI not found on PATH.");
    }

    const phpArgs = isWindows
      ? ["-d", `extension_dir=${resolve(dirname(phpPath), "ext")}`, pureRunner]
      : [pureRunner];
    const result = spawnSync(phpPath, phpArgs, { encoding: "utf8", cwd: root });
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-auto-sicil: OK");
  });
});

describe("otomatik sicil — disposable MariaDB", () => {
  beforeAll(async () => {
    await ensureDisposableMariaDbEnv();
  }, 90_000);

  it("gerçek DB üzerinde tahsis, eşzamanlılık ve idempotency davranışını doğrular", () => {
    const result = runPhpMysqlRunner(mysqlRunner);
    expect(result.status, result.stderr || result.stdout).toBe(0);
    expect(result.stdout).toContain("verify-personel-auto-sicil-mysql: OK");
    expect(result.stdout).toContain("[PASS] bos tabloda ilk otomatik sicil 001");
    expect(result.stdout).toContain("[PASS] dolu aday sicil atlanip sonraki veriliyor");
    expect(result.stdout).toContain("[PASS] PASIF personel sicili yeniden kullanilmiyor");
    expect(result.stdout).toContain("[PASS] explicit yuksek sicil sayac zeminini tasiyor");
    expect(result.stdout).toContain("[PASS] non-numeric sicil numeric sayaci bozmuyor");
    expect(result.stdout).toContain("[PASS] concurrent create ayni sicili alamiyor");
    expect(result.stdout).toContain("[PASS] rollback yarim personel birakmiyor");
    expect(result.stdout).toContain("[PASS] replay ikinci sicil uretmiyor");
    expect(result.stdout).toContain("[PASS] explicit duplicate sicil DUPLICATE_SICIL_NO davranisini koruyor");
    expect(result.stdout).toContain("[PASS] import sicilsiz satiri reddediyor");
    expect(result.stdout).toContain("[PASS] interactive create sicilsiz payload kabul ediyor");
  }, 600_000);
});
