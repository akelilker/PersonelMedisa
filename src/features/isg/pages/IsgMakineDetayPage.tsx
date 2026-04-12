import { useMemo } from "react";
import { useParams } from "react-router-dom";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useIsgMakineDetay } from "../../../hooks/useIsgMakineDetay";
import {
  formatIsgBakimDurumuLabel,
  formatIsgMakineDurumLabel
} from "../../../lib/display/enum-display";

function formatDate(value: string | null): string {
  if (!value) {
    return "-";
  }

  const parsed = Date.parse(value);
  if (Number.isNaN(parsed)) {
    return "-";
  }

  return new Intl.DateTimeFormat("tr-TR", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric"
  }).format(new Date(parsed));
}

function formatPeriyot(value: number | null): string {
  if (!value || value <= 0) {
    return "-";
  }

  return `${value} gun`;
}

function formatGecikme(value: number): string {
  if (!value || value <= 0) {
    return "-";
  }

  return `${value} gun`;
}

export function IsgMakineDetayPage() {
  const { makineId } = useParams();
  const parsedMakineId = Number.parseInt(makineId ?? "", 10);
  const hasValidId = Number.isFinite(parsedMakineId) && parsedMakineId > 0;

  const {
    makine,
    bakimKayitlari,
    pagination,
    isLoading,
    isHistoryLoading,
    errorMessage,
    notFoundReason,
    refetch,
    goPrevPage,
    goNextPage
  } = useIsgMakineDetay(parsedMakineId, hasValidId);

  const historyEmptyCopy = useMemo(() => {
    if (!makine) {
      return null;
    }

    if (makine.uyariDurumu === "eksik_veri") {
      return {
        title: "Eksik veri",
        message: "Bu makine icin gecerli bakim kaydi bulunmuyor."
      };
    }

    return {
      title: "Bakim gecmisi yok",
      message: "Bu makine icin gosterilecek bakim kaydi bulunmuyor."
    };
  }, [makine]);

  return (
    <section className="isg-page isg-detail-page" data-testid="isg-machine-detail-page">
      {isLoading ? <LoadingState label="Makine detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void refetch()} /> : null}

      {!isLoading && !errorMessage && (!hasValidId || !makine) ? (
        <EmptyState
          title="Makine bulunamadi"
          message={
            notFoundReason === "out_of_scope"
              ? "Bu makine aktif sube kapsaminda goruntulenemiyor."
              : "Belirtilen makine kaydi bulunamadi."
          }
        />
      ) : null}

      {!isLoading && !errorMessage && makine ? (
        <div className="isg-detail-stack">
          <section className="isg-detail-card" data-testid="isg-machine-detail">
            <div className="isg-list-card__header">
              <div>
                <h3>{makine.ad}</h3>
                <p>{makine.tip}</p>
              </div>
              <span
                className={`isg-badge isg-badge--${makine.uyariDurumu}`}
                data-testid="isg-machine-detail-status"
              >
                {formatIsgBakimDurumuLabel(makine.uyariDurumu)}
              </span>
            </div>

            <dl className="isg-detail-meta">
              <div>
                <dt>Sube</dt>
                <dd>{makine.subeAdi ?? (makine.subeId !== null ? `Sube #${makine.subeId}` : "-")}</dd>
              </div>
              <div>
                <dt>Sube ID</dt>
                <dd>{makine.subeId ?? "-"}</dd>
              </div>
              <div>
                <dt>Konum</dt>
                <dd>{makine.konum ?? "-"}</dd>
              </div>
              <div>
                <dt>Durum</dt>
                <dd>{formatIsgMakineDurumLabel(makine.durum)}</dd>
              </div>
              <div>
                <dt>Bakim Periyodu</dt>
                <dd>{formatPeriyot(makine.bakimPeriyotGun)}</dd>
              </div>
              <div>
                <dt>Son Bakim</dt>
                <dd>{formatDate(makine.sonBakim)}</dd>
              </div>
              <div>
                <dt>Sonraki Bakim</dt>
                <dd>{formatDate(makine.sonrakiBakim)}</dd>
              </div>
              <div>
                <dt>Gecikme</dt>
                <dd>{formatGecikme(makine.gecikmeGun)}</dd>
              </div>
            </dl>
          </section>

          <section className="isg-detail-card" data-testid="isg-machine-history">
            <div className="isg-section-header">
              <h3>Bakim Gecmisi</h3>
            </div>

            {isHistoryLoading ? <LoadingState label="Bakim gecmisi yukleniyor..." /> : null}

            {!isHistoryLoading && bakimKayitlari.length === 0 && historyEmptyCopy ? (
              <EmptyState title={historyEmptyCopy.title} message={historyEmptyCopy.message} />
            ) : null}

            {!isHistoryLoading && bakimKayitlari.length > 0 ? (
              <div className="isg-history-list" data-testid="isg-maintenance-list">
                {bakimKayitlari.map((kayit) => (
                  <article
                    key={kayit.id}
                    className="isg-history-row"
                    data-testid={`isg-maintenance-row-${kayit.id}`}
                  >
                    <dl className="isg-history-meta">
                      <div>
                        <dt>Tarih</dt>
                        <dd>{formatDate(kayit.normalizedDate)}</dd>
                      </div>
                      <div>
                        <dt>Yapan</dt>
                        <dd>{kayit.yapan ?? "-"}</dd>
                      </div>
                      <div className="isg-history-meta__notes">
                        <dt>Notlar</dt>
                        <dd>{kayit.notlar ?? "-"}</dd>
                      </div>
                    </dl>
                  </article>
                ))}
              </div>
            ) : null}

            {!isHistoryLoading && pagination ? (
              <div className="form-actions-row isg-pagination-row">
                <button
                  type="button"
                  className="universal-btn-aux"
                  onClick={goPrevPage}
                  disabled={!pagination.hasPreviousPage}
                >
                  Onceki Sayfa
                </button>
                <span className="isg-pagination-copy">
                  Sayfa {pagination.page ?? 1}
                  {pagination.totalPages ? ` / ${pagination.totalPages}` : ""}
                </span>
                <button
                  type="button"
                  className="universal-btn-aux"
                  onClick={goNextPage}
                  disabled={!pagination.hasNextPage}
                >
                  Sonraki Sayfa
                </button>
              </div>
            ) : null}
          </section>
        </div>
      ) : null}
    </section>
  );
}
