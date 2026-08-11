import { useEffect, useState, type FormEvent } from "react";
import {
  approveRetentionImha,
  createLegalHold,
  fetchLegalHoldlar,
  fetchRetentionEligibility,
  fetchRetentionImhaTalepleri,
  releaseLegalHold,
  requestRetentionImha,
  type LegalHoldItem,
  type RetentionEligibility,
  type RetentionImhaTalep
} from "../../../api/retention.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";

/**
 * Narrow saklama / legal hold panel for Yonetim.
 * Company policy wording only (Medisa saklama politikası). No statutory claim. No auto-delete UX.
 */
export function SaklamaLegalHoldPanel() {
  const { hasPermission } = useRoleAccess();
  const canManageHold = hasPermission("legal_hold.manage");
  const canRequest = hasPermission("retention.destruction.request");
  const canApprove = hasPermission("retention.destruction.approve");
  const canViewRetention = hasPermission("retention.view");

  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [holds, setHolds] = useState<LegalHoldItem[]>([]);
  const [talepler, setTalepler] = useState<RetentionImhaTalep[]>([]);
  const [eligibility, setEligibility] = useState<RetentionEligibility | null>(null);

  const [holdDomain, setHoldDomain] = useState("personel");
  const [holdPersonelId, setHoldPersonelId] = useState("");
  const [holdReason, setHoldReason] = useState("");
  const [releaseReason, setReleaseReason] = useState("");

  const [eligCategory, setEligCategory] = useState("PERSONEL_OZLUK");
  const [eligPersonelId, setEligPersonelId] = useState("");
  const [imhaReason, setImhaReason] = useState("");
  const [approveReason, setApproveReason] = useState("");

  async function reload() {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const [holdItems, talepItems] = await Promise.all([
        fetchLegalHoldlar(true),
        fetchRetentionImhaTalepleri()
      ]);
      setHolds(holdItems);
      setTalepler(talepItems);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Saklama paneli yuklenemedi.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void reload();
  }, []);

  async function handleCreateHold(event: FormEvent) {
    event.preventDefault();
    if (!canManageHold) return;
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await createLegalHold({
        target_domain: holdDomain.trim() || "personel",
        personel_id: holdPersonelId ? Number(holdPersonelId) : undefined,
        reason: holdReason.trim()
      });
      setHoldReason("");
      setSuccessMessage("Legal hold olusturuldu.");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Legal hold olusturulamadi.");
    }
  }

  async function handleRelease(holdId: number) {
    if (!canManageHold || !releaseReason.trim()) return;
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await releaseLegalHold(holdId, releaseReason.trim());
      setReleaseReason("");
      setSuccessMessage("Legal hold serbest birakildi (kayit silinmedi).");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Legal hold serbest birakilamadi.");
    }
  }

  async function handleEligibility(event: FormEvent) {
    event.preventDefault();
    if (!canViewRetention) return;
    setErrorMessage(null);
    try {
      const result = await fetchRetentionEligibility({
        category: eligCategory,
        personel_id: eligPersonelId ? Number(eligPersonelId) : undefined,
        entity_type: "personel",
        record_id: eligPersonelId ? Number(eligPersonelId) : undefined
      });
      setEligibility(result);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Uygunluk degerlendirilemedi.");
    }
  }

  async function handleRequestImha(event: FormEvent) {
    event.preventDefault();
    if (!canRequest || !eligPersonelId) return;
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await requestRetentionImha({
        category: eligCategory,
        entity_type: "personel",
        record_id: Number(eligPersonelId),
        personel_id: Number(eligPersonelId),
        reason: imhaReason.trim()
      });
      setImhaReason("");
      setSuccessMessage("Imha talebi kaydedildi. Otomatik silme yok.");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Imha talebi olusturulamadi.");
    }
  }

  async function handleApprove(talepId: number, approve: boolean) {
    if (!canApprove || !approveReason.trim()) return;
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await approveRetentionImha(talepId, approveReason.trim(), approve);
      setApproveReason("");
      setSuccessMessage(approve ? "Imha talebi onaylandi (fiziksel silme yok)." : "Imha talebi reddedildi.");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Imha onayi islenemedi.");
    }
  }

  return (
    <section className="yonetim-list-surface" aria-label="Saklama / Legal Hold" data-testid="yonetim-section-saklama">
      <p className="yonetim-success" style={{ marginBottom: "0.75rem" }}>
        Medisa saklama politikası — minimum 10 takvim yılı. Otomatik silme yoktur.
      </p>

      {isLoading ? <LoadingState label="Saklama paneli yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void reload()} /> : null}
      {!isLoading && successMessage ? <p className="yonetim-success">{successMessage}</p> : null}

      {!isLoading ? (
        <div className="yonetim-form-stack" style={{ gap: "1.5rem" }}>
          {canManageHold ? (
            <form className="form-field-grid" onSubmit={handleCreateHold}>
              <h3>Legal hold oluştur</h3>
              <FormField
                label="Hedef domain"
                name="saklama-hold-domain"
                value={holdDomain}
                onChange={setHoldDomain}
                required
              />
              <FormField
                label="Personel ID"
                name="saklama-hold-personel"
                value={holdPersonelId}
                onChange={setHoldPersonelId}
              />
              <FormField
                label="Gerekçe"
                name="saklama-hold-reason"
                value={holdReason}
                onChange={setHoldReason}
                required
              />
              <div className="form-actions-row">
                <button type="submit" className="universal-btn-aux">
                  Hold oluştur
                </button>
              </div>
            </form>
          ) : null}

          <div>
            <h3>Aktif legal holdlar</h3>
            {holds.length === 0 ? <p>Aktif hold yok.</p> : null}
            <ul>
              {holds.map((hold) => (
                <li key={hold.id}>
                  #{hold.id} — {hold.target_domain}
                  {hold.personel_id != null ? ` / personel ${hold.personel_id}` : ""} — {hold.reason}
                  {canManageHold ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      style={{ marginLeft: "0.5rem" }}
                      onClick={() => void handleRelease(hold.id)}
                    >
                      Serbest bırak
                    </button>
                  ) : null}
                </li>
              ))}
            </ul>
            {canManageHold ? (
              <FormField
                label="Serbest bırakma gerekçesi"
                name="saklama-release-reason"
                value={releaseReason}
                onChange={setReleaseReason}
              />
            ) : null}
          </div>

          {canViewRetention ? (
            <form className="form-field-grid" onSubmit={handleEligibility}>
              <h3>Saklama uygunluğu</h3>
              <FormField
                label="Kategori"
                name="saklama-elig-category"
                value={eligCategory}
                onChange={setEligCategory}
                required
              />
              <FormField
                label="Personel ID"
                name="saklama-elig-personel"
                value={eligPersonelId}
                onChange={setEligPersonelId}
              />
              <div className="form-actions-row">
                <button type="submit" className="universal-btn-aux">
                  Değerlendir
                </button>
              </div>
              {eligibility ? (
                <p data-testid="saklama-eligibility-result">
                  Kod: {eligibility.code}
                  {eligibility.retention_until ? ` — en erken değerlendirme: ${eligibility.retention_until}` : ""}
                  {eligibility.message ? ` — ${eligibility.message}` : ""}
                </p>
              ) : null}
            </form>
          ) : null}

          {canRequest ? (
            <form className="form-field-grid" onSubmit={handleRequestImha}>
              <h3>Imha talebi</h3>
              <FormField
                label="Gerekçe"
                name="saklama-imha-reason"
                value={imhaReason}
                onChange={setImhaReason}
                required
              />
              <div className="form-actions-row">
                <button type="submit" className="universal-btn-aux">
                  Talep oluştur
                </button>
              </div>
            </form>
          ) : null}

          <div>
            <h3>Imha talepleri</h3>
            {canApprove ? (
              <FormField
                label="Onay / red gerekçesi"
                name="saklama-approve-reason"
                value={approveReason}
                onChange={setApproveReason}
              />
            ) : null}
            {talepler.length === 0 ? <p>Talep yok.</p> : null}
            <ul>
              {talepler.map((talep) => (
                <li key={talep.id}>
                  #{talep.id} — {talep.category} / {talep.status}
                  {talep.retention_until_snapshot
                    ? ` — saklama sonu: ${talep.retention_until_snapshot}`
                    : ""}
                  {canApprove && talep.status === "REQUESTED" ? (
                    <>
                      <button
                        type="button"
                        className="universal-btn-aux"
                        style={{ marginLeft: "0.5rem" }}
                        onClick={() => void handleApprove(talep.id, true)}
                      >
                        Onayla
                      </button>
                      <button
                        type="button"
                        className="universal-btn-aux"
                        style={{ marginLeft: "0.25rem" }}
                        onClick={() => void handleApprove(talep.id, false)}
                      >
                        Reddet
                      </button>
                    </>
                  ) : null}
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
    </section>
  );
}
