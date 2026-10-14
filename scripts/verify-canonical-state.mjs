import fs from 'fs/promises';
import path from 'path';

// STRICT FAIL-CLOSED SCRIPT
// Herhangi bir dosya okunamazsa, parse edilemezse veya değerler eşleşmezse non-zero exit verir.

const ANSI_RED = "\x1b[31m";
const ANSI_GREEN = "\x1b[32m";
const ANSI_RESET = "\x1b[0m";

let hasError = false;

function reportError(checker, message) {
  console.error(`${ANSI_RED}[FAIL] ${checker}: ${message}${ANSI_RESET}`);
  hasError = true;
}

function reportSuccess(checker, message) {
  console.log(`${ANSI_GREEN}[PASS] ${checker}: ${message}${ANSI_RESET}`);
}

async function getLatestFilesystemMigration() {
  const checker = "FILESYSTEM";
  try {
    const migrationDir = path.resolve(process.cwd(), "api/migrations");
    const files = await fs.readdir(migrationDir);
    const versions = files
      .map((file) => {
        const match = file.match(/^(\d+)_/);
        return match ? parseInt(match[1], 10) : 0;
      })
      .filter((num) => num > 0);

    if (versions.length === 0) {
      reportError(checker, "api/migrations/ içinde migration dosyası bulunamadı.");
      return null;
    }
    const maxVersion = Math.max(...versions);
    const paddedVersion = String(maxVersion).padStart(3, '0');
    reportSuccess(checker, `En son migration: ${paddedVersion}`);
    return paddedVersion;
  } catch (error) {
    reportError(checker, `Okuma hatası: ${error.message}`);
    return null;
  }
}

async function parseDocVersion(filePath, keyName) {
  const checker = `DOCS:${path.basename(filePath)} (${keyName})`;
  try {
    const fullPath = path.resolve(process.cwd(), filePath);
    const content = await fs.readFile(fullPath, "utf-8");
    
    // Exact match arıyoruz: "CODE_MIGRATION_TIP: 070" vb.
    const regex = new RegExp(`^${keyName}:\\s*(\\d{3})$`, "im");
    const match = content.match(regex);

    if (!match || !match[1]) {
      reportError(checker, `Değer bulunamadı veya '000' formatında değil.`);
      return null;
    }

    const version = match[1];
    reportSuccess(checker, `Okunan versiyon: ${version}`);
    return version;

  } catch (error) {
    reportError(checker, `Dosya okunamadı/bulunamadı: ${error.message}`);
    return null;
  }
}

async function parseDocLine(filePath, keyName, pattern) {
  const checker = `DOCS:${path.basename(filePath)} (${keyName})`;
  try {
    const content = await fs.readFile(path.resolve(process.cwd(), filePath), "utf-8");
    const match = content.match(new RegExp(`^${keyName}:\\s*(${pattern})\\s*$`, "im"));
    if (!match) {
      reportError(checker, `Değer bulunamadı veya beklenen biçimde değil.`);
      return null;
    }
    reportSuccess(checker, `Okunan değer: ${match[1]}`);
    return { value: match[1], content };
  } catch (error) {
    reportError(checker, `Dosya okunamadı/bulunamadı: ${error.message}`);
    return null;
  }
}

/** SHA, son PR ve tablo satırlarının başlıkla aynı olduğunu doğrular. */
async function verifyPinRows(currentStatePath, codeTip, prodTip) {
  const mainSha = await parseDocLine(currentStatePath, "CODE_MAIN_SHA", "[0-9a-f]{40}");
  const deploySha = await parseDocLine(currentStatePath, "PRODUCTION_DEPLOY_SHA", "[0-9a-f]{40}");
  const lastPr = await parseDocLine(currentStatePath, "LAST_MERGED_PR", "\\d+");
  if (!mainSha || !deploySha || !lastPr) return;
  const content = mainSha.content;
  const baseline = content.match(/^PIN_SHA_BASELINE:\s*(\S+)\s*$/m)?.[1];
  if (baseline === "LAST_PRODUCT_MERGE_DEPLOY" && mainSha.value !== deploySha.value) {
    reportError("SHA_SYNC", `CODE_MAIN_SHA (${mainSha.value.slice(0, 8)}) != PRODUCTION_DEPLOY_SHA (${deploySha.value.slice(0, 8)})`);
  } else {
    reportSuccess("SHA_SYNC", "CODE_MAIN_SHA ve PRODUCTION_DEPLOY_SHA tutarlı");
  }
  const short = mainSha.value.slice(0, 8);
  const rowChecks = [
    ["ROW:PRODUCTION_DEPLOY_SHA", new RegExp(`^\\| PRODUCTION_DEPLOY_SHA \\| \`${deploySha.value.slice(0, 8)}`, "m")],
    ["ROW:CODE_MAIN_SHA", new RegExp(`^\\| CODE_MAIN_SHA \\| \`${short}`, "m")],
    ["ROW:LAST_MERGED_PR", new RegExp(`^\\| PR #${lastPr.value} \\| \\*\\*MERGED`, "m")],
    ["ROW:MIGRATION_TIP", new RegExp(`^\\| CODE / PROD migration tip \\| \\*\\*${Number(codeTip)} / ${Number(prodTip)}\\*\\*`, "m")],
    ["TITLE:LAST_MERGED_PR", new RegExp(`^# .*POST_PR${lastPr.value}\\b`, "m")]
  ];
  for (const [checker, regex] of rowChecks) {
    if (regex.test(content)) {
      reportSuccess(checker, "Tablo satırı başlıkla tutarlı");
    } else {
      reportError(checker, `Tablo satırı başlıkla uyuşmuyor (${regex})`);
    }
  }
}

async function main() {
  console.log("Canonical State Verification Başlıyor...\n");

  const fsMigrationTip = await getLatestFilesystemMigration();
  
  const registryPath = "docs/guncel/110-master-closure-gap-registry.md";
  const currentStatePath = "CURRENT_STATE.md";

  const registryCodeTip = await parseDocVersion(registryPath, "CODE_MIGRATION_TIP");
  const registryProdTip = await parseDocVersion(registryPath, "PRODUCTION_MIGRATION_TIP");

  const currentStateCodeTip = await parseDocVersion(currentStatePath, "CODE_MIGRATION_TIP");
  const currentStateProdTip = await parseDocVersion(currentStatePath, "PRODUCTION_MIGRATION_TIP");

  // Eğer değerlerden biri null ise, eksik/okunamamış demektir (Fail-Closed)
  if ([fsMigrationTip, registryCodeTip, registryProdTip, currentStateCodeTip, currentStateProdTip].includes(null)) {
    console.error(`\n${ANSI_RED}KRİTİK HATA: Okunamayan canonical dosyalar var.${ANSI_RESET}`);
    process.exit(1);
  }

  console.log("\n--- Invariant Kontrolleri ---");

  // 1. fs_latest == CURRENT_STATE.CODE == REGISTRY.CODE
  if (fsMigrationTip !== currentStateCodeTip) {
    reportError("CODE_SYNC", `FS Tip (${fsMigrationTip}) != CURRENT_STATE CODE (${currentStateCodeTip})`);
  } else {
    reportSuccess("CODE_SYNC", "FS Tip == CURRENT_STATE CODE");
  }

  if (fsMigrationTip !== registryCodeTip) {
    reportError("CODE_SYNC", `FS Tip (${fsMigrationTip}) != REGISTRY CODE (${registryCodeTip})`);
  } else {
    reportSuccess("CODE_SYNC", "FS Tip == REGISTRY CODE");
  }

  // 2. CURRENT_STATE.PROD == REGISTRY.PROD
  if (currentStateProdTip !== registryProdTip) {
    reportError("PROD_SYNC", `CURRENT_STATE PROD (${currentStateProdTip}) != REGISTRY PROD (${registryProdTip})`);
  } else {
    reportSuccess("PROD_SYNC", "CURRENT_STATE PROD == REGISTRY PROD");
  }

  // 3. PROD <= CODE
  const prodVal = parseInt(currentStateProdTip, 10);
  const codeVal = parseInt(currentStateCodeTip, 10);
  if (prodVal > codeVal) {
    reportError("INVARIANT", `PROD TIP (${prodVal}) > CODE TIP (${codeVal}) olamaz.`);
  } else {
    reportSuccess("INVARIANT", "PROD TIP <= CODE TIP");
  }

  await verifyPinRows(currentStatePath, currentStateCodeTip, currentStateProdTip);

  if (hasError) {
    console.error(`\n${ANSI_RED}‼️  Canonical state doğrulama BAŞARISIZ.${ANSI_RESET}`);
    process.exit(1);
  } else {
    console.log(`\n${ANSI_GREEN}🎉 Tüm durum dosyaları TUTARLI ve GEÇERLİ.${ANSI_RESET}`);
  }
}

main().catch(err => {
  console.error("Beklenmeyen hata:", err);
  process.exit(1);
});
