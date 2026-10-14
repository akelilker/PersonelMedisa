import { useCallback, useEffect, useState, type FormEvent } from "react";
import { getApiErrorMessage } from "../../../api/api-client";
import { createYonetimDuyuru, fetchYonetimDuyurular, type YonetimDuyuru } from "../../../api/self-product.api";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { formatIsoDateDetail } from "../../../lib/display/iso-date-format";
import { userErrorText } from "../../../lib/display/user-error";
import { highlightFieldErrors } from "../../../lib/forms/field-error-highlight";
import { SurecInlineBackButton } from "../../kayit/components/SurecInlineBackButton";
import { istanbulBusinessDate } from "../../self-service/birim-amiri-operational";

const BOS_FORM = { baslik: "", aciklama: "", yayin: "", bitis: "" };
const BASLIK_ZORUNLU = "Başlık Boş Bırakılamaz.";
const ACIKLAMA_ZORUNLU = "Açıklama Boş Bırakılamaz.";
const BITIS_ONCE = "Bitiş Tarihi Yayın Tarihinden Önce Olamaz.";

export function DuyuruYonetimModal({ onClose, onBack }: { onClose: () => void; onBack: () => void }) {
  const [items, setItems] = useState<YonetimDuyuru[]>([]);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [form, setForm] = useState(() => ({ ...BOS_FORM, yayin: istanbulBusinessDate() }));
  const [message, setMessage] = useState<string | null>(null);
  /** Başarılı gönderimden sonra form yeniden kurulur; eski kırmızı işaretler kalmaz. */
  const [formKey, setFormKey] = useState(0);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      setItems(await fetchYonetimDuyurular());
    } catch (err) {
      setError(getApiErrorMessage(err, "Duyurular Yüklenemedi."));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  async function handleSubmit(event: FormEvent) {
    event.preventDefault();
    setMessage(null);
    const eksik = !form.baslik.trim() ? ["duyuru-baslik"] : !form.aciklama.trim() ? ["duyuru-aciklama"] : [];
    if (eksik.length > 0) {
      setError(userErrorText(eksik[0] === "duyuru-baslik" ? BASLIK_ZORUNLU : ACIKLAMA_ZORUNLU));
      void highlightFieldErrors(eksik);
      return;
    }
    if (form.bitis && form.yayin && form.bitis < form.yayin) {
      setError(userErrorText(BITIS_ONCE));
      void highlightFieldErrors(["duyuru-bitis"]);
      return;
    }
    setBusy(true);
    setError(null);
    try {
      await createYonetimDuyuru({
        baslik: form.baslik.trim(),
        aciklama: form.aciklama.trim(),
        yayin_tarihi: form.yayin,
        bitis_tarihi: form.bitis || null
      });
      setForm({ ...BOS_FORM, yayin: istanbulBusinessDate() });
      setFormKey((k) => k + 1);
      setMessage("Duyuru Yayınlandı.");
      await load();
    } catch (err) {
      setError(getApiErrorMessage(err, "Duyuru Oluşturulamadı."));
    } finally {
      setBusy(false);
    }
  }

  return (
    <AppModal title="DUYURULAR" titleTestId="duyuru-yonetim-title" onClose={onClose} className="duyuru-yonetim-modal">
      <SurecInlineBackButton label="Ayarlar" onClick={onBack} testId="duyuru-yonetim-back" />
      <div className="duyuru-yonetim-body" data-testid="duyuru-yonetim-modal">
        <form key={formKey} className="duyuru-yonetim-form" onSubmit={(event) => void handleSubmit(event)} noValidate>
          <FormField
            label="Başlık"
            name="duyuru-baslik"
            value={form.baslik}
            onChange={(value) => setForm((prev) => ({ ...prev, baslik: value }))}
            maxLength={200}
            required
          />
          <FormField
            as="textarea"
            label="Açıklama"
            name="duyuru-aciklama"
            rows={4}
            value={form.aciklama}
            onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
            required
          />
          <div className="duyuru-yonetim-dates">
            <FormField
              label="Yayın Tarihi"
              name="duyuru-yayin"
              type="date"
              value={form.yayin}
              onChange={(value) => setForm((prev) => ({ ...prev, yayin: value }))}
              required
            />
            <FormField
              label="Bitiş Tarihi"
              name="duyuru-bitis"
              type="date"
              value={form.bitis}
              onChange={(value) => setForm((prev) => ({ ...prev, bitis: value }))}
            />
          </div>
          {error ? (
            <p className="form-error workspace-error" role="alert" data-testid="duyuru-yonetim-error">
              {error}
            </p>
          ) : null}
          {message ? (
            <p className="workspace-success" role="status" data-testid="duyuru-yonetim-ok">
              {message}
            </p>
          ) : null}
          <div className="universal-btn-group">
            <button type="submit" className="universal-btn-save" disabled={busy} data-testid="duyuru-yonetim-kaydet">
              {busy ? "Yayınlanıyor..." : "Yayınla"}
            </button>
          </div>
        </form>
        <ul className="duyuru-yonetim-list" data-testid="duyuru-yonetim-list">
          {loading ? <li className="self-service-muted">Yükleniyor...</li> : null}
          {!loading && items.length === 0 ? (
            <li className="self-service-muted" data-testid="duyuru-yonetim-empty">
              Henüz Duyuru Yok.
            </li>
          ) : null}
          {items.map((item) => (
            <li key={item.id} className="duyuru-yonetim-item" data-testid={`duyuru-yonetim-${item.id}`}>
              <strong>{item.baslik}</strong>
              <span>
                {formatIsoDateDetail(item.yayin_tarihi)}
                {item.bitis_tarihi ? ` – ${formatIsoDateDetail(item.bitis_tarihi)}` : ""}
                {item.aktif ? "" : " · Pasif"}
                {` · ${item.okuma_sayisi} Okuma`}
              </span>
            </li>
          ))}
        </ul>
      </div>
    </AppModal>
  );
}
