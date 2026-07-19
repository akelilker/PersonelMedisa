import { Link, useSearchParams } from "react-router-dom";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { ROUTE_PERMISSION } from "../../../lib/authorization/role-permissions";

export function HaftalikKapanisPage() {
  const { hasPermission } = useRoleAccess();
  const canViewRevizyon = hasPermission(ROUTE_PERMISSION.haftalikKapanisPage);
  const canCreate = hasPermission("revizyon.create");
  const canApprove = hasPermission("revizyon.approve");
  const [searchParams] = useSearchParams();
  const personelId = searchParams.get("personel_id");

  return (
    <section className="states-page" data-testid="haftalik-kapanis-page">
      <h2>Haftalık Kapanış</h2>
      <p>
        Kapalı hafta snapshot’ları korunur. Revizyon talepleri ve correction kayıtları burada yönetilir;
        rapor/bordro motoru otomatik yeniden hesaplanmaz.
      </p>

      {!canViewRevizyon ? (
        <p role="alert">Revizyon Merkezi görüntüleme yetkiniz yok.</p>
      ) : (
        <div className="universal-btn-group" style={{ flexWrap: "wrap", gap: "0.75rem" }}>
          <Link
            className="universal-btn-save"
            to={
              personelId
                ? `/haftalik-kapanis/revizyonlar?personel_id=${encodeURIComponent(personelId)}`
                : "/haftalik-kapanis/revizyonlar"
            }
            data-testid="hk-revizyon-merkezi-link"
          >
            Revizyon Merkezi
          </Link>
          {canApprove ? (
            <Link
              className="universal-btn-aux"
              to="/haftalik-kapanis/revizyonlar?gorunum=onay"
              data-testid="hk-onay-bekleyenler-link"
            >
              Onay Bekleyenler
            </Link>
          ) : null}
          <Link
            className="universal-btn-aux"
            to="/haftalik-kapanis/revizyonlar?gorunum=corrections"
            data-testid="hk-corrections-link"
          >
            Corrections
          </Link>
          {canCreate ? (
            <Link
              className="universal-btn-aux"
              to={
                personelId
                  ? `/haftalik-kapanis/revizyonlar/yeni?personel_id=${encodeURIComponent(personelId)}`
                  : "/haftalik-kapanis/revizyonlar/yeni"
              }
              data-testid="hk-revizyon-talebi-ac"
            >
              Revizyon Talebi Aç
            </Link>
          ) : null}
        </div>
      )}

      <p className="form-hint" style={{ marginTop: "1.25rem" }}>
        Aktif correction görünürlüğü, rapor satırlarında gerçek overlay anlamına gelmez. Ham kapanış
        snapshot’ı değişmez.
      </p>
    </section>
  );
}
