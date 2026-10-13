import { Link } from "react-router-dom";
import { currentMonthParts } from "../../../../lib/donem-kapanis/display";

function buildBordroHref(tab: string, ay: string) {
  return `/raporlar?panel=bordro-hazirlik&tab=${tab}&ay=${encodeURIComponent(ay)}`;
}

export function PersonelBordroGatewaySection({
  canViewBordro,
  ay
}: {
  canViewBordro: boolean;
  ay?: string;
}) {
  if (!canViewBordro) {
    return null;
  }

  const donemAy = ay ?? currentMonthParts().ay;

  return (
    <section className="personel-puantaj-summary-card personel-devam-primi-card" data-testid="personel-bordro-gateway-card">
      <span className="personel-puantaj-summary-kicker">Bordro Hazırlık Geçişleri</span>
      <div className="personel-bordro-gateway-links">
        <Link to={buildBordroHref("on-izleme", donemAy)} data-testid="personel-bordro-gateway-on-izleme">
          Bordro Ön İzlemesine Git
        </Link>
        <Link to={buildBordroHref("on-izleme", donemAy)} data-testid="personel-bordro-gateway-aday">
          Maaş Adayını Gör
        </Link>
        <Link to={buildBordroHref("devir", donemAy)} data-testid="personel-bordro-gateway-devir">
          Eksik Devir Verisini Tamamla
        </Link>
        <Link to={buildBordroHref("preflight", donemAy)} data-testid="personel-bordro-gateway-preflight">
          Ön Kontrol Sorununu Gör
        </Link>
      </div>
    </section>
  );
}
