import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { describe, expect, it } from "vitest";
import { formatIsoDateTimeDetail } from "../../src/lib/display/iso-date-format";

const root = process.cwd();
const read = (path: string) => readFileSync(resolve(root, path), "utf8");
const controller = read("api/src/Controllers/VeriYedeklemeController.php");
const service = read("api/src/Database/FullDatabaseBackupService.php");
const router = read("api/src/Router.php");
const modal = read("src/features/yonetim/veri-yedekleme/VeriYedeklemeModal.tsx");
const header = read("src/components/shell/ShellHeaderActions.tsx");

describe("Veri Yedekleme (Ayarlar)", () => {
  it("üç uç yalnız GENEL_YONETICI ve geri yükleme ucu yok", () => {
    expect(router).toContain("VeriYedeklemeController::durum");
    expect(router).toContain("VeriYedeklemeController::olustur");
    expect(router).toContain("VeriYedeklemeController::indir");
    expect(router).not.toMatch(/veri-yedekleme\/geri-yukle/);
    expect(controller.match(/self::assertGenelYonetici\(\$request\)/g)?.length).toBe(3);
    expect(controller).toContain("!== 'GENEL_YONETICI'");
  });

  it("tutarlı anlık görüntü, web kökü dışı dizin, sha256 ve son 7 saklama", () => {
    const block = service.slice(service.indexOf("function createAppBackup"), service.indexOf("function listAppBackups"));
    expect(block).toContain("START TRANSACTION WITH CONSISTENT SNAPSHOT");
    expect(block).toContain("MigrationBackupService::operationsDirectory");
    expect(block).toContain("verifyFile");
    expect(block).not.toContain("full-backup-evidence");
    expect(block).not.toContain("CREATE DATABASE");
    expect(service).toContain("APP_BACKUP_KEEP = 7");
  });

  it("indirme ad kalıbı, dizin içi olma ve sha256 eşleşmesi ister; denetlenir", () => {
    const block = service.slice(service.indexOf("function resolveAppBackupForDownload"), service.indexOf("function pruneAppBackups"));
    expect(block).toContain("isAppBackupName");
    expect(block).toContain("pathInside");
    expect(block).toContain("hash_equals");
    expect(controller).toContain("self::audit('YEDEK_INDIR'");
    expect(controller).toContain("Cache-Control: no-store");
    expect(controller).not.toMatch(/absolute_path|error_log\(/);
  });

  it("modal başlık, geri satırı ve metinler", () => {
    expect(modal).toContain('title="VERİ YEDEKLEME"');
    // geri satırı: kırmızı header içinde değil, .modal-body > .universal-back-bar (onaylı karar)
    expect(modal).toContain('<SurecInlineBackButton label="Ayarlar"');
    expect(modal).not.toContain("backLabel=");
    expect(modal).toContain("Yedek Al");
    expect(modal).toContain("Yedekten Geri Yükle");
    expect(modal).toContain("Sunucudaki Son Yedekleme Dosyası:");
    expect(modal).toContain("Yedek Almak İçin &quot;Yedek Al&quot; Butonunu Kullanınız.");
    expect(modal).toContain("formatIsoDateTimeDetail");
    expect(header).toContain('data-testid="settings-veri-yedekleme"');
    expect(header).toContain('role === "GENEL_YONETICI"');
  });

  it("tarih-saat gg/aa/yyyy HH:MM:SS (İstanbul)", () => {
    expect(formatIsoDateTimeDetail("2026-10-12T00:30:05Z")).toBe("12/10/2026 03:30:05");
    expect(formatIsoDateTimeDetail("")).toBe("-");
    expect(formatIsoDateTimeDetail("bozuk")).toBe("-");
  });
});
