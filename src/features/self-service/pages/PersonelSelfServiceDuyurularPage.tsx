import { useCallback, useEffect, useState } from "react";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { fetchSelfDuyurular, markSelfDuyuruRead, type SelfDuyuru } from "../../../api/self-product.api";
import { LoadingState } from "../../../components/states/LoadingState";
import { formatIsoDateDetail } from "../../../lib/display/iso-date-format";

export function PersonelSelfServiceDuyurularPage() {
  const [loading, setLoading] = useState(true);
  const [items, setItems] = useState<SelfDuyuru[]>([]);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (shouldPreferDemoApi()) {
      setItems([]);
      setError(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const result = await fetchSelfDuyurular();
      setItems(result.items);
      setError(null);
    } catch (cause) {
      setItems([]);
      setError(
        isApiRequestError(cause) && cause.code === "DUYURU_SCHEMA_NOT_READY"
          ? "Duyurular henüz hazır değil."
          : "Duyurular yüklenemedi. Tekrar deneyin."
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function markRead(item: SelfDuyuru) {
    if (item.okundu) return;
    try {
      await markSelfDuyuruRead(item.id);
      setItems((current) => current.map((row) => (row.id === item.id ? { ...row, okundu: true } : row)));
    } catch {
      setError("Okundu işaretlenemedi.");
    }
  }

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-duyurular-page">
      {loading ? <LoadingState label="Duyurular yükleniyor..." /> : null}
      {!loading && error ? (
        <p className="self-service-muted" data-testid="personel-duyurular-status">
          {error}
        </p>
      ) : null}
      {!loading && !error && items.length === 0 ? (
        <p className="self-service-muted" data-testid="personel-duyurular-empty">
          Henüz duyuru bulunmuyor
        </p>
      ) : null}
      {!loading && items.length > 0 ? (
        <ul className="pm-self-request-list" data-testid="personel-duyuru-list">
          {items.map((item) => (
            <li key={item.id}>
              <article className="pm-self-request" data-testid={`personel-duyuru-${item.id}`}>
                <p className="pm-self-request__title">{item.baslik}</p>
                <p>{item.aciklama}</p>
                <p className="self-service-muted">{formatIsoDateDetail(item.yayin_tarihi)}</p>
                {item.okundu ? (
                  <p className="self-service-muted">Okundu</p>
                ) : (
                  <button
                    type="button"
                    className="self-service-action"
                    data-testid={`personel-duyuru-okundu-${item.id}`}
                    onClick={() => void markRead(item)}
                  >
                    Okundu işaretle
                  </button>
                )}
              </article>
            </li>
          ))}
        </ul>
      ) : null}
    </section>
  );
}
