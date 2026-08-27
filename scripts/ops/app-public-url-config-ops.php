<?php

declare(strict_types=1);

/**
 * Privacy-safe owner for a single config.local.php key: app_public_url.
 *
 * Hard-locked:
 * - TARGET_KEY is fixed (no generic key input)
 * - Commands never print sibling secrets / full config
 * - Remote path is owned by the GitHub Actions workflow (not this CLI)
 *
 * Usage:
 *   php app-public-url-config-ops.php validate-url --value=URL
 *   php app-public-url-config-ops.php get --file=PATH
 *   php app-public-url-config-ops.php patch --file=PATH --out=PATH --value=URL
 *   php app-public-url-config-ops.php assert-unrelated-equal --before=PATH --after=PATH
 *   php app-public-url-config-ops.php self-test
 */

const APP_PUBLIC_URL_TARGET_KEY = 'app_public_url';

final class AppPublicUrlConfigOps
{
    public static function main(array $argv): int
    {
        $command = isset($argv[1]) ? (string) $argv[1] : '';
        $opts = self::parseOpts(array_slice($argv, 2));

        try {
            switch ($command) {
                case 'validate-url':
                    self::validateUrl(self::requireOpt($opts, 'value'));
                    echo "URL_VALID=YES\n";
                    return 0;
                case 'get':
                    return self::cmdGet(self::requireOpt($opts, 'file'));
                case 'patch':
                    return self::cmdPatch(
                        self::requireOpt($opts, 'file'),
                        self::requireOpt($opts, 'out'),
                        self::requireOpt($opts, 'value')
                    );
                case 'assert-unrelated-equal':
                    return self::cmdAssertUnrelatedEqual(
                        self::requireOpt($opts, 'before'),
                        self::requireOpt($opts, 'after')
                    );
                case 'self-test':
                    return self::cmdSelfTest();
                default:
                    fwrite(STDERR, "Unknown or missing command.\n");
                    return 2;
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'APP_PUBLIC_URL_OPS_ERROR=' . self::safeErrorCode($e->getMessage()) . "\n");
            return 1;
        }
    }

    /** @param list<string> $args */
    private static function parseOpts(array $args): array
    {
        $opts = [];
        foreach ($args as $arg) {
            if (strpos($arg, '--') !== 0) {
                continue;
            }
            $body = substr($arg, 2);
            $eq = strpos($body, '=');
            if ($eq === false) {
                $opts[$body] = '1';
                continue;
            }
            $opts[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
        return $opts;
    }

    private static function requireOpt(array $opts, string $name): string
    {
        if (!isset($opts[$name]) || $opts[$name] === '') {
            throw new RuntimeException('MISSING_OPT_' . strtoupper($name));
        }
        return (string) $opts[$name];
    }

    public static function validateUrl(string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('URL_EMPTY');
        }
        if (substr($value, -1) === '/') {
            throw new RuntimeException('URL_TRAILING_SLASH');
        }
        if (!preg_match('#^https://[A-Za-z0-9.-]+(?::[0-9]{2,5})?(?:/[A-Za-z0-9._~/-]*)?$#', $value)) {
            throw new RuntimeException('URL_SHAPE_INVALID');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
            throw new RuntimeException('URL_SCHEME_INVALID');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('URL_UNSAFE_COMPONENTS');
        }
        if (!isset($parts['host']) || $parts['host'] === '') {
            throw new RuntimeException('URL_HOST_MISSING');
        }
    }

    private static function cmdGet(string $file): int
    {
        $config = self::loadConfigArray($file);
        if (!array_key_exists(APP_PUBLIC_URL_TARGET_KEY, $config)) {
            echo "APP_PUBLIC_URL=MISSING\n";
            echo "KEY_PRESENT=NO\n";
            return 0;
        }
        $raw = $config[APP_PUBLIC_URL_TARGET_KEY];
        if (!is_string($raw)) {
            throw new RuntimeException('URL_TYPE_INVALID');
        }
        $trimmed = trim($raw);
        // Read path is privacy-safe but must not reject an existing bad value —
        // patch/validate-url own the authorized write-shape gate.
        if ($trimmed === '') {
            echo "APP_PUBLIC_URL=\n";
            echo "KEY_PRESENT=YES\n";
            echo "VALUE_EMPTY=YES\n";
            return 0;
        }
        if (preg_match('/[\r\n]/', $trimmed)) {
            throw new RuntimeException('URL_CONTROL_CHARS');
        }
        echo 'APP_PUBLIC_URL=' . $trimmed . "\n";
        echo "KEY_PRESENT=YES\n";
        echo "VALUE_EMPTY=NO\n";
        return 0;
    }

    private static function cmdPatch(string $file, string $out, string $value): int
    {
        self::validateUrl($value);
        $before = self::loadConfigArray($file);
        $source = self::readFile($file);
        $patched = self::patchSource($source, $value);
        if ($out === $file) {
            throw new RuntimeException('OUT_MUST_DIFFER');
        }
        if (file_put_contents($out, $patched) === false) {
            throw new RuntimeException('WRITE_FAILED');
        }
        $after = self::loadConfigArray($out);
        if (!isset($after[APP_PUBLIC_URL_TARGET_KEY]) || !is_string($after[APP_PUBLIC_URL_TARGET_KEY])) {
            throw new RuntimeException('PATCH_VERIFY_FAILED');
        }
        if (trim((string) $after[APP_PUBLIC_URL_TARGET_KEY]) !== $value) {
            throw new RuntimeException('PATCH_VALUE_MISMATCH');
        }
        $delta = self::unrelatedDelta($before, $after);
        if ($delta !== 0) {
            @unlink($out);
            throw new RuntimeException('UNRELATED_CONFIG_DELTA');
        }
        echo "PATCH_OK=YES\n";
        echo 'APP_PUBLIC_URL=' . $value . "\n";
        echo "UNRELATED_CONFIG_KEYS_CHANGED=0\n";
        return 0;
    }

    private static function cmdAssertUnrelatedEqual(string $beforePath, string $afterPath): int
    {
        $before = self::loadConfigArray($beforePath);
        $after = self::loadConfigArray($afterPath);
        $delta = self::unrelatedDelta($before, $after);
        echo 'UNRELATED_CONFIG_KEYS_CHANGED=' . $delta . "\n";
        if ($delta !== 0) {
            throw new RuntimeException('UNRELATED_CONFIG_DELTA');
        }
        return 0;
    }

    /** @return array<string, mixed> */
    private static function loadConfigArray(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('FILE_MISSING');
        }
        /** @var mixed $loaded */
        $loaded = require $file;
        if (!is_array($loaded)) {
            throw new RuntimeException('CONFIG_NOT_ARRAY');
        }
        return $loaded;
    }

    private static function readFile(string $file): string
    {
        $source = file_get_contents($file);
        if ($source === false || $source === '') {
            throw new RuntimeException('FILE_READ_FAILED');
        }
        return $source;
    }

    /**
     * Replace or insert only app_public_url using PHP tokens (no full rewrite).
     */
    public static function patchSource(string $source, string $newValue): string
    {
        self::validateUrl($newValue);
        $tokens = token_get_all($source);
        $keyLiteral = "'" . APP_PUBLIC_URL_TARGET_KEY . "'";
        $valueLiteral = "'" . self::phpSingleQuoted($newValue) . "'";

        $keyIndex = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $lit = $tok[1];
            if ($lit !== $keyLiteral && $lit !== '"' . APP_PUBLIC_URL_TARGET_KEY . '"') {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && self::isIgnorableToken($tokens[$j])) {
                $j++;
            }
            if ($j >= $count || $tokens[$j] !== '=>') {
                continue;
            }
            $keyIndex = $i;
            break;
        }

        if ($keyIndex !== null) {
            $arrow = $keyIndex + 1;
            while ($arrow < $count && self::isIgnorableToken($tokens[$arrow])) {
                $arrow++;
            }
            $valueIndex = $arrow + 1;
            while ($valueIndex < $count && self::isIgnorableToken($tokens[$valueIndex])) {
                $valueIndex++;
            }
            if ($valueIndex >= $count || !is_array($tokens[$valueIndex])) {
                throw new RuntimeException('VALUE_TOKEN_MISSING');
            }
            $valueTok = $tokens[$valueIndex];
            if ($valueTok[0] !== T_CONSTANT_ENCAPSED_STRING
                && $valueTok[0] !== T_LNUMBER
                && $valueTok[0] !== T_DNUMBER
                && !($valueTok[0] === T_STRING && in_array(strtolower($valueTok[1]), ['true', 'false', 'null'], true))
            ) {
                throw new RuntimeException('VALUE_TOKEN_UNSUPPORTED');
            }
            $tokens[$valueIndex][1] = $valueLiteral;
            // Normalize key quoting to single quotes for stable output.
            $tokens[$keyIndex][1] = $keyLiteral;
            return self::tokensToSource($tokens);
        }

        return self::insertKeyBeforeClosing($source, $keyLiteral . ' => ' . $valueLiteral . ',');
    }

    private static function insertKeyBeforeClosing(string $source, string $assignment): string
    {
        if (!preg_match('/\];\s*$/', $source)) {
            throw new RuntimeException('INSERT_ANCHOR_MISSING');
        }
        return preg_replace('/\];\s*$/', '    ' . $assignment . "\n];\n", $source, 1) ?? $source;
    }

    /** @param list<string|array{0:int,1:string,2?:int}> $tokens */
    private static function tokensToSource(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $tok) {
            $out .= is_array($tok) ? $tok[1] : $tok;
        }
        return $out;
    }

    /** @param string|array{0:int,1:string,2?:int} $tok */
    private static function isIgnorableToken($tok): bool
    {
        if (!is_array($tok)) {
            return false;
        }
        return $tok[0] === T_WHITESPACE || $tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT;
    }

    private static function phpSingleQuoted(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    public static function unrelatedDelta(array $before, array $after): int
    {
        $delta = 0;
        $keys = array_unique(array_merge(array_keys($before), array_keys($after)));
        foreach ($keys as $key) {
            if ($key === APP_PUBLIC_URL_TARGET_KEY) {
                continue;
            }
            $hasBefore = array_key_exists($key, $before);
            $hasAfter = array_key_exists($key, $after);
            if ($hasBefore !== $hasAfter) {
                $delta++;
                continue;
            }
            if (!$hasBefore) {
                continue;
            }
            if (!self::valuesEqual($before[$key], $after[$key])) {
                $delta++;
            }
        }
        return $delta;
    }

    /** @param mixed $a @param mixed $b */
    private static function valuesEqual($a, $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return serialize($a) === serialize($b);
        }
        return $a === $b;
    }

    private static function safeErrorCode(string $message): string
    {
        if (preg_match('/^[A-Z0-9_]+$/', $message)) {
            return $message;
        }
        return 'OPS_FAILED';
    }

    private static function cmdSelfTest(): int
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'app-public-url-ops-' . bin2hex(random_bytes(4));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('SELFTEST_TMP_FAILED');
        }
        try {
            $before = $dir . DIRECTORY_SEPARATOR . 'before.php';
            $after = $dir . DIRECTORY_SEPARATOR . 'after.php';
            $secret = 'super-secret-db-password-do-not-leak';
            file_put_contents($before, "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
                . "    'db_password' => '{$secret}',\n"
                . "    'jwt_secret' => 'abcdefghijklmnopqrstuvwxyz012345',\n"
                . "    'app_public_url' => '',\n"
                . "];\n");

            $url = 'https://www.example.com/personelmedisa';
            self::validateUrl($url);
            $patchedSource = self::patchSource((string) file_get_contents($before), $url);
            file_put_contents($after, $patchedSource);

            $beforeArr = self::loadConfigArray($before);
            $afterArr = self::loadConfigArray($after);
            if (trim((string) $afterArr[APP_PUBLIC_URL_TARGET_KEY]) !== $url) {
                throw new RuntimeException('SELFTEST_VALUE');
            }
            if (self::unrelatedDelta($beforeArr, $afterArr) !== 0) {
                throw new RuntimeException('SELFTEST_DELTA');
            }
            if (strpos($patchedSource, $secret) === false) {
                throw new RuntimeException('SELFTEST_SECRET_PRESERVED');
            }

            // Missing key insert path
            $missing = $dir . DIRECTORY_SEPARATOR . 'missing.php';
            $missingOut = $dir . DIRECTORY_SEPARATOR . 'missing-out.php';
            file_put_contents($missing, "<?php\nreturn [\n    'db_password' => '{$secret}',\n];\n");
            file_put_contents($missingOut, self::patchSource((string) file_get_contents($missing), $url));
            $missingAfter = self::loadConfigArray($missingOut);
            if (trim((string) $missingAfter[APP_PUBLIC_URL_TARGET_KEY]) !== $url) {
                throw new RuntimeException('SELFTEST_INSERT');
            }
            if (self::unrelatedDelta(self::loadConfigArray($missing), $missingAfter) !== 0) {
                throw new RuntimeException('SELFTEST_INSERT_DELTA');
            }

            echo "SELF_TEST=PASS\n";
            return 0;
        } finally {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}

exit(AppPublicUrlConfigOps::main($argv));
