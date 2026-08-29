<?php

declare(strict_types=1);

namespace Medisa\Api\Auth;

use Medisa\Api\Http\JsonResponse;

/**
 * Canonical owner of the deterministic initial password derived from the account
 * holder's own name.
 *
 * The rule is the one already in production in the Tasit Yonetim Sistemi
 * (`medisaBuildLegacyDefaultCredentials` in its
 * `scripts/migrate-medisa-default-credentials.php`): transliterate Turkish
 * letters to ASCII, strip everything that is not a letter or a digit, then build
 * the password from the surname alone as `Surname` + `123`.
 *
 * Only the derived plaintext of a single request lives in memory long enough to
 * be hashed. Nothing is read from config, no shared secret exists and the
 * plaintext is never returned, logged or persisted.
 */
class InitialPassword
{
    public const SUFFIX = '123';
    public const MIN_LENGTH = 6;

    /** Tasit `medisaDefaultCredentialsTransliterate`, byte-for-byte. */
    private const TRANSLITERATION = [
        'Ç' => 'C',
        'ç' => 'c',
        'Ğ' => 'G',
        'ğ' => 'g',
        'İ' => 'I',
        'ı' => 'i',
        'Ö' => 'O',
        'ö' => 'o',
        'Ş' => 'S',
        'ş' => 's',
        'Ü' => 'U',
        'ü' => 'u',
    ];

    public static function transliterate($value)
    {
        return strtr((string) $value, self::TRANSLITERATION);
    }

    /** Tasit `medisaDefaultCredentialsAsciiToken`. */
    public static function asciiToken($value)
    {
        $ascii = self::transliterate(trim((string) $value));

        return (string) preg_replace('/[^A-Za-z0-9]/', '', $ascii);
    }

    /**
     * Derive the initial password from a full name, or null when the name cannot
     * carry the rule.
     *
     * @return string|null
     */
    public static function deriveOrNull($fullName)
    {
        $parts = preg_split('/\s+/u', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || count($parts) < 1) {
            return null;
        }

        $surname = self::asciiToken((string) $parts[count($parts) - 1]);
        $firstName = self::asciiToken((string) $parts[0]);
        if ($surname === '' || $firstName === '') {
            return null;
        }

        $password = strtoupper(substr($surname, 0, 1))
            . strtolower(substr($surname, 1))
            . self::SUFFIX;

        if (!self::satisfiesDerivedShape($password)) {
            return null;
        }

        return $password;
    }

    /**
     * The shape Tasit asserts on every derived default credential. This is not
     * the user-chosen password policy (`PasswordPolicy`): an initial password is
     * assigned by the system and always carries must_change_password=1.
     */
    public static function satisfiesDerivedShape($password)
    {
        $password = (string) $password;

        return strlen($password) >= self::MIN_LENGTH
            && preg_match('/[A-Z]/', $password) === 1
            && preg_match('/[a-z]/', $password) === 1
            && preg_match('/[0-9]/', $password) === 1;
    }

    /**
     * Fail closed via JsonResponse when the stored name cannot produce an
     * initial password.
     *
     * @return string the bcrypt hash of the derived initial password
     */
    public static function requireHashForName($fullName, $field = 'ad_soyad')
    {
        $password = self::deriveOrNull($fullName);
        if ($password === null) {
            JsonResponse::badRequest(
                'Baslangic sifresi ad soyad bilgisinden uretilemedi. Ad ve soyad alanlarini kontrol edin.',
                'INITIAL_PASSWORD_NAME_INVALID',
                $field
            );
        }

        return PasswordHasher::hash($password);
    }
}
