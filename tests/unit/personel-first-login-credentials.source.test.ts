import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";
import { describe, expect, it } from "vitest";
import { buildPersonelUsernameFromNames } from "../../src/features/yonetim/personelUsernameFromNames";
import { shouldEmitGlobalAuthForbidden } from "../../src/lib/api-forbidden-policy";

const root = resolve(process.cwd());
const read = (relative: string) => readFileSync(resolve(root, relative), "utf8");

const service = read("api/src/Services/Auth/PersonelAccountOnboardingService.php");
const worker = read("api/bin/personel-first-login-credentials.php");

/** Ad/soyad STDIN'den verilir; Windows argv encoding riski yok. */
function phpRule(kind: "username" | "password", ad: string, soyad: string): string {
  const method =
    kind === "username" ? "buildPersonelUsernameFromNames" : "buildPersonelInitialPasswordFromNames";
  const code = [
    "require 'api/src/bootstrap.php';",
    "$in = json_decode(stream_get_contents(STDIN), true);",
    "echo Medisa\\Api\\Services\\Auth\\PersonelAccountOnboardingService::" + method + "($in[0], $in[1]);"
  ].join(" ");
  const result = spawnSync("php", ["-r", code], {
    encoding: "utf8",
    cwd: process.cwd(),
    input: JSON.stringify([ad, soyad])
  });
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php rule evaluation failed");
  }
  return (result.stdout || "").trim();
}

/** personel_id + ad/soyad ile canonical owner cozumlemesi (override/correction dahil). */
function phpResolve(
  kind: "username" | "password",
  personelId: number,
  ad: string,
  soyad: string | null
): string {
  const method =
    kind === "username" ? "resolvePersonelCanonicalUsername" : "resolvePersonelInitialPasswordMaterial";
  const code = [
    "require 'api/src/bootstrap.php';",
    "$in = json_decode(stream_get_contents(STDIN), true);",
    "$v = Medisa\\Api\\Services\\Auth\\PersonelAccountOnboardingService::" + method + "($in[0], $in[1], $in[2]);",
    "echo $v === null ? '' : $v;"
  ].join(" ");
  const result = spawnSync("php", ["-r", code], {
    encoding: "utf8",
    cwd: process.cwd(),
    input: JSON.stringify([personelId, ad, soyad])
  });
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php resolver evaluation failed");
  }
  return (result.stdout || "").trim();
}

/** Template sifre materyali canonical PasswordHasher ile hash/verify edilebiliyor mu? */
function phpPasswordHashRoundTrip(personelId: number, ad: string, soyad: string | null): string {
  const code = [
    "require 'api/src/bootstrap.php';",
    "$in = json_decode(stream_get_contents(STDIN), true);",
    "$m = Medisa\\Api\\Services\\Auth\\PersonelAccountOnboardingService::resolvePersonelInitialPasswordMaterial($in[0], $in[1], $in[2]);",
    "$h = Medisa\\Api\\Auth\\PasswordHasher::hash($m);",
    "echo Medisa\\Api\\Auth\\PasswordHasher::verify($m, $h) ? 'OK' : 'FAIL';"
  ].join(" ");
  const result = spawnSync("php", ["-r", code], {
    encoding: "utf8",
    cwd: process.cwd(),
    input: JSON.stringify([personelId, ad, soyad])
  });
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php hash round trip failed");
  }
  return (result.stdout || "").trim();
}

type PhpBusinessMaps = {
  overrides: Record<string, { username?: string; initial_password?: string }>;
  corrections: Record<string, { from: { ad: string | null; soyad: string | null }; to: { ad: string; soyad: string } }>;
};

function phpBusinessMaps(): PhpBusinessMaps {
  const code = [
    "require 'api/src/bootstrap.php';",
    "$c = 'Medisa\\\\Api\\\\Services\\\\Auth\\\\PersonelAccountOnboardingService';",
    "echo json_encode([",
    "  'overrides' => $c::PERSONEL_CREDENTIAL_OVERRIDES,",
    "  'corrections' => $c::PERSONEL_NAME_CORRECTIONS,",
    "], JSON_UNESCAPED_UNICODE);"
  ].join(" ");
  const result = spawnSync("php", ["-r", code], { encoding: "utf8", cwd: process.cwd() });
  if (result.status !== 0) {
    throw new Error(result.stderr || result.stdout || "php business map read failed");
  }

  return JSON.parse(result.stdout) as PhpBusinessMaps;
}

describe("PERSONEL canonical first-login credential kurallari", () => {
  it("username kurali: ilk ad kucuk ASCII + soyad ilk harfi buyuk ASCII", () => {
    const cases: Array<[string, string, string]> = [
      ["Serhan", "Köse", "serhanK"],
      ["Musa", "Taş", "musaT"],
      ["İlker", "AKEL", "ilkerA"],
      ["Mehmet Ali", "YILMAZ", "mehmetY"]
    ];
    for (const [ad, soyad, expected] of cases) {
      expect(buildPersonelUsernameFromNames(ad, soyad)).toBe(expected);
      expect(phpRule("username", ad, soyad)).toBe(expected);
    }
  });

  it("baslangic sifresi kurali: soyad ASCII TitleCase + 123", () => {
    const cases: Array<[string, string, string]> = [
      ["Serhan", "Köse", "Kose123"],
      ["Musa", "Taş", "Tas123"],
      ["Serhan", "KÖSE", "Kose123"],
      ["Emre", "ÇELİK", "Celik123"],
      ["X", "ŞENAY", "Senay123"]
    ];
    for (const [ad, soyad, expected] of cases) {
      expect(phpRule("password", ad, soyad)).toBe(expected);
    }
    expect(phpRule("password", "Serhan", "Köse")).toMatch(/^[A-Z][a-z0-9]*123$/);
  });
});

describe("PERSONEL canonical first-login gecisi (owner kontratlari)", () => {
  it("canonical sahip cohort'u ve hedef state'i tanimlar", () => {
    expect(service).toContain("migrateCanonicalFirstLoginCredentials");
    expect(service).toContain("PROTECTED_USERNAMES = ['ilkerA']");
    expect(service).toContain("buildPersonelInitialPasswordFromNames");
    expect(service).toContain("EVENT_FIRST_LOGIN_CREDENTIALS_APPLIED = 'PERSONEL_FIRST_LOGIN_CREDENTIALS_APPLIED'");
    expect(service).toContain("PasswordHasher::hash(");
    expect(service).toContain("activation_required = 0");
    expect(service).toContain("must_change_password = 1");
    // Cohort yalniz aktif, bagli ve bagli personeli AKTIF satirlar icindir.
    expect(service).toContain("WHERE u.rol = 'PERSONEL'");
    expect(service).toMatch(/user_durum'\] !== 'AKTIF'/);
    expect(service).toMatch(/personel_aktif_durum[\s\S]{0,80}!== 'AKTIF'/);
    // Degismeyen alanlar SET listesinde yok; cohort guard WHERE'de kalir.
    const methodStart = service.indexOf("migrateCanonicalFirstLoginCredentials");
    const updateStart = service.indexOf("UPDATE users", methodStart);
    const setClause = service.slice(updateStart, service.indexOf("WHERE id = :id", methodStart));
    const whereClause = service.slice(
      service.indexOf("WHERE id = :id", methodStart),
      service.indexOf("AND username = :old_username", methodStart)
    );
    expect(setClause).toContain("SET username = :new_username");
    expect(setClause).toContain("password_hash = :password_hash");
    expect(setClause).not.toContain("rol =");
    expect(setClause).not.toContain("durum =");
    expect(setClause).not.toContain("personel_id =");
    expect(whereClause).toContain("rol = 'PERSONEL'");
  });

  it("collision guard mutation'dan once fail-closed durur", () => {
    expect(service).toContain("ERR_CANONICAL_USERNAME_COLLISION = 'PERSONEL_CANONICAL_USERNAME_COLLISION'");
    expect(service).toContain("detectCanonicalUsernameCollisions");
    expect(service).toMatch(/count\(\$collisions\) > 0[\s\S]*'blocked' => true[\s\S]*beginTransaction\(\)/);
    expect(service).toContain("'outside_cohort'");
  });

  it("plaintext sifre ve hash hicbir yere yazilmaz", () => {
    expect(service).not.toMatch(/error_log\s*\(/);
    expect(service).not.toMatch(/password_hash'\s*=>\s*self::build/);
    expect(worker).not.toMatch(/error_log\s*\(/);
    expect(worker).not.toMatch(/buildPersonelInitialPasswordFromNames\s*\(/);
    expect(worker).not.toMatch(/\b(UPDATE|INSERT|DELETE)\b/);
  });

  it("ops worker yalniz acik onayla mutation yapar ve web'den erisilemez", () => {
    expect(worker).toContain("PHP_SAPI !== 'cli'");
    expect(worker).toContain("PERSONEL_FIRST_LOGIN_CREDENTIALS");
    expect(worker).toContain("--apply");
    expect(worker).toContain("--confirm=");
    expect(worker).toContain("--actor-user-id=");
    expect(worker).toContain("ACTOR_USER_ID_REQUIRED");
    expect(worker).toContain("migrateCanonicalFirstLoginCredentials");
  });

  it("yeni hesap create yolu ayni canonical first-login modelini kullanir", () => {
    const create = service.slice(
      service.indexOf("public static function onboardAndIssue("),
      service.indexOf("public static function rejectGenericPersonelBoundCreate(")
    );
    expect(create).toContain("resolvePersonelCanonicalUsername");
    expect(create).toContain("resolvePersonelInitialPasswordMaterial");
    expect(create).toContain("PasswordHasher::hash($passwordMaterial)");
    expect(create).toMatch(/must_change_password';\s*\n\s*\$insertVals \.= ', 1';/);
    expect(create).toMatch(/activation_required';\s*\n\s*\$insertVals \.= ', 0';/);
    // Aktivasyon daveti/linki yok; secret response'ta yok.
    expect(create).not.toContain("issueInvitationLocked");
    expect(create).not.toContain("activation_url");
    expect(create).toContain("buildFirstLoginResponse");

    const response = service.slice(
      service.indexOf("private static function buildFirstLoginResponse("),
      service.indexOf("private static function writeAudit(")
    );
    expect(response).not.toContain("'activation'");
    expect(response).not.toContain("password_hash");
    expect(response).not.toContain("password_material");
    expect(response).toContain("credential_model");
  });
});

describe("zorunlu sifre degisimi global /yetkisiz yonlendirmesi uretmez", () => {
  it("PASSWORD_CHANGE_REQUIRED 403'u global forbidden sayilmaz", () => {
    expect(
      shouldEmitGlobalAuthForbidden("/gunluk-puantaj/2/2026-01-01", "GET", "PASSWORD_CHANGE_REQUIRED")
    ).toBe(false);
    expect(shouldEmitGlobalAuthForbidden("/yonetim/kullanicilar", "GET", "password_change_required")).toBe(false);
  });

  it("diger 403 davranislari ve diger kodlar degismedi", () => {
    expect(shouldEmitGlobalAuthForbidden("/gunluk-puantaj/2/2026-01-01", "GET")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/unknown-endpoint", "GET", "FORBIDDEN")).toBe(true);
    expect(shouldEmitGlobalAuthForbidden("/personeller", "GET", "FORBIDDEN")).toBe(false);
  });

  it("api-client 403 kararinda hata kodunu politikaya gecirir", () => {
    const client = read("src/api/api-client.ts");
    expect(client).toContain(
      "shouldEmitGlobalAuthForbidden(path, method, extractFirstApiError(payload)?.code ?? null)"
    );
  });
});

type BusinessDecisionRow = {
  personelId: number;
  ad: string;
  soyad: string | null;
  username: string;
  password: string;
  label: string;
};

/** Business karari kayitlari: kayitli ad/soyad + beklenen canonical sonuc. */
const BUSINESS_DECISIONS: BusinessDecisionRow[] = [
  {
    personelId: 108,
    ad: "Hakan",
    soyad: "Açıkgöz",
    username: "hakanAc",
    password: "Acikgoz123",
    label: "A: Hakan Açıkgöz"
  },
  { personelId: 109, ad: "Hakan", soyad: "Atay", username: "hakanAt", password: "Atay123", label: "B: Hakan Atay" },
  {
    personelId: 200,
    ad: "RAED FAWAZ",
    soyad: null,
    username: "raedF",
    password: "Fawaz123",
    label: "C: Raed Fawaz"
  },
  {
    personelId: 201,
    ad: "SAIF TAREQ JASIM AL-GBURI",
    soyad: null,
    username: "saifA",
    password: "Algburi123",
    label: "D: Saif Tareq Jasim Al-Gburi"
  },
  {
    personelId: 206,
    ad: "ABDULLAH",
    soyad: null,
    username: "abdullah",
    password: "Abdullah123",
    label: "E: Abdullah"
  },
  {
    personelId: 207,
    ad: "OKTAY ERSÖZ",
    soyad: null,
    username: "oktayE",
    password: "Ersoz123",
    label: "F: Oktay Ersöz"
  },
  {
    personelId: 209,
    ad: "MUQTADA MAZIN KHALEE",
    soyad: null,
    username: "muqtadaK",
    password: "Khalee123",
    label: "G: Muqtada Mazin Khalee"
  },
  {
    personelId: 210,
    ad: "FAHRİ TAYLAN MERCAN",
    soyad: null,
    username: "fahriM",
    password: "Mercan123",
    label: "H: Fahri Taylan Mercan"
  }
];

describe("PERSONEL username/first-login business kararlari (explicit override + name correction)", () => {
  it("A-H: her karar kaydi icin username canonical owner'dan cozulur", () => {
    for (const row of BUSINESS_DECISIONS) {
      expect(phpResolve("username", row.personelId, row.ad, row.soyad), row.label).toBe(row.username);
    }
  });

  it("A-B: Hakan cakismasi explicit override ile cozulur; hakanA kullanilmaz", () => {
    const map = phpBusinessMaps();
    expect(map.overrides["108"]).toEqual({ username: "hakanAc" });
    expect(map.overrides["109"]).toEqual({ username: "hakanAt" });
    expect(BUSINESS_DECISIONS.filter((row) => row.username.toLowerCase() === "hakana")).toEqual([]);
  });

  it("C-H: template sifre materyali canonical kurallardan uretilir ve hash dogrulanir", () => {
    for (const row of BUSINESS_DECISIONS) {
      expect(phpResolve("password", row.personelId, row.ad, row.soyad), row.label).toBe(row.password);
      expect(phpPasswordHashRoundTrip(row.personelId, row.ad, row.soyad), row.label).toBe("OK");
    }
  });

  it("C: Raed Fawaz sifresi soyad kuralindan gelir (Fawaz123)", () => {
    expect(phpResolve("password", 200, "RAED FAWAZ", null)).toBe("Fawaz123");
    expect(phpRule("password", "Raed", "Fawaz")).toBe("Fawaz123");
    expect(phpResolve("username", 200, "RAED FAWAZ", null)).toBe("raedF");
  });

  it("D: Al-Gburi canonical normalization ile cozulur (saifA / Algburi123)", () => {
    expect(phpRule("password", "Saif Tareq Jasim", "Al-Gburi")).toBe("Algburi123");
    expect(phpRule("username", "Saif Tareq Jasim", "Al-Gburi")).toBe("saifA");
  });

  it("E: Abdullah explicit exception - soyad uydurulmaz, name correction map'inde yok", () => {
    const map = phpBusinessMaps();
    expect(map.overrides["206"]).toEqual({ username: "abdullah", initial_password: "Abdullah123" });
    expect(map.corrections["206"]).toBeUndefined();
    expect(phpResolve("username", 206, "ABDULLAH", null)).toBe("abdullah");
    expect(phpResolve("password", 206, "ABDULLAH", null)).toBe("Abdullah123");
  });

  it("name correction plani yalniz ad/soyad alanlari icin ve beklenen 6 kayit icin tanimli", () => {
    const map = phpBusinessMaps();
    expect(Object.keys(map.corrections).sort()).toEqual(["200", "201", "207", "209", "210", "219"]);
    for (const correction of Object.values(map.corrections)) {
      expect(Object.keys(correction.to).sort()).toEqual(["ad", "soyad"]);
      expect(Object.keys(correction.from).sort()).toEqual(["ad", "soyad"]);
    }
    expect(map.corrections["200"].to).toEqual({ ad: "Raed", soyad: "Fawaz" });
    expect(map.corrections["201"].to).toEqual({ ad: "Saif Tareq Jasim", soyad: "Al-Gburi" });
    expect(map.corrections["207"].to).toEqual({ ad: "Oktay", soyad: "Ersöz" });
    expect(map.corrections["209"].to).toEqual({ ad: "Muqtada Mazin", soyad: "Khalee" });
    expect(map.corrections["210"].to).toEqual({ ad: "Fahri Taylan", soyad: "Mercan" });
    // 219: kayitli bolunme duzeltilir; preimage exact kayitli degerdir.
    expect(map.corrections["219"].from).toEqual({ ad: "DOĞU", soyad: "BERKAN ATMACA" });
    expect(map.corrections["219"].to).toEqual({ ad: "Doğu Berkan", soyad: "Atmaca" });
  });

  it("219: son kelime soyad kurali ile kilitli canonical sonuc (doguA / Atmaca123)", () => {
    // Kayitli (yanlis) bolunme: ad tek kelime, soyad iki kelime -> doguB uretirdi.
    expect(phpRule("username", "DOĞU", "BERKAN ATMACA")).toBe("doguB");
    // Kilitli kural kendi basina: son kelime soyad, onceki tum kelimeler ad.
    expect(phpRule("username", "Doğu Berkan", "Atmaca")).toBe("doguA");
    expect(phpRule("password", "Doğu Berkan", "Atmaca")).toBe("Atmaca123");
    // Canonical owner kayitli satir icin correction'i uygular.
    expect(phpResolve("username", 219, "DOĞU", "BERKAN ATMACA")).toBe("doguA");
    expect(phpResolve("password", 219, "DOĞU", "BERKAN ATMACA")).toBe("Atmaca123");
    expect(phpPasswordHashRoundTrip(219, "DOĞU", "BERKAN ATMACA")).toBe("OK");
  });

  it("I: business karari setinde username collision yok", () => {
    const usernames = BUSINESS_DECISIONS.map((row) =>
      phpResolve("username", row.personelId, row.ad, row.soyad)
    );
    expect(new Set(usernames).size).toBe(BUSINESS_DECISIONS.length);
  });

  it("J: business karari setinde name unresolved yok; kural cozemezse bos doner", () => {
    for (const row of BUSINESS_DECISIONS) {
      expect(phpResolve("username", row.personelId, row.ad, row.soyad)).not.toBe("");
      expect(phpResolve("password", row.personelId, row.ad, row.soyad)).not.toBe("");
    }
    expect(phpResolve("username", 9999, "", "")).toBe("");
    expect(phpResolve("password", 9999, "", "")).toBe("");
  });

  it("K: ilkerA override/correction map'lerinde yok ve korumali listede degismedi", () => {
    const map = phpBusinessMaps();
    expect(Object.keys(map.overrides).sort()).toEqual(["108", "109", "206"]);
    expect(JSON.stringify(map)).not.toContain("ilker");
    expect(service).toContain("PROTECTED_USERNAMES = ['ilkerA']");
    expect(service).toContain("isset($protected[strtolower($username)])");
  });

  it("name correction fail-closed ve yalniz ad/soyad mutasyonu", () => {
    expect(service).toContain("PERSONEL_NAME_CORRECTION_PREIMAGE_MISMATCH");
    expect(service).toContain("nameCorrectionPreimageMismatches");
    expect(service).toContain("AND ad <=> :old_ad");
    const correctionUpdate = service.slice(
      service.indexOf("UPDATE personeller"),
      service.indexOf("WHERE id = :personel_id")
    );
    expect(correctionUpdate).toContain("SET ad = :new_ad");
    expect(correctionUpdate).toContain("soyad = :new_soyad");
    expect(correctionUpdate).not.toContain("aktif_durum");
    expect(correctionUpdate).not.toContain("sube_id");
    expect(correctionUpdate).not.toContain("sicil_no");
  });
});
