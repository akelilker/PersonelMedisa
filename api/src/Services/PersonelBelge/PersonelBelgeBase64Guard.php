<?php

declare(strict_types=1);

namespace Medisa\Api\Services\PersonelBelge;

/**
 * S86: Strict Base64 decode for personel belge uploads (validation before storage).
 */
final class PersonelBelgeBase64Guard
{
    /**
     * @return array{ok:true,bytes:string}|array{ok:false,http:int,code:string,message:string,meta:array<string,int>}
     */
    public static function decode(string $encoded): array
    {
        if ($encoded === '') {
            return [
                'ok' => false,
                'http' => 422,
                'code' => 'PERSONEL_BELGE_BASE64_GECERSIZ',
                'message' => 'Dosya icerigi bos olamaz.',
                'meta' => ['limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES],
            ];
        }

        $encodedLen = strlen($encoded);
        if ($encodedLen > PersonelBelgeContracts::maxEncodedLength()) {
            return [
                'ok' => false,
                'http' => 413,
                'code' => 'PERSONEL_BELGE_DOSYA_BOYUTU_ASILDI',
                'message' => 'Dosya boyutu limiti asildi.',
                'meta' => [
                    'limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES,
                    'tahmini_byte' => intdiv($encodedLen * 3, 4),
                ],
            ];
        }

        if (!preg_match('#^[A-Za-z0-9+/]*={0,2}$#', $encoded) || ($encodedLen % 4) !== 0) {
            return [
                'ok' => false,
                'http' => 422,
                'code' => 'PERSONEL_BELGE_BASE64_GECERSIZ',
                'message' => 'Dosya Base64 bicimi gecersiz.',
                'meta' => ['limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES],
            ];
        }

        $decoded = base64_decode($encoded, true);
        if ($decoded === false || base64_encode($decoded) !== $encoded) {
            return [
                'ok' => false,
                'http' => 422,
                'code' => 'PERSONEL_BELGE_BASE64_GECERSIZ',
                'message' => 'Dosya Base64 icerigi cozumlenemedi.',
                'meta' => ['limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES],
            ];
        }

        $actual = strlen($decoded);
        if ($actual === 0) {
            return [
                'ok' => false,
                'http' => 422,
                'code' => 'PERSONEL_BELGE_DOSYA_BOS',
                'message' => 'Dosya icerigi bos olamaz.',
                'meta' => ['limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES],
            ];
        }
        if ($actual > PersonelBelgeContracts::MAX_DECODED_BYTES) {
            return [
                'ok' => false,
                'http' => 413,
                'code' => 'PERSONEL_BELGE_DOSYA_BOYUTU_ASILDI',
                'message' => 'Dosya boyutu limiti asildi.',
                'meta' => [
                    'limit_byte' => PersonelBelgeContracts::MAX_DECODED_BYTES,
                    'byte_sayisi' => $actual,
                ],
            ];
        }

        return ['ok' => true, 'bytes' => $decoded];
    }
}
