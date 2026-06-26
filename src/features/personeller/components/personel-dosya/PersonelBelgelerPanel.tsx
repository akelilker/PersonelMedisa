import { useEffect, useState } from "react";
import { fetchPersonelBelgeDurumu } from "../../../../api/belgeler.api";
import { getApiErrorMessage } from "../../../../api/api-client";
import type { Personel } from "../../../../types/personel";
import { BELGE_TURU_KEYS, BELGE_TURU_LABELS, type BelgeDurumuItem } from "../../../../types/belgeler";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";

function formatBelgeDurumLabel(durum: BelgeDurumuItem["durum"]) {
  return durum === "VAR" ? "Var" : "Yok";
}

export function PersonelBelgelerPanel({
  personel,
  isActive
}: {
  personel: Personel;
  isActive: boolean;
}) {
  const [items, setItems] = useState<BelgeDurumuItem[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    let isCancelled = false;

    if (!isActive) {
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    fetchPersonelBelgeDurumu(personel.id)
      .then((fetchedItems) => {
        if (isCancelled) {
          return;
        }
        setItems(fetchedItems);
      })
      .catch((err) => {
        if (isCancelled) {
          return;
        }
        setItems([]);
        setErrorMessage(getApiErrorMessage(err, "Belge durumu yüklenemedi."));
      })
      .finally(() => {
        if (!isCancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [isActive, personel.id]);

  const isPasif = personel.aktif_durum === "PASIF";
  const hasAnyBelge = items.some((item) => item.durum === "VAR");

  return (
    <div className="personel-dosya-sections" data-testid="personel-belgeler-panel">
      <DossierSection
        title="Belge Durumu"
        description="Personel dosyasındaki zorunlu belgeler salt okunur izlenir; düzenleme kayıt ve süreç ekranından yapılır."
      >
        {isPasif ? (
          <DossierRecord label="Durum" value="Bu personel pasif; belge durumu salt okunur gösterilir." />
        ) : null}

        {isLoading ? <DossierRecord label="Durum" value="Belgeler yükleniyor..." /> : null}
        {!isLoading && errorMessage ? <DossierRecord label="Durum" value={errorMessage} /> : null}

        {!isLoading && !errorMessage ? (
          <>
            {!hasAnyBelge ? (
              <DossierRecord label="Kayıt" value="Henüz VAR olarak işaretlenmiş belge yok." />
            ) : null}
            {BELGE_TURU_KEYS.map((tur) => {
              const item = items.find((row) => row.belge_turu === tur);
              const durum = item?.durum ?? "YOK";
              return (
                <DossierRecord
                  key={tur}
                  label={BELGE_TURU_LABELS[tur]}
                  value={formatBelgeDurumLabel(durum)}
                />
              );
            })}
          </>
        ) : null}
      </DossierSection>

      <DossierSection title="Eğitim & Sertifikalar">
        <DossierRecord
          label="Durum"
          value="Eğitim, sertifika ve ehliyet kayıtları sonraki fazda ayrı veri modeliyle eklenecektir."
        />
      </DossierSection>
    </div>
  );
}
