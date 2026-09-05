import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { fetchGunlukTamamlamaDetail } from "../../../api/bildirimler.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import {
  formatGunlukTamamlamaKayitLine,
  formatGunlukTamamlamaTarih
} from "../../../lib/bildirim/gunluk-tamamlama-detail-copy";
import { getApiErrorMessage } from "../../../api/api-client";
import type { GunlukTamamlamaDetail } from "../../../types/bildirim";

export function GunlukTamamlamaDetayPage() {
  const { submissionId } = useParams();
  const parsedId = Number.parseInt(submissionId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedId) && parsedId > 0;

  const [detail, setDetail] = useState<GunlukTamamlamaDetail | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const refetch = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Geçerli bir bildirim özeti ID verilmedi.");
      setDetail(null);
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);
    try {
      const data = await fetchGunlukTamamlamaDetail(parsedId);
      setDetail(data);
    } catch (caught) {
      setDetail(null);
      setErrorMessage(getApiErrorMessage(caught, "Devamsızlık bildirimi özeti yüklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedId]);

  useEffect(() => {
    void refetch();
  }, [refetch]);

  const submission = detail?.submission;
  const ozet = detail?.ozet;

  return (
    <section className="bildirimler-page gunluk-tamamlama-detay-page">
      <h2>Devamsızlık Bildirimi</h2>

      {isLoading ? <LoadingState label="Devamsızlık bildirimi özeti yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !detail ? (
        <EmptyState
          title="Bildirim özeti bulunamadı"
          message="Belirtilen ID ile tamamlanmış günlük bildirim bulunamadı."
        />
      ) : null}

      {!isLoading && !errorMessage && detail && submission && ozet ? (
        <div className="gunluk-tamamlama-detay">
          <div className="gunluk-tamamlama-meta bildirim-detail-card">
            <p>
              <strong>Bildirimi Yapan:</strong> {submission.tamamlayan_ad_soyad}
            </p>
            <p>
              <strong>Tarih:</strong> {formatGunlukTamamlamaTarih(submission.tarih)}
            </p>
            {submission.sube_adi ? (
              <p>
                <strong>Şube:</strong> {submission.sube_adi}
              </p>
            ) : null}
          </div>

          <div className="gunluk-tamamlama-ozet-card bildirim-detail-card">
            <h3 className="gunluk-tamamlama-scope">{detail.scope_label}</h3>
            <ul className="gunluk-tamamlama-ozet-list">
              <li>Toplam Personel: {ozet.toplam_personel}</li>
              <li>Geç Gelen: {ozet.gec_gelen} Kişi</li>
              <li>Gelmeyen: {ozet.gelmeyen} Kişi</li>
              <li>İzinli / Raporlu: {ozet.izinli_raporlu} Kişi</li>
              <li>Erken Çıkan: {ozet.erken_cikan} Kişi</li>
              <li>Görevde: {ozet.gorevde} Kişi</li>
              <li>Diğer: {ozet.diger} Kişi</li>
            </ul>
            {(ozet.eksik_giris ?? 0) > 0 ? (
              <p
                className="gunluk-eksik-giris-warning"
                data-testid="tamamlama-eksik-giris-warning"
                role="status"
              >
                {ozet.eksik_giris} Personel Henüz Giriş Yapmadı
              </p>
            ) : null}
          </div>

          <div className="gunluk-tamamlama-kategoriler">
            {detail.kategoriler
              .filter((kategori) => kategori.count > 0)
              .map((kategori) => (
                <section key={kategori.tur} className="gunluk-tamamlama-kategori bildirim-detail-card">
                  <h3>
                    {kategori.label}{" "}
                    <span className="gunluk-tamamlama-kategori-count">({kategori.count})</span>
                  </h3>
                  <ul className="gunluk-tamamlama-kisi-list">
                    {kategori.kayitlar.map((kayit) => (
                      <li key={kayit.bildirim_id}>{formatGunlukTamamlamaKayitLine(kayit)}</li>
                    ))}
                  </ul>
                </section>
              ))}
          </div>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/bildirimler">Günlük kayıt listesine dön</Link>
      </div>
    </section>
  );
}
