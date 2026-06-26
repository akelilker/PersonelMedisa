import { useMemo } from "react";
import { formatSurecStateLabel, formatSurecTuruLabel } from "../../../../lib/display/enum-display";
import { hesaplaIzinBakiye } from "../../../../services/izin-hesap-motoru";
import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { DossierSection } from "./personel-dosya-dossier";
import { formatDetailValue } from "./personel-dosya-format-utils";

export function PersonelIzinOzetSection({
  personel,
  surecler,
  onOpenSurecHistory
}: {
  personel: Personel;
  surecler: Surec[];
  onOpenSurecHistory?: () => void;
}) {
  const bakiye = useMemo(() => {
    if (!personel.ise_giris_tarihi) return null;
    return hesaplaIzinBakiye(
      {
        ise_giris_tarihi: personel.ise_giris_tarihi,
        dogum_tarihi: personel.dogum_tarihi
      },
      surecler
    );
  }, [personel.ise_giris_tarihi, personel.dogum_tarihi, surecler]);

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
      description="Yıllık izin bakiyesi ve son hareketler burada özetlenir; detaylı izin geçmişi Süreç Geçmişi sekmesindedir."
    >
      <div data-testid="personel-izin-ozet-section">
        {bakiye ? (
          <div className="personel-izin-infobox" data-testid="izin-bakiye-infobox">
            <p>
              <strong>Kıdem:</strong> {bakiye.hak_edis.kidem_yil} yıl
            </p>
            {bakiye.hak_edis.yas !== null ? (
              <p>
                <strong>Yaş:</strong> {bakiye.hak_edis.yas}
              </p>
            ) : null}
            <p>
              <strong>Yıllık İzin Hakkı:</strong> {bakiye.hak_edis.yillik_izin_gun} gün
              {bakiye.hak_edis.yas_istisna_uygulandi ? (
                <span className="personel-izin-istisna-badge"> (50 yaş istisnası)</span>
              ) : null}
            </p>
            <p>
              <strong>Kullanılan:</strong> {bakiye.kullanilan_gun} gün
            </p>
            <p className="personel-izin-kalan">
              <strong>Kalan İzin:</strong> {bakiye.kalan_gun} gün
            </p>
          </div>
        ) : (
          <p>İşe giriş tarihi bilgisi eksik; izin hakkı hesaplanamadı.</p>
        )}

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
                  Başlangıç: {formatDetailValue(surec.baslangic_tarihi)}
                  {surec.bitis_tarihi ? ` | Bitiş: ${surec.bitis_tarihi}` : ""}
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
