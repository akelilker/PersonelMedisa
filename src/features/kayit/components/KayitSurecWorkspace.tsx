import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
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
  initialIntent?: "personel-edit-gateway" | "personel-zimmet-gateway" | null;
  initialReturnTo?: string | null;
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

type DevamsizlikSubId = "izin" | "rapor" | "is_kazasi" | "izinsiz" | "gec" | "erken";

type DevamsizlikSubCard = {
  id: DevamsizlikSubId;
  title: string;
  description: string;
  candidateKeys: string[];
};

const DEVAMSIZLIK_SUB_CARDS: DevamsizlikSubCard[] = [
  {
    id: "izin",
    title: "İzin",
    description: "Ücretli veya süreli izin hareketi",
    candidateKeys: ["IZIN"]
  },
  {
    id: "rapor",
    title: "Rapor",
    description: "Hastalık veya istirahat raporu",
    candidateKeys: ["RAPOR"]
  },
  {
    id: "is_kazasi",
    title: "İş Kazası",
    description: "İş kazasına bağlı süreç kaydı",
    candidateKeys: ["IS_KAZASI"]
  },
  {
    id: "izinsiz",
    title: "İzinsiz Devamsızlık",
    description: "Mazeretsiz yokluk (referansta karşılığı varsa tür atanır)",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "gec",
    title: "Geç Geldi",
    description: "Referansta tür yoksa süreç türünü aşağıdan seçin",
    candidateKeys: []
  },
  {
    id: "erken",
    title: "Erken Çıktı",
    description: "Referansta tür yoksa süreç türünü aşağıdan seçin",
    candidateKeys: []
  }
];

function resolveSurecTuruKeyFromOptions(candidateKeys: string[], options: KeyOption[]): string | null {
  if (candidateKeys.length === 0 || options.length === 0) {
    return null;
  }

  const keyByNorm = new Map(options.map((option) => [normalizeEnumKey(option.key), option.key]));

  for (const candidate of candidateKeys) {
    const resolved = keyByNorm.get(normalizeEnumKey(candidate));
    if (resolved) {
      return resolved;
    }
  }

  return null;
}

function formatSummaryField(value: string | null | undefined) {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  return trimmed.length > 0 ? trimmed : "-";
}

export function KayitSurecWorkspace({
  activeTab,
  onTabChange,
  onClose,
  initialSurecPersonelId,
  initialIntent,
  initialReturnTo,
  primaryActionLabel,
  primaryFormId
}: KayitSurecWorkspaceProps) {
  const navigate = useNavigate();
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

  const directSurecEntry =
    typeof initialSurecPersonelId === "string" && initialSurecPersonelId.trim().length > 0;

  const [surecShellPanel, setSurecShellPanel] = useState<null | "devamsizlik">(null);
  const [devamsizlikSubId, setDevamsizlikSubId] = useState<DevamsizlikSubId | null>(null);

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

  const resolvedDevamsizlikSurecTuruKey = useMemo(() => {
    if (!devamsizlikSubId) {
      return null;
    }

    const card = DEVAMSIZLIK_SUB_CARDS.find((item) => item.id === devamsizlikSubId);
    if (!card) {
      return null;
    }

    return resolveSurecTuruKeyFromOptions(card.candidateKeys, surecTuruOptions);
  }, [devamsizlikSubId, surecTuruOptions]);

  const useShellSurecLayout = !directSurecEntry;

  const hideSurecTuruFieldInShell =
    useShellSurecLayout &&
    !editingSurec &&
    surecShellPanel === "devamsizlik" &&
    surecTuruOptions.length > 0 &&
    devamsizlikSubId !== null &&
    resolvedDevamsizlikSurecTuruKey !== null;

  useEffect(() => {
    if (!useShellSurecLayout || editingSurec) {
      return;
    }

    setSurecShellPanel(null);
    setDevamsizlikSubId(null);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
    setSurecError(null);
    setSurecInfo(null);
  }, [editingSurec, surecForm.personelId, useShellSurecLayout]);

  function openDevamsizlikShellPanel() {
    setSurecError(null);
    setSurecInfo(null);
    setSurecShellPanel("devamsizlik");
    setDevamsizlikSubId(null);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
  }

  function closeDevamsizlikShellPanel() {
    setSurecShellPanel(null);
    setDevamsizlikSubId(null);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
    setSurecError(null);
  }

  function selectDevamsizlikSubCard(id: DevamsizlikSubId) {
    setDevamsizlikSubId(id);
    const card = DEVAMSIZLIK_SUB_CARDS.find((item) => item.id === id);
    const resolvedKey =
      card && card.candidateKeys.length > 0
        ? resolveSurecTuruKeyFromOptions(card.candidateKeys, surecTuruOptions)
        : null;

    setSurecForm((prev) => ({
      ...prev,
      surecTuru: resolvedKey ?? "",
      altTur: ""
    }));
  }

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
    setSurecShellPanel(null);
    setDevamsizlikSubId(null);
    setEditingSurec(item);
    setSurecForm(toSurecFormState(item));
  }

  function resetSurecEditor() {
    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo(null);
    setSurecShellPanel(null);
    setDevamsizlikSubId(null);
    setSurecForm(resetSurecFormKeepingPersonel(surecForm.personelId));
  }

  const showGatewayMessage =
    activeTab === "yeni-kayit" &&
    (initialIntent === "personel-edit-gateway" || initialIntent === "personel-zimmet-gateway") &&
    typeof initialReturnTo === "string" &&
    initialReturnTo.length > 0;

  const gatewayActionLabel =
    initialIntent === "personel-zimmet-gateway"
      ? "Personel Kartına dön ve zimmet ekle"
      : "Personel Kartına dön ve düzenle";

  const gatewayInfoMessage =
    initialIntent === "personel-zimmet-gateway"
      ? "Zimmet işlemi merkez ekrana taşınıyor. Bu geçişte zimmet formu personel kartında çalışmaya devam eder."
      : "Kart düzenleme işlemi merkez ekrana taşınıyor. Bu geçişte düzenleme formu personel kartında çalışmaya devam eder.";

  const classicSurecFormLayout = directSurecEntry || editingSurec !== null;

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
                {showGatewayMessage ? (
                  <>
                    <p className="workspace-success">
                      {gatewayInfoMessage}
                    </p>
                    <div className="universal-btn-group workspace-form-actions">
                      <button
                        type="button"
                        className="universal-btn-save"
                        onClick={() => {
                          navigate(initialReturnTo, {
                            state:
                              initialIntent === "personel-zimmet-gateway"
                                ? { openPersonelZimmet: true }
                                : { openPersonelEdit: true }
                          });
                        }}
                      >
                        {gatewayActionLabel}
                      </button>
                      <button type="button" className="universal-btn-cancel" onClick={onClose}>
                        Kapat
                      </button>
                    </div>
                  </>
                ) : (
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
                )}
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
                {classicSurecFormLayout ? (
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
                        <p>
                          {selectedSurecPersonel.departman_adi ?? "-"} • {selectedSurecPersonel.gorev_adi ?? "-"}
                        </p>
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
                ) : (
                  <>
                    {personelOptions.length > 0 ? (
                      <FormField
                        as="select"
                        label="Personel"
                        name="surec-create-personel"
                        value={surecForm.personelId}
                        onChange={(value) => setSurecForm((prev) => ({ ...prev, personelId: value }))}
                        required
                        placeholderOption={{ value: "", label: "Seçiniz" }}
                        selectOptions={personelOptions}
                      />
                    ) : (
                      <p className="workspace-empty-hint">Personel listesi yüklenemedi veya boş.</p>
                    )}

                    {!selectedSurecPersonel ? (
                      <p className="workspace-empty-hint">Süreç girmek için önce personel seçin.</p>
                    ) : surecShellPanel === null ? (
                      <div className="surec-shell-overview">
                        <div className="surec-shell-summary">
                          <p className="surec-shell-summary-kicker">Genel bilgiler</p>
                          <div className="surec-shell-summary-grid">
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Ad Soyad</span>
                              <strong className="surec-shell-summary-value">
                                {selectedSurecPersonel.ad} {selectedSurecPersonel.soyad}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">T.C. Kimlik No</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.tc_kimlik_no)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Doğum Tarihi</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.dogum_tarihi)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Bölüm</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.departman_adi)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Görev / Unvan</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.gorev_adi)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Bağlı Amir</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.bagli_amir_adi)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Kan Grubu</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.kan_grubu)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Telefon</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.telefon)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">İşe Giriş Tarihi</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.ise_giris_tarihi)}
                              </strong>
                            </div>
                            <div className="surec-shell-summary-item">
                              <span className="surec-shell-summary-label">Personel Tipi</span>
                              <strong className="surec-shell-summary-value">
                                {formatSummaryField(selectedSurecPersonel.personel_tipi_adi)}
                              </strong>
                            </div>
                          </div>
                        </div>

                        <div className="surec-shell-actions">
                          <button
                            type="button"
                            className="surec-shell-action-tile"
                            onClick={openDevamsizlikShellPanel}
                          >
                            <span className="surec-shell-action-icon" aria-hidden="true" />
                            <span className="surec-shell-action-text">
                              <span className="surec-shell-action-title">Devamsızlık</span>
                              <span className="surec-shell-action-desc">
                                İzin, rapor, devamsızlık ve günlük yoklukla ilgili kayıtlar
                              </span>
                            </span>
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div className="surec-shell-panel">
                        <div className="surec-shell-panel-head">
                          <button type="button" className="universal-btn-aux" onClick={closeDevamsizlikShellPanel}>
                            Geri
                          </button>
                          <h4 className="surec-shell-panel-title">Devamsızlık</h4>
                        </div>
                        <p className="workspace-empty-hint surec-shell-panel-hint">
                          Hareket türünü seçin. Yalnızca referansta tanımlı süreç türleri forma otomatik yazılır;
                          liste dışındaki durumlarda süreç türünü elle seçmeniz gerekir.
                        </p>

                        <div className="surec-devamsizlik-tiles" role="group" aria-label="Devamsızlık alt türleri">
                          {DEVAMSIZLIK_SUB_CARDS.map((card) => {
                            const matched = resolveSurecTuruKeyFromOptions(card.candidateKeys, surecTuruOptions);
                            const statusLabel =
                              card.candidateKeys.length === 0
                                ? "Referansta eşleşme yok; tür elle seçilecek"
                                : matched
                                  ? "Referansla eşleşti"
                                  : "Bu ortamda referans eşleşmedi; tür elle seçilecek";

                            return (
                              <button
                                key={card.id}
                                type="button"
                                className={`surec-devamsizlik-tile${devamsizlikSubId === card.id ? " is-active" : ""}`}
                                onClick={() => selectDevamsizlikSubCard(card.id)}
                              >
                                <span className="surec-devamsizlik-tile-title">{card.title}</span>
                                <span className="surec-devamsizlik-tile-desc">{card.description}</span>
                                <span className="surec-devamsizlik-tile-status">{statusLabel}</span>
                              </button>
                            );
                          })}
                        </div>

                        <form id={KAYIT_SUREC_SUREC_FORM_ID} className="workspace-form" onSubmit={handleSurecSubmit}>
                          <SurecFormFields
                            form={surecForm}
                            setForm={setSurecForm}
                            surecTuruOptions={surecTuruOptions}
                            personelOptions={personelOptions}
                            showPersonelField={false}
                            showSurecTuruField={!hideSurecTuruFieldInShell}
                            errorMessage={surecError}
                            referenceError={null}
                            className="workspace-form-stack workspace-form-stack--compact"
                          />
                        </form>

                        <div className="workspace-inline-actions">
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
                      </div>
                    )}
                  </>
                )}
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
