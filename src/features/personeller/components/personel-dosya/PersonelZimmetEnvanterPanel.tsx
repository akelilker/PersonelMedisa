import {
  formatZimmetKayitDurumuLabel,
  formatZimmetTeslimDurumuLabel,
  formatZimmetUrunTuruLabel
} from "../../../../lib/display/enum-display";
import type { Zimmet } from "../../../../types/zimmet";
import { formatDetailValue, formatIsoDateDetail } from "./personel-dosya-format-utils";

export function PersonelZimmetEnvanterPanel({
  canCreateZimmet,
  isLoading,
  errorMessage,
  zimmetler,
  onOpenCreateModal
}: {
  canCreateZimmet: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  zimmetler: Zimmet[];
  onOpenCreateModal: () => void;
}) {
  return (
    <div className="personel-zimmet-panel">
      <div className="personel-zimmet-head">
        <div>
          <h3>Zimmet ve Envanter Kayıtları</h3>
          <p>Kullanıcıya teslim edilen ekipmanlar ve geri alınmış kayıtlar bu listede izlenir.</p>
        </div>
        {canCreateZimmet ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenCreateModal}>
            Yeni Zimmet Ekle
          </button>
        ) : null}
      </div>

      {isLoading ? <p className="personel-kart-placeholder-note">Zimmet kayıtları yükleniyor...</p> : null}
      {!isLoading && errorMessage ? <p className="personel-create-error">{errorMessage}</p> : null}

      {!isLoading && !errorMessage && zimmetler.length === 0 ? (
        <div className="personel-kart-placeholder">
          <h3>Zimmet Kaydı Bulunamadı</h3>
          <p>Bu personel için henüz zimmetlenmiş ürün kaydı bulunmuyor.</p>
        </div>
      ) : null}

      {!isLoading && !errorMessage && zimmetler.length > 0 ? (
        <div className="personel-zimmet-table-wrap">
          <table className="personel-zimmet-table">
            <thead>
              <tr>
                <th>Ürün Türü</th>
                <th>Teslim Tarihi</th>
                <th>Teslim Eden</th>
                <th>Teslim Durumu</th>
                <th>Kayıt Durumu</th>
                <th>Seri No / Açıklama</th>
              </tr>
            </thead>
            <tbody>
              {zimmetler.map((zimmet) => (
                <tr key={zimmet.id}>
                  <td className="personel-zimmet-cell-strong">{formatZimmetUrunTuruLabel(zimmet.urun_turu)}</td>
                  <td>{formatIsoDateDetail(zimmet.teslim_tarihi)}</td>
                  <td>{formatDetailValue(zimmet.teslim_eden)}</td>
                  <td>{formatZimmetTeslimDurumuLabel(zimmet.teslim_durumu)}</td>
                  <td>
                    <span
                      className={`personel-zimmet-state${zimmet.zimmet_durumu === "IADE_EDILDI" ? " is-returned" : ""}`}
                      data-testid="zimmet-durum"
                    >
                      {formatZimmetKayitDurumuLabel(zimmet.zimmet_durumu)}
                    </span>
                  </td>
                  <td className="personel-zimmet-note-cell">{formatDetailValue(zimmet.aciklama)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </div>
  );
}
