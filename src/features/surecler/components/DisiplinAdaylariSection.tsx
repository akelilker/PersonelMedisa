import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { getApiErrorMessage } from "../../../api/api-client";
import {
  fetchDisiplinVakalarList,
  generateDisiplinVakalar
} from "../../../api/disiplin-vakalar.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { DisiplinVaka } from "../../../types/disiplin-vaka";

function currentYearMonth() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, "0");
  return `${now.getFullYear()}-${month}`;
}

const LIFECYCLE_LABELS: Record<string, string> = {
  INCELEME_ADAYI: "İnceleme Adayı",
  IK_INCELEME: "İK İnceleme",
  SAVUNMA_BEKLENIYOR: "Savunma Bekleniyor",
  SAVUNMA_ALINDI: "Savunma Alındı",
  SAVUNMA_SUNULMADI: "Savunma Sunulmadı",
  KARAR_BEKLIYOR: "Karar Bekliyor",
  KARAR_VERILDI: "Karar Verildi",
  KAPANDI: "Kapandı",
  ISLEMSIZ_KAPATILDI: "İşlemsiz Kapatıldı"
};

const OLAY_TURU_LABELS: Record<string, string> = {
  GEC_KALMA: "Geç Kalma",
  TAM_GUN_DEVAMSIZLIK: "Tam Gün Devamsızlık",
  AYLIK_TEKRARLAYAN_GEC_KALMA: "Aylık Tekrarlayan Geç Kalma"
};

export function DisiplinAdaylariSection() {
  const { hasPermission } = useRoleAccess();
  const canGenerate = hasPermission("disiplin.review");

  const [items, setItems] = useState<DisiplinVaka[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [generateAy, setGenerateAy] = useState(currentYearMonth());
  const [isGenerating, setIsGenerating] = useState(false);
  const [generateMessage, setGenerateMessage] = useState<string | null>(null);

  const loadItems = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const list = await fetchDisiplinVakalarList({ open_only: true });
      setItems(list);
    } catch (error) {
      setErrorMessage(getApiErrorMessage(error, "Disiplin adayları yüklenemedi."));
      setItems([]);
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadItems();
  }, [loadItems]);

  async function handleGenerate() {
    setGenerateMessage(null);
    setIsGenerating(true);
    try {
      const result = await generateDisiplinVakalar({ ay: generateAy });
      setGenerateMessage(
        `${result.created_count} aday oluşturuldu, ${result.skipped_count} atlandı.`
      );
      await loadItems();
    } catch (error) {
      setGenerateMessage(getApiErrorMessage(error, "Disiplin aday üretimi başarısız."));
    } finally {
      setIsGenerating(false);
    }
  }

  return (
    <section className="surec-detail-card" data-testid="disiplin-adaylari-section">
      <div className="surecler-header-row">
        <h3>Disiplin Adayları</h3>
        {canGenerate ? (
          <div className="form-field-grid">
            <FormField
              label="Ay"
              name="disiplin-generate-ay"
              type="month"
              value={generateAy}
              onChange={setGenerateAy}
            />
            <button
              type="button"
              className="universal-btn-aux"
              disabled={isGenerating}
              onClick={() => void handleGenerate()}
            >
              {isGenerating ? "Üretiliyor..." : "Aday Üret"}
            </button>
          </div>
        ) : null}
      </div>

      {generateMessage ? <p>{generateMessage}</p> : null}
      {isLoading ? <LoadingState label="Disiplin adayları yükleniyor..." /> : null}
      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadItems()} />
      ) : null}
      {!isLoading && !errorMessage && items.length === 0 ? (
        <p>Açık disiplin adayı bulunamadı.</p>
      ) : null}
      {!isLoading && !errorMessage && items.length > 0 ? (
        <table className="module-table">
          <thead>
            <tr>
              <th>Personel</th>
              <th>Tarih</th>
              <th>Olay</th>
              <th>Durum</th>
              <th>Süreç</th>
            </tr>
          </thead>
          <tbody>
            {items.map((item) => (
              <tr key={item.id}>
                <td>{item.personel_id}</td>
                <td>{item.tarih}</td>
                <td>{OLAY_TURU_LABELS[item.olay_turu] ?? item.olay_turu}</td>
                <td>{LIFECYCLE_LABELS[item.lifecycle_state] ?? item.lifecycle_state}</td>
                <td>
                  <Link to={`/surecler/${item.surec_id}`} className="universal-btn-aux">
                    #{item.surec_id}
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : null}
    </section>
  );
}
