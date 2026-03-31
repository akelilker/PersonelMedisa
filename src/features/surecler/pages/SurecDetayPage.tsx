import { useCallback, useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import { fetchSurecDetail } from "../../../api/surecler.api";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type { Surec } from "../../../types/surec";

export function SurecDetayPage() {
  const { surecId } = useParams();
  const parsedSurecId = Number.parseInt(surecId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedSurecId) && parsedSurecId > 0;

  const [surec, setSurec] = useState<Surec | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const loadSurecDetail = useCallback(async () => {
    if (!hasValidId) {
      setIsLoading(false);
      setErrorMessage("Gecerli bir surec id verilmedi.");
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const data = await fetchSurecDetail(parsedSurecId);
      setSurec(data);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Surec detayi alinamadi.");
    } finally {
      setIsLoading(false);
    }
  }, [hasValidId, parsedSurecId]);

  useEffect(() => {
    void loadSurecDetail();
  }, [loadSurecDetail]);

  return (
    <section className="surec-page surec-detay-page">
      <h2>Surec Detay</h2>

      {isLoading ? <LoadingState label="Surec detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadSurecDetail()} />
      ) : null}

      {!isLoading && !errorMessage && !surec ? (
        <EmptyState title="Surec bulunamadi" message="Belirtilen id ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && surec ? (
        <div className="surec-detail-card">
          <p>
            <strong>Surec ID:</strong> {surec.id}
          </p>
          <p>
            <strong>Personel ID:</strong> {surec.personel_id}
          </p>
          <p>
            <strong>Surec Turu:</strong> {surec.surec_turu}
          </p>
          <p>
            <strong>Alt Tur:</strong> {surec.alt_tur ?? "-"}
          </p>
          <p>
            <strong>Baslangic:</strong> {surec.baslangic_tarihi ?? "-"}
          </p>
          <p>
            <strong>Bitis:</strong> {surec.bitis_tarihi ?? "-"}
          </p>
          <p>
            <strong>Ucretli Mi:</strong> {surec.ucretli_mi === undefined ? "-" : surec.ucretli_mi ? "Evet" : "Hayir"}
          </p>
          <p>
            <strong>Durum:</strong> {surec.state ?? "-"}
          </p>
          <p>
            <strong>Aciklama:</strong> {surec.aciklama ?? "-"}
          </p>
        </div>
      ) : null}

      <div className="module-links">
        <Link to="/bildirimler">Bildirimlere git</Link>
      </div>
    </section>
  );
}
