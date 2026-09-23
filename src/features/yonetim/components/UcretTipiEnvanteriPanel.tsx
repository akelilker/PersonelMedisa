import { useEffect, useState } from "react";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import {
  UCRET_TIPI_ENVANTERI_BUCKET_KEYS,
  UCRET_TIPI_ENVANTERI_BUCKET_LABELS,
  UCRET_TIPI_ENVANTERI_DURUM_LABELS,
  buildUcretTipiEnvanteri,
  type UcretTipiEnvanteriDurum,
  type UcretTipiEnvanteriSonuc
} from "../../../lib/yonetim/ucret-tipi-envanteri";
import type { Personel } from "../../../types/personel";

/** PersonellerController list üst sınırı. */
const PAGE_LIMIT = 250;
/** Güvenlik sınırı: 60 × 250 = 15.000 kayıt. */
const MAX_PAGES = 60;

const DURUM_BADGE_CLASS: Record<UcretTipiEnvanteriDurum, string> = {
  TANIMLI: "badge badge--success",
  EKSIK: "badge badge--warn",
  GECERSIZ: "badge badge--danger"
};

/**
 * Salt okunur canonical read: GET /personeller (aktif + Dahili Personel).
 * Yazma, güncelleme veya toplu düzeltme çağrısı yapılmaz.
 */
async function fetchAktifIcPersoneller(signal: AbortSignal): Promise<{
  items: Personel[];
  possiblyTruncated: boolean;
}> {
  const items: Personel[] = [];
  let page = 1;
  let possiblyTruncated = false;

  while (page <= MAX_PAGES) {
    const result = await fetchPersonellerList({
      aktiflik: "aktif",
      calisan_kapsami: "IC_PERSONEL",
      page,
      limit: PAGE_LIMIT,
      prefer_query_sube: true,
      active_sube_header: null,
      signal
    });

    items.push(...result.items);

    const totalPages = result.pagination?.totalPages ?? null;
    if (result.items.length < PAGE_LIMIT || (totalPages != null && page >= totalPages)) {
      break;
    }

    if (page === MAX_PAGES) {
      possiblyTruncated = true;
      break;
    }

    page += 1;
  }

  return { items, possiblyTruncated };
}

/**
 * Ücret Tipi Envanteri — yönetim için salt okunur görünüm.
 *
 * Dağılımın tek kaynağı `personeller.ucret_tipi_id`; Mavi/Beyaz statüsünden
 * ücret tipi çıkarılmaz. Bu ekran hiçbir kaydı değiştirmez.
 */
export function UcretTipiEnvanteriPanel() {
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [envanter, setEnvanter] = useState<UcretTipiEnvanteriSonuc | null>(null);
  const [possiblyTruncated, setPossiblyTruncated] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    const controller = new AbortController();
    let cancelled = false;

    setIsLoading(true);
    setErrorMessage(null);

    void (async () => {
      try {
        const { items, possiblyTruncated: truncated } = await fetchAktifIcPersoneller(controller.signal);
        if (cancelled) {
          return;
        }
        setEnvanter(buildUcretTipiEnvanteri(items));
        setPossiblyTruncated(truncated);
      } catch (error) {
        if (cancelled) {
          return;
        }
        setErrorMessage(error instanceof Error ? error.message : "Ücret tipi envanteri yüklenemedi.");
      } finally {
        if (!cancelled) {
          setIsLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
      controller.abort();
    };
  }, [reloadKey]);

  return (
    <section
      className="yonetim-list-surface"
      aria-label="Ücret tipi envanteri"
      data-testid="yonetim-section-ucret-tipi-envanteri"
    >
      {isLoading ? <LoadingState label="Ücret tipi envanteri yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => setReloadKey((key) => key + 1)} />
      ) : null}

      {!isLoading && !errorMessage && envanter ? (
        <>
          <p className="yonetim-hint" role="status">
            Salt okunur envanter: dağılım yalnız aktif Dahili Personelin kanonik ücret tipi alanından okunur.
            Bu ekran hiçbir kaydı değiştirmez ve ücret tipi atamaz.
          </p>
          <p className="yonetim-hint">
            Harici Personel ücret tipi kapsamı dışındadır; bu envantere dahil edilmez.
          </p>

          <div className="yonetim-summary-grid" data-testid="ucret-tipi-envanteri-sayaclar">
            <article className="yonetim-summary-card" data-testid="ucret-tipi-envanteri-toplam">
              <span>Aktif Dahili Personel</span>
              <strong>{envanter.toplam}</strong>
            </article>
            {UCRET_TIPI_ENVANTERI_BUCKET_KEYS.map((key) => (
              <article
                key={key}
                className="yonetim-summary-card"
                data-testid={`ucret-tipi-envanteri-sayac-${key}`}
              >
                <span>
                  {UCRET_TIPI_ENVANTERI_BUCKET_LABELS[key]} ({key})
                </span>
                <strong>{envanter.sayaclar[key]}</strong>
              </article>
            ))}
          </div>

          {possiblyTruncated ? (
            <p className="yonetim-hint" role="alert" data-testid="ucret-tipi-envanteri-truncated">
              Liste üst sınıra ulaştı; sayaçlar eksik olabilir. Personel listesini daraltmak için yönetime
              başvurun.
            </p>
          ) : null}

          {envanter.satirlar.length === 0 ? (
            <p className="yonetim-hint" data-testid="ucret-tipi-envanteri-empty">
              Aktif Dahili Personel bulunamadı.
            </p>
          ) : (
            <div className="yonetim-list-table-wrap">
              <table className="yonetim-list-table" data-testid="ucret-tipi-envanteri-tablo">
                <thead>
                  <tr>
                    <th scope="col">Ad Soyad</th>
                    <th scope="col">Statü</th>
                    <th scope="col">Ücret Tipi</th>
                    <th scope="col">Durum</th>
                  </tr>
                </thead>
                <tbody>
                  {envanter.satirlar.map((satir) => (
                    <tr
                      key={satir.personelId}
                      className="yonetim-list-table-row"
                      data-testid={`ucret-tipi-envanteri-row-${satir.personelId}`}
                    >
                      <td className="yonetim-list-table-cell-strong">{satir.adSoyad}</td>
                      <td>{satir.statu ?? "-"}</td>
                      <td>{satir.ucretTipiEtiketi}</td>
                      <td>
                        <span
                          className={DURUM_BADGE_CLASS[satir.durum]}
                          data-testid={`ucret-tipi-envanteri-durum-${satir.personelId}`}
                        >
                          {UCRET_TIPI_ENVANTERI_DURUM_LABELS[satir.durum]}
                        </span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </>
      ) : null}
    </section>
  );
}
