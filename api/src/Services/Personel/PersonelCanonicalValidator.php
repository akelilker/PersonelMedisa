<?php

declare(strict_types=1);

namespace Medisa\Api\Services\Personel;

/**
 * Shared create/update/import field normalization for personel ana veri.
 * Does not write salary and does not assume wage model.
 */
final class PersonelCanonicalValidator
{
    /** @return array<int, string> */
    public static function validKanGruplari()
    {
        return ['A Rh+', 'A Rh-', 'B Rh+', 'B Rh-', 'AB Rh+', 'AB Rh-', '0 Rh+', '0 Rh-'];
    }

    public static function maskTcKimlikNo($tcKimlikNo)
    {
        $digits = preg_replace('/\D+/', '', (string) $tcKimlikNo);
        if ($digits === null || $digits === '') {
            return '***********';
        }
        $len = strlen($digits);
        if ($len >= 5) {
            return substr($digits, 0, 3) . str_repeat('*', $len - 5) . substr($digits, -2);
        }
        if ($len <= 2) {
            return str_repeat('*', max(1, $len));
        }

        return str_repeat('*', $len - 2) . substr($digits, -2);
    }

    public static function isValidDateString($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }

        [$year, $month, $day] = array_map('intval', explode('-', $value));

        return checkdate($month, $day, $year);
    }

    /**
     * Accept only canonical YYYY-MM-DD. Local formats (DD.MM.YYYY, slash) are not guessed.
     *
     * @return string|null Canonical date or null when invalid/ambiguous
     */
    public static function normalizeDateToCanonical($value)
    {
        if ($value === null) {
            return null;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        return self::isValidDateString($raw) ? $raw : null;
    }

    public static function isValidTcKimlikNo($value)
    {
        return is_string($value) && preg_match('/^\d{11}$/', $value) === 1;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function normalizeAndValidateCreatePayload(array $body)
    {
        if (!array_key_exists('tc_kimlik_no', $body) || trim((string) $body['tc_kimlik_no']) === '') {
            throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No zorunludur.');
        }

        $tcKimlikNo = trim((string) $body['tc_kimlik_no']);
        if (!self::isValidTcKimlikNo($tcKimlikNo)) {
            throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
        }

        $ad = self::requireTrimmedString($body, 'ad', 'Ad zorunludur.');
        $soyad = self::requireTrimmedString($body, 'soyad', 'Soyad zorunludur.');

        $dogumTarihi = self::requireValidDate($body, 'dogum_tarihi', 'Dogum tarihi zorunludur.');
        $telefon = self::requireTrimmedString($body, 'telefon', 'Telefon zorunludur.');
        // Emergency contact is optional for initial master create/import; empty → NULL.
        $acilDurumKisi = self::optionalTrimmedString($body, 'acil_durum_kisi');
        $acilDurumTelefon = self::optionalTrimmedString($body, 'acil_durum_telefon');
        $sicilNo = self::requireTrimmedString($body, 'sicil_no', 'Sicil no zorunludur.');
        $iseGirisTarihi = self::requireValidDate($body, 'ise_giris_tarihi', 'Ise giris tarihi zorunludur.');

        $subeId = self::requirePositiveInt($body, 'sube_id', 'Sube secilmelidir.');
        $departmanId = self::requirePositiveInt($body, 'departman_id', 'Departman secilmelidir.');
        $gorevId = self::requirePositiveInt($body, 'gorev_id', 'Gorev secilmelidir.');
        $personelTipiId = self::requirePositiveInt($body, 'personel_tipi_id', 'Personel tipi secilmelidir.');

        if (!array_key_exists('aktif_durum', $body)) {
            throw new PersonelValidationException('aktif_durum', 'Aktif durum zorunludur.');
        }
        $aktifDurum = strtoupper(trim((string) $body['aktif_durum']));
        if (!in_array($aktifDurum, ['AKTIF', 'PASIF'], true)) {
            throw new PersonelValidationException('aktif_durum', 'Aktif durum AKTIF veya PASIF olmalidir.');
        }

        $dogumYeri = self::optionalTrimmedString($body, 'dogum_yeri');
        $kanGrubu = self::optionalTrimmedString($body, 'kan_grubu');
        if ($kanGrubu !== null && !in_array($kanGrubu, self::validKanGruplari(), true)) {
            throw new PersonelValidationException('kan_grubu', 'Gecersiz kan grubu.');
        }

        $bagliAmirId = self::optionalPositiveInt($body, 'bagli_amir_id');
        $ucretTipiId = self::optionalPositiveInt($body, 'ucret_tipi_id');
        if ($ucretTipiId !== null && !in_array($ucretTipiId, [1, 2, 3], true)) {
            throw new PersonelValidationException('ucret_tipi_id', 'Gecersiz ucret tipi.');
        }

        $primKuraliId = self::optionalPositiveInt($body, 'prim_kurali_id');
        if ($primKuraliId !== null && !in_array($primKuraliId, [1, 2, 3], true)) {
            throw new PersonelValidationException('prim_kurali_id', 'Gecersiz prim kurali.');
        }

        $maasTutari = self::resolveMaasTutariFromBody($body);

        $payload = [
            'tc_kimlik_no' => $tcKimlikNo,
            'ad' => $ad,
            'soyad' => $soyad,
            'dogum_tarihi' => $dogumTarihi,
            'telefon' => $telefon,
            'acil_durum_kisi' => $acilDurumKisi,
            'acil_durum_telefon' => $acilDurumTelefon,
            'sicil_no' => $sicilNo,
            'ise_giris_tarihi' => $iseGirisTarihi,
            'sube_id' => $subeId,
            'departman_id' => $departmanId,
            'gorev_id' => $gorevId,
            'personel_tipi_id' => $personelTipiId,
            'aktif_durum' => $aktifDurum,
            'dogum_yeri' => $dogumYeri,
            'kan_grubu' => $kanGrubu,
            'bagli_amir_id' => $bagliAmirId,
            'ucret_tipi_id' => $ucretTipiId,
            'maas_tutari' => $maasTutari,
            'prim_kurali_id' => $primKuraliId,
        ];

        // Optional Pack5 org refs — key present means write intent (blank → NULL).
        if (array_key_exists('sgk_isveren_id', $body)) {
            $payload['sgk_isveren_id'] = self::optionalPositiveInt($body, 'sgk_isveren_id');
        }
        if (array_key_exists('calisma_lokasyonu_id', $body)) {
            $payload['calisma_lokasyonu_id'] = self::optionalPositiveInt($body, 'calisma_lokasyonu_id');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function normalizeAndValidateUpdatePayload(array $body)
    {
        $payload = [];

        if (array_key_exists('effective_date', $body) && $body['effective_date'] !== null && trim((string) $body['effective_date']) !== '') {
            $effectiveDate = trim((string) $body['effective_date']);
            if (!self::isValidDateString($effectiveDate)) {
                throw new PersonelValidationException('effective_date', 'Gecerli bir tarih olmalidir.');
            }
        }

        if (array_key_exists('tc_kimlik_no', $body)) {
            $tcKimlikNo = trim((string) $body['tc_kimlik_no']);
            if (!self::isValidTcKimlikNo($tcKimlikNo)) {
                throw new PersonelValidationException('tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
            }
            $payload['tc_kimlik_no'] = $tcKimlikNo;
        }

        foreach (['ad', 'soyad', 'telefon', 'sicil_no'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::requireTrimmedString($body, $field, 'Gecersiz deger.');
            }
        }

        foreach (['acil_durum_kisi', 'acil_durum_telefon'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::optionalTrimmedString($body, $field);
            }
        }

        foreach (['dogum_tarihi', 'ise_giris_tarihi'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::requireValidDate($body, $field, 'Gecerli bir tarih olmalidir.');
            }
        }

        foreach (['dogum_yeri', 'kan_grubu'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::optionalTrimmedString($body, $field);
            }
        }

        if (array_key_exists('kan_grubu', $payload) && $payload['kan_grubu'] !== null && !in_array($payload['kan_grubu'], self::validKanGruplari(), true)) {
            throw new PersonelValidationException('kan_grubu', 'Gecersiz kan grubu.');
        }

        if (array_key_exists('sube_id', $body)) {
            $payload['sube_id'] = self::requirePositiveInt($body, 'sube_id', 'Sube secilmelidir.');
        }

        foreach (['departman_id', 'gorev_id', 'bagli_amir_id', 'personel_tipi_id', 'sgk_isveren_id', 'calisma_lokasyonu_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $payload[$field] = self::optionalPositiveInt($body, $field);
            }
        }

        foreach (['ucret_tipi_id', 'prim_kurali_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = self::optionalPositiveInt($body, $field);
                if ($value !== null && !in_array($value, [1, 2, 3], true)) {
                    throw new PersonelValidationException($field, 'Gecersiz deger.');
                }
                $payload[$field] = $value;
            }
        }

        if (array_key_exists('net_maas_tutari', $body) || array_key_exists('maas_tutari', $body)) {
            $payload['maas_tutari'] = self::resolveMaasTutariFromBody($body);
        }

        if (array_key_exists('aktif_durum', $body)) {
            $aktifDurum = strtoupper(trim((string) $body['aktif_durum']));
            if (!in_array($aktifDurum, ['AKTIF', 'PASIF'], true)) {
                throw new PersonelValidationException('aktif_durum', 'Aktif durum AKTIF veya PASIF olmalidir.');
            }
            $payload['aktif_durum'] = $aktifDurum;
        }

        return $payload;
    }

    /**
     * Import-row field checks using the same rules as create (without salary / aktif_durum column).
     *
     * @param array{
     *   tc_kimlik_no?: mixed,
     *   ad?: mixed,
     *   soyad?: mixed,
     *   dogum_tarihi?: mixed,
     *   dogum_yeri?: mixed,
     *   telefon?: mixed,
     *   kan_grubu?: mixed,
     *   acil_durum_kisi?: mixed,
     *   acil_durum_telefon?: mixed,
     *   ise_giris_tarihi?: mixed,
     *   sicil_no?: mixed,
     *   sube_id?: mixed,
     *   departman_id?: mixed,
     *   gorev_id?: mixed,
     *   personel_tipi_id?: mixed
     * } $row
     * @return array{payload: ?array<string, mixed>, errors: list<array{code: string, field: string, message: string}>}
     */
    public static function validateImportAnaVeriRow(array $row)
    {
        $errors = [];
        $payload = [
            'aktif_durum' => 'AKTIF',
            'bagli_amir_id' => null,
            'ucret_tipi_id' => null,
            'maas_tutari' => null,
            'prim_kurali_id' => null,
        ];

        $tc = trim((string) ($row['tc_kimlik_no'] ?? ''));
        if ($tc === '') {
            $errors[] = self::importError('PERSONEL_IMPORT_EKSIK_ALAN', 'tc_kimlik_no', 'T.C. Kimlik No zorunludur.');
        } elseif (!self::isValidTcKimlikNo($tc)) {
            $errors[] = self::importError('PERSONEL_IMPORT_GECERSIZ_TC', 'tc_kimlik_no', 'T.C. Kimlik No 11 hane olmalidir.');
        } else {
            $payload['tc_kimlik_no'] = $tc;
        }

        foreach (
            [
                'ad' => 'Ad zorunludur.',
                'soyad' => 'Soyad zorunludur.',
                'telefon' => 'Telefon zorunludur.',
                'sicil_no' => 'Sicil no zorunludur.',
            ] as $field => $message
        ) {
            $value = trim((string) ($row[$field] ?? ''));
            if ($value === '') {
                $errors[] = self::importError('PERSONEL_IMPORT_EKSIK_ALAN', $field, $message);
            } else {
                $payload[$field] = $value;
            }
        }

        // Optional emergency contact: present value kept; empty → NULL. No placeholders.
        foreach (['acil_durum_kisi', 'acil_durum_telefon'] as $field) {
            $value = trim((string) ($row[$field] ?? ''));
            $payload[$field] = $value === '' ? null : $value;
        }

        foreach (['dogum_tarihi', 'ise_giris_tarihi'] as $field) {
            $raw = trim((string) ($row[$field] ?? ''));
            if ($raw === '') {
                $errors[] = self::importError('PERSONEL_IMPORT_EKSIK_ALAN', $field, 'Tarih zorunludur.');
                continue;
            }
            $canonical = self::normalizeDateToCanonical($raw);
            if ($canonical === null) {
                $errors[] = self::importError('PERSONEL_IMPORT_GECERSIZ_TARIH', $field, 'Gecerli bir tarih olmalidir.');
            } else {
                $payload[$field] = $canonical;
            }
        }

        $dogumYeri = trim((string) ($row['dogum_yeri'] ?? ''));
        $payload['dogum_yeri'] = $dogumYeri === '' ? null : $dogumYeri;

        $kanGrubu = trim((string) ($row['kan_grubu'] ?? ''));
        if ($kanGrubu === '') {
            $payload['kan_grubu'] = null;
        } elseif (!in_array($kanGrubu, self::validKanGruplari(), true)) {
            $errors[] = self::importError('PERSONEL_IMPORT_GECERSIZ_KAN_GRUBU', 'kan_grubu', 'Gecersiz kan grubu.');
        } else {
            $payload['kan_grubu'] = $kanGrubu;
        }

        foreach (['sube_id', 'departman_id', 'gorev_id', 'personel_tipi_id'] as $field) {
            if (!array_key_exists($field, $row) || $row[$field] === null || $row[$field] === '') {
                $errors[] = self::importError('PERSONEL_IMPORT_EKSIK_ALAN', $field, 'Referans zorunludur.');
                continue;
            }
            $id = self::parsePositiveInt($row[$field]);
            if ($id === null) {
                $errors[] = self::importError('PERSONEL_IMPORT_EKSIK_ALAN', $field, 'Referans zorunludur.');
            } else {
                $payload[$field] = $id;
            }
        }

        // Optional Pack5 org refs — only when import row explicitly carries keys.
        foreach (['sgk_isveren_id', 'calisma_lokasyonu_id'] as $field) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            if ($row[$field] === null || $row[$field] === '') {
                $payload[$field] = null;
                continue;
            }
            $id = self::parsePositiveInt($row[$field]);
            if ($id === null) {
                $errors[] = self::importError('PERSONEL_IMPORT_EKSIK_ALAN', $field, 'Gecersiz referans.');
            } else {
                $payload[$field] = $id;
            }
        }

        if (count($errors) > 0) {
            return ['payload' => null, 'errors' => $errors];
        }

        try {
            $normalized = self::normalizeAndValidateCreatePayload($payload);

            return ['payload' => $normalized, 'errors' => []];
        } catch (PersonelValidationException $e) {
            $code = 'PERSONEL_IMPORT_EKSIK_ALAN';
            if ($e->getField() === 'tc_kimlik_no') {
                $code = 'PERSONEL_IMPORT_GECERSIZ_TC';
            } elseif (in_array($e->getField(), ['dogum_tarihi', 'ise_giris_tarihi'], true)) {
                $code = 'PERSONEL_IMPORT_GECERSIZ_TARIH';
            } elseif ($e->getField() === 'kan_grubu') {
                $code = 'PERSONEL_IMPORT_GECERSIZ_KAN_GRUBU';
            }

            return [
                'payload' => null,
                'errors' => [self::importError($code, $e->getField(), $e->getMessage())],
            ];
        }
    }

    /** @return array{code: string, field: string, message: string} */
    private static function importError($code, $field, $message)
    {
        return [
            'code' => (string) $code,
            'field' => (string) $field,
            'message' => (string) $message,
        ];
    }

    /** @param array<string, mixed> $body */
    private static function requireTrimmedString(array $body, $field, $message)
    {
        if (!array_key_exists($field, $body)) {
            throw new PersonelValidationException((string) $field, $message);
        }

        $value = trim((string) $body[$field]);
        if ($value === '') {
            throw new PersonelValidationException((string) $field, $message);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function requireValidDate(array $body, $field, $missingMessage)
    {
        if (!array_key_exists($field, $body) || trim((string) $body[$field]) === '') {
            throw new PersonelValidationException((string) $field, $missingMessage);
        }

        $value = trim((string) $body[$field]);
        if (!self::isValidDateString($value)) {
            throw new PersonelValidationException((string) $field, 'Gecerli bir tarih olmalidir.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function requirePositiveInt(array $body, $field, $message)
    {
        if (!array_key_exists($field, $body)) {
            throw new PersonelValidationException((string) $field, $message);
        }

        $value = self::parsePositiveInt($body[$field]);
        if ($value === null) {
            throw new PersonelValidationException((string) $field, $message);
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalTrimmedString(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }

        $value = trim((string) $body[$field]);

        return $value === '' ? null : $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalPositiveInt(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }

        $value = self::parsePositiveInt($body[$field]);
        if ($value === null) {
            throw new PersonelValidationException((string) $field, 'Gecersiz deger.');
        }

        return $value;
    }

    /** @param array<string, mixed> $body */
    private static function optionalNonNegativeNumber(array $body, $field)
    {
        if (!array_key_exists($field, $body) || $body[$field] === null || $body[$field] === '') {
            return null;
        }

        if (!is_numeric($body[$field])) {
            throw new PersonelValidationException((string) $field, 'Maas tutari sayisal olmalidir.');
        }

        $value = (float) $body[$field];
        if ($value < 0) {
            throw new PersonelValidationException((string) $field, 'Maas tutari sifirdan kucuk olamaz.');
        }

        return $value;
    }

    /** @param mixed $value */
    private static function parsePositiveInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && trim($value) !== $value) {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $parsed = (int) $value;
        if ($parsed <= 0 || (string) $parsed !== trim((string) $value)) {
            return null;
        }

        return $parsed;
    }

    /** @param array<string, mixed> $body */
    private static function resolveMaasTutariFromBody(array $body)
    {
        if (array_key_exists('net_maas_tutari', $body)) {
            return self::optionalNonNegativeNumber($body, 'net_maas_tutari');
        }

        if (array_key_exists('maas_tutari', $body)) {
            return self::optionalNonNegativeNumber($body, 'maas_tutari');
        }

        return null;
    }
}
