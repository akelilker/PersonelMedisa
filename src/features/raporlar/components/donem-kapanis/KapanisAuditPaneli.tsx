import { LoadingState } from "../../../../components/states/LoadingState";
import { ErrorState } from "../../../../components/states/ErrorState";
import type { DonemKapanisAudit } from "../../../../api/donem-kapanis.api";

type KapanisAuditPaneliProps = {
  audits: DonemKapanisAudit[];
  isLoading: boolean;
  errorMessage: string | null;
  onRetry?: () => void;
};

function formatActionLabel(action: string): string {
  if (action === "CLOSE_ATTEMPT_BLOCKED") {
    return "Kapanış engellendi";
  }
  if (action === "CLOSE_SUCCESS") {
    return "Kapanış başarılı";
  }
  return action;
}

function formatResultLabel(result: string): string {
  if (result === "BLOCKED") {
    return "Engellendi";
  }
  if (result === "SEALED") {
    return "Mühürlendi";
  }
  return result;
}

export function KapanisAuditPaneli({ audits, isLoading, errorMessage, onRetry }: KapanisAuditPaneliProps) {
  return (
    <section className="kapanis-audit-panel" data-testid="donem-kapanis-audit-panel">
      <h3>Kapanış audit geçmişi</h3>
      {isLoading ? <LoadingState label="Audit kayıtları yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={onRetry} /> : null}
      {!isLoading && !errorMessage && audits.length === 0 ? (
        <p className="yonetim-hint">Bu dönem için audit kaydı yok.</p>
      ) : null}
      {!isLoading && !errorMessage && audits.length > 0 ? (
        <div className="raporlar-table-wrap yonetim-table-wrap" data-testid="donem-kapanis-audit-table">
          <table className="raporlar-table">
            <thead>
              <tr>
                <th>Zaman</th>
                <th>İşlem</th>
                <th>Sonuç</th>
                <th>Engelleyici</th>
                <th>Uyarı</th>
                <th>Mühür</th>
                <th>Preflight hash</th>
              </tr>
            </thead>
            <tbody>
              {audits.map((audit) => (
                <tr key={audit.id} data-testid={`donem-kapanis-audit-row-${audit.id}`}>
                  <td>{audit.created_at ? new Date(audit.created_at).toLocaleString("tr-TR") : "-"}</td>
                  <td>{formatActionLabel(audit.action)}</td>
                  <td>{formatResultLabel(audit.result_state)}</td>
                  <td>{audit.blocker_count}</td>
                  <td>{audit.warning_count}</td>
                  <td>{audit.muhur_id ?? "-"}</td>
                  <td className="kapanis-hash-cell" title={audit.preflight_hash}>
                    {audit.preflight_hash ? `${audit.preflight_hash.slice(0, 12)}…` : "-"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
