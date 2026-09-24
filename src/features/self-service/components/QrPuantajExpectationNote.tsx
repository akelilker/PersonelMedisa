/**
 * Pilot / saha eğitim notu: QR raw event ≠ otomatik gunluk_puantaj.
 * Candidate / review / apply bilinçli modeldir (S3E–S3F) — internal only.
 */
export function QrPuantajExpectationNote() {
  return (
    <p
      className="self-service-muted qr-puantaj-expectation-note"
      data-testid="qr-puantaj-expectation-note"
      role="note"
    >
      QR giriş/çıkış kaydı puantaja otomatik yazılmaz. Kayıt kontrol edildikten sonra puantaja işlenir.
    </p>
  );
}
