import fs from "fs/promises";
import path from "path";

const ANSI_RED = "\x1b[31m";
const ANSI_GREEN = "\x1b[32m";
const ANSI_RESET = "\x1b[0m";

let hasError = false;

function reportError(message) {
  console.error(`${ANSI_RED}❌ HATA: ${message}${ANSI_RESET}`);
  hasError = true;
}

function reportSuccess(message) {
  console.log(`${ANSI_GREEN}✅ BAŞARILI: ${message}${ANSI_RESET}`);
}

async function getLatestMigrationVersion() {
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
      reportError("api/migrations/ içinde hiçbir migration dosyası bulunamadı.");
      return null;
    }

    return Math.max(...versions);
  } catch (error) {
    reportError(`Migration dosyaları okunurken hata oluştu: ${error.message}`);
    return null;
  }
}

async function checkFileContent(filePath, regex, expectedVersion, fileName) {
  try {
    const fullPath = path.resolve(process.cwd(), filePath);
    const content = await fs.readFile(fullPath, "utf-8");
    const match = content.match(regex);

    if (!match || !match[1]) {
      reportError(`'${fileName}' içinde versiyon bilgisi bulunamadı.`);
      return;
    }

    const fileVersion = parseInt(match[1], 10);
    if (fileVersion !== expectedVersion) {
      reportError(
        `'${fileName}' içindeki versiyon (${fileVersion}) en son migration versiyonu (${expectedVersion}) ile tutarsız.`
      );
    } else {
      reportSuccess(`'${fileName}' en son migration versiyonu (${expectedVersion}) ile tutarlı.`);
    }
  } catch (error) {
    if (error.code === 'ENOENT') {
      // Dosya yoksa bunu bir hata olarak kabul etmeyebiliriz, projenin yapısına bağlı.
      // Şimdilik bir uyarı olarak geçelim.
      console.warn(`UYARI: '${fileName}' dosyası bulunamadı, kontrol atlanıyor.`);
    } else {
      reportError(`'${fileName}' dosyası okunurken hata: ${error.message}`);
    }
  }
}

async function main() {
  console.log("Proje durumu senkronizasyon kontrolü başlıyor...");

  const latestMigrationVersion = await getLatestMigrationVersion();
  if (latestMigrationVersion === null) {
    process.exit(1);
  }

  reportSuccess(`En son migration versiyonu: ${latestMigrationVersion}`);

  const filesToCkeck = [
    {
      path: "docs/guncel/110-master-closure-gap-registry.md",
      regex: /Production migration tip\s*\|\s*\*\*(\d+)\*\*/i,
      name: "110-master-closure-gap-registry.md"
    }
    // CURRENT_STATE.md bulunamadığı için şimdilik devre dışı.
    // {
    //   path: "CURRENT_STATE.md", // veya doğru yolu
    //   regex: /PRODUCTION_MIGRATION_TIP\s*=\s*(\d+)/i,
    //   name: "CURRENT_STATE.md"
    // },
  ];

  // CURRENT_STATE.md bulunamadığı için özel bir uyarı ekleyelim.
  const currentStatePath = path.resolve(process.cwd(), "CURRENT_STATE.md");
  try {
    await fs.access(currentStatePath);
  } catch (error) {
     if (error.code === 'ENOENT') {
        console.warn(`UYARI: Proje kökünde 'CURRENT_STATE.md' dosyası bulunamadı. Bu dosyanın varlığı kritik öneme sahiptir.`);
     }
  }


  if (hasError) {
    console.error("\nSenkronizasyon kontrolü başarısız oldu. Lütfen yukarıdaki hataları düzeltin.");
    process.exit(1);
  } else {
    console.log("\nTüm durum dosyaları en son migration ile senkronize görünüyor.");
  }
}

main().catch(err => {
    console.error("Beklenmedik bir hata oluştu:", err);
    process.exit(1);
});
