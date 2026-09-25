/**
 * Kiosk QR model — personelin kendi kimlik QR’ı değil, şube ekranındaki kodu okuttuğu netliği.
 */
export function QrKioskModelNote() {
  return (
    <div className="pm-secondary-card pm-kiosk-model-note" data-testid="qr-kiosk-model-note" role="note">
      <p>Şube kiosk QR’ını telefonunuzla okutun. Kendi kimlik QR’ınızı göstermezsiniz.</p>
    </div>
  );
}
