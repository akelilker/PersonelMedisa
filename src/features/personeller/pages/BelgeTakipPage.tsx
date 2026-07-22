import { FormEvent, useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { fetchBelgeTakip } from "../../../api/personel-belge-kayitlari.api";
import { getApiErrorMessage } from "../../../api/api-client";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import {
  formatPersonelBelgeKayitTipiLabel,
  formatPersonelBelgeTakipDurumuLabel,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  PERSONEL_BELGE_KAYIT_TIPI_LABELS,
  PERSONEL_BELGE_TAKIP_DURUMU_LABELS,
  takipDurumuClassName,
  type BelgeTakipOzet,
  type BelgeTakipSatir,
  type PersonelBelgeKayitTipi,
  type PersonelBelgeTakipDurumu
} from "../../../types/personel-belge-kaydi";
import { formatIsoDateDetail } from "../components/personel-dosya/personel-dosya-format-utils";
import type { IdOption } from "../../../types/referans";

type FilterDraft = {
  subeId: string;
  departmanId: string;
  personelId: string;
  kayitTipi: string;
  takipDurumu: string;
  baslangicTarihi: string;
  bitisTarihi: string;
  personelAktiflik: "AKTIF" | "PASIF" | "tum";
};

const EMPTY_FILTERS: FilterDraft = {
  subeId: "",
  departmanId: "",
  personelId: "",
  kayitTipi: "",
  takipDurumu: "",
  baslangicTarihi: "",
  bitisTarihi: "",
  personelAktiflik: "AKTIF"
};

const EMPTY_OZET: BelgeTakipOzet = {
  toplam_aktif: 0,
  suresi_yaklasan: 0,
  suresi_dolan: 0,
  dosyasi_eksik: 0,
  belgesi_hic_bulunmayan: 0
};

function toSelectOptions(options: IdOption[]) {
  return options.map((option) => ({ value: String(option.id), label: option.label }));
}

export function BelgeTakipPage() {
  const [draft, setDraft] = useState<FilterDraft>(EMPTY_FILTERS);
  const [applied, setApplied] = useState<FilterDraft>(EMPTY_FILTERS);
  const [ozet, setOzet] = useState<BelgeTakipOzet>(EMPTY_OZET);
  const [items, setItems] = useState<BelgeTakipSatir[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [personelOptions, setPersonelOptions] = useState<Array<{ id: number; label: string }>>([]);

  useEffect(() => {
    let cancelled = false;
    fetchDepartmanOptions()
      .then((options) => {
        if (!cancelled) {
          setDepartmanOptions(options);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setDepartmanOptions([]);
        }
      });

    fetchPersonellerList({ limit: 200, page: 1 })
      .then((result) => {
        if (!cancelled) {
          setPersonelOptions(
            result.items.map((personel) => ({
              id: personel.id,
              label: `${personel.ad} ${personel.soyad}`
            }))
          );
        }
      })
      .catch(() => {
        if (!cancelled) {
          setPersonelOptions([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  const loadData = useCallback(async (filters: FilterDraft) => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const result = await fetchBelgeTakip({
        sube_id: filters.subeId || undefined,
        departman_id: filters.departmanId || undefined,
        personel_id: filters.personelId || undefined,
        kayit_tipi: (filters.kayitTipi as PersonelBelgeKayitTipi) || undefined,
        takip_durumu: (filters.takipDurumu as PersonelBelgeTakipDurumu) || undefined,
        baslangic_tarihi: filters.baslangicTarihi || undefined,
        bitis_tarihi: filters.bitisTarihi || undefined,
        personel_aktiflik: filters.personelAktiflik,
        limit: 100
      });
      setOzet(result.ozet);
      setItems(result.items);
    } catch (err) {
      setOzet(EMPTY_OZET);
      setItems([]);
      setErrorMessage(getApiErrorMessage(err, "Belge takip verisi yüklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadData(applied);
  }, [applied, loadData]);

  function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setApplied({ ...draft });
  }

  function handleClear() {
    setDraft(EMPTY_FILTERS);
    setApplied(EMPTY_FILTERS);
  }

  const summaryCards = [
    { key: "toplam_aktif", label: "Toplam aktif", value: ozet.toplam_aktif },
    { key: "suresi_yaklasan", label: "Süresi yaklaşan", value: ozet.suresi_yaklasan },
    { key: "suresi_dolan", label: "Süresi dolan", value: ozet.suresi_dolan },
    { key: "dosyasi_eksik", label: "Dosyası eksik", value: ozet.dosyasi_eksik },
    {
      key: "belgesi_hic_bulunmayan",
      label: "Belgesi hiç bulunmayan",
      value: ozet.belgesi_hic_bulunmayan
    }
  ] as const;

  return (
    <section className="belge-takip-page" data-testid="belge-takip-page">
      <div className="personel-belge-panel-head">
        <div>
          <h2>Belge Takip</h2>
          <p>Personel belge kayıtlarının süre, dosya ve eksiklik durumunu izleyin.</p>
        </div>
        <Link className="universal-btn-aux" to="/personeller">
          Personellere dön
        </Link>
      </div>

      <form className="belge-takip-filter-panel workspace-form" onSubmit={handleSubmit}>
        <div className="form-field-grid">
          <FormField
            as="select"
            label="Departman"
            name="belge-takip-departman"
            value={draft.departmanId}
            onChange={(value) => setDraft((prev) => ({ ...prev, departmanId: value }))}
            placeholderOption={{ value: "", label: "Tümü" }}
            selectOptions={toSelectOptions(departmanOptions)}
          />
          <FormField
            as="select"
            label="Personel"
            name="belge-takip-personel"
            value={draft.personelId}
            onChange={(value) => setDraft((prev) => ({ ...prev, personelId: value }))}
            placeholderOption={{ value: "", label: "Tümü" }}
            selectOptions={personelOptions.map((option) => ({
              value: String(option.id),
              label: option.label
            }))}
          />
          <FormField
            as="select"
            label="Kayıt tipi"
            name="belge-takip-kayit-tipi"
            value={draft.kayitTipi}
            onChange={(value) => setDraft((prev) => ({ ...prev, kayitTipi: value }))}
            placeholderOption={{ value: "", label: "Tümü" }}
            selectOptions={PERSONEL_BELGE_KAYIT_TIPI_KEYS.map((tip) => ({
              value: tip,
              label: PERSONEL_BELGE_KAYIT_TIPI_LABELS[tip]
            }))}
          />
          <FormField
            as="select"
            label="Takip durumu"
            name="belge-takip-durumu"
            value={draft.takipDurumu}
            onChange={(value) => setDraft((prev) => ({ ...prev, takipDurumu: value }))}
            placeholderOption={{ value: "", label: "Tümü" }}
            selectOptions={(
              Object.keys(PERSONEL_BELGE_TAKIP_DURUMU_LABELS) as PersonelBelgeTakipDurumu[]
            ).map((durum) => ({
              value: durum,
              label: PERSONEL_BELGE_TAKIP_DURUMU_LABELS[durum]
            }))}
          />
          <FormField
            label="Bitiş başlangıç"
            name="belge-takip-baslangic"
            type="date"
            value={draft.baslangicTarihi}
            onChange={(value) => setDraft((prev) => ({ ...prev, baslangicTarihi: value }))}
          />
          <FormField
            label="Bitiş bitiş"
            name="belge-takip-bitis"
            type="date"
            value={draft.bitisTarihi}
            onChange={(value) => setDraft((prev) => ({ ...prev, bitisTarihi: value }))}
          />
          <FormField
            as="select"
            label="Personel aktiflik"
            name="belge-takip-aktiflik"
            value={draft.personelAktiflik}
            onChange={(value) =>
              setDraft((prev) => ({
                ...prev,
                personelAktiflik: (value as FilterDraft["personelAktiflik"]) || "tum"
              }))
            }
            selectOptions={[
              { value: "AKTIF", label: "Aktif" },
              { value: "PASIF", label: "Pasif" },
              { value: "tum", label: "Tümü" }
            ]}
          />
        </div>
        <div className="universal-btn-group">
          <button type="submit" className="universal-btn-save" data-testid="belge-takip-filter-submit">
            Filtrele
          </button>
          <button type="button" className="universal-btn-aux" onClick={handleClear}>
            Temizle
          </button>
        </div>
      </form>

      <div className="belge-takip-summary-grid">
        {summaryCards.map((card) => (
          <article key={card.key} className="yonetim-summary-card" data-testid={`belge-takip-ozet-${card.key}`}>
            <p>{card.label}</p>
            <strong>{card.value}</strong>
          </article>
        ))}
      </div>

      {isLoading ? <LoadingState label="Belge takip listesi yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadData(applied)} /> : null}
      {!isLoading && !errorMessage && items.length === 0 ? (
        <EmptyState title="Kayıt bulunamadı" message="Seçili filtrelere uygun belge kaydı yok." />
      ) : null}

      {!isLoading && !errorMessage && items.length > 0 ? (
        <div className="belge-takip-table-wrap" data-testid="belge-takip-table">
          <table className="personel-belge-kayit-table">
            <thead>
              <tr>
                <th>Personel</th>
                <th>Tip</th>
                <th>Ad</th>
                <th>Belge no</th>
                <th>Bitiş</th>
                <th>Takip</th>
                <th>Son güncelleme</th>
              </tr>
            </thead>
            <tbody>
              {items.map((row) => (
                <tr key={row.belge_kaydi_id} data-testid={`belge-takip-row-${row.belge_kaydi_id}`}>
                  <td>
                    <Link to={`/personeller/${row.personel_id}?tab=egitim-belgeler`}>
                      {row.personel_ad_soyad}
                    </Link>
                  </td>
                  <td>{formatPersonelBelgeKayitTipiLabel(row.kayit_tipi)}</td>
                  <td>{row.ad}</td>
                  <td>{row.belge_no_masked ?? "-"}</td>
                  <td>{formatIsoDateDetail(row.bitis_tarihi)}</td>
                  <td>
                    <span
                      className={takipDurumuClassName(row.takip_durumu)}
                      data-testid={`belge-takip-badge-${row.belge_kaydi_id}`}
                    >
                      {formatPersonelBelgeTakipDurumuLabel(row.takip_durumu)}
                    </span>
                  </td>
                  <td>{formatIsoDateDetail(row.updated_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  );
}
