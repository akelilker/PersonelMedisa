import { useEffect, useState, type FormEvent } from "react";
import {
  cancelMevzuatParametresi,
  createMevzuatParametresi,
  fetchMevzuatParametreleri,
  getMevzuatApiErrorMessage
} from "../../../api/mevzuat.api";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type {
  CreateMevzuatParametresiPayload,
  MevzuatDegerTipi,
  MevzuatParametresi
} from "../../../types/mevzuat";

const MEVZUAT_FORM_ID = "yonetim-mevzuat-form";
const MEVZUAT_IPTAL_ONAY_MESAJI = "Bu mevzuat parametresini iptal etmek istediğinize emin misiniz?";

const DEGER_TIPI_OPTIONS = [
  { value: "SAYISAL", label: "Sayısal" },
  { value: "METIN", label: "Metin" }
];

const DURUM_LABELS: Record<MevzuatParametresi["durum"], string> = {
  AKTIF: "Aktif",
  IPTAL: "İptal"
};

type MevzuatFormState = {
  parametreKodu: string;
  degerTipi: MevzuatDegerTipi;
  sayisalDeger: string;
  metinDeger: string;
  gecerlilikBaslangic: string;
  gecerlilikBitis: string;
  birim: string;
  aciklama: string;
  kaynakReferansi: string;
};

const INITIAL_MEVZUAT_FORM: MevzuatFormState = {
  parametreKodu: "",
  degerTipi: "SAYISAL",
  sayisalDeger: "",
  metinDeger: "",
  gecerlilikBaslangic: "",
  gecerlilikBitis: "",
  birim: "",
  aciklama: "",
  kaynakReferansi: ""
};

function formatMevzuatDeger(item: MevzuatParametresi): string {
  if (item.deger_tipi === "SAYISAL") {
    const deger =
      item.sayisal_deger != null
        ? new Intl.NumberFormat("tr-TR", { maximumFractionDigits: 6 }).format(item.sayisal_deger)
        : "-";
    return item.birim ? `${deger} ${item.birim}` : deger;
  }
  return item.metin_deger ?? "-";
}

function formatMevzuatTarih(value: string | null): string {
  if (!value) {
    return "-";
  }
  const parsed = Date.parse(`${value}T00:00:00Z`);
  if (!Number.isFinite(parsed)) {
    return value;
  }
  return new Intl.DateTimeFormat("tr-TR", { dateStyle: "short", timeZone: "UTC" }).format(parsed);
}

export function MevzuatParametreleriPanel({ canManage }: { canManage: boolean }) {
  const [items, setItems] = useState<MevzuatParametresi[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [form, setForm] = useState<MevzuatFormState>(INITIAL_MEVZUAT_FORM);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [submitErrorMessage, setSubmitErrorMessage] = useState<string | null>(null);
  const [actionErrorMessage, setActionErrorMessage] = useState<string | null>(null);
  const [cancellingId, setCancellingId] = useState<number | null>(null);

  async function loadItems() {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      setItems(await fetchMevzuatParametreleri());
    } catch (error) {
      setErrorMessage(getMevzuatApiErrorMessage(error, "Mevzuat parametreleri yüklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadItems();
  }, []);

  function openCreateForm() {
    setForm(INITIAL_MEVZUAT_FORM);
    setSubmitErrorMessage(null);
    setActionErrorMessage(null);
    setIsFormOpen(true);
  }

  function closeCreateForm() {
    setIsFormOpen(false);
    setForm(INITIAL_MEVZUAT_FORM);
    setSubmitErrorMessage(null);
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    const kod = form.parametreKodu.trim().toUpperCase();
    if (!kod) {
      setSubmitErrorMessage("Parametre kodu zorunludur.");
      return;
    }

    if (!form.gecerlilikBaslangic) {
      setSubmitErrorMessage("Geçerlilik başlangıç tarihi zorunludur.");
      return;
    }

    const payload: CreateMevzuatParametresiPayload = {
      parametre_kodu: kod,
      deger_tipi: form.degerTipi,
      gecerlilik_baslangic: form.gecerlilikBaslangic,
      gecerlilik_bitis: form.gecerlilikBitis || null
    };

    if (form.degerTipi === "SAYISAL") {
      const sayisal = Number.parseFloat(form.sayisalDeger.replace(",", "."));
      if (!Number.isFinite(sayisal)) {
        setSubmitErrorMessage("Sayısal parametre için geçerli bir değer girilmelidir.");
        return;
      }
      payload.sayisal_deger = sayisal;
    } else {
      const metin = form.metinDeger.trim();
      if (!metin) {
        setSubmitErrorMessage("Metin parametresi için değer girilmelidir.");
        return;
      }
      payload.metin_deger = metin;
    }

    const birim = form.birim.trim();
    if (birim) {
      payload.birim = birim;
    }
    const aciklama = form.aciklama.trim();
    if (aciklama) {
      payload.aciklama = aciklama;
    }
    const kaynak = form.kaynakReferansi.trim();
    if (kaynak) {
      payload.kaynak_referansi = kaynak;
    }

    setIsSubmitting(true);
    setSubmitErrorMessage(null);

    try {
      await createMevzuatParametresi(payload);
      closeCreateForm();
      await loadItems();
    } catch (error) {
      setSubmitErrorMessage(getMevzuatApiErrorMessage(error, "Mevzuat parametresi kaydedilemedi."));
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleCancel(item: MevzuatParametresi) {
    if (cancellingId !== null || !window.confirm(MEVZUAT_IPTAL_ONAY_MESAJI)) {
      return;
    }

    setCancellingId(item.id);
    setActionErrorMessage(null);

    try {
      await cancelMevzuatParametresi(item.id);
      await loadItems();
    } catch (error) {
      setActionErrorMessage(getMevzuatApiErrorMessage(error, "Mevzuat parametresi iptal edilemedi."));
    } finally {
      setCancellingId(null);
    }
  }

  return (
    <section
      className="yonetim-list-surface"
      aria-label="Mevzuat parametreleri"
      data-testid="yonetim-section-mevzuat"
    >
      <div className="yonetim-list-header">
        <div className="yonetim-card-meta">
          <strong>Mevzuat Parametreleri</strong>
          <span>Asgari ücret, SGK tavanı gibi yasal parametrelerin tarihli kayıtları burada yönetilir.</span>
        </div>
      </div>

      {canManage ? (
        <div className="yonetim-create-row">
          <button
            type="button"
            className="yonetim-create-link"
            data-testid="yonetim-mevzuat-yeni"
            onClick={openCreateForm}
          >
            + Yeni Parametre
          </button>
        </div>
      ) : null}

      {isLoading ? <LoadingState label="Mevzuat parametreleri yükleniyor..." /> : null}
      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void loadItems()} />
      ) : null}
      {actionErrorMessage ? (
        <p className="personel-create-error" role="alert">
          {actionErrorMessage}
        </p>
      ) : null}

      {!isLoading && !errorMessage && items.length === 0 ? (
        <EmptyState
          title="Mevzuat parametresi yok"
          message="Henüz tanımlı yasal parametre kaydı bulunmuyor."
        />
      ) : null}

      {!isLoading && !errorMessage && items.length > 0 ? (
        <div className="yonetim-list-table-wrap">
          <table className="yonetim-list-table">
            <thead>
              <tr>
                <th>Parametre Kodu</th>
                <th>Değer</th>
                <th>Geçerlilik Başlangıç</th>
                <th>Geçerlilik Bitiş</th>
                <th>Durum</th>
                {canManage ? <th>İşlem</th> : null}
              </tr>
            </thead>
            <tbody>
              {items.map((item) => (
                <tr key={item.id} data-testid={`yonetim-mevzuat-satir-${item.id}`}>
                  <td className="yonetim-list-table-cell-strong">{item.parametre_kodu}</td>
                  <td>{formatMevzuatDeger(item)}</td>
                  <td>{formatMevzuatTarih(item.gecerlilik_baslangic)}</td>
                  <td>{formatMevzuatTarih(item.gecerlilik_bitis)}</td>
                  <td>{DURUM_LABELS[item.durum]}</td>
                  {canManage ? (
                    <td>
                      {item.durum === "AKTIF" ? (
                        <button
                          type="button"
                          className="universal-btn-cancel"
                          onClick={() => void handleCancel(item)}
                          disabled={cancellingId !== null}
                          data-testid={`yonetim-mevzuat-iptal-${item.id}`}
                        >
                          {cancellingId === item.id ? "İptal ediliyor..." : "İptal Et"}
                        </button>
                      ) : null}
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {canManage && isFormOpen ? (
        <AppModal
          title="Yeni Mevzuat Parametresi"
          onClose={closeCreateForm}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={MEVZUAT_FORM_ID}
                className="universal-btn-save"
                disabled={isSubmitting}
              >
                {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeCreateForm}
                disabled={isSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={MEVZUAT_FORM_ID} className="workspace-form" onSubmit={handleSubmit}>
            <div className="form-field-grid">
              <FormField
                label="Parametre Kodu"
                name="mevzuat-kod"
                value={form.parametreKodu}
                onChange={(value) => setForm((prev) => ({ ...prev, parametreKodu: value }))}
                placeholder="Örn. ASGARI_UCRET_BRUT"
                required
              />
              <FormField
                as="select"
                label="Değer Tipi"
                name="mevzuat-deger-tipi"
                value={form.degerTipi}
                onChange={(value) =>
                  setForm((prev) => ({
                    ...prev,
                    degerTipi: value === "METIN" ? "METIN" : "SAYISAL"
                  }))
                }
                selectOptions={DEGER_TIPI_OPTIONS}
                required
              />
              {form.degerTipi === "SAYISAL" ? (
                <FormField
                  label="Sayısal Değer"
                  name="mevzuat-sayisal-deger"
                  type="number"
                  step="0.000001"
                  value={form.sayisalDeger}
                  onChange={(value) => setForm((prev) => ({ ...prev, sayisalDeger: value }))}
                  required
                />
              ) : (
                <FormField
                  label="Metin Değeri"
                  name="mevzuat-metin-deger"
                  value={form.metinDeger}
                  onChange={(value) => setForm((prev) => ({ ...prev, metinDeger: value }))}
                  required
                />
              )}
              <FormField
                label="Birim"
                name="mevzuat-birim"
                value={form.birim}
                onChange={(value) => setForm((prev) => ({ ...prev, birim: value }))}
                placeholder="Örn. TL"
              />
              <FormField
                label="Geçerlilik Başlangıç"
                name="mevzuat-baslangic"
                type="date"
                value={form.gecerlilikBaslangic}
                onChange={(value) => setForm((prev) => ({ ...prev, gecerlilikBaslangic: value }))}
                required
              />
              <FormField
                label="Geçerlilik Bitiş"
                name="mevzuat-bitis"
                type="date"
                value={form.gecerlilikBitis}
                onChange={(value) => setForm((prev) => ({ ...prev, gecerlilikBitis: value }))}
              />
              <FormField
                label="Kaynak Referansı"
                name="mevzuat-kaynak"
                value={form.kaynakReferansi}
                onChange={(value) => setForm((prev) => ({ ...prev, kaynakReferansi: value }))}
                placeholder="Örn. Resmî Gazete sayısı"
              />
              <FormField
                as="textarea"
                label="Açıklama"
                name="mevzuat-aciklama"
                value={form.aciklama}
                onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
              />
            </div>

            {submitErrorMessage ? (
              <p className="personel-create-error" role="alert">
                {submitErrorMessage}
              </p>
            ) : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
