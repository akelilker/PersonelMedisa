import { useEffect, useMemo, useState, type FormEvent } from "react";
import { fetchPersonellerList } from "../../../api/personeller.api";
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
import {
  formatRetentionCategoryLabel,
  formatRetentionEligibilitySummary,
  formatRetentionImhaStatusLabel,
  RETENTION_CATEGORY_SELECT_OPTIONS
} from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";

const TARGET_DOMAIN_PERSONEL = "personel";
const DEFAULT_CATEGORY = "PERSONEL_OZLUK";

function formatPersonelOptionLabel(personel: Personel): string {
  const name = [personel.ad, personel.soyad].filter(Boolean).join(" ").trim() || `Personel #${personel.id}`;
  const sicil = personel.sicil_no?.trim();
  const place = [personel.sube_adi, personel.departman_adi].filter(Boolean).join(" / ");
  return [name, sicil || null, place || null].filter(Boolean).join(" • ");
}

function formatDisplayDate(value: string | null | undefined): string | null {
  if (!value) {
    return null;
  }
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }
  return parsed.toLocaleDateString("tr-TR");
}

/**
 * Saklama / koruma paneli — yönetim kullanıcıları için sade Türkçe arayüz.
 * Backend saklama/koruma/imha semantiği korunur; yalnızca görünür metin ve form düzeni değişir.
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
  const [personeller, setPersoneller] = useState<Personel[]>([]);

  const [selectedPersonelId, setSelectedPersonelId] = useState("");
  const [holdReason, setHoldReason] = useState("");
  const [releaseReason, setReleaseReason] = useState("");
  const [eligCategory, setEligCategory] = useState(DEFAULT_CATEGORY);
  const [imhaReason, setImhaReason] = useState("");
  const [approveReason, setApproveReason] = useState("");

  const personelOptions = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: formatPersonelOptionLabel(personel)
      })),
    [personeller]
  );

  const personelLabelById = useMemo(() => {
    const map = new Map<number, string>();
    for (const personel of personeller) {
      map.set(personel.id, formatPersonelOptionLabel(personel));
    }
    return map;
  }, [personeller]);

  async function reload() {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const [holdItems, talepItems, personelPage] = await Promise.all([
        fetchLegalHoldlar(true),
        fetchRetentionImhaTalepleri(),
        fetchPersonellerList({ page: 1, limit: 250, aktiflik: "tum" })
      ]);
      setHolds(holdItems);
      setTalepler(talepItems);
      setPersoneller(personelPage.items);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Saklama paneli yüklenemedi.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void reload();
  }, []);

  async function handleCreateHold(event: FormEvent) {
    event.preventDefault();
    if (!canManageHold || !selectedPersonelId) {
      return;
    }
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await createLegalHold({
        target_domain: TARGET_DOMAIN_PERSONEL,
        personel_id: Number(selectedPersonelId),
        reason: holdReason.trim()
      });
      setHoldReason("");
      setSuccessMessage("Kayıt koruma altına alındı.");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Kayıt koruma altına alınamadı.");
    }
  }

  async function handleRelease(holdId: number) {
    if (!canManageHold || !releaseReason.trim()) {
      return;
    }
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await releaseLegalHold(holdId, releaseReason.trim());
      setReleaseReason("");
      setSuccessMessage("Kayıt üzerindeki koruma kaldırıldı. Kayıt silinmedi.");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Koruma kaldırılamadı.");
    }
  }

  async function handleEligibility(event: FormEvent) {
    event.preventDefault();
    if (!canViewRetention || !selectedPersonelId) {
      return;
    }
    setErrorMessage(null);
    try {
      const result = await fetchRetentionEligibility({
        category: eligCategory,
        personel_id: Number(selectedPersonelId),
        entity_type: "personel",
        record_id: Number(selectedPersonelId)
      });
      setEligibility(result);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Saklama süresi değerlendirilemedi.");
    }
  }

  async function handleRequestImha(event: FormEvent) {
    event.preventDefault();
    if (!canRequest || !selectedPersonelId) {
      return;
    }
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await requestRetentionImha({
        category: eligCategory,
        entity_type: "personel",
        record_id: Number(selectedPersonelId),
        personel_id: Number(selectedPersonelId),
        reason: imhaReason.trim()
      });
      setImhaReason("");
      setSuccessMessage("İmha talebi kaydedildi. Otomatik silme yapılmaz.");
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "İmha talebi oluşturulamadı.");
    }
  }

  async function handleApprove(talepId: number, approve: boolean) {
    if (!canApprove || !approveReason.trim()) {
      return;
    }
    setSuccessMessage(null);
    setErrorMessage(null);
    try {
      await approveRetentionImha(talepId, approveReason.trim(), approve);
      setApproveReason("");
      setSuccessMessage(
        approve ? "İmha talebi onaylandı (fiziksel silme yok)." : "İmha talebi reddedildi."
      );
      await reload();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "İmha onayı işlenemedi.");
    }
  }

  const personelSelect = (
    <FormField
      as="select"
      label="Personel"
      name="saklama-shared-personel"
      value={selectedPersonelId}
      onChange={setSelectedPersonelId}
      required
      placeholderOption={{ value: "", label: "Personel seçiniz" }}
      selectOptions={personelOptions}
    />
  );

  return (
    <section
      className="yonetim-saklama-panel"
      aria-label="Saklama ve İmha Yönetimi"
      data-testid="yonetim-section-saklama"
    >
      <div className="yonetim-saklama-policy-banner" role="status">
        <strong>Saklama Politikası</strong>
        <p>
          Personel kayıtları Medisa politikasına göre en az 10 takvim yılı saklanır. Sistem kayıtları
          kendiliğinden silmez. Otomatik silme yoktur.
        </p>
      </div>

      {isLoading ? <LoadingState label="Saklama paneli yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void reload()} /> : null}
      {!isLoading && successMessage ? <p className="yonetim-success">{successMessage}</p> : null}

      {!isLoading ? (
        <div className="yonetim-saklama-sections">
          {canManageHold ? (
            <section className="yonetim-saklama-section" aria-labelledby="saklama-koruma-title">
              <div className="yonetim-saklama-section-copy">
                <h3 id="saklama-koruma-title">Kayıt Koruma</h3>
                <p>
                  Dava, denetim veya inceleme gibi nedenlerle silinmemesi gereken personel kayıtlarını
                  koruma altına alabilirsiniz.
                </p>
              </div>
              <form className="yonetim-saklama-form" onSubmit={handleCreateHold}>
                {personelSelect}
                <FormField
                  label="Gerekçe"
                  name="saklama-hold-reason"
                  value={holdReason}
                  onChange={setHoldReason}
                  required
                />
                <div className="form-actions-row">
                  <button type="submit" className="universal-btn-aux" data-testid="saklama-korumaya-al">
                    Korumaya Al
                  </button>
                </div>
              </form>

              <div className="yonetim-saklama-subsection">
                <h4>Koruma Altındaki Kayıtlar</h4>
                {holds.length === 0 ? <p className="yonetim-hint">Koruma altında kayıt yok.</p> : null}
                <ul className="yonetim-saklama-list">
                  {holds.map((hold) => {
                    const personelLabel =
                      hold.personel_id != null
                        ? personelLabelById.get(hold.personel_id) ?? `Personel #${hold.personel_id}`
                        : "Personel seçilmemiş";
                    const createdAt = formatDisplayDate(hold.created_at);
                    return (
                      <li key={hold.id} className="yonetim-saklama-list-item">
                        <div className="yonetim-saklama-list-main">
                          <strong>{personelLabel}</strong>
                          <span>{hold.reason}</span>
                          {createdAt ? <span className="yonetim-hint">Başlangıç: {createdAt}</span> : null}
                        </div>
                        <button
                          type="button"
                          className="universal-btn-aux"
                          onClick={() => void handleRelease(hold.id)}
                        >
                          Korumayı Kaldır
                        </button>
                      </li>
                    );
                  })}
                </ul>
                {holds.length > 0 ? (
                  <FormField
                    label="Korumayı kaldırma gerekçesi"
                    name="saklama-release-reason"
                    value={releaseReason}
                    onChange={setReleaseReason}
                  />
                ) : null}
              </div>
            </section>
          ) : null}

          {canViewRetention ? (
            <section className="yonetim-saklama-section" aria-labelledby="saklama-sure-title">
              <div className="yonetim-saklama-section-copy">
                <h3 id="saklama-sure-title">İmha İçin Süre Kontrolü</h3>
                <p>Bir personel kaydının saklama süresinin dolup dolmadığını kontrol eder.</p>
              </div>
              <form className="yonetim-saklama-form" onSubmit={handleEligibility}>
                {canManageHold ? (
                  <p className="yonetim-hint" data-testid="saklama-shared-personel-hint">
                    Seçili personel yukarıdaki Personel alanından kullanılır.
                  </p>
                ) : (
                  personelSelect
                )}
                <FormField
                  as="select"
                  label="Kategori"
                  name="saklama-elig-category"
                  value={eligCategory}
                  onChange={setEligCategory}
                  required
                  selectOptions={RETENTION_CATEGORY_SELECT_OPTIONS}
                />
                <div className="form-actions-row">
                  <button type="submit" className="universal-btn-aux" data-testid="saklama-sure-kontrol">
                    Süreyi Kontrol Et
                  </button>
                </div>
                {eligibility ? (
                  <div
                    className={`yonetim-saklama-result${eligibility.eligible ? " yonetim-saklama-result--ok" : " yonetim-saklama-result--wait"}`}
                    data-testid="saklama-eligibility-result"
                    role="status"
                  >
                    <p>{formatRetentionEligibilitySummary(eligibility)}</p>
                  </div>
                ) : null}
              </form>
            </section>
          ) : null}

          {canRequest || talepler.length > 0 || canApprove ? (
            <section className="yonetim-saklama-section" aria-labelledby="saklama-imha-title">
              <div className="yonetim-saklama-section-copy">
                <h3 id="saklama-imha-title">İmha Talepleri</h3>
                <p>
                  Saklama süresi tamamlanan kayıtlar için kontrollü imha talebi oluşturulur. Sistem
                  otomatik silme yapmaz.
                </p>
              </div>

              {canRequest ? (
                <form className="yonetim-saklama-form" onSubmit={handleRequestImha}>
                  {!canManageHold && !canViewRetention ? personelSelect : null}
                  {(canManageHold || canViewRetention) && selectedPersonelId ? (
                    <p className="yonetim-hint">
                      Talep, seçili personel için oluşturulur:{" "}
                      {personelLabelById.get(Number(selectedPersonelId)) ?? selectedPersonelId}
                    </p>
                  ) : null}
                  <FormField
                    label="Gerekçe"
                    name="saklama-imha-reason"
                    value={imhaReason}
                    onChange={setImhaReason}
                    required
                  />
                  <div className="form-actions-row">
                    <button type="submit" className="universal-btn-aux" data-testid="saklama-imha-talep">
                      İmha Talebi Oluştur
                    </button>
                  </div>
                </form>
              ) : null}

              <div className="yonetim-saklama-subsection">
                <h4>Mevcut Talepler</h4>
                {canApprove ? (
                  <FormField
                    label="Onay / red gerekçesi"
                    name="saklama-approve-reason"
                    value={approveReason}
                    onChange={setApproveReason}
                  />
                ) : null}
                {talepler.length === 0 ? <p className="yonetim-hint">Talep yok.</p> : null}
                <ul className="yonetim-saklama-list">
                  {talepler.map((talep) => {
                    const personelLabel =
                      talep.personel_id != null
                        ? personelLabelById.get(talep.personel_id) ?? `Personel #${talep.personel_id}`
                        : null;
                    const until = formatDisplayDate(talep.retention_until_snapshot);
                    return (
                      <li key={talep.id} className="yonetim-saklama-list-item">
                        <div className="yonetim-saklama-list-main">
                          <strong>
                            {formatRetentionCategoryLabel(talep.category)} —{" "}
                            {formatRetentionImhaStatusLabel(talep.status)}
                          </strong>
                          {personelLabel ? <span>{personelLabel}</span> : null}
                          <span>{talep.reason}</span>
                          {until ? <span className="yonetim-hint">Saklama sonu: {until}</span> : null}
                        </div>
                        {canApprove && talep.status === "REQUESTED" ? (
                          <div className="yonetim-saklama-list-actions">
                            <button
                              type="button"
                              className="universal-btn-aux"
                              onClick={() => void handleApprove(talep.id, true)}
                            >
                              Onayla
                            </button>
                            <button
                              type="button"
                              className="universal-btn-aux"
                              onClick={() => void handleApprove(talep.id, false)}
                            >
                              Reddet
                            </button>
                          </div>
                        ) : null}
                      </li>
                    );
                  })}
                </ul>
              </div>
            </section>
          ) : null}
        </div>
      ) : null}
    </section>
  );
}
