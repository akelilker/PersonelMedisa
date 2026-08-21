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
