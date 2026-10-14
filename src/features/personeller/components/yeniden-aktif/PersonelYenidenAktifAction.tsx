import { useRef, useState } from "react";
import { applyPersonelYenidenAktif } from "../../../../api/personeller.api";
import { AppActionDialog } from "../../../../components/modal/AppActionDialog";
import { commitPersonelUpdateToCaches } from "../../../../data/data-manager";
import { useRoleAccess } from "../../../../hooks/use-role-access";
import { ApiRequestError } from "../../../../api/api-client";
import { highlightFieldErrors } from "../../../../lib/forms/field-error-highlight";
import { userErrorText } from "../../../../lib/display/user-error";
import type { Personel } from "../../../../types/personel";

const GEREKCE_MIN = 10;
const GEREKCE_MAX = 500;
export const YENIDEN_AKTIF_BASARI = "Personel Yeniden Aktif Edildi.";
const GEREKCE_KISA = "Gerekçe En Az 10 Karakter Olmalıdır.";
const GEREKCE_UZUN = "Gerekçe En Fazla 500 Karakter Olabilir.";

const HATA_METINLERI: Record<string, string> = {
  REACTIVATE_EXIT_SUREC_MISSING: "Açık İşten Ayrılma Kaydı Bulunamadı.",
  REACTIVATE_EXIT_SUREC_NOT_UNIQUE: "Birden Fazla Açık İşten Ayrılma Kaydı Var.",
  REACTIVATE_LEGAL_HOLD_SCHEMA_NOT_READY: "Personel Hukuki Saklama Altında.",
  REACTIVATE_TEST_FIXTURE_HIDDEN: "Bu Kayıt Yeniden Aktif Edilemez.",
  REACTIVATE_SGK_SIRKET_UYUSMAZLIGI: "SGK İşveren Bilgisi Uyuşmuyor.",
  REACTIVATE_DOGUM_TARIH_CONFLICT: "Doğum Tarihi Uyuşmuyor.",
  REACTIVATE_PERSONEL_NOT_FOUND: "Personel Bulunamadı."
};

function hataMetni(error: unknown): string {
  if (error instanceof ApiRequestError) {
    if (error.code && HATA_METINLERI[error.code]) return HATA_METINLERI[error.code];
    return error.message;
  }
  return "Personel Yeniden Aktif Edilemedi.";
}

function newKey(): string {
  if (typeof crypto !== "undefined" && typeof crypto.randomUUID === "function") {
    return crypto.randomUUID();
  }
  return `reaktif-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

export function PersonelYenidenAktifAction({
  personel,
  onReactivated,
  testId = "personel-yeniden-aktif",
  successInline = true
}: {
  personel: Personel;
  onReactivated: (updated: Personel) => void;
  testId?: string;
  /** false: başarı metnini üst ekran gösterir (yeniden yüklemede bileşen yeniden kurulur). */
  successInline?: boolean;
}) {
  const { hasPermission } = useRoleAccess();
  const [open, setOpen] = useState(false);
  const [gerekce, setGerekce] = useState("");
  const [fieldError, setFieldError] = useState<string | null>(null);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const keyRef = useRef<string | null>(null);

  const success = successMessage && successInline ? (
    <p className="workspace-success" data-testid={`${testId}-success`}>
      {successMessage}
    </p>
  ) : null;

  if (successMessage || personel.aktif_durum !== "PASIF" || !hasPermission("personeller.reaktif")) {
    return success;
  }

  function close() {
    setOpen(false);
    setGerekce("");
    setFieldError(null);
    setErrorMessage(null);
    keyRef.current = null;
  }

  async function handleConfirm() {
    const value = gerekce.trim();
    if (value.length < GEREKCE_MIN) {
      setFieldError(userErrorText(GEREKCE_KISA));
      void highlightFieldErrors(["gerekce"]);
      return;
    }
    if (value.length > GEREKCE_MAX) {
      setFieldError(userErrorText(GEREKCE_UZUN));
      void highlightFieldErrors(["gerekce"]);
      return;
    }
    setFieldError(null);
    setErrorMessage(null);
    keyRef.current ??= newKey();
    try {
      const updated = await applyPersonelYenidenAktif(
        personel.id,
        { gerekce: value },
        { idempotencyKey: keyRef.current }
      );
      commitPersonelUpdateToCaches(updated);
      close();
      setSuccessMessage(YENIDEN_AKTIF_BASARI);
      onReactivated(updated);
    } catch (error) {
      setErrorMessage(hataMetni(error));
    }
  }

  return (
    <>
      <div className="universal-btn-group" data-testid={`${testId}-row`}>
        <button
          type="button"
          className="universal-btn-save"
          data-testid={`${testId}-button`}
          onClick={() => setOpen(true)}
        >
          Yeniden Aktif Et
        </button>
      </div>
      <AppActionDialog
        open={open}
        testId={`${testId}-dialog`}
        title="Yeniden Aktif Et"
        description={`${personel.ad} ${personel.soyad} yeniden aktif edilsin mi?`}
        confirmLabel="Yeniden Aktif Et"
        submitLabel="Kaydediliyor..."
        field={{
          label: "Gerekçe",
          value: gerekce,
          onChange: (next: string) => {
            setGerekce(next);
            if (fieldError) setFieldError(null);
          },
          required: true,
          rows: 3,
          errorMessage: fieldError ?? undefined,
          testId: `${testId}-gerekce`
        }}
        errorMessage={errorMessage}
        onConfirm={handleConfirm}
        onCancel={close}
      />
    </>
  );
}
