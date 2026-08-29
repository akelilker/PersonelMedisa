<?php

declare(strict_types=1);

/**
 * Privacy-safe owner for a single config.local.php key: standard_initial_password_hash.
 *
 * Hard-locked:
 * - TARGET_KEY is fixed (no generic key input)
 * - The value is only ever read from a file (never argv, never printed)
 * - Commands print status flags only: never the hash, never sibling secrets,
 *   never the full config
 * - Remote path is owned by the GitHub Actions workflow (not this CLI)
 *
 * Usage:
 *   php standard-initial-password-config-ops.php target-key
 *   php standard-initial-password-config-ops.php validate-hash --hash-file=PATH
 *   php standard-initial-password-config-ops.php get --file=PATH
 *   php standard-initial-password-config-ops.php patch --file=PATH --out=PATH --hash-file=PATH
 *   php standard-initial-password-config-ops.php assert-unrelated-equal --before=PATH --after=PATH
 *   php standard-initial-password-config-ops.php self-test
 */

const STANDARD_INITIAL_PASSWORD_TARGET_KEY = 'standard_initial_password_hash';

/** Canonical bcrypt shape produced by PASSWORD_BCRYPT. */
const STANDARD_INITIAL_PASSWORD_HASH_PATTERN = '/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/';

final class StandardInitialPasswordConfigOps
{
    public static function main(array $argv): int
    {
        $command = isset($argv[1]) ? (string) $argv[1] : '';
        $opts = self::parseOpts(array_slice($argv, 2));

        try {
            switch ($command) {
                case 'target-key':
                    echo 'TARGET_KEY=' . STANDARD_INITIAL_PASSWORD_TARGET_KEY . "\n";
                    return 0;
                case 'validate-hash':
                    self::readHashFile(self::requireOpt($opts, 'hash-file'));
                    echo "HASH_SHAPE_VALID=YES\n";
                    return 0;
                case 'get':
                    return self::cmdGet(self::requireOpt($opts, 'file'));
                case 'patch':
                    return self::cmdPatch(
                        self::requireOpt($opts, 'file'),
                        self::requireOpt($opts, 'out'),
                        self::requireOpt($opts, 'hash-file')
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
            fwrite(STDERR, 'STANDARD_INITIAL_PASSWORD_OPS_ERROR=' . self::safeErrorCode($e->getMessage()) . "\n");
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
            throw new RuntimeException('MISSING_OPT_' . strtoupper(str_replace('-', '_', $name)));
        }
        return (string) $opts[$name];
    }

    /**
     * The hash never travels through argv or output; only through a file the
     * caller owns and deletes.
     */
    private static function readHashFile(string $file): string
    {
        if (!is_file($file)) {
            throw new RuntimeException('HASH_FILE_MISSING');
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('HASH_FILE_READ_FAILED');
        }
        $hash = trim($raw);
        self::assertValidHash($hash);

        return $hash;
    }

    public static function assertValidHash(string $hash): void
    {
        if ($hash === '') {
            throw new RuntimeException('HASH_EMPTY');
        }
        if (strpos($hash, 'CHANGE_ME') === 0) {
            throw new RuntimeException('HASH_IS_PLACEHOLDER');
        }
        if (preg_match(STANDARD_INITIAL_PASSWORD_HASH_PATTERN, $hash) !== 1) {
            throw new RuntimeException('HASH_SHAPE_INVALID');
        }
    }

    public static function isConfiguredValue($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strpos($trimmed, 'CHANGE_ME') === 0) {
            return false;
        }

        return preg_match(STANDARD_INITIAL_PASSWORD_HASH_PATTERN, $trimmed) === 1;
    }

    private static function cmdGet(string $file): int
    {
        $config = self::loadConfigArray($file);
        $present = array_key_exists(STANDARD_INITIAL_PASSWORD_TARGET_KEY, $config);
        echo 'KEY_PRESENT=' . ($present ? 'YES' : 'NO') . "\n";
        $configured = $present && self::isConfiguredValue($config[STANDARD_INITIAL_PASSWORD_TARGET_KEY]);
        echo 'STANDARD_INITIAL_PASSWORD_HASH_CONFIGURED=' . ($configured ? 'YES' : 'NO') . "\n";

        return 0;
    }

    private static function cmdPatch(string $file, string $out, string $hashFile): int
    {
        $hash = self::readHashFile($hashFile);
        $before = self::loadConfigArray($file);
        if ($out === $file) {
            throw new RuntimeException('OUT_MUST_DIFFER');
        }
        $patched = self::patchSource(self::readFile($file), $hash);
        if (file_put_contents($out, $patched) === false) {
            throw new RuntimeException('WRITE_FAILED');
        }
        $after = self::loadConfigArray($out);
        if (!isset($after[STANDARD_INITIAL_PASSWORD_TARGET_KEY])
            || !is_string($after[STANDARD_INITIAL_PASSWORD_TARGET_KEY])
        ) {
            @unlink($out);
            throw new RuntimeException('PATCH_VERIFY_FAILED');
        }
        if (!hash_equals($hash, trim((string) $after[STANDARD_INITIAL_PASSWORD_TARGET_KEY]))) {
            @unlink($out);
            throw new RuntimeException('PATCH_VALUE_MISMATCH');
        }
        if (self::unrelatedDelta($before, $after) !== 0) {
            @unlink($out);
            throw new RuntimeException('UNRELATED_CONFIG_DELTA');
        }
        echo "PATCH_OK=YES\n";
        echo "STANDARD_INITIAL_PASSWORD_HASH_CONFIGURED=YES\n";
        echo "UNRELATED_CONFIG_KEYS_CHANGED=0\n";

        return 0;
    }

    private static function cmdAssertUnrelatedEqual(string $beforePath, string $afterPath): int
    {
        $delta = self::unrelatedDelta(
            self::loadConfigArray($beforePath),
            self::loadConfigArray($afterPath)
        );
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
     * Replace or insert only standard_initial_password_hash using PHP tokens
     * (no full rewrite, sibling secrets untouched).
     */
    public static function patchSource(string $source, string $newValue): string
    {
        self::assertValidHash($newValue);
        $tokens = token_get_all($source);
        $keyLiteral = "'" . STANDARD_INITIAL_PASSWORD_TARGET_KEY . "'";
        $valueLiteral = "'" . self::phpSingleQuoted($newValue) . "'";

        $keyIndex = null;
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $lit = $tok[1];
            if ($lit !== $keyLiteral && $lit !== '"' . STANDARD_INITIAL_PASSWORD_TARGET_KEY . '"') {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && self::isIgnorableToken($tokens[$j])) {
                $j++;
            }
            if ($j >= $count || !self::isDoubleArrowToken($tokens[$j])) {
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
            $tokens[$keyIndex][1] = $keyLiteral;

            return self::tokensToSource($tokens);
        }

        return self::insertKeyBeforeClosing($source, $keyLiteral . ' => ' . $valueLiteral . ',');
    }

    /**
     * Literal splice, never preg_replace: a bcrypt hash contains `$2`/`$10`,
     * which a regex replacement would consume as backreferences.
     */
    private static function insertKeyBeforeClosing(string $source, string $assignment): string
    {
        if (preg_match('/\];\s*$/', $source, $match, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('INSERT_ANCHOR_MISSING');
        }
        $offset = (int) $match[0][1];

        return substr($source, 0, $offset) . '    ' . $assignment . "\n];\n";
    }

    /** @param string|array{0:int,1:string,2?:int} $tok */
    private static function isDoubleArrowToken($tok): bool
    {
        if (is_array($tok)) {
            return $tok[0] === T_DOUBLE_ARROW;
        }

        return $tok === '=>';
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
            if ($key === STANDARD_INITIAL_PASSWORD_TARGET_KEY) {
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
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'standard-initial-password-ops-' . bin2hex(random_bytes(4));
        if (!mkdir($dir) && !is_dir($dir)) {
            throw new RuntimeException('SELFTEST_TMP_FAILED');
        }
        try {
            $secret = 'super-secret-db-password-do-not-leak';
            $hash = password_hash('SelfTestOnlyValue!2026', PASSWORD_BCRYPT);
            if (!is_string($hash)) {
                throw new RuntimeException('SELFTEST_HASH_FAILED');
            }
            self::assertValidHash($hash);

            $rejected = 0;
            foreach (['', 'CHANGE_ME_STANDARD_INITIAL_PASSWORD_BCRYPT_HASH', 'plaintext-not-a-hash'] as $bad) {
                try {
                    self::assertValidHash($bad);
                } catch (Throwable $e) {
                    $rejected++;
                }
            }
            if ($rejected !== 3) {
                throw new RuntimeException('SELFTEST_INVALID_HASH_ACCEPTED');
            }

            $before = $dir . DIRECTORY_SEPARATOR . 'before.php';
            $after = $dir . DIRECTORY_SEPARATOR . 'after.php';
            file_put_contents($before, "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n"
                . "    'db_password' => '{$secret}',\n"
                . "    'jwt_secret' => 'abcdefghijklmnopqrstuvwxyz012345',\n"
                . "    'standard_initial_password_hash' => 'CHANGE_ME_STANDARD_INITIAL_PASSWORD_BCRYPT_HASH',\n"
                . "];\n");

            $patchedSource = self::patchSource((string) file_get_contents($before), $hash);
            file_put_contents($after, $patchedSource);

            $beforeArr = self::loadConfigArray($before);
            $afterArr = self::loadConfigArray($after);
            if (!hash_equals($hash, trim((string) $afterArr[STANDARD_INITIAL_PASSWORD_TARGET_KEY]))) {
                throw new RuntimeException('SELFTEST_VALUE');
            }
            if (self::unrelatedDelta($beforeArr, $afterArr) !== 0) {
                throw new RuntimeException('SELFTEST_DELTA');
            }
            if (strpos($patchedSource, $secret) === false) {
                throw new RuntimeException('SELFTEST_SECRET_PRESERVED');
            }
            if (self::isConfiguredValue($beforeArr[STANDARD_INITIAL_PASSWORD_TARGET_KEY])) {
                throw new RuntimeException('SELFTEST_PLACEHOLDER_TREATED_CONFIGURED');
            }
            if (!self::isConfiguredValue($afterArr[STANDARD_INITIAL_PASSWORD_TARGET_KEY])) {
                throw new RuntimeException('SELFTEST_CONFIGURED_FLAG');
            }

            // Missing key insert path.
            $missing = $dir . DIRECTORY_SEPARATOR . 'missing.php';
            $missingOut = $dir . DIRECTORY_SEPARATOR . 'missing-out.php';
            file_put_contents($missing, "<?php\nreturn [\n    'db_password' => '{$secret}',\n];\n");
            file_put_contents($missingOut, self::patchSource((string) file_get_contents($missing), $hash));
            $missingAfter = self::loadConfigArray($missingOut);
            if (!hash_equals($hash, trim((string) $missingAfter[STANDARD_INITIAL_PASSWORD_TARGET_KEY]))) {
                throw new RuntimeException('SELFTEST_INSERT');
            }
            if (self::unrelatedDelta(self::loadConfigArray($missing), $missingAfter) !== 0) {
                throw new RuntimeException('SELFTEST_INSERT_DELTA');
            }

            echo "SELF_TEST=PASS\n";
            echo 'TARGET_KEY=' . STANDARD_INITIAL_PASSWORD_TARGET_KEY . "\n";

            return 0;
        } finally {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}

exit(StandardInitialPasswordConfigOps::main($argv));
