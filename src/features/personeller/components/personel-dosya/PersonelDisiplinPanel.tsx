import { useEffect, useMemo, useState } from "react";
import { getApiErrorMessage } from "../../../../api/api-client";
import { fetchFinansKalemList } from "../../../../api/finans.api";
import type { FinansKalem } from "../../../../types/finans";
import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";
import { formatDetailValue } from "./personel-dosya-format-utils";
import {
  formatDisiplinSurecSignalState,
  formatDisiplinSurecSignalSummary,
  formatDisiplinSurecSignalTitle,
  formatFinansCezaKayitSummary,
  sortCezaFinansKalemleri,
  sortDisiplinSurecSignals
} from "./personel-disiplin-utils";

export function PersonelDisiplinPanel({
  personel,
  surecler,
  isActive,
  isSurecHistoryLoading,
  surecHistoryErrorMessage,
  canViewFinans,
  canAccessSurecler,
  onOpenSurecHistory
}: {
  personel: Personel;
  surecler: Surec[];
  isActive: boolean;
  isSurecHistoryLoading: boolean;
  surecHistoryErrorMessage: string | null;
  canViewFinans: boolean;
  canAccessSurecler: boolean;
  onOpenSurecHistory?: () => void;
}) {
  const [cezaKalemleri, setCezaKalemleri] = useState<FinansKalem[]>([]);
  const [isCezaLoading, setIsCezaLoading] = useState(false);
  const [cezaErrorMessage, setCezaErrorMessage] = useState<string | null>(null);

  const disiplinSurecSignals = useMemo(() => sortDisiplinSurecSignals(surecler), [surecler]);
  const sonDisiplinSurecSignals = disiplinSurecSignals.slice(0, 5);

  useEffect(() => {
    let isCancelled = false;

    if (!isActive || !canViewFinans) {
      setCezaKalemleri([]);
      setIsCezaLoading(false);
      setCezaErrorMessage(null);
      return;
    }

    setIsCezaLoading(true);
    setCezaErrorMessage(null);

    fetchFinansKalemList({
      personel_id: personel.id,
      kalem_turu: "CEZA",
      limit: 50
    })
      .then((result) => {
        if (isCancelled) {
          return;
        }
        setCezaKalemleri(sortCezaFinansKalemleri(result.items, personel.id));
      })
      .catch((err) => {
        if (isCancelled) {
          return;
        }
        setCezaKalemleri([]);
        setCezaErrorMessage(getApiErrorMessage(err, "Ceza kayıtları yüklenemedi."));
      })
      .finally(() => {
        if (!isCancelled) {
          setIsCezaLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [canViewFinans, isActive, personel.id]);

  return (
    <div className="personel-dosya-sections" data-testid="personel-disiplin-panel">
      <DossierSection
        title="Disiplin Özeti"
        description="Ceza ve disiplin kayıtları salt okunur izlenir; yeni kayıt kayıt ve süreç ekranından veya finans modülünden yönetilir."
      >
        <DossierRecord
          label="Kart Davranışı"
          value="Bu sekmeden ceza veya disiplin kaydı oluşturulmaz."
        />
      </DossierSection>

      <DossierSection
        title="Finans CEZA Kayıtları"
        description="Personel için tanımlı finans CEZA kalemleri burada özetlenir."
      >
        <div data-testid="personel-disiplin-ceza-section">
          {!canViewFinans ? (
            <DossierRecord label="Yetki" value="Finans ceza kayıtlarını görüntüleme yetkiniz yok." />
          ) : null}

          {canViewFinans && isCezaLoading ? (
            <DossierRecord label="Durum" value="Ceza kayıtları yükleniyor..." />
          ) : null}
          {canViewFinans && !isCezaLoading && cezaErrorMessage ? (
            <DossierRecord label="Durum" value={cezaErrorMessage} />
          ) : null}

          {canViewFinans && !isCezaLoading && !cezaErrorMessage && cezaKalemleri.length === 0 ? (
            <DossierRecord label="Kayıt" value="Bu personel için CEZA finans kaydı bulunamadı." />
          ) : null}

          {canViewFinans && !isCezaLoading && !cezaErrorMessage && cezaKalemleri.length > 0 ? (
            <ul className="personel-surec-list personel-izin-list" data-testid="personel-disiplin-ceza-list">
              {cezaKalemleri.map((item) => (
                <li key={item.id} className="personel-surec-card">
                  <span className="personel-surec-card-type">Ceza · {item.donem}</span>
                  <span className="personel-surec-card-state">{formatFinansCezaKayitSummary(item)}</span>
                  {item.aciklama ? (
                    <span className="personel-surec-card-desc">{formatDetailValue(item.aciklama)}</span>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      </DossierSection>

      <DossierSection
        title="Süreç Disiplin Sinyalleri"
        description="Süreç geçmişindeki devamsızlık, geç gelme ve benzeri disiplin sinyalleri burada özetlenir."
      >
        <div data-testid="personel-disiplin-surec-signals">
          {!canAccessSurecler ? (
            <DossierRecord label="Yetki" value="Süreç disiplin sinyallerini görüntüleme yetkiniz yok." />
          ) : null}

          {canAccessSurecler && isSurecHistoryLoading ? (
            <DossierRecord label="Durum" value="Süreç sinyalleri yükleniyor..." />
          ) : null}
          {canAccessSurecler && !isSurecHistoryLoading && surecHistoryErrorMessage ? (
            <DossierRecord label="Durum" value={surecHistoryErrorMessage} />
          ) : null}

          {canAccessSurecler && !isSurecHistoryLoading && !surecHistoryErrorMessage && sonDisiplinSurecSignals.length === 0 ? (
            <DossierRecord label="Kayıt" value="Süreç geçmişinde disiplin sinyali bulunamadı." />
          ) : null}

          {canAccessSurecler && !isSurecHistoryLoading && !surecHistoryErrorMessage && sonDisiplinSurecSignals.length > 0 ? (
            <ul className="personel-surec-list personel-izin-list" data-testid="personel-disiplin-surec-list">
              {sonDisiplinSurecSignals.map((surec) => (
                <li key={surec.id} className="personel-surec-card">
                  <span className="personel-surec-card-type">{formatDisiplinSurecSignalTitle(surec)}</span>
                  <span className="personel-surec-card-state">{formatDisiplinSurecSignalState(surec)}</span>
                  <span className="personel-surec-card-dates">{formatDisiplinSurecSignalSummary(surec)}</span>
                  {surec.aciklama ? (
                    <span className="personel-surec-card-desc">{formatDetailValue(surec.aciklama)}</span>
                  ) : null}
                </li>
              ))}
            </ul>
          ) : null}

          {canAccessSurecler && onOpenSurecHistory ? (
            <button type="button" className="universal-btn-aux" onClick={onOpenSurecHistory}>
              Süreç Geçmişi&apos;nde gör
            </button>
          ) : null}
        </div>
      </DossierSection>
    </div>
  );
}
