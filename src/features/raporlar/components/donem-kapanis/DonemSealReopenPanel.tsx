import { useCallback, useEffect, useState } from "react";
import { ApiRequestError } from "../../../../api/api-client";
import {
  approveDonemReopenRequest,
  createDonemReopenRequest,
  fetchDonemSealHistory,
  rejectDonemReopenRequest,
  resealDonemPuantaj,
  type DonemSealHistory
} from "../../../../api/puantaj.api";
import { AppActionDialog } from "../../../../components/modal/AppActionDialog";
import { useRoleAccess } from "../../../../hooks/use-role-access";
import { useAuth } from "../../../../state/auth.store";

type Props = {
  yil: number;
  ay: number;
  enabled: boolean;
  onChanged?: () => void;
};

type DialogKind = "request" | "approve" | "reject" | "reseal" | null;

export function DonemSealReopenPanel({ yil, ay, enabled, onChanged }: Props) {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const actorId = session?.user?.id ?? null;
  const canRequest = hasPermission("puantaj.donem_reopen.request");
  const canApprove = hasPermission("puantaj.donem_reopen.approve");
  const canReseal = hasPermission("puantaj.donem_reseal");
  const canHistory = hasPermission("puantaj.donem_seal.history");

  const [history, setHistory] = useState<DonemSealHistory | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<DialogKind>(null);
  const [fieldValue, setFieldValue] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!enabled || !canHistory) return;
    setError(null);
    try {
      setHistory(await fetchDonemSealHistory(yil, ay));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Seal history yuklenemedi.");
    }
  }, [enabled, canHistory, yil, ay]);

  useEffect(() => {
    void load();
  }, [load]);

  if (!canHistory) {
    return null;
  }

  const openTalep = history?.reopen_talepleri.find(
    (t) => t.talep_durumu === "ONAY_BEKLIYOR" || t.talep_durumu === "ONAYLANDI"
  );
  const activeSnapshot = history?.snapshots.find((s) => s.state === "OLUSTURULDU");
  const effectiveId = history?.effective_seal_id ?? 0;

  async function runAction() {
    if (!dialog) return;
    setSubmitting(true);
    setDialogError(null);
    try {
      if (dialog === "request") {
        await createDonemReopenRequest(yil, ay, fieldValue.trim());
      } else if (dialog === "approve" && openTalep) {
        await approveDonemReopenRequest(yil, ay, openTalep.id, fieldValue.trim() || undefined);
      } else if (dialog === "reject" && openTalep) {
        await rejectDonemReopenRequest(yil, ay, openTalep.id, fieldValue.trim());
      } else if (dialog === "reseal") {
        await resealDonemPuantaj(yil, ay, fieldValue.trim(), effectiveId);
      }
      setDialog(null);
      setFieldValue("");
      await load();
      onChanged?.();
    } catch (e) {
      const message =
        e instanceof ApiRequestError
          ? `${e.code ?? "HATA"}: ${e.message}`
          : e instanceof Error
            ? e.message
            : "Islem basarisiz.";
      setDialogError(message);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <section className="kapanis-audit-panel" data-testid="donem-seal-reopen-panel">
      <h3>Mühür Revizyon / Reopen</h3>
      <p className="raporlar-aylik-lead">
        Eski mühür ve snapshot silinmez. Reopen sırasında maaş hesaplama kapalıdır. Aktif snapshot iptal
        edilmeden canonical düzeltme yapılamaz. Reseal yeni revision üretir.
      </p>

      {error ? <p className="yonetim-error">{error}</p> : null}

      {history ? (
        <div className="raporlar-table-wrap yonetim-table-wrap">
          <table>
            <tbody>
              <tr>
                <th>Dönem state</th>
                <td data-testid="donem-seal-period-state">{history.period_state}</td>
              </tr>
              <tr>
                <th>Effective revision</th>
                <td data-testid="donem-seal-effective-rev">
                  {history.effective_revision_no ?? "—"} (id {history.effective_seal_id ?? "—"})
                </td>
              </tr>
              <tr>
                <th>Açık reopen</th>
                <td>
                  {openTalep
                    ? `#${openTalep.id} · ${openTalep.talep_durumu} · req=${openTalep.requested_by}`
                    : "Yok"}
                </td>
              </tr>
              <tr>
                <th>Aktif snapshot</th>
                <td data-testid="donem-seal-active-snapshot">
                  {activeSnapshot
                    ? `#${activeSnapshot.id} rev ${activeSnapshot.revision_no} — iptal gerekli`
                    : "Yok"}
                </td>
              </tr>
              <tr>
                <th>Seal history</th>
                <td>
                  {history.seals
                    .map((s) => `r${s.revision_no}:${s.durum}${s.effective ? "*" : ""}`)
                    .join(" · ") || "—"}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      ) : null}

      <div className="form-actions-row">
        {canRequest && history?.period_state === "SEALED" ? (
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="donem-reopen-request-btn"
            onClick={() => {
              setFieldValue("");
              setDialogError(null);
              setDialog("request");
            }}
          >
            Dönemi Düzeltmeye Açma Talebi
          </button>
        ) : null}
        {canApprove
        && openTalep?.talep_durumu === "ONAY_BEKLIYOR"
        && (actorId == null || openTalep.requested_by !== actorId) ? (
          <>
            <button
              type="button"
              className="universal-btn-save"
              data-testid="donem-reopen-approve-btn"
              onClick={() => {
                setFieldValue("");
                setDialogError(null);
                setDialog("approve");
              }}
            >
              Talebi Onayla
            </button>
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="donem-reopen-reject-btn"
              onClick={() => {
                setFieldValue("");
                setDialogError(null);
                setDialog("reject");
              }}
            >
              Talebi Reddet
            </button>
          </>
        ) : null}
        {canReseal && history?.period_state === "REOPENED" ? (
          <button
            type="button"
            className="universal-btn-save"
            data-testid="donem-reseal-btn"
            disabled={Boolean(activeSnapshot)}
            onClick={() => {
              setFieldValue("");
              setDialogError(null);
              setDialog("reseal");
            }}
          >
            Yeniden Mühürle
          </button>
        ) : null}
      </div>

      {dialog ? (
        <AppActionDialog
          open
          testId={`donem-seal-${dialog}-dialog`}
          title={
            dialog === "request"
              ? "Reopen Talebi"
              : dialog === "approve"
                ? "Reopen Onayı"
                : dialog === "reject"
                  ? "Reopen Reddi"
                  : "Yeniden Mühürle"
          }
          description={
            dialog === "request"
              ? "Eski mühür ve snapshot silinmeyecek. Onay sonrası aktif snapshot iptal edilmeden canonical değişiklik yapılamaz."
              : dialog === "approve"
                ? "Onaylayan talep sahibinden farklı olmalıdır. Reopen sırasında maaş snapshot/hesaplama kapalıdır."
                : dialog === "reject"
                  ? "Red sonrası dönem kilitli kalır; canonical write açılmaz."
                  : "Reseal yeni mühür revision üretir. Aktif snapshot olmamalı; canonical takvim tam olmalıdır."
          }
          confirmLabel={
            dialog === "request"
              ? "Talep Oluştur"
              : dialog === "approve"
                ? "Onayla"
                : dialog === "reject"
                  ? "Reddet"
                  : "Reseal"
          }
          submitLabel="İşleniyor…"
          destructive={dialog === "reject" || dialog === "reseal"}
          isSubmitting={submitting}
          errorMessage={dialogError}
          field={{
            label: dialog === "reject" ? "Red gerekçesi" : dialog === "approve" ? "Onay notu (ops)" : "Gerekçe",
            value: fieldValue,
            onChange: setFieldValue,
            required: dialog !== "approve",
            rows: 4
          }}
          onConfirm={() => void runAction()}
          onCancel={() => {
            if (!submitting) {
              setDialog(null);
              setDialogError(null);
            }
          }}
        />
      ) : null}
    </section>
  );
}
