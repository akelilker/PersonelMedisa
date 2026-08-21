import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import {
  fetchSerbestZamanDeadlineTakip,
  postSerbestZamanKullanim
} from "../../../api/serbest-zaman.api";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { downloadReportCsv } from "../../../reports/export-report";
import type {
  PostSerbestZamanKullanimPayload,
  SerbestZamanDeadlineRow,
  SerbestZamanDeadlineState,
  SerbestZamanDeadlineSummary
} from "../../../types/serbest-zaman";
import { serbestZamanDeadlineStateLabel } from "../raporlar-ia";
import { FormField } from "../../../components/form/FormField";
import { getApiErrorMessage } from "../../../api/api-client";

const PAGE_SIZE = 25;

const DURUM_OPTIONS: Array<{ value: "" | SerbestZamanDeadlineState; label: string }> = [
  { value: "", label: "Tümü" },
  { value: "SURESI_DOLDU", label: "Süresi dolmuş" },
  { value: "YAKLASIYOR", label: "Yaklaşan" },
  { value: "ALLOCATION_UNRESOLVED", label: "İnceleme gerekli" },
  { value: "NORMAL", label: "Normal" }
];

function todayYmd(): string {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

const KULLANIM_FORM_ID = "serbest-zaman-kullanim-form";

type KullanimFormState = Omit<PostSerbestZamanKullanimPayload, "islem_anahtari">;

export function SerbestZamanTakipPage() {
  const [referansTarih, setReferansTarih] = useState(todayYmd);
  const [durum, setDurum] = useState<"" | SerbestZamanDeadlineState>("");
  const [personelId, setPersonelId] = useState("");
  const [page, setPage] = useState(1);
  const [items, setItems] = useState<SerbestZamanDeadlineRow[]>([]);
  const [summary, setSummary] = useState<SerbestZamanDeadlineSummary | null>(null);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [hasSearched, setHasSearched] = useState(false);

  // === Kullanım Formu State'leri ===
  const [isKullanimModalOpen, setIsKullanimModalOpen] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);
  const [kullanimForm, setKullanimForm] = useState<KullanimFormState>({
    personel_id: "",
    event_tarihi: todayYmd(),
    dakika: "",
    aciklama: ""
  });

  const openKullanimModal = useCallback(() => {
    setSubmitError(null);
    setKullanimForm({
      personel_id: "",
      event_tarihi: todayYmd(),
      dakika: "",
aciklama: "Serbest zaman kullanımı."
    });
    setIsKullanimModalOpen(true);
  }, []);

  const closeKullanimModal = useCallback(() => {
    setIsKullanimModalOpen(false);
  }, []);


  const load = useCallback(
    async (nextPage: number) => {
      setIsLoading(true);
      setErrorMessage(null);
      try {
        const parsedPersonel = personelId.trim()
          ? Number.parseInt(personelId.trim(), 10)
          : undefined;
        const result = await fetchSerbestZamanDeadlineTakip({
          referans_tarih: referansTarih,
          durum: durum || undefined,
          personel_id:
            parsedPersonel !== undefined && Number.isFinite(parsedPersonel)
              ? parsedPersonel
              : undefined,
          page: nextPage,
          limit: PAGE_SIZE
        });
        setItems(result.items);
        setSummary(result.summary);
        setTotal(result.total);
        setTotalPages(result.total_pages);
        setPage(result.page);
        setHasSearched(true);
      } catch (error) {
        setErrorMessage(
          error instanceof Error ? error.message : "Serbest zaman takip yüklenemedi."
        );
      } finally {
        setIsLoading(false);
      }
    },
    [durum, personelId, referansTarih]
  );

  const handleKullanimSubmit = useCallback(
    async (event: FormEvent<HTMLFormElement>) => {
      event.preventDefault();
      setIsSubmitting(true);
      setSubmitError(null);

      try {
        await postSerbestZamanKullanim({
          ...kullanimForm,
          islem_anahtari: crypto.randomUUID()
        });
        closeKullanimModal();
        // Listeyi yenilemek için load fonksiyonunu çağır.
        await load(1);
      } catch (error) {
        setSubmitError(getApiErrorMessage(error, "Kullanım kaydı oluşturulamadı."));
      } finally {
        setIsSubmitting(false);
      }
    },
    [kullanimForm, closeKullanimModal, load]
  );

  useEffect(() => {
    void load(1);
  }, [load]);

  const cards = useMemo(() => {
    return [
      {
        key: "yaklasan",
        label: "Yaklaşan",
        value: summary?.yaklasan_lot_sayisi ?? 0,
        hint: `${summary?.yaklasan_dakika ?? 0} dk`
      },
      {
        key: "suresi",
        label: "Süresi Dolmuş",
        value: summary?.suresi_dolmus_lot_sayisi ?? 0,
        hint: `${summary?.suresi_dolmus_kullanilmamis_dakika ?? 0} dk kullanılmamış`
      },
      {
        key: "unresolved",
        label: "Allocation İncelemesi Gereken",
        value: summary?.allocation_unresolved_personel_sayisi ?? 0,
        hint: "Tahsis geçmişi belirsiz; otomatik dakika üretilmez"
      }
    ];
  }, [summary]);

  function handleExport() {
    downloadReportCsv(
      `serbest-zaman-takip-${referansTarih}.csv`,
      [
        "personel_id",
        "ad_soyad",
        "sicil_no",
        "sube_ad",
        "allocation_state",
        "olusum_event_id",
        "son_kullanim_tarihi",
        "available_dakika",
        "kalan_gun",
        "deadline_state",
        "compliance_action"
      ],
      items.map((row) => ({
        personel_id: row.personel_id,
        ad_soyad: row.ad_soyad,
        sicil_no: row.sicil_no,
        sube_ad: row.sube_ad,
        allocation_state: row.allocation_state,
        olusum_event_id: row.olusum_event_id,
        son_kullanim_tarihi: row.son_kullanim_tarihi,
        available_dakika: row.available_dakika,
        kalan_gun: row.kalan_gun,
        deadline_state: row.deadline_state,
        compliance_action: row.compliance_action
      }))
    );
  }

  return (
    <section className="raporlar-panel" data-testid="serbest-zaman-takip-page">
      <header className="raporlar-panel__header">
        <h2>Serbest Zaman Takibi</h2>
        <p>
          6 aylık kullanım deadline takibi operasyonel uyarıdır. &quot;Süresi dolmuş&quot; otomatik
          ücret/bordro blokajı anlamına gelmez. &quot;İnceleme gerekli&quot; tahsis geçmişi
          belirsiz personeller içindir; sahte dakika üretilmez.
        </p>
      </header>

      <div className="raporlar-summary-cards" data-testid="serbest-zaman-takip-summary">
        {cards.map((card) => (
          <article key={card.key} className="raporlar-summary-card">
            <h3>{card.label}</h3>
            <p className="raporlar-summary-card__value">{card.value}</p>
          <p className="raporlar-summary-card__hint">{card.hint}</p>
        </article>
      ))}
    </div>

    <div className="raporlar-panel__actions">
      <button type="button" className="universal-btn-aux" onClick={openKullanimModal}>
        Serbest Zaman Kullanımı Ekle
      </button>
    </div>


      <form
        className="raporlar-filters"
        onSubmit={(event) => {
          event.preventDefault();
          void load(1);
        }}
      >
        <label>
          Referans tarihi
          <input
            type="date"
            value={referansTarih}
            onChange={(event) => setReferansTarih(event.target.value)}
            data-testid="serbest-zaman-takip-referans"
          />
        </label>
        <label>
          Durum
          <select
            value={durum}
            onChange={(event) =>
              setDurum(event.target.value as "" | SerbestZamanDeadlineState)
            }
            data-testid="serbest-zaman-takip-durum"
          >
            {DURUM_OPTIONS.map((opt) => (
              <option key={opt.value || "all"} value={opt.value}>
                {opt.label}
              </option>
            ))}
          </select>
        </label>
        <label>
          Personel ID
          <input
            type="text"
            inputMode="numeric"
            value={personelId}
            onChange={(event) => setPersonelId(event.target.value)}
            data-testid="serbest-zaman-takip-personel"
          />
        </label>
        <button type="submit" data-testid="serbest-zaman-takip-submit">
          Uygula
        </button>
        <button type="button" onClick={handleExport} data-testid="serbest-zaman-takip-export">
          CSV
        </button>
      </form>

      {isLoading ? <LoadingState label="Serbest zaman takip yükleniyor…" /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} /> : null}
      {!isLoading && !errorMessage && hasSearched && items.length === 0 ? (
        <EmptyState
          title="Kayıt yok"
          message="Seçilen filtrelerde takip satırı yok."
        />
      ) : null}

      {!isLoading && !errorMessage && items.length > 0 ? (
        <div className="table-wrap">
          <table data-testid="serbest-zaman-takip-table">
            <thead>
              <tr>
                <th>Personel</th>
                <th>Sicil</th>
                <th>Şube</th>
                <th>Allocation</th>
                <th>OLUSUM</th>
                <th>Son kullanım</th>
                <th>Kalan dk</th>
                <th>Kalan gün</th>
                <th>Durum</th>
              </tr>
            </thead>
            <tbody>
              {items.map((row, index) => (
                <tr key={`${row.personel_id}-${row.olusum_event_id ?? "u"}-${index}`}>
                  <td>{row.ad_soyad || row.personel_id}</td>
                  <td>{row.sicil_no}</td>
                  <td>{row.sube_ad}</td>
                  <td>{row.allocation_state}</td>
                  <td>{row.olusum_event_id ?? "—"}</td>
                  <td>{row.son_kullanim_tarihi ?? "—"}</td>
                  <td>{row.available_dakika ?? "—"}</td>
                  <td>{row.kalan_gun ?? "—"}</td>
                  <td>{serbestZamanDeadlineStateLabel(row.deadline_state)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="raporlar-pagination">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => void load(page - 1)}
            >
              Önceki
            </button>
            <span>
              Sayfa {page} / {totalPages} · {total} kayıt
            </span>
            <button
              type="button"
              disabled={page >= totalPages}
              onClick={() => void load(page + 1)}
            >
              Sonraki
            </button>
          </div>
        </div>
      ) : null}

      {isKullanimModalOpen && (
        <AppModal
          title="Serbest Zaman Kullanımı Ekle"
          onClose={closeKullanimModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={KULLANIM_FORM_ID}
                className="universal-btn-save"
                disabled={isSubmitting}
              >
                {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeKullanimModal}
                disabled={isSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form
            id={KULLANIM_FORM_ID}
            className="finans-form-grid"
            onSubmit={handleKullanimSubmit}
          >
            <FormField
              label="Personel ID"
              name="kullanim-personel-id"
              type="number"
              min={1}
              value={kullanimForm.personel_id}
              onChange={(value) =>
                setKullanimForm((prev) => ({ ...prev, personel_id: value }))
              }
              required
            />
            <FormField
              label="Kullanım Tarihi"
              name="kullanim-tarih"
              type="date"
              value={kullanimForm.event_tarihi}
              onChange={(value) =>
                setKullanimForm((prev) => ({ ...prev, event_tarihi: value }))
              }
              required
            />
            <FormField
              label="Kullanılan Dakika"
              name="kullanim-dakika"
              type="number"
              min={1}
              value={kullanimForm.dakika}
              onChange={(value) =>
                setKullanimForm((prev) => ({ ...prev, dakika: value }))
              }
              required
            />
            <FormField
              label="Açıklama"
              name="kullanim-aciklama"
              value={kullanimForm.aciklama || ""}
              onChange={(value) =>
                setKullanimForm((prev) => ({ ...prev, aciklama: value }))
              }
            />
            {submitError ? (
              <p className="finans-form-error">{submitError}</p>
            ) : null}
          </form>
        </AppModal>
      )}
    </section>
  );
}
