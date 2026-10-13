/**
 * t871: ön yüz kaynaklarından backend aynalarını üretir.
 *   GEN_PHP=1 npx vite-node scripts/gen-ozel-mesajlar-php.ts
 * Üretilen: api/src/Http/OzelMesajlar.php, api/src/Http/FieldLabels.php
 */
import { writeFileSync } from "node:fs";
import { OZEL_MESAJ_KURALLARI } from "../src/lib/display/ozel-mesajlar";
import { FIELD_LABELS } from "../src/lib/display/field-labels";

const q = (s: string) => `'${s.replace(/\\/g, "\\\\").replace(/'/g, "\\'")}'`;

export function ozelMesajlarPhp(): string {
  const rows = OZEL_MESAJ_KURALLARI.map((k) => `        [${k.satir}, ${q(k.desen)}, ${q(k.metin)}],`).join("\n");
  return `<?php

declare(strict_types=1);

namespace Medisa\\Api\\Http;

/**
 * ÜRETİLMİŞ DOSYA — elle düzenlemeyin. Kaynak: src/lib/display/ozel-mesajlar.ts
 * (GEN_PHP=1 npx vite-node scripts/gen-ozel-mesajlar-php.ts). Onaylı karar t871 (OZEL-MESAJLAR-KISA.md, 39 satır).
 */
final class OzelMesajlar
{
    /** @var list<array{0:int,1:string,2:string}> [satır, desen, metin] */
    public const KURALLAR = [
${rows}
    ];

    public static function forMessage(string $message, string $code = ''): ?string
    {
        $raw = trim($message);
        if ($raw === '') {
            return null;
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower(str_replace('I', 'ı', str_replace('İ', 'i', $raw)), 'UTF-8') : strtolower($raw);
        $adaylar = [$lower, $raw, trim($code . ' ' . $lower)];
        foreach (self::KURALLAR as [, $desen, $metin]) {
            foreach ($adaylar as $t) {
                if (@preg_match('~' . $desen . '~iu', $t) === 1) {
                    return $metin;
                }
            }
        }
        return null;
    }
}
`;
}

export function fieldLabelsPhp(): string {
  const rows = Object.entries(FIELD_LABELS).map(([k, v]) => `        ${q(k)} => ${q(v)},`).join("\n");
  return `<?php

declare(strict_types=1);

namespace Medisa\\Api\\Http;

/**
 * ÜRETİLMİŞ DOSYA — elle düzenlemeyin. Kaynak: src/lib/display/field-labels.ts
 * (GEN_PHP=1 npx vite-node scripts/gen-ozel-mesajlar-php.ts). Onaylı karar t869/t871: alan anahtarı → alan adı (Title Case).
 */
final class FieldLabels
{
    public const LABELS = [
${rows}
    ];

    public static function labelOf(?string $field): ?string
    {
        if ($field === null || $field === '') {
            return null;
        }
        return self::LABELS[$field] ?? null;
    }
}
`;
}

if (process.env.GEN_PHP === "1") {
  writeFileSync("api/src/Http/OzelMesajlar.php", ozelMesajlarPhp());
  writeFileSync("api/src/Http/FieldLabels.php", fieldLabelsPhp());
  console.log("ok");
}
