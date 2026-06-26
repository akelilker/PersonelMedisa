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
const TRANSPARENT = { r: 0, g: 0, b: 0, alpha: 0 };

const PNG_SIZES = [16, 32, 48, 72, 96, 128, 144, 152, 167, 180, 192, 256, 384, 512];

/** Kenarlardan ulasilan siyah zemin piksellerini seffaf yapar; logodaki siyah detaylar korunur. */
async function loadSourceWithTransparentBg() {
  const { data, info } = await sharp(SOURCE).ensureAlpha().raw().toBuffer({ resolveWithObject: true });
  const { width, height, channels } = info;
  const isBlack = (idx) => data[idx] < 30 && data[idx + 1] < 30 && data[idx + 2] < 30;
  const visited = new Uint8Array(width * height);
  const queue = [];

  const tryPush = (x, y) => {
    if (x < 0 || y < 0 || x >= width || y >= height) {
      return;
    }
    const pos = y * width + x;
    if (visited[pos]) {
      return;
    }
    const idx = pos * channels;
    if (!isBlack(idx)) {
      return;
    }
    visited[pos] = 1;
    queue.push(pos);
  };

  for (let x = 0; x < width; x += 1) {
    tryPush(x, 0);
    tryPush(x, height - 1);
  }
  for (let y = 0; y < height; y += 1) {
    tryPush(0, y);
    tryPush(width - 1, y);
  }

  while (queue.length > 0) {
    const pos = queue.pop();
    const x = pos % width;
    const y = (pos - x) / width;
    tryPush(x - 1, y);
    tryPush(x + 1, y);
    tryPush(x, y - 1);
    tryPush(x, y + 1);
  }

  for (let pos = 0; pos < visited.length; pos += 1) {
    if (visited[pos]) {
      data[pos * channels + 3] = 0;
    }
  }

  return sharp(data, { raw: info }).png();
}

async function ensureSource() {
  try {
    await fs.access(SOURCE);
  } catch {
    throw new Error(`Kaynak ikon bulunamadi: ${SOURCE}`);
  }
}

async function writePng(source, size, filename) {
  await source
    .clone()
    .resize(size, size, { fit: "contain", background: TRANSPARENT })
    .png({ compressionLevel: 9 })
    .toFile(path.join(OUT_DIR, filename));
}

async function writeMaskable512(source) {
  const innerSize = Math.round(512 * 0.8);
  const inner = await source
    .clone()
    .resize(innerSize, innerSize, { fit: "contain", background: TRANSPARENT })
    .png()
    .toBuffer();

  await sharp({
    create: {
      width: 512,
      height: 512,
      channels: 4,
      background: TRANSPARENT
    }
  })
    .composite([{ input: inner, gravity: "center" }])
    .png({ compressionLevel: 9 })
    .toFile(path.join(OUT_DIR, "icon-512-maskable.png"));
}

async function writeOgImage(source) {
  const logoSize = 420;
  const logo = await source
    .clone()
    .resize(logoSize, logoSize, { fit: "contain", background: TRANSPARENT })
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

async function writeFaviconIco(source) {
  const buffers = await Promise.all(
    [16, 32, 48].map((size) =>
      source
        .clone()
        .resize(size, size, { fit: "contain", background: TRANSPARENT })
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

  const source = await loadSourceWithTransparentBg();

  for (const size of PNG_SIZES) {
    const name =
      size === 180
        ? "apple-touch-icon.png"
        : size === 192
          ? "icon-192.png"
          : size === 512
            ? "icon-512.png"
            : `icon-${size}x${size}.png`;
    await writePng(source, size, name);
  }

  await writeMaskable512(source);
  await writeOgImage(source);
  await writeFaviconIco(source);

  console.log(`Ikonlar uretildi: ${OUT_DIR}`);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
