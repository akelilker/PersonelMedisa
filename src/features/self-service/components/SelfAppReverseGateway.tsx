import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { canonicalizeUserRole } from "../../../lib/authorization/canonicalize-user-role";
import { useRoleAccess } from "../../../hooks/use-role-access";

const GATEWAY_DIM_DELAY_MS = 4000;

/**
 * /self yüzeyinden ana Personel Yönetim uygulamasına dönüş (Taşıt "Kullanıcı Paneli >" tersi ritim).
 * PERSONEL rolü zaten ana /self evinde; yönetici / IK çift yüzeyde görünür.
 */
export function SelfAppReverseGateway() {
  const { pathname } = useLocation();
  const { activeRole } = useRoleAccess();
  const [dimmed, setDimmed] = useState(false);
  const canonicalRole = canonicalizeUserRole(activeRole);
  const onSelfSurface = pathname === "/self" || pathname.startsWith("/self/");

  useEffect(() => {
    const timer = window.setTimeout(() => setDimmed(true), GATEWAY_DIM_DELAY_MS);
    return () => window.clearTimeout(timer);
  }, [pathname]);

  if (!onSelfSurface || canonicalRole === "PERSONEL") {
    return null;
  }

  return (
    <Link
      to="/"
      className={
        dimmed ? "home-self-service-gateway home-self-service-gateway--dimmed" : "home-self-service-gateway"
      }
      data-testid="self-app-reverse-gateway"
      aria-label="Personel Yönetim Sistemi"
    >
      <span className="home-self-service-gateway__text">Personel Yönetim Sistemi</span>
      <span className="home-self-service-gateway__chevron" aria-hidden="true">
        {">"}
      </span>
    </Link>
  );
}
