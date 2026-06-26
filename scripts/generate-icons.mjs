import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";
import sharp from "sharp";
import toIco from "to-ico";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(__dirname, "..");
const SOURCE = path.join(ROOT, "icons", "medisa personel.png");
const OUT_DIR = path.join(ROOT, "public", "icons");

const THEME_BG = { r: 8, g: 13, b: 22, alpha: 1 };
const MASKABLE_BG = { r: 0, g: 0, b: 0, alpha: 1 };

const PNG_SIZES = [16, 32, 48, 72, 96, 128, 144, 152, 167, 180, 192, 256, 384, 512];

async function ensureSource() {
  try {
    await fs.access(SOURCE);
  } catch {
    throw new Error(`Kaynak ikon bulunamadi: ${SOURCE}`);
  }
}

async function writePng(size, filename) {
  await sharp(SOURCE)
    .resize(size, size, { fit: "contain", background: MASKABLE_BG })
    .png({ compressionLevel: 9 })
    .toFile(path.join(OUT_DIR, filename));
}

async function writeMaskable512() {
  const innerSize = Math.round(512 * 0.8);
  const inner = await sharp(SOURCE)
    .resize(innerSize, innerSize, { fit: "contain", background: MASKABLE_BG })
    .png()
    .toBuffer();

  await sharp({
    create: {
      width: 512,
      height: 512,
      channels: 4,
      background: MASKABLE_BG
    }
  })
    .composite([{ input: inner, gravity: "center" }])
    .png({ compressionLevel: 9 })
    .toFile(path.join(OUT_DIR, "icon-512-maskable.png"));
}

async function writeOgImage() {
  const logoSize = 420;
  const logo = await sharp(SOURCE)
    .resize(logoSize, logoSize, { fit: "contain", background: THEME_BG })
    .png()
    .toBuffer();

  await sharp({
    create: {
      width: 1200,
      height: 630,
      channels: 4,
      background: THEME_BG
    }
  })
    .composite([{ input: logo, gravity: "center" }])
    .png({ compressionLevel: 9 })
    .toFile(path.join(OUT_DIR, "og-image.png"));
}

async function writeFaviconIco() {
  const buffers = await Promise.all(
    [16, 32, 48].map((size) =>
      sharp(SOURCE)
        .resize(size, size, { fit: "contain", background: MASKABLE_BG })
        .png()
        .toBuffer()
    )
  );

  const ico = await toIco(buffers);
  await fs.writeFile(path.join(OUT_DIR, "favicon.ico"), ico);
}

async function main() {
  await ensureSource();
  await fs.mkdir(OUT_DIR, { recursive: true });

  for (const size of PNG_SIZES) {
    const name =
      size === 180
        ? "apple-touch-icon.png"
        : size === 192
          ? "icon-192.png"
          : size === 512
            ? "icon-512.png"
            : `icon-${size}x${size}.png`;
    await writePng(size, name);
  }

  await writeMaskable512();
  await writeOgImage();
  await writeFaviconIco();

  console.log(`Ikonlar uretildi: ${OUT_DIR}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
