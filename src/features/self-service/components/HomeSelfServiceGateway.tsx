import { Link } from "react-router-dom";
import {
  hasPersonnelLinkedSelfServiceEligibility,
  hasUserPermission
} from "../../../lib/authorization/role-permissions";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";
import { canonicalizeUserRole } from "../../../lib/authorization/canonicalize-user-role";

/**
 * Manager ana ekranı: bağlı personel + self_service.view → /self (QR bağımsız).
 * QR kısayolları SelfServiceQrShortcuts owner'ında kalır.
 */
export function HomeSelfServiceGateway() {
  const { session } = useAuth();
  const { activeRole } = useRoleAccess();
  const personelId = session?.user.personel_id ?? null;
  const personelTipiAd = session?.user.personel_tipi_ad ?? null;
  const canonicalRole = canonicalizeUserRole(activeRole);

  if (canonicalRole === "PERSONEL" || canonicalRole === "BIRIM_AMIRI") {
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
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path
          d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-3.33 0-6 1.67-6 3.75V20h12v-2.25C18 15.67 15.33 14 12 14Z"
          fill="currentColor"
        />
      </svg>
    </Link>
  );
}
