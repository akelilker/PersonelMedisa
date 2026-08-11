import { useEffect, useMemo, useState } from "react";
import { fetchYillikIzinBakiye } from "../../../../api/yillik-izin-hak-duzeltme.api";
import { formatSurecStateLabel, formatSurecTuruLabel } from "../../../../lib/display/enum-display";
import type { YillikIzinBakiye } from "../../../../types/yillik-izin-hak-duzeltme";
import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { DossierSection } from "./personel-dosya-dossier";
import { formatIsoDateDetail } from "./personel-dosya-format-utils";

function formatSigned(value: number): string {
  return value > 0 ? `+${value}` : String(value);
}

export function PersonelIzinOzetSection({
  personel,
  surecler,
  onOpenSurecHistory
}: {
  personel: Personel;
  surecler: Surec[];
  onOpenSurecHistory?: () => void;
}) {
  const [bakiye, setBakiye] = useState<YillikIzinBakiye | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    void fetchYillikIzinBakiye(personel.id)
      .then((result) => {
        if (!cancelled) {
          setBakiye(result);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setBakiye(null);
          setError("İzin bakiyesi sunucudan alınamadı.");
        }
      })
      .finally(() => {
        if (!cancelled) {
          setLoading(false);
        }
      });
    return () => {
      cancelled = true;
    };
  }, [personel.id]);

  const izinSurecleri = useMemo(
    () =>
      [...surecler]
        .filter((s) => s.surec_turu === "IZIN" && s.state !== "IPTAL")
        .sort((left, right) =>
          (right.baslangic_tarihi ?? "").localeCompare(left.baslangic_tarihi ?? "")
        ),
    [surecler]
  );

  const sonIzinHareketleri = izinSurecleri.slice(0, 3);

  return (
    <DossierSection
      title="İzin Özeti"
      description="Yıllık izin bakiyesi sunucu modelinden okunur; detaylı izin geçmişi Süreç Geçmişi sekmesindedir."
    >
      <div data-testid="personel-izin-ozet-section">
        {loading ? <p data-testid="izin-bakiye-loading">İzin bakiyesi yükleniyor…</p> : null}
        {error ? <p data-testid="izin-bakiye-error">{error}</p> : null}
        {!loading && !error && bakiye ? (
          <div className="personel-izin-infobox" data-testid="izin-bakiye-infobox">
            <p>
              <strong>Kıdem:</strong> {bakiye.kidem_yil} yıl
            </p>
            {bakiye.yas !== null ? (
              <p>
                <strong>Yaş:</strong> {bakiye.yas}
              </p>
            ) : null}
            <p data-testid="izin-mevcut-hak">
              <strong>Bu Yıl / Mevcut Hak Ediş:</strong> {bakiye.mevcut_yillik_hak_gun} gün
              {bakiye.yas_istisna_uygulandi ? (
                <span className="personel-izin-istisna-badge"> (yaş istisnası)</span>
              ) : null}
            </p>
            <p data-testid="izin-birikmis-yasal-hak">
              <strong>Birikmiş Yasal Hak:</strong> {bakiye.birikmis_yasal_hak_gun} gün
            </p>
            <p data-testid="izin-manuel-duzeltme">
              <strong>Manuel Hak Düzeltmeleri:</strong> {formatSigned(bakiye.manuel_duzeltme_gun)} gün
            </p>
            <p data-testid="izin-kullanilan">
              <strong>Kullanılan:</strong>{" "}
              {bakiye.kullanilan_gun === null ? "Kesinleştirilemedi" : `${bakiye.kullanilan_gun} gün`}
            </p>
            <p className="personel-izin-kalan" data-testid="izin-kalan">
              <strong>Kalan İzin:</strong>{" "}
              {bakiye.kalan_gun === null ? "Kesinleştirilemedi" : `${bakiye.kalan_gun} gün`}
            </p>
            {!bakiye.takvim_dogrulandi_mi ? (
              <p data-testid="izin-takvim-eksik-uyarisi">
                Canonical çalışma takviminde {bakiye.eksik_takvim_tarihleri.length} tarih
                sınıflandırılmadığı için kullanılan ve kalan izin kesinleştirilemedi.
              </p>
            ) : null}
          </div>
        ) : null}
        {!loading && !error && !bakiye ? (
          <p>İşe giriş tarihi bilgisi eksik; izin hakkı hesaplanamadı.</p>
        ) : null}

        {sonIzinHareketleri.length > 0 ? (
          <ul className="personel-surec-list personel-izin-list" data-testid="izin-hareket-listesi">
            {sonIzinHareketleri.map((surec) => (
              <li key={surec.id} className="personel-surec-card">
                <span className="personel-surec-card-type">
                  {formatSurecTuruLabel(surec.surec_turu)}
                  {surec.alt_tur ? ` · ${formatSurecTuruLabel(surec.alt_tur)}` : ""}
                </span>
                <span className="personel-surec-card-state">{formatSurecStateLabel(surec.state)}</span>
                <span className="personel-surec-card-dates">
                  Başlangıç: {formatIsoDateDetail(surec.baslangic_tarihi)}
                  {surec.bitis_tarihi ? ` | Bitiş: ${formatIsoDateDetail(surec.bitis_tarihi)}` : ""}
                </span>
                {surec.aciklama ? (
                  <span className="personel-surec-card-desc">{surec.aciklama}</span>
                ) : null}
              </li>
            ))}
          </ul>
        ) : (
          <p>Kayıtlı izin hareketi bulunamadı.</p>
        )}

        {onOpenSurecHistory ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenSurecHistory}>
            Süreç Geçmişi&apos;nde gör
          </button>
        ) : null}
      </div>
    </DossierSection>
  );
}
