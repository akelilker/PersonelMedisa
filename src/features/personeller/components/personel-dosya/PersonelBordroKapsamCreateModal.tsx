import { useState, type FormEvent } from "react";
import { FormField } from "../../../../components/form/FormField";
import { AppModal } from "../../../../components/modal/AppModal";
import type {
  BordroKapsamDurum,
  BordroKapsamNedenKodu,
  CreatePersonelBordroKapsamPayload,
  PersonelBordroKapsamDryRunResult
} from "../../../../types/personel-bordro-kapsam";

const FORM_ID = "personel-bordro-kapsam-form";

type FormState = {
  durum: BordroKapsamDurum;
  nedenKodu: BordroKapsamNedenKodu;
  aciklama: string;
  gecerlilikBaslangic: string;
  gecerlilikBitis: string;
  yil: string;
  ay: string;
};

const INITIAL: FormState = {
  durum: "HARIC",
  nedenKodu: "BORDRO_DISI_STATU",
  aciklama: "",
  gecerlilikBaslangic: "",
  gecerlilikBitis: "",
  yil: "2026",
  ay: "3"
};

const DURUM_OPTIONS = [
  { value: "HARIC", label: "HARİÇ" },
  { value: "DAHIL", label: "DAHİL" }
];

const NEDEN_OPTIONS = [
  { value: "BORDRO_DISI_STATU", label: "Bordro dışı statü" },
  { value: "HARICI_BORDRO", label: "Harici bordro" },
  { value: "DIGER_ONAYLI_NEDEN", label: "Diğer onaylı neden" },
  { value: "DEMO_TEST_VERISI", label: "Demo / test verisi (yalnız GY)" }
];

export type PersonelBordroKapsamCreateModalProps = {
  isOpen: boolean;
  onClose: () => void;
  canApprove: boolean;
  isSubmitting: boolean;
  submitErrorMessage: string | null;
  dryRunResult: PersonelBordroKapsamDryRunResult | null;
  onDryRun: (
    payload: Omit<CreatePersonelBordroKapsamPayload, "dry_run_hash">
  ) => Promise<PersonelBordroKapsamDryRunResult | null>;
  onCreate: (payload: CreatePersonelBordroKapsamPayload) => Promise<boolean>;
};

export function PersonelBordroKapsamCreateModal({
  isOpen,
  onClose,
  canApprove,
  isSubmitting,
  submitErrorMessage,
  dryRunResult,
  onDryRun,
  onCreate
}: PersonelBordroKapsamCreateModalProps) {
  const [form, setForm] = useState<FormState>(INITIAL);
  const [validationMessage, setValidationMessage] = useState<string | null>(null);
  const [direktOnayla, setDirektOnayla] = useState(false);

  if (!isOpen) {
    return null;
  }

  function handleClose() {
    setForm(INITIAL);
    setValidationMessage(null);
    setDirektOnayla(false);
    onClose();
  }

  function buildPayloadBase(): Omit<CreatePersonelBordroKapsamPayload, "dry_run_hash"> | null {
    if (!form.gecerlilikBaslangic) {
      setValidationMessage("Geçerlilik başlangıç tarihi zorunludur.");
      return null;
    }
    if (form.gecerlilikBitis && form.gecerlilikBitis < form.gecerlilikBaslangic) {
      setValidationMessage("Bitiş tarihi başlangıçtan önce olamaz.");
      return null;
    }
    if (form.aciklama.trim().length < 3) {
      setValidationMessage("Açıklama en az 3 karakter olmalıdır.");
      return null;
    }
    const yil = Number.parseInt(form.yil, 10);
    const ay = Number.parseInt(form.ay, 10);
    return {
      durum: form.durum,
      neden_kodu: form.nedenKodu,
      aciklama: form.aciklama.trim(),
      gecerlilik_baslangic: form.gecerlilikBaslangic,
      gecerlilik_bitis: form.gecerlilikBitis || null,
      yil: Number.isFinite(yil) ? yil : undefined,
      ay: Number.isFinite(ay) ? ay : undefined,
      direkt_onayla: canApprove ? direktOnayla : undefined
    };
  }

  async function handleDryRun() {
    setValidationMessage(null);
    const base = buildPayloadBase();
    if (!base) {
      return;
    }
    await onDryRun(base);
  }

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }
    setValidationMessage(null);
    const base = buildPayloadBase();
    if (!base) {
      return;
    }
    let hash = dryRunResult?.dry_run_hash;
    if (!hash) {
      const preview = await onDryRun(base);
      hash = preview?.dry_run_hash;
    }
    if (!hash) {
      setValidationMessage("Önce dry-run çalıştırılmalıdır.");
      return;
    }
    const ok = await onCreate({ ...base, dry_run_hash: hash });
    if (ok) {
      handleClose();
    }
  }

  return (
    <AppModal
      title="Bordro Kapsam Kararı"
      onClose={handleClose}
      footer={
        <div className="universal-btn-group modal-footer-actions">
          <button
            type="submit"
            form={FORM_ID}
            className="universal-btn-save"
            disabled={isSubmitting}
            data-testid="personel-bordro-kapsam-kaydet"
          >
            {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
          </button>
          <button
            type="button"
            className="universal-btn-cancel"
            onClick={handleClose}
            disabled={isSubmitting}
          >
            Vazgeç
          </button>
        </div>
      }
    >
      <form
        id={FORM_ID}
        className="workspace-form"
        onSubmit={handleSubmit}
        data-testid="personel-bordro-kapsam-form"
      >
        <div className="form-field-grid">
          <FormField
            label="Durum"
            name="pbk-durum"
            as="select"
            value={form.durum}
            onChange={(value) => setForm((prev) => ({ ...prev, durum: value as BordroKapsamDurum }))}
            selectOptions={DURUM_OPTIONS}
            required
          />
          <FormField
            label="Neden"
            name="pbk-neden"
            as="select"
            value={form.nedenKodu}
            onChange={(value) =>
              setForm((prev) => ({ ...prev, nedenKodu: value as BordroKapsamNedenKodu }))
            }
            selectOptions={NEDEN_OPTIONS}
            required
          />
          <FormField
            label="Açıklama (audit)"
            name="pbk-aciklama"
            as="textarea"
            value={form.aciklama}
            onChange={(value) => setForm((prev) => ({ ...prev, aciklama: value }))}
            rows={3}
            required
          />
          <FormField
            label="Geçerlilik başlangıç"
            name="pbk-baslangic"
            type="date"
            value={form.gecerlilikBaslangic}
            onChange={(value) => setForm((prev) => ({ ...prev, gecerlilikBaslangic: value }))}
            required
          />
          <FormField
            label="Geçerlilik bitiş (opsiyonel)"
            name="pbk-bitis"
            type="date"
            value={form.gecerlilikBitis}
            onChange={(value) => setForm((prev) => ({ ...prev, gecerlilikBitis: value }))}
          />
          <FormField
            label="Etki dönemi yıl"
            name="pbk-yil"
            type="number"
            value={form.yil}
            onChange={(value) => setForm((prev) => ({ ...prev, yil: value }))}
          />
          <FormField
            label="Etki dönemi ay"
            name="pbk-ay"
            type="number"
            min={1}
            value={form.ay}
            onChange={(value) => setForm((prev) => ({ ...prev, ay: value }))}
          />
        </div>

        {canApprove ? (
          <label className="personel-puantaj-summary-note">
            <input
              type="checkbox"
              checked={direktOnayla}
              onChange={(e) => setDirektOnayla(e.target.checked)}
              data-testid="personel-bordro-kapsam-direkt-onay"
            />{" "}
            Doğrudan onayla (GY)
          </label>
        ) : null}

        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => void handleDryRun()}
          disabled={isSubmitting}
          data-testid="personel-bordro-kapsam-dry-run"
        >
          Dry-run önizle
        </button>

        {dryRunResult ? (
          <div
            className="personel-puantaj-summary-note"
            data-testid="personel-bordro-kapsam-dry-run-result"
          >
            <p>
              Sicil {dryRunResult.personel.sicil_no} — {dryRunResult.personel.ad_soyad}
            </p>
            <p>
              Dönem: {dryRunResult.donem.baslangic} → {dryRunResult.donem.bitis}
            </p>
            <p>
              Yeni snapshot setinden çıkarılır:{" "}
              {dryRunResult.effects.would_exclude_from_new_snapshot ? "Evet" : "Hayır"}
            </p>
            <p>
              Mevcut snapshot değişmez:{" "}
              {dryRunResult.effects.existing_snapshot_unchanged ? "Evet" : "Hayır"}
            </p>
            {dryRunResult.effects.existing_snapshot ? (
              <p data-testid="personel-bordro-kapsam-revision-uyari">
                Aktif snapshot #{dryRunResult.effects.existing_snapshot.id} var — explicit
                cancel/revision gerekir (source hash değişir).
              </p>
            ) : null}
            {dryRunResult.warnings.map((w) => (
              <p key={w}>{w}</p>
            ))}
          </div>
        ) : null}

        {validationMessage ? (
          <p className="form-error" data-testid="personel-bordro-kapsam-validation">
            {validationMessage}
          </p>
        ) : null}
        {submitErrorMessage ? (
          <p className="form-error" data-testid="personel-bordro-kapsam-submit-error">
            {submitErrorMessage}
          </p>
        ) : null}
      </form>
    </AppModal>
  );
}
