/**
 * dist/ icindekileri zip'ler (alt klasor olmadan kokte).
 * cPanel'de personelmedisa/ icine cikar; src, .git, node_modules YUKLEME.
 */
import { createWriteStream, existsSync } from "node:fs";
import { join, dirname } from "node:path";
import { fileURLToPath } from "node:url";
import archiver from "archiver";

const root = join(dirname(fileURLToPath(import.meta.url)), "..");
const distDir = join(root, "dist");
const outFile = join(root, "personelmedisa-cpanel-upload.zip");

if (!existsSync(distDir)) {
  console.error("dist/ bulunamadi. Once calistirin: npm run build");
  process.exit(1);
}

const output = createWriteStream(outFile);
const archive = archiver("zip", { zlib: { level: 9 } });

const finished = new Promise((resolve, reject) => {
  output.on("close", resolve);
  output.on("error", reject);
  archive.on("error", reject);
});

archive.pipe(output);
archive.directory(distDir, false);
await archive.finalize();
await finished;

const kb = Math.round(archive.pointer() / 1024);
console.log(`Paket: ${outFile} (${kb} KB)`);
console.log("Sunucuda: Eski dosyalari silip sadece zip icindekileri yukleyin.");
