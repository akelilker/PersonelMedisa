import { useEffect, useMemo, useRef, useState } from "react";
import { createHaftalikKapanis } from "../../../api/haftalik-kapanis.api";
import { getApiErrorMessage } from "../../../api/api-client";
import { fetchDepartmanOptions } from "../../../api/referans.api";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import { useRoleAccess } from "../../../hooks/use-role-access";
import {
  computeHaftaBitisFromMonday,
  isMondayIsoDate
} from "../../../lib/bildirim/haftalik-mutabakat";
import { useAuth } from "../../../state/auth.store";
import type { HaftalikKapanisPayload, HaftalikKapanisSonuc } from "../../../types/haftalik-kapanis";
import type { IdOption } from "../../../types/referans";

type CloseScopeMode = "sube" | "departman";

function displayOrDash(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === "") {
    return "—";
  }
  return String(value);
}

function buildClosePayload(
  haftaBaslangic: string,
  haftaBitis: string,
  scopeMode: CloseScopeMode,
  departmanId: string
): HaftalikKapanisPayload {
  const payload: HaftalikKapanisPayload = {
    hafta_baslangic: haftaBaslangic,
    hafta_bitis: haftaBitis
  };
  if (scopeMode === "departman") {
    payload.departman_id = Number.parseInt(departmanId, 10);
  }
  return payload;
}

export function HaftalikKapanisClosePanel() {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const canClose = hasPermission("puantaj.muhurle");

  const activeSubeId = session?.active_sube_id ?? null;
  const activeSubeLabel =
    activeSubeId != null
      ? (session?.sube_list ?? []).find((sube) => sube.id === activeSubeId)?.ad ?? `Şube ${activeSubeId}`
      : null;

  const [closeHaftaBaslangic, setCloseHaftaBaslangic] = useState("");
  const [scopeMode, setScopeMode] = useState<CloseScopeMode>("sube");
  const [departmanId, setDepartmanId] = useState("");
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [departmanLoadError, setDepartmanLoadError] = useState<string | null>(null);
  const [departmanLoading, setDepartmanLoading] = useState(false);

  const [confirmOpen, setConfirmOpen] = useState(false);
  const [isClosing, setIsClosing] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);
  const [panelError, setPanelError] = useState<string | null>(null);
  const [successResult, setSuccessResult] = useState<HaftalikKapanisSonuc | null>(null);
  const [successScopeLabel, setSuccessScopeLabel] = useState<string>("");
  const [closedSelectionKey, setClosedSelectionKey] = useState<string | null>(null);

  const submitLockRef = useRef(false);

  useEffect(() => {
    if (!canClose) {
      return;
    }
    let cancelled = false;
    setDepartmanLoading(true);
    setDepartmanLoadError(null);
    void (async () => {
      try {
        const options = await fetchDepartmanOptions();
        if (!cancelled) {
          setDepartmanOptions(options);
        }
      } catch (error) {
        if (!cancelled) {
          setDepartmanOptions([]);
          setDepartmanLoadError(
            getApiErrorMessage(error, "Departman listesi yüklenemedi.")
          );
          setScopeMode((current) => (current === "departman" ? "sube" : current));
          setDepartmanId("");
        }
      } finally {
        if (!cancelled) {
          setDepartmanLoading(false);
        }
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [canClose]);

  const weekEnd = useMemo(
    () => (isMondayIsoDate(closeHaftaBaslangic) ? computeHaftaBitisFromMonday(closeHaftaBaslangic) : null),
    [closeHaftaBaslangic]
  );

  const weekEmpty = closeHaftaBaslangic.trim() === "";
  const weekInvalidNonMonday =
    !weekEmpty && !isMondayIsoDate(closeHaftaBaslangic);
  const departmentUnavailable = Boolean(departmanLoadError) || departmanOptions.length === 0;
  const departmentMissing =
    scopeMode === "departman" &&
    (!departmanId || !Number.isFinite(Number.parseInt(departmanId, 10)));

  const selectionKey =
    weekEnd && closeHaftaBaslangic
      ? `${closeHaftaBaslangic}|${weekEnd}|${scopeMode}|${scopeMode === "departman" ? departmanId : "all"}`
      : null;

  const sameSelectionAlreadyClosed =
    Boolean(successResult) &&
    Boolean(closedSelectionKey) &&
    closedSelectionKey === selectionKey;

  const closeDisabled =
    isClosing ||
    activeSubeId == null ||
    weekEmpty ||
    weekInvalidNonMonday ||
    !weekEnd ||
    departmentMissing ||
    sameSelectionAlreadyClosed;

  const selectedDepartmanLabel =
    scopeMode === "departman"
      ? departmanOptions.find((item) => String(item.id) === departmanId)?.label ?? `Departman ${departmanId}`
      : "Şube Geneli";

  if (!canClose) {
    return null;
  }

  function resetSuccessOnInputChange() {
    if (successResult) {
      setSuccessResult(null);
      setSuccessScopeLabel("");
      setClosedSelectionKey(null);
    }
    setPanelError(null);
  }

  function openConfirm() {
    if (closeDisabled || submitLockRef.current) {
      return;
    }
    setDialogError(null);
    setPanelError(null);
    setConfirmOpen(true);
  }

  function closeConfirm() {
    if (isClosing) {
      return;
    }
    setConfirmOpen(false);
    setDialogError(null);
  }

  async function confirmClose() {
    if (closeDisabled || !weekEnd || submitLockRef.current) {
      return;
    }

    submitLockRef.current = true;
    setIsClosing(true);
    setDialogError(null);
    setPanelError(null);

    const payload = buildClosePayload(closeHaftaBaslangic, weekEnd, scopeMode, departmanId);
    const scopeLabel = selectedDepartmanLabel;

    try {
      const result = await createHaftalikKapanis(payload);
      setSuccessResult(result);
      setSuccessScopeLabel(scopeLabel);
      setClosedSelectionKey(
        `${payload.hafta_baslangic}|${payload.hafta_bitis}|${scopeMode}|${
          scopeMode === "departman" ? String(payload.departman_id) : "all"
        }`
      );
      setConfirmOpen(false);
    } catch (error) {
      const message = getApiErrorMessage(error, "Haftalık kapanış oluşturulamadı.");
      setDialogError(message);
      setPanelError(message);
      setSuccessResult(null);
      setSuccessScopeLabel("");
      setClosedSelectionKey(null);
    } finally {
      setIsClosing(false);
      submitLockRef.current = false;
    }
  }

  const confirmDescription = [
    `Hafta başlangıcı: ${closeHaftaBaslangic}.`,
    `Hafta bitişi: ${weekEnd ?? "—"}.`,
    `Kapsam: ${selectedDepartmanLabel}.`,
    activeSubeLabel ? `Aktif şube: ${activeSubeLabel}.` : null,
    "Bu işlem geri alınamaz; ham kapanış snapshot'ı korunur. Sonraki düzeltmeler Revizyon Merkezi üzerinden yürür."
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <div className="hk-close-panel" data-testid="hk-close-panel" style={{ marginTop: "1.5rem" }}>
      <h3>Haftayı Kapat</h3>
      <p className="form-hint">
        Haftalık kapanış, yalnızca ilgili şube ve hafta için tamamlanmış haftalık mutabakat sonrası
        yapılabilir. Kapanış için haftalık mutabakat tamamlanmış olmalıdır. Sunucu önkoşulları
        karşılanmazsa kapanış reddedilir. Ham kapanış snapshot&apos;ı korunur; sonraki düzeltmeler
        Revizyon Merkezi üzerinden yürür.
      </p>

      {activeSubeId == null ? (
        <p className="workspace-error" role="alert" data-testid="hk-close-active-sube-required">
          Haftalık kapanış için aktif şube seçilmelidir.
        </p>
      ) : (
        <p className="form-hint" data-testid="hk-close-active-sube">
          Aktif şube: {activeSubeLabel}
        </p>
      )}

      <label className="form-label" htmlFor="hk-close-hafta-baslangic">
        Hafta başlangıcı (Pazartesi)
      </label>
      <input
        id="hk-close-hafta-baslangic"
        data-testid="hk-close-hafta-baslangic"
        className="form-input"
        type="date"
        value={closeHaftaBaslangic}
        disabled={isClosing}
        onChange={(event) => {
          resetSuccessOnInputChange();
          setCloseHaftaBaslangic(event.target.value);
        }}
      />
      {weekInvalidNonMonday ? (
        <p className="workspace-error" role="alert" data-testid="hk-close-monday-error">
          Hafta başlangıcı Pazartesi olmalıdır.
        </p>
      ) : null}
      <p className="form-hint" data-testid="hk-close-hafta-bitis">
        Hafta bitişi: {weekEnd ?? "—"}
      </p>

      <fieldset style={{ border: "none", padding: 0, margin: "1rem 0 0" }}>
        <legend className="form-label">Kapanış kapsamı</legend>
        <label style={{ display: "block", marginBottom: "0.35rem" }}>
          <input
            type="radio"
            name="hk-close-scope"
            data-testid="hk-close-scope-sube"
            checked={scopeMode === "sube"}
            disabled={isClosing}
            onChange={() => {
              resetSuccessOnInputChange();
              setScopeMode("sube");
              setDepartmanId("");
            }}
          />{" "}
          Şube Geneli
        </label>
        <label style={{ display: "block", marginBottom: "0.35rem" }}>
          <input
            type="radio"
            name="hk-close-scope"
            data-testid="hk-close-scope-departman"
            checked={scopeMode === "departman"}
            disabled={isClosing || departmentUnavailable}
            onChange={() => {
              if (departmentUnavailable) {
                return;
              }
              resetSuccessOnInputChange();
              setScopeMode("departman");
            }}
          />{" "}
          Departman
        </label>
      </fieldset>

      {departmanLoading ? <p className="form-hint">Departmanlar yükleniyor…</p> : null}
      {departmanLoadError ? (
        <p className="workspace-error" role="alert" data-testid="hk-close-departman-load-error">
          {departmanLoadError} Şube geneli kapanış kullanılabilir; departman kapsamı şu an seçilemez.
        </p>
      ) : null}

      {scopeMode === "departman" && !departmentUnavailable ? (
        <>
          <label className="form-label" htmlFor="hk-close-departman">
            Departman
          </label>
          <select
            id="hk-close-departman"
            data-testid="hk-close-departman"
            className="form-input"
            value={departmanId}
            disabled={isClosing}
            onChange={(event) => {
              resetSuccessOnInputChange();
              setDepartmanId(event.target.value);
            }}
          >
            <option value="">Seçiniz</option>
            {departmanOptions.map((option) => (
              <option key={option.id} value={option.id}>
                {option.label}
              </option>
            ))}
          </select>
        </>
      ) : null}

      <div className="universal-btn-group" style={{ marginTop: "1rem" }}>
        <button
          type="button"
          className="universal-btn-save"
          data-testid="hk-close-open"
          disabled={closeDisabled}
          onClick={openConfirm}
        >
          {isClosing ? "Kapatılıyor…" : "Haftayı Kapat"}
        </button>
      </div>

      {panelError && !confirmOpen ? (
        <p className="workspace-error" role="alert" data-testid="hk-close-error">
          {panelError}
        </p>
      ) : null}

      {successResult ? (
        <div className="yonetim-success" data-testid="hk-close-success" style={{ marginTop: "1rem" }}>
          <p>
            <strong>Hafta kapatıldı</strong>
          </p>
          <ul data-testid="hk-close-success-summary">
            <li data-testid="hk-close-success-id">
              Kapanış ID: {displayOrDash(successResult.kapanis_id ?? successResult.id)}
            </li>
            <li data-testid="hk-close-success-state">
              Durum: {displayOrDash(successResult.state)}
            </li>
            <li data-testid="hk-close-success-week">
              Hafta: {displayOrDash(successResult.hafta_baslangic)} —{" "}
              {displayOrDash(successResult.hafta_bitis)}
            </li>
            <li data-testid="hk-close-success-scope">Kapsam: {successScopeLabel || "—"}</li>
            <li data-testid="hk-close-success-personel">
              Personel sayısı: {displayOrDash(successResult.personel_sayisi)}
            </li>
            <li data-testid="hk-close-success-snapshot">
              Snapshot satır sayısı: {displayOrDash(successResult.snapshot_satir_sayisi)}
            </li>
          </ul>
        </div>
      ) : null}

      {confirmOpen ? (
        <AppActionDialog
          open
          testId="hk-close-confirm-dialog"
          title="Haftalık Kapanışı Onayla"
          description={confirmDescription}
          confirmLabel="Kapanışı Onayla"
          submitLabel="Kapatılıyor…"
          destructive
          isSubmitting={isClosing}
          errorMessage={dialogError}
          errorTestId="hk-close-confirm-error"
          onConfirm={confirmClose}
          onCancel={closeConfirm}
        />
      ) : null}
    </div>
  );
}
