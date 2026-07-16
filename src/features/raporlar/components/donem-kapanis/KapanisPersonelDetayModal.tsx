import { Link } from "react-router-dom";
import { AppModal } from "../../../../components/modal/AppModal";
import { EmptyState } from "../../../../components/states/EmptyState";
import { ErrorState } from "../../../../components/states/ErrorState";
import { LoadingState } from "../../../../components/states/LoadingState";
import type { DonemKapanisIssue } from "../../../../api/donem-kapanis.api";
import { useDonemKapanisItems } from "../../../../hooks/useDonemKapanisItems";
import { severityClassName, SEVERITY_ICONS, formatSeverityLabel } from "../../../../lib/donem-kapanis/display";
import type { DonemKapanisPreflightParams } from "../../../../api/donem-kapanis.api";

type KapanisPersonelDetayModalProps = {
  issue: DonemKapanisIssue | null;
  params: DonemKapanisPreflightParams | null;
  onClose: () => void;
};

export function KapanisPersonelDetayModal({ issue, params, onClose }: KapanisPersonelDetayModalProps) {
  const { items, isLoading, errorMessage, currentPage, hasNextPage, hasPrevPage, refetch } = useDonemKapanisItems({
    enabled: Boolean(issue && params),
    params,
    code: issue?.code ?? null,
    severity: issue?.severity,
    page: 1,
    limit: 20
  });

  if (!issue) {
    return null;
  }

  return (
    <AppModal title={issue.title} onClose={onClose} className="kapanis-detail-modal">
      <div data-testid="donem-kapanis-personel-detay-modal">
        <p className={severityClassName(issue.severity)} data-testid="donem-kapanis-detail-severity">
          <span aria-hidden="true">{SEVERITY_ICONS[issue.severity]}</span>
          {formatSeverityLabel(issue.severity)} · {issue.count} kayıt
        </p>
        <p className="yonetim-hint">{issue.message}</p>

        {issue.domain === "etki_adayi" ? (
          <p className="yonetim-hint">
            Koru / Revize kararları için{" "}
            <Link to="/puantaj" data-testid="donem-kapanis-etki-aday-link">
              Puantaj etki adayları
            </Link>{" "}
            ekranını kullanın.
          </p>
        ) : null}

        {isLoading ? <LoadingState label="Detay kayıtları yükleniyor..." /> : null}
        {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void refetch(currentPage)} /> : null}
        {!isLoading && !errorMessage && items.length === 0 ? (
          <EmptyState title="Kayıt yok" message="Bu kategori için detay satırı bulunamadı." />
        ) : null}

        {!isLoading && !errorMessage && items.length > 0 ? (
          <>
            <div className="raporlar-table-wrap yonetim-table-wrap" data-testid="donem-kapanis-detail-table">
              <table className="raporlar-table">
                <thead>
                  <tr>
                    <th>Kayıt</th>
                    <th>Personel</th>
                    <th>Tarih</th>
                    <th>Durum</th>
                    <th>Detay</th>
                  </tr>
                </thead>
                <tbody>
                  {items.map((item, index) => (
                    <tr key={`${item.record_id ?? index}-${item.personel_id ?? index}`}>
                      <td>{item.record_id ?? "-"}</td>
                      <td>
                        {item.personel_id ? (
                          <Link to={`/personeller/${item.personel_id}`}>{item.personel_id}</Link>
                        ) : (
                          "-"
                        )}
                      </td>
                      <td>{item.tarih ?? "-"}</td>
                      <td>{item.state ?? "-"}</td>
                      <td>{item.detail ?? "-"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="module-pagination">
              <button
                type="button"
                className="state-action-btn"
                disabled={!hasPrevPage || isLoading}
                onClick={() => void refetch(Math.max(1, currentPage - 1))}
              >
                Önceki
              </button>
              <span className="module-page-info">Sayfa {currentPage}</span>
              <button
                type="button"
                className="state-action-btn"
                disabled={!hasNextPage || isLoading}
                onClick={() => void refetch(currentPage + 1)}
              >
                Sonraki
              </button>
            </div>
          </>
        ) : null}
      </div>
    </AppModal>
  );
}
