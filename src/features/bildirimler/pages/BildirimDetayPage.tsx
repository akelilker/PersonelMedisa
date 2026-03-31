import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { fetchBildirimDetail } from "../../../api/bildirimler.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type { Bildirim } from "../../../types/bildirim";

export function BildirimDetayPage() {
  const { bildirimId } = useParams();
  const parsedBildirimId = Number.parseInt(bildirimId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedBildirimId) && parsedBildirimId > 0;

  const [bildirim, setBildirim] = useState<Bildirim | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const loadBildirimDetail = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir bildirim id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchBildirimDetail(parsedBildirimId);
      setBildirim(data);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bildirim detayi alinamadi.");
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedBildirimId]);

  useEffect(() => {
    void loadBildirimDetail();
  }, [loadBildirimDetail]);

  return (
    <section className="bildirimler-page bildirim-detay-page">
      <h2>Bildirim Detay</h2>

      {isLoading ? <LoadingState label="Bildirim detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadBildirimDetail()} />
      ) : null}

      {!isLoading && !errorMessage && !bildirim ? (
        <EmptyState title="Bildirim bulunamadi" message="Belirtilen id ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && bildirim ? (
        <div className="bildirim-detail-card">
          <p>
            <strong>Bildirim ID:</strong> {bildirim.id}
          </p>
          <p>
            <strong>Tarih:</strong> {bildirim.tarih ?? "-"}
          </p>
          <p>
            <strong>Departman ID:</strong> {bildirim.departman_id ?? "-"}
          </p>
          <p>
            <strong>Personel ID:</strong> {bildirim.personel_id ?? "-"}
          </p>
          <p>
            <strong>Bildirim Turu:</strong> {bildirim.bildirim_turu}
          </p>
          <p>
            <strong>Durum:</strong> {bildirim.state ?? "-"}
          </p>
          <p>
            <strong>Aciklama:</strong> {bildirim.aciklama ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/bildirimler">Bildirim listesine don</Link>
        <Link to="/surecler">Surec takibe git</Link>
      </div>
    </section>
  );
}
