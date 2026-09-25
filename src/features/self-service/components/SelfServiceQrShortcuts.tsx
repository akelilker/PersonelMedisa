import { Link } from "react-router-dom";
import { useRoleAccess } from "../../../hooks/use-role-access";

type SelfServiceQrShortcutsProps = {
  /**
   * Setliyse kısayollar başlıklı bir self-service bölümü içinde render edilir.
   * Yönetici ana ekranı gibi personel-mobile shell dışındaki yüzeylerde kullanılır.
   */
  title?: string;
  /** Ana giriş kısayolu: tam self-service yüzeyine (/self) de link verir. */
  showSelfServiceHomeLink?: boolean;
};

/**
 * Personnel-linked self-service QR/kart okutma kısayollarının tek owner'ı
 * ("QR Okut" + "QR Hareketlerim").
 *
 * Görünürlük tek karar noktasından gelir: `self_service.qr.scan`. Bu karar rol
 * bağımsızdır (bağlı + aktif personel + kanonik "Mavi Yaka" collar), bu yüzden
 * BIRIM_AMIRI / BOLUM_YONETICISI gibi yönetici rolleri PERSONEL rolüne
 * düşürülmeden, yönetim yetkilerini kaybetmeden kendi QR yüzeylerine ulaşır.
 * Backend 403 otoritedir; bu yalnız UX aynasıdır.
 */
export function SelfServiceQrShortcuts({
  title,
  showSelfServiceHomeLink = false
}: SelfServiceQrShortcutsProps) {
  const { hasPermission } = useRoleAccess();

  if (!hasPermission("self_service.qr.scan")) {
    return null;
  }

  const shortcuts = (
    <nav className="pm-secondary-nav" data-testid="self-qr-shortcuts" aria-label="Self-service kısayollar">
      {showSelfServiceHomeLink ? (
        <Link to="/self" data-testid="self-service-home-link">
          Öz Servis
        </Link>
      ) : null}
      <Link to="/self/qr-okut" data-testid="self-qr-scan-link">
        QR Okut
      </Link>
      <Link to="/self/qr-hareketleri" data-testid="self-qr-history-link">
        Giriş / Çıkış Geçmişim
      </Link>
    </nav>
  );

  if (!title) {
    return shortcuts;
  }

  return (
    <section className="pm-section" data-testid="self-service-qr-section">
      <h2 className="pm-section-title">{title}</h2>
      {shortcuts}
    </section>
  );
}
