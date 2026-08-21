import fs from "fs/promises";
import path from "path";

const ANSI_RED = "\x1b[31m";
const ANSI_GREEN = "\x1b[32m";
const ANSI_YELLOW = "\x1b[33m";
const ANSI_RESET = "\x1b[0m";

let hasError = false;

function reportError(checker, message) {
  console.error(`${ANSI_RED}❌ [${checker}] HATA: ${message}${ANSI_RESET}`);
  hasError = true;
}

function reportSuccess(checker, message) {
  console.log(`${ANSI_GREEN}✅ [${checker}] BAŞARILI: ${message}${ANSI_RESET}`);
}

function normalizeVersion(versionStr) {
    if (versionStr === null || versionStr === undefined) return null;
    const padded = String(versionStr).padStart(3, '0');
    return /^\d{3}$/.test(padded) ? padded : null;
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
      reportError(checker, "api/migrations/ içinde hiçbir migration dosyası bulunamadı.");
      return null;
    }
    const maxVersion = Math.max(...versions);
    const normalized = normalizeVersion(maxVersion);
    if (!normalized) {
        reportError(checker, `Geçersiz migration versiyonu bulundu: ${maxVersion}`);
        return null;
    }
    reportSuccess(checker, `En son migration versiyonu: ${normalized}`);
    return normalized;
  } catch (error) {
    reportError(checker, `Migration dosyaları okunurken hata oluştu: ${error.message}`);
    return null;
  }
}

async function parseDocVersion(filePath, type) {
    const checker = `DOCS:${path.basename(filePath)}`;
    try {
        const fullPath = path.resolve(process.cwd(), filePath);
        const content = await fs.readFile(fullPath, "utf-8");
        const regex = new RegExp(`^${type}:\\s*(\\d+)`, "im");
        const match = content.match(regex);

        if (!match || !match[1]) {
            reportError(checker, `'${type}' tipi için versiyon bilgisi bulunamadı.`);
            return null;
        }

        const version = normalizeVersion(match[1]);
        if (!version) {
            reportError(checker, `'${type}' tipi için geçerli bir versiyon numarası bulunamadı: "${match[1]}"`);
            return null;
        }
        reportSuccess(checker, `'${type}' tipi için bulunan versiyon: ${version}`);
        return version;

    } catch (error) {
        if (error.code === 'ENOENT') {
            reportError(checker, "Dosya bulunamadı. Bu dosyanın varlığı zorunludur.");
        } else {
            reportError(checker, `Dosya okunurken hata: ${error.message}`);
        }
        return null;
    }
}


async function main() {
  console.log("Proje durumu senkronizasyon kontrolü başlıyor...\n");

  const codeMigrationTipFs = await getLatestFilesystemMigration();
  if (!codeMigrationTipFs) {
    process.exit(1);
  }

  console.log("\n--- Canonical Doküman Kontrolleri ---");
  const registryPath = "docs/guncel/110-master-closure-gap-registry.md";
  const currentStatePath = "CURRENT_STATE.md";

  const registryCodeTip = await parseDocVersion(registryPath, "CODE_MIGRATION_TIP");
  const registryProdTip = await parseDocVersion(registryPath, "PRODUCTION_MIGRATION_TIP");

  const currentStateCodeTip = await parseDocVersion(currentStatePath, "CODE_MIGRATION_TIP");
  const currentStateProdTip = await parseDocVersion(currentStatePath, "PRODUCTION_MIGRATION_TIP");

  if ([registryCodeTip, registryProdTip, currentStateCodeTip, currentStateProdTip].includes(null)) {
    hasError = true;
  } else {
    console.log("\n--- Mantıksal Tutarlılık Kontrolleri ---");
    // Invariant 1: Dokümanlar kendi içinde ve birbiriyle tutarlı olmalı.
    if (registryCodeTip !== currentStateCodeTip) {
      reportError("TUTARLILIK", `Registry CODE_TIP (${registryCodeTip}) ile CURRENT_STATE CODE_TIP (${currentStateCodeTip}) çelişiyor.`);
    } else {
      reportSuccess("TUTARLILIK", `CODE_TIP dokümanlar arası tutarlı: ${registryCodeTip}`);
    }
    if (registryProdTip !== currentStateProdTip) {
      reportError("TUTARLILIK", `Registry PROD_TIP (${registryProdTip}) ile CURRENT_STATE PROD_TIP (${currentStateProdTip}) çelişiyor.`);
    } else {
      reportSuccess("TUTARLILIK", `PROD_TIP dokümanlar arası tutarlı: ${registryProdTip}`);
    }

    // Invariant 2: Dokümanlardaki CODE_TIP, dosya sistemindeki en son migration ile eşleşmeli.
    if (currentStateCodeTip !== codeMigrationTipFs) {
        reportError("CODE_SYNC", `CURRENT_STATE CODE_TIP (${currentStateCodeTip}), dosya sistemindeki en son migration (${codeMigrationTipFs}) ile eşleşmiyor.`);
    } else {
        reportSuccess("CODE_SYNC", "Dokümanlardaki CODE_TIP, dosya sistemi ile senkronize.");
    }

    // Invariant 3: PRODUCTION_TIP, CODE_TIP'ten büyük olamaz.
    if (parseInt(currentStateProdTip, 10) > parseInt(currentStateCodeTip, 10)) {
        reportError("MANTIK", `PRODUCTION_TIP (${currentStateProdTip}), CODE_TIP'ten (${currentStateCodeTip}) büyük olamaz.`);
    } else {
        reportSuccess("MANTIK", "PRODUCTION_TIP <= CODE_TIP kuralı sağlanıyor.");
    }
  }


  if (hasError) {
    console.error(`\n${ANSI_RED}‼️  Senkronizasyon kontrolü BAŞARISIZ oldu. Lütfen yukarıdaki hataları düzeltin.${ANSI_RESET}`);
    process.exit(1);
  } else {
    console.log(`\n${ANSI_GREEN}🎉 Tüm durum dosyaları ve migration'lar birbiriyle tutarlı.${ANSI_RESET}`);
  }
}

main().catch(err => {
    console.error("Beklenmedik bir hata oluştu:", err);
    process.exit(1);
});
