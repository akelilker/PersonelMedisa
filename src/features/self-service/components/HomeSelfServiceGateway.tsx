import { Link } from "react-router-dom";
import {
  hasPersonnelLinkedSelfServiceEligibility,
  hasUserPermission
} from "../../../lib/authorization/role-permissions";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";
import { canonicalizeUserRole } from "../../../lib/authorization/canonicalize-user-role";

/**
 * Bağlı çalışan / yönetici ana ekranı: bağlı personel + self_service.view → /self (QR bağımsız).
 * Taşıt "Kullanıcı Paneli >" ritmi: kutusuz, sade metin geçişi. Ayrı ürün / panel / FAB yok.
 * PERSONEL zaten kendi ekranını kullanır; QR kısayolları SelfServiceQrShortcuts owner'ında kalır.
 */
export function HomeSelfServiceGateway() {
  const { session } = useAuth();
  const { activeRole } = useRoleAccess();
  const personelId = session?.user.personel_id ?? null;
  const personelTipiAd = session?.user.personel_tipi_ad ?? null;
  const canonicalRole = canonicalizeUserRole(activeRole);

  if (canonicalRole === "PERSONEL") {
    return null;
  }

  const eligible =
    hasPersonnelLinkedSelfServiceEligibility(personelId) &&
    hasUserPermission(activeRole, "self_service.view", personelId, personelTipiAd);

  if (!eligible) {
    return null;
  }

  return (
    <Link
      to="/self"
      className="home-self-service-gateway"
      data-testid="home-self-service-gateway"
      aria-label="Kendi Bilgilerime Geç"
    >
      <span className="home-self-service-gateway__text">Kendi Bilgilerim</span>
      <span className="home-self-service-gateway__chevron" aria-hidden="true">
        {">"}
      </span>
    </Link>
  );
}
