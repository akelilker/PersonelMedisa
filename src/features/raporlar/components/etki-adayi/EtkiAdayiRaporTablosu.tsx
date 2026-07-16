import { Link } from "react-router-dom";
import type { BildirimEtkiRaporRow, BildirimEtkiRaporSummary } from "../../../../api/bildirim-etki-rapor.api";

type EtkiAdayiRaporTablosuProps = {
  rows: BildirimEtkiRaporRow[];
  summary: BildirimEtkiRaporSummary;
  page: number;
  totalPages: number;
  hasNextPage: boolean;
  hasPrevPage: boolean;
  onPageChange: (page: number) => void;
};

function formatEffective(row: BildirimEtkiRaporRow): string {
  if (row.effective_miktar == null) {
    return "-";
  }
  return `${row.effective_miktar}${row.effective_birim ? ` ${row.effective_birim}` : ""}`;
}

export function EtkiAdayiRaporTablosu({
  rows,
  summary,
  page,
  totalPages,
  hasNextPage,
  hasPrevPage,
  onPageChange
}: EtkiAdayiRaporTablosuProps) {
  return (
    <div className="etki-adayi-rapor-result" data-testid="etki-adayi-rapor-result">
      <div className="yonetim-summary-grid" data-testid="etki-adayi-rapor-summary">
        <article className="yonetim-summary-card">
          <span>Toplam aday</span>
          <strong>{summary.toplam_aday}</strong>
        </article>
        <article className="yonetim-summary-card">
          <span>Bekleyen</span>
          <strong>{summary.bekleyen}</strong>
        </article>
        <article className="yonetim-summary-card">
          <span>Koru / Revize</span>
          <strong>
            {summary.koru} / {summary.revize}
          </strong>
        </article>
        <article className="yonetim-summary-card">
          <span>Otomatik / Manuel</span>
          <strong>
            {summary.otomatik_uygulanan} / {summary.manuel_uygulanan}
          </strong>
        </article>
      </div>

      <p className="raporlar-result-meta">
        Koru / Revize kararları için{" "}
        <Link to="/puantaj" data-testid="etki-adayi-rapor-puantaj-link">
          Puantaj etki adayları
        </Link>{" "}
        ekranını kullanın.
      </p>

      <div className="raporlar-table-wrap yonetim-table-wrap" data-testid="etki-adayi-rapor-table">
        <table className="raporlar-table">
          <thead>
            <tr>
              <th>Personel</th>
              <th>Tarih</th>
              <th>Bildirim türü</th>
              <th>Etki</th>
              <th>Durum</th>
              <th>Çakışma</th>
              <th>Mevcut puantaj</th>
              <th>Uygulanan</th>
              <th>Karar</th>
              <th>Karar veren</th>
              <th>Mod</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id} data-testid={`etki-adayi-rapor-row-${row.id}`}>
                <td>
                  <div>{row.personel_ad_soyad}</div>
                  <small>{row.sicil_no ?? `#${row.personel_id}`}</small>
                </td>
                <td>{row.tarih}</td>
                <td>{row.bildirim_turu}</td>
                <td>
                  {row.etki_turu}
                  <br />
                  <small>{formatEffective(row)}</small>
                </td>
                <td>{row.state}</td>
                <td>{row.conflict_code ?? "-"}</td>
                <td>{row.mevcut_puantaj_ozet ?? "-"}</td>
                <td>{row.uygulanan_puantaj_ozet ?? "-"}</td>
                <td>{row.karar_turu ?? "-"}</td>
                <td>
                  {row.karar_veren ?? "-"}
                  {row.karar_zamani ? (
                    <>
                      <br />
                      <small>{new Date(row.karar_zamani).toLocaleString("tr-TR")}</small>
                    </>
                  ) : null}
                </td>
                <td>{row.uygulama_modu ?? "-"}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="module-pagination" data-testid="etki-adayi-rapor-pagination">
        <button
          type="button"
          className="state-action-btn"
          disabled={!hasPrevPage}
          onClick={() => onPageChange(Math.max(1, page - 1))}
        >
          Önceki
        </button>
        <span className="module-page-info">
          Sayfa {page}
          {totalPages ? ` / ${totalPages}` : ""}
        </span>
        <button type="button" className="state-action-btn" disabled={!hasNextPage} onClick={() => onPageChange(page + 1)}>
          Sonraki
        </button>
      </div>
    </div>
  );
}
