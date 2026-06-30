import { useEffect, useMemo, useState } from "react";
import { fetchPersonelBelgeDurumu } from "../../../../api/belgeler.api";
import { fetchPersonelBelgeKayitlari } from "../../../../api/personel-belge-kayitlari.api";
import { getApiErrorMessage } from "../../../../api/api-client";
import type { Personel } from "../../../../types/personel";
import { BELGE_TURU_KEYS, BELGE_TURU_LABELS, type BelgeDurumuItem } from "../../../../types/belgeler";
import {
  formatPersonelBelgeDisplayText,
  formatPersonelBelgeKayitTipiLabel,
  PERSONEL_BELGE_GECERLILIK_LABELS,
  PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE,
  type PersonelBelgeKaydi
} from "../../../../types/personel-belge-kaydi";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";
import { formatIsoDateDetail } from "./personel-dosya-format-utils";

function formatBelgeDurumLabel(durum: BelgeDurumuItem["durum"]) {
  return durum === "VAR" ? "Var" : "Yok";
}

function gecerlilikClassName(durum: PersonelBelgeKaydi["gecerlilik_durumu"]) {
  if (durum === "SURESI_DOLMUS") {
    return "personel-belge-kayit-state is-expired";
  }
  if (durum === "YAKINDA_DOLUYOR") {
    return "personel-belge-kayit-state is-expiring";
  }
  return "personel-belge-kayit-state";
}

export function PersonelBelgelerPanel({
  personel,
  isActive
}: {
  personel: Personel;
  isActive: boolean;
}) {
  const [items, setItems] = useState<BelgeDurumuItem[]>([]);
  const [belgeKayitlari, setBelgeKayitlari] = useState<PersonelBelgeKaydi[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isBelgeKayitlariLoading, setIsBelgeKayitlariLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [belgeKayitlariErrorMessage, setBelgeKayitlariErrorMessage] = useState<string | null>(null);

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

  useEffect(() => {
    let isCancelled = false;

    if (!isActive) {
      return;
    }

    setIsBelgeKayitlariLoading(true);
    setBelgeKayitlariErrorMessage(null);

    fetchPersonelBelgeKayitlari(personel.id, { state: "AKTIF", limit: 50 })
      .then((result) => {
        if (isCancelled) {
          return;
        }
        setBelgeKayitlari(result.items);
      })
      .catch((err) => {
        if (isCancelled) {
          return;
        }
        setBelgeKayitlari([]);
        setBelgeKayitlariErrorMessage(getApiErrorMessage(err, "Belge kayıtları yüklenemedi."));
      })
      .finally(() => {
        if (!isCancelled) {
          setIsBelgeKayitlariLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [isActive, personel.id]);

  const isPasif = personel.aktif_durum === "PASIF";
  const hasAnyBelge = items.some((item) => item.durum === "VAR");
  const sortedBelgeKayitlari = useMemo(
    () =>
      [...belgeKayitlari].sort((left, right) => {
        const leftDate = left.bitis_tarihi ?? "";
        const rightDate = right.bitis_tarihi ?? "";
        if (!leftDate && !rightDate) {
          return left.ad.localeCompare(right.ad, "tr");
        }
        if (!leftDate) {
          return 1;
        }
        if (!rightDate) {
          return -1;
        }
        return leftDate.localeCompare(rightDate);
      }),
    [belgeKayitlari]
  );

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

      <DossierSection
        title="Eğitim & Sertifikalar"
        description="Eğitim, sertifika, ehliyet ve yetkinlik kayıtları salt okunur gösterilir; düzenleme Kayıt ve Süreç ekranından yapılır."
      >
        {isBelgeKayitlariLoading ? (
          <DossierRecord label="Durum" value="Belge kayıtları yükleniyor..." />
        ) : null}
        {!isBelgeKayitlariLoading && belgeKayitlariErrorMessage ? (
          <DossierRecord label="Durum" value={belgeKayitlariErrorMessage} />
        ) : null}
        {!isBelgeKayitlariLoading && !belgeKayitlariErrorMessage && sortedBelgeKayitlari.length === 0 ? (
          <DossierRecord label="Kayıt" value={PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE} />
        ) : null}

        {!isBelgeKayitlariLoading && !belgeKayitlariErrorMessage && sortedBelgeKayitlari.length > 0 ? (
          <div className="personel-belge-kayit-table-wrap" data-testid="personel-belge-kayit-list">
            <table className="personel-belge-kayit-table">
              <thead>
                <tr>
                  <th>Tip</th>
                  <th>Ad</th>
                  <th>Veren kurum</th>
                  <th>Belge no</th>
                  <th>Başlangıç</th>
                  <th>Bitiş</th>
                  <th>Geçerlilik</th>
                  <th>Ek referansı</th>
                  <th>Açıklama</th>
                </tr>
              </thead>
              <tbody>
                {sortedBelgeKayitlari.map((kayit) => (
                  <tr key={kayit.id} data-testid={`personel-belge-kayit-row-${kayit.id}`}>
                    <td>{formatPersonelBelgeKayitTipiLabel(kayit.kayit_tipi)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.ad)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.veren_kurum)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.belge_no)}</td>
                    <td>{formatIsoDateDetail(kayit.baslangic_tarihi)}</td>
                    <td>{formatIsoDateDetail(kayit.bitis_tarihi)}</td>
                    <td>
                      <span className={gecerlilikClassName(kayit.gecerlilik_durumu)}>
                        {PERSONEL_BELGE_GECERLILIK_LABELS[kayit.gecerlilik_durumu]}
                      </span>
                    </td>
                    <td>{formatPersonelBelgeDisplayText(kayit.ek_ref)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.aciklama)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </DossierSection>
    </div>
  );
}
