import { useEffect, useMemo, useState, type FormEvent } from "react";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type { KayitTab } from "../../../components/main-menu/MainMenu";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import { createPersonel, fetchPersonellerList } from "../../../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchUcretTipiOptions
} from "../../../api/referans.api";
import { cancelSurec, createSurec, fetchSureclerList, updateSurec } from "../../../api/surecler.api";
import { PersonelCreateFields } from "../../../features/personeller/components/PersonelCreateFields";
import { buildCreatePersonelPayload } from "../../../features/personeller/personel-create-utils";
import { SurecFormFields } from "../../../features/surecler/components/SurecFormFields";
import {
  buildCreateSurecPayload,
  buildUpdateSurecPayload,
  toSurecFormState
} from "../../../features/surecler/surec-form-utils";
import { INITIAL_CREATE_PERSONEL_FORM, type CreatePersonelFormState } from "../../../hooks/usePersoneller";
import { INITIAL_SUREC_FORM, type SurecFormState } from "../../../hooks/useSurecler";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { Personel } from "../../../types/personel";
import type { KeyOption } from "../../../types/referans";
import type { Surec } from "../../../types/surec";

export const KAYIT_SUREC_PERSONEL_FORM_ID = "kayit-surec-personel-form";
export const KAYIT_SUREC_SUREC_FORM_ID = "kayit-surec-surec-form";

type KayitSurecWorkspaceProps = {
  activeTab: KayitTab;
  onTabChange: (tab: KayitTab) => void;
  onClose: () => void;
  initialSurecPersonelId?: string | null;
  primaryActionLabel: string;
  primaryFormId: string;
};

const EMPTY_REFS: PersonelReferenceBundle = {
  departmanOptions: [],
  gorevOptions: [],
  personelTipiOptions: [],
  bagliAmirOptions: [],
  ucretTipiOptions: [],
  primKuraliOptions: []
};

const SUREC_TURU_LABELS: Record<string, string> = {
  IZIN: "İzin",
  RAPOR: "Rapor",
  ISTEN_AYRILMA: "İşten Ayrılma",
  YILLIK_IZIN: "Yıllık İzin",
  MAZERET_IZNI: "Mazeret İzni",
  UCRETSIZ_IZIN: "Ücretsiz İzin",
  GOREVLENDIRME: "Görevlendirme",
  EGITIM: "Eğitim"
};

const SUREC_STATE_LABELS: Record<string, string> = {
  AKTIF: "Aktif",
  BEKLEMEDE: "Beklemede",
  IPTAL: "İptal",
  IPTAL_EDILDI: "İptal Edildi",
  TAMAMLANDI: "Tamamlandı"
};

function normalizeEnumKey(value: string) {
  return value.trim().replace(/-/g, "_").toUpperCase();
}

function formatSurecTuruLabel(value: string | null | undefined) {
  if (!value) {
    return "-";
  }

  const normalized = normalizeEnumKey(value);
  return SUREC_TURU_LABELS[normalized] ?? normalized.split("_").join(" ");
}

function formatSurecStateLabel(value: string | null | undefined) {
  if (!value) {
    return "-";
  }

  const normalized = normalizeEnumKey(value);
  return SUREC_STATE_LABELS[normalized] ?? normalized.split("_").join(" ");
}

function formatPersonelLabel(personel: Personel) {
  const meta = [personel.departman_adi, personel.gorev_adi].filter(Boolean).join(" • ");
  return meta ? `${personel.ad} ${personel.soyad} • ${meta}` : `${personel.ad} ${personel.soyad}`;
}

function resetSurecFormKeepingPersonel(personelId: string) {
  return {
    ...INITIAL_SUREC_FORM,
    personelId
  };
}

export function KayitSurecWorkspace({
  activeTab,
  onTabChange,
  onClose,
  initialSurecPersonelId,
  primaryActionLabel,
  primaryFormId
}: KayitSurecWorkspaceProps) {
  const { hasPermission } = useRoleAccess();
  const canCreatePersonel = hasPermission("personeller.create");
  const canCreateSurec = hasPermission("surecler.create");
  const canEditSurec = hasPermission("surecler.update");
  const canCancelSurec = hasPermission("surecler.cancel");

  const [refs, setRefs] = useState<PersonelReferenceBundle>(EMPTY_REFS);
  const [surecTuruOptions, setSurecTuruOptions] = useState<KeyOption[]>([]);
  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [bootstrapLoading, setBootstrapLoading] = useState(true);
  const [bootstrapError, setBootstrapError] = useState<string | null>(null);

  const [personelForm, setPersonelForm] = useState<CreatePersonelFormState>(INITIAL_CREATE_PERSONEL_FORM);
  const [personelSubmitting, setPersonelSubmitting] = useState(false);
  const [personelError, setPersonelError] = useState<string | null>(null);
  const [personelInfo, setPersonelInfo] = useState<string | null>(null);

  const [surecForm, setSurecForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [surecSubmitting, setSurecSubmitting] = useState(false);
  const [surecError, setSurecError] = useState<string | null>(null);
  const [surecInfo, setSurecInfo] = useState<string | null>(null);
  const [editingSurec, setEditingSurec] = useState<Surec | null>(null);
  const [surecler, setSurecler] = useState<Surec[]>([]);
  const [sureclerLoading, setSureclerLoading] = useState(false);
  const [sureclerError, setSureclerError] = useState<string | null>(null);

  const personelOptions = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: formatPersonelLabel(personel)
      })),
    [personeller]
  );

  const personelMap = useMemo(() => new Map(personeller.map((personel) => [personel.id, personel])), [personeller]);

  const selectedSurecPersonel = useMemo(() => {
    const personelId = Number.parseInt(surecForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [personelMap, surecForm.personelId]);

  async function loadBootstrap() {
    setBootstrapLoading(true);
    setBootstrapError(null);

    try {
      const [
        departmanOptions,
        gorevOptions,
        personelTipiOptions,
        bagliAmirOptions,
        ucretTipiOptions,
        primKuraliOptions,
        surecTurleri,
        personelList
      ] = await Promise.all([
        fetchDepartmanOptions(),
        fetchGorevOptions(),
        fetchPersonelTipiOptions(),
        fetchBagliAmirOptions(),
        fetchUcretTipiOptions(),
        fetchPrimKuraliOptions(),
        fetchSurecTuruOptions(),
        fetchPersonellerList({ page: 1, limit: 250, aktiflik: "tum" })
      ]);

      setRefs({
        departmanOptions,
        gorevOptions,
        personelTipiOptions,
        bagliAmirOptions,
        ucretTipiOptions,
        primKuraliOptions
      });
      setSurecTuruOptions(surecTurleri);
      setPersoneller(personelList.items);
    } catch (error) {
      setBootstrapError(error instanceof Error ? error.message : "Kayıt alanı yüklenemedi.");
    } finally {
      setBootstrapLoading(false);
    }
  }

  async function loadSurecler(personelId: string) {
    setSureclerLoading(true);
    setSureclerError(null);

    try {
      const result = await fetchSureclerList({
        page: 1,
        limit: 25,
        personel_id: personelId ? Number.parseInt(personelId, 10) : undefined
      });
      setSurecler(result.items);
    } catch (error) {
      setSureclerError(error instanceof Error ? error.message : "Süreç kayıtları yüklenemedi.");
    } finally {
      setSureclerLoading(false);
    }
  }

  useEffect(() => {
    void loadBootstrap();
  }, []);

  useEffect(() => {
    if (!initialSurecPersonelId) {
      return;
    }

    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo("Seçili personel ile süreç girişine devam edebilirsin.");
    setSurecForm(resetSurecFormKeepingPersonel(initialSurecPersonelId));
  }, [initialSurecPersonelId]);

  useEffect(() => {
    if (activeTab !== "surec" || bootstrapLoading || bootstrapError) {
      return;
    }

    void loadSurecler(surecForm.personelId);
  }, [activeTab, bootstrapError, bootstrapLoading, surecForm.personelId]);

  async function handlePersonelSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (personelSubmitting) {
      return;
    }
    if (!canCreatePersonel) {
      setPersonelError("Bu işlem için yetkin bulunmuyor.");
      return;
    }

    setPersonelSubmitting(true);
    setPersonelError(null);
    setPersonelInfo(null);

    try {
      const created = await createPersonel(buildCreatePersonelPayload(personelForm));
      setPersoneller((prev) => [created, ...prev.filter((item) => item.id !== created.id)]);
      setPersonelForm(INITIAL_CREATE_PERSONEL_FORM);
      setSurecForm(resetSurecFormKeepingPersonel(String(created.id)));
      setPersonelInfo("Personel kaydı oluşturuldu. Süreç sekmesine geçiliyor.");
      setSurecInfo("Yeni personel seçildi. Süreç girişine devam edebilirsin.");
      onTabChange("surec");
    } catch (error) {
      setPersonelError(error instanceof Error ? error.message : "Personel kaydı oluşturulamadı.");
    } finally {
      setPersonelSubmitting(false);
    }
  }

  async function handleSurecSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (surecSubmitting) {
      return;
    }
    if (!editingSurec && !canCreateSurec) {
      setSurecError("Bu işlem için yetkin bulunmuyor.");
      return;
    }
    if (editingSurec && !canEditSurec) {
      setSurecError("Bu süreci düzenlemek için yetkin bulunmuyor.");
      return;
    }

    setSurecSubmitting(true);
    setSurecError(null);
    setSurecInfo(null);

    try {
      if (editingSurec) {
        await updateSurec(editingSurec.id, buildUpdateSurecPayload(surecForm));
        setSurecInfo("Süreç kaydı güncellendi.");
      } else {
        await createSurec(buildCreateSurecPayload(surecForm));
        setSurecInfo("Süreç kaydı eklendi.");
      }

      const currentPersonelId = surecForm.personelId;
      setEditingSurec(null);
      setSurecForm(resetSurecFormKeepingPersonel(currentPersonelId));
      await loadSurecler(currentPersonelId);
    } catch (error) {
      setSurecError(error instanceof Error ? error.message : "Süreç kaydı kaydedilemedi.");
    } finally {
      setSurecSubmitting(false);
    }
  }

  async function handleSurecCancel(item: Surec) {
    if (!canCancelSurec) {
      setSurecError("Bu süreci iptal etmek için yetkin bulunmuyor.");
      return;
    }

    const confirmed = window.confirm(`Süreç #${item.id} kaydını iptal etmek istiyor musun?`);
    if (!confirmed) {
      return;
    }

    setSurecError(null);
    setSurecInfo(null);

    try {
      await cancelSurec(item.id);
      setSurecInfo("Süreç kaydı iptal edildi.");
      await loadSurecler(surecForm.personelId);
    } catch (error) {
      setSurecError(error instanceof Error ? error.message : "Süreç kaydı iptal edilemedi.");
    }
  }

  function beginSurecEdit(item: Surec) {
    if (!canEditSurec) {
      setSurecError("Bu süreci düzenlemek için yetkin bulunmuyor.");
      return;
    }

    setSurecError(null);
    setSurecInfo(null);
    setEditingSurec(item);
    setSurecForm(toSurecFormState(item));
  }

  function resetSurecEditor() {
    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo(null);
    setSurecForm(resetSurecFormKeepingPersonel(surecForm.personelId));
  }

  return (
    <div className="kayit-workspace">
      <div className="kayit-workspace-tabs" role="tablist" aria-label="Kayıt ve süreç sekmeleri">
        <button
          type="button"
          data-testid="kayit-tab-yeni-kayit"
          className={`kayit-workspace-tab${activeTab === "yeni-kayit" ? " is-active" : ""}`}
          aria-selected={activeTab === "yeni-kayit"}
          onClick={() => onTabChange("yeni-kayit")}
        >
          Kayıt
        </button>
        <button
          type="button"
          data-testid="kayit-tab-surec"
          className={`kayit-workspace-tab${activeTab === "surec" ? " is-active" : ""}`}
          aria-selected={activeTab === "surec"}
          onClick={() => onTabChange("surec")}
        >
          Süreç
        </button>
      </div>

      {activeTab === "yeni-kayit" ? (
        <div className="kayit-workspace-grid">
          <section className="workspace-surface-card">
            <div className="workspace-surface-header">
              <h3>Kayıt İşlemleri</h3>
              <p>Personel kartının ana kaydını burada oluştur, ardından süreç sekmesine geç.</p>
            </div>

            {bootstrapLoading ? <LoadingState label="Kayıt alanı yükleniyor..." /> : null}
            {!bootstrapLoading && bootstrapError ? (
              <ErrorState message={bootstrapError} onRetry={() => void loadBootstrap()} />
            ) : null}

            {!bootstrapLoading && !bootstrapError ? (
              <>
                <form id={KAYIT_SUREC_PERSONEL_FORM_ID} className="workspace-form" onSubmit={handlePersonelSubmit}>
                  <PersonelCreateFields
                    form={personelForm}
                    setForm={setPersonelForm}
                    refs={refs}
                    createErrorMessage={personelError}
                    referenceError={null}
                    className="workspace-form-stack"
                  />
                </form>
                {personelInfo ? <p className="workspace-success">{personelInfo}</p> : null}
                <div className="universal-btn-group workspace-form-actions">
                  <button type="submit" form={primaryFormId} className="universal-btn-save" disabled={personelSubmitting}>
                    {primaryActionLabel}
                  </button>
                  <button type="button" className="universal-btn-cancel" onClick={onClose}>
                    Kapat
                  </button>
                </div>
              </>
            ) : null}
          </section>
        </div>
      ) : (
        <div className="surec-workspace-grid">
          <section className="workspace-surface-card">
            <div className="workspace-surface-header">
              <h3>{editingSurec ? `Süreç Düzenle #${editingSurec.id}` : "Süreç İşlemleri"}</h3>
              <p>İzin, rapor ve hareket girişlerini doğrudan bu sekmeden yönet.</p>
            </div>

            {bootstrapLoading ? <LoadingState label="Süreç alanı yükleniyor..." /> : null}
            {!bootstrapLoading && bootstrapError ? (
              <ErrorState message={bootstrapError} onRetry={() => void loadBootstrap()} />
            ) : null}

            {!bootstrapLoading && !bootstrapError ? (
              <>
                <form id={KAYIT_SUREC_SUREC_FORM_ID} className="workspace-form" onSubmit={handleSurecSubmit}>
                  <SurecFormFields
                    form={surecForm}
                    setForm={setSurecForm}
                    surecTuruOptions={surecTuruOptions}
                    personelOptions={personelOptions}
                    errorMessage={surecError}
                    referenceError={null}
                    className="workspace-form-stack workspace-form-stack--compact"
                  />
                </form>

                {selectedSurecPersonel ? (
                  <div className="workspace-personel-preview workspace-personel-preview--compact">
                    <strong>
                      {selectedSurecPersonel.ad} {selectedSurecPersonel.soyad}
                    </strong>
                    <p>{selectedSurecPersonel.departman_adi ?? "-"} • {selectedSurecPersonel.gorev_adi ?? "-"}</p>
                    <p>{selectedSurecPersonel.telefon ?? "Telefon tanımlı değil"}</p>
                  </div>
                ) : null}

                <div className="workspace-inline-actions">
                  {editingSurec ? (
                    <button type="button" className="universal-btn-aux" onClick={resetSurecEditor}>
                      Düzenlemeyi Sıfırla
                    </button>
                  ) : null}
                  {surecInfo ? <p className="workspace-success workspace-success--inline">{surecInfo}</p> : null}
                </div>
                <div className="universal-btn-group workspace-form-actions">
                  <button type="submit" form={primaryFormId} className="universal-btn-save" disabled={surecSubmitting}>
                    {primaryActionLabel}
                  </button>
                  <button type="button" className="universal-btn-cancel" onClick={onClose}>
                    Kapat
                  </button>
                </div>
              </>
            ) : null}
          </section>

          <section className="workspace-surface-card">
            <div className="workspace-surface-header">
              <h3>Süreç Kayıtları</h3>
              <p>
                {selectedSurecPersonel
                  ? `${selectedSurecPersonel.ad} ${selectedSurecPersonel.soyad} için kayıtlar`
                  : "Seçili personel yoksa son süreç kayıtları listelenir."}
              </p>
            </div>

            {sureclerLoading ? <LoadingState label="Süreç kayıtları yükleniyor..." /> : null}
            {!sureclerLoading && sureclerError ? (
              <ErrorState message={sureclerError} onRetry={() => void loadSurecler(surecForm.personelId)} />
            ) : null}

            {!sureclerLoading && !sureclerError && surecler.length === 0 ? (
              <EmptyState
                title="Süreç kaydı yok"
                message="Bu seçim için görüntülenecek süreç kaydı bulunamadı."
              />
            ) : null}

            {!sureclerLoading && !sureclerError && surecler.length > 0 ? (
              <ul className="workspace-surec-list">
                {surecler.map((item) => {
                  const relatedPersonel = personelMap.get(item.personel_id) ?? null;

                  return (
                    <li key={item.id} className="workspace-surec-item">
                      <div className="workspace-surec-copy">
                        <strong>{formatSurecTuruLabel(item.surec_turu)}</strong>
                        <p>Personel: {relatedPersonel ? `${relatedPersonel.ad} ${relatedPersonel.soyad}` : item.personel_id}</p>
                        <p>Durum: {formatSurecStateLabel(item.state ?? "BEKLEMEDE")}</p>
                        <p>
                          Tarih: {item.baslangic_tarihi ?? "-"} / {item.bitis_tarihi ?? "-"}
                        </p>
                        {item.alt_tur ? <p>Alt Tür: {item.alt_tur}</p> : null}
                        {item.aciklama ? <p>Açıklama: {item.aciklama}</p> : null}
                      </div>

                      <div className="module-item-actions workspace-surec-actions">
                        {canEditSurec ? (
                          <button type="button" className="universal-btn-aux" onClick={() => beginSurecEdit(item)}>
                            Düzenle
                          </button>
                        ) : null}
                        {canCancelSurec ? (
                          <button
                            type="button"
                            className="universal-btn-aux"
                            onClick={() => void handleSurecCancel(item)}
                          >
                            İptal
                          </button>
                        ) : null}
                      </div>
                    </li>
                  );
                })}
              </ul>
            ) : null}
          </section>
        </div>
      )}
    </div>
  );
}
