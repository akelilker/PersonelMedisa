import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type SetStateAction
} from "react";
import { useNavigate } from "react-router-dom";
import { AppSelectField } from "../../../components/form/AppSelect";
import { resolveSubeDisplayLabels } from "../../../lib/organizasyon/sube-display-label";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type { KayitTab } from "../../../components/main-menu/MainMenu";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import {
  commitPersonelCreateToCaches,
  commitPersonelUpdateToCaches,
  dataCacheKeys,
  deleteCacheEntry,
  getActiveSube
} from "../../../data/data-manager";
import {
  applyPersonelKaliciSubeDegisikligi,
  applyPersonelOrganizasyonDegisikligi,
  createPersonel,
  fetchPersonellerListForSelect
} from "../../../api/personeller.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import {
  fetchBagliAmirOptions,
  fetchBirimOptions,
  fetchBolumOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPozisyonOptions,
  fetchPrimKuraliOptions,
  fetchSgkIsverenOptions,
  fetchCalismaLokasyonuOptions,
  fetchSurecTuruOptions,
  fetchUcretTipiOptions
} from "../../../api/referans.api";
import { createSurec, updateSurec } from "../../../api/surecler.api";
import { fetchPersonelBelgeDurumu, putPersonelBelgeDurumu } from "../../../api/belgeler.api";
import { getApiErrorDetail, getApiErrorMessage } from "../../../api/api-client";
import { PersonelCreateFields } from "../../../features/personeller/components/PersonelCreateFields";
import { PersonelZimmetCreateForm } from "../../../features/personeller/components/PersonelZimmetCreateForm";
import { PersonelBelgelerPanel } from "../../../features/personeller/components/personel-dosya/PersonelBelgelerPanel";
import { type KayitModalFooterModel } from "./KayitModalFooter";
import { KayitSurecPersonelBelgeTakipPanel } from "./KayitSurecPersonelBelgeTakipPanel";
import { KayitSurecPersonelFinansPanel } from "./KayitSurecPersonelFinansPanel";
import { KayitSurecPersonelGenelPanel } from "./KayitSurecPersonelGenelPanel";
import { KayitSurecPersonelHaftalikKapanisPanel } from "./KayitSurecPersonelHaftalikKapanisPanel";
import { KayitSurecPersonelOrganizasyonPanel } from "./KayitSurecPersonelOrganizasyonPanel";
import { KayitSurecPersonelProcessNav } from "./KayitSurecPersonelProcessNav";
import { KayitSurecPersonelPuantajPanel } from "./KayitSurecPersonelPuantajPanel";
import { KayitSurecPersonelUcretPanel } from "./KayitSurecPersonelUcretPanel";
import { KayitSurecTabHeader } from "./KayitSurecTabHeader";
import { SurecInlineBackButton } from "./SurecInlineBackButton";
import { YillikIzinHakDuzeltmePanel } from "./YillikIzinHakDuzeltmePanel";
import { buildCreatePersonelPayload } from "../../../features/personeller/personel-create-utils";
import { SurecFormFields } from "../../../features/surecler/components/SurecFormFields";
import {
  buildCreateSurecPayload,
  buildUpdateSurecPayload,
  toSurecFormState
} from "../../../features/surecler/surec-form-utils";
import { usePersonelFinansCreate } from "../../../hooks/useFinans";
import { INITIAL_CREATE_PERSONEL_FORM, usePersonelZimmetCreate, type CreatePersonelFormState } from "../../../hooks/usePersoneller";
import { INITIAL_SUREC_FORM, type SurecFormState } from "../../../hooks/useSurecler";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { dispatchRefreshBugunPersonelDurumu } from "../../../lib/bildirim/bugun-personel-durumu-events";
import { formatAktifDurumLabel } from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";
import type { IdOption, KeyOption } from "../../../types/referans";
import type { Surec } from "../../../types/surec";
import {
  BELGE_TURU_KEYS,
  BELGE_TURU_LABELS,
  createDefaultBelgeDurumDraft,
  type BelgeDurum,
  type BelgeDurumuItem,
  type BelgeTuru
} from "../../../types/belgeler";
import { refetchPersonelDetailAfterIstenAyrilma, refetchSurecCachesForPersonel } from "../kayit-surec-cache";
import {
  executeKaliciSubeDegisikligi,
  executeOrganizasyonPersonnelUpdate,
  hasOrganizasyonFormDiff
} from "../kayit-surec-pozisyon";
import {
  createOrganizasyonFormFromPersonel,
  DEVAMSIZLIK_ALT_TUR_CONFIG,
  KAYIT_SUREC_BELGELER_FORM_ID,
  KAYIT_SUREC_CEZA_FORM_ID,
  KAYIT_SUREC_MALI_FORM_ID,
  KAYIT_SUREC_PERSONEL_FORM_ID,
  KAYIT_SUREC_PERSONEL_PICKER_LABEL,
  KAYIT_SUREC_POZISYON_FORM_ID,
  KAYIT_SUREC_SUREC_FORM_ID,
  KAYIT_SUREC_ZIMMET_FORM_ID,
  normalizePersonelSurecTab,
  resolveVisiblePersonelSurecTabs,
  resolvePersonelSurecTabForSurecTuru,
  type DevamsizlikSubId,
  type OrganizasyonFormState,
  type PersonelSurecTab,
  type PuantajSubdomainId
} from "../kayit-surec-constants";
import {
  applyDevamsizlikAltTur,
  buildPersonelSelectLabels,
  formatPersonelLabel,
  normalizePersonelSearchText,
  resetSurecFormKeepingPersonel,
  resolveDevamsizlikEditSelection
} from "../kayit-surec-utils";
import { useAuth } from "../../../state/auth.store";
import { canonicalizeUserRole } from "../../../lib/authorization/canonicalize-user-role";

export {
  KAYIT_SUREC_BELGELER_FORM_ID,
  KAYIT_SUREC_CEZA_FORM_ID,
  KAYIT_SUREC_MALI_FORM_ID,
  KAYIT_SUREC_PERSONEL_FORM_ID,
  KAYIT_SUREC_SUREC_FORM_ID,
  KAYIT_SUREC_ZIMMET_FORM_ID
} from "../kayit-surec-constants";

function IconSearch(props: { className?: string }) {
  return (
    <svg
      className={props.className}
      width="20"
      height="20"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden
    >
      <circle cx="11" cy="11" r="8" />
      <path d="m21 21-4.3-4.3" />
    </svg>
  );
}

function KayitSurecPersonelContext({ personel }: { personel: Personel }) {
  const fullName = [personel.ad, personel.soyad].filter(Boolean).join(" ") || "Personel";
  const initials = `${personel.ad?.[0] ?? ""}${personel.soyad?.[0] ?? ""}`.toUpperCase() || "P";
  const isPassive = personel.aktif_durum === "PASIF";
  // Yalnız açıkça AKTIF olan kayıt aktif sayılır; boş/bilinmeyen durum yeşil gösterilmez.
  const isActive = personel.aktif_durum === "AKTIF";
  const aktifDurumLabel = formatAktifDurumLabel(personel.aktif_durum);
  const durumLabel = isPassive
    ? personel.pasiflik_durumu_etiketi ?? "Pasif"
    : aktifDurumLabel !== "-"
      ? aktifDurumLabel
      : "Durum Bilinmiyor";
  const durumClass = isPassive ? " is-passive" : isActive ? "" : " is-unknown";

  return (
    <section
      className={`kayit-personel-context workspace-personel-preview--compact${isPassive ? " is-passive" : ""}`}
      aria-label="Seçili personel bağlamı"
      data-testid="kayit-surec-personel-context"
    >
      <div className="kayit-personel-context-avatar" aria-hidden="true">
        {initials}
      </div>
      <div className="kayit-personel-context-copy">
        <p className="kayit-personel-context-kicker">İşlem yapılan personel</p>
        <h3>
          <strong>{fullName}</strong>
        </h3>
        <p className="kayit-personel-context-meta">
          Sicil {personel.sicil_no ?? "-"} · {personel.departman_adi ?? "-"} · {personel.gorev_adi ?? "-"}
        </p>
      </div>
      <div className="kayit-personel-context-aside">
        <dl className="kayit-personel-context-facts">
          <div>
            <dt>Durum</dt>
            {/* Aktif: yalnız yeşil nokta (görünür "Çalışıyor"/pill yok). Pasif: kırmızı nokta + ayrılış açıklaması. */}
            <dd
              className={`kayit-personel-context-status${durumClass}`}
              aria-label={durumLabel}
              title={durumLabel}
              data-testid="kayit-surec-personel-durum"
            >
              <span className="kayit-personel-context-status-dot" aria-hidden="true" />
              {isActive ? null : <span>{durumLabel}</span>}
            </dd>
          </div>
          <div>
            <dt>İşe giriş</dt>
            <dd>{personel.ise_giris_tarihi ?? "-"}</dd>
          </div>
        </dl>
      </div>
    </section>
  );
}

type KayitSurecWorkspaceProps = {
  activeTab: KayitTab;
  onTabChange: (tab: KayitTab) => void;
  onClose: () => void;
  initialSurecPersonelId?: string | null;
  initialPersonelTab?: PersonelSurecTab | null;
  initialOperation?: "yillik-izin-hak-duzeltme" | null;
  initialKayitIntent?: string | null;
  primaryActionLabel: string;
  primaryFormId: string;
  onFooterModelChange?: (model: KayitModalFooterModel | null) => void;
  onOpenBulkImport?: () => void;
};

const EMPTY_REFS: PersonelReferenceBundle = {
  departmanOptions: [],
  bolumOptions: [],
  birimOptions: [],
  gorevOptions: [],
  pozisyonOptions: [],
  personelTipiOptions: [],
  sgkIsverenOptions: [],
  calismaLokasyonuOptions: [],
  bagliAmirOptions: [],
  ucretTipiOptions: [],
  primKuraliOptions: []
};

export function KayitSurecWorkspace({
  activeTab,
  onTabChange,
  onClose,
  initialSurecPersonelId,
  initialPersonelTab = null,
  initialOperation = null,
  initialKayitIntent = null,
  primaryActionLabel,
  primaryFormId,
  onFooterModelChange,
  onOpenBulkImport
}: KayitSurecWorkspaceProps) {
  const navigate = useNavigate();
  const [openPersonelEditFromMissingGateway, setOpenPersonelEditFromMissingGateway] = useState(false);
  const { session } = useAuth();
  const { hasPermission } = useRoleAccess();
  const actorRole = canonicalizeUserRole(session?.user.rol ?? null);
  const canCreatePersonel = hasPermission("personeller.create");
  const canManageAccountOnboarding = hasPermission("yonetim-paneli.manage");
  const canCreateSurec = hasPermission("surecler.create");
  const canEditSurec = hasPermission("surecler.update");
  const canManageYillikIzinHak = hasPermission("yillik_izin_hak_duzeltme.manage");
  const canViewSurec =
    hasPermission("surecler.view") ||
    hasPermission("surecler.view.sube") ||
    hasPermission("surecler.detail.view");
  const canViewPersonelDetail = hasPermission("personeller.detail.view");
  const canViewBelgeler = canViewSurec || canViewPersonelDetail;
  const canWriteBelgeDurum = canCreateSurec || canEditSurec;
  const canUpdatePersonel = hasPermission("personeller.update");
  const canViewUcret = hasPermission("personeller.ucret.view");
  const canManageUcret = hasPermission("personeller.ucret.manage");
  const canCreateZimmet = canUpdatePersonel;
  const canCreateFinans = hasPermission("finans.create");
  /** Org change: personeller.update; surec history is best-effort after success. */
  const canSubmitPozisyon = canUpdatePersonel;
  const canTransferSube =
    actorRole === "GENEL_YONETICI" ||
    actorRole === "SISTEM_YONETICISI" ||
    actorRole === "IK_SORUMLUSU";

  const [refs, setRefs] = useState<PersonelReferenceBundle>(EMPTY_REFS);
  const [subeOptions, setSubeOptions] = useState<IdOption[]>([]);
  const [subeLoadError, setSubeLoadError] = useState<string | null>(null);
  const [surecTuruOptions, setSurecTuruOptions] = useState<KeyOption[]>([]);
  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [bootstrapLoading, setBootstrapLoading] = useState(true);
  const [bootstrapError, setBootstrapError] = useState<string | null>(null);

  const [personelForm, setPersonelForm] = useState<CreatePersonelFormState>(INITIAL_CREATE_PERSONEL_FORM);
  const [personelSubmitting, setPersonelSubmitting] = useState(false);
  const [personelError, setPersonelError] = useState<string | null>(null);
  const [personelFieldErrors, setPersonelFieldErrors] = useState<
    Partial<Record<"tcKimlikNo" | "subeId", string>>
  >({});
  const [personelInfo, setPersonelInfo] = useState<string | null>(null);
  const [createdPersonelIdForOnboarding, setCreatedPersonelIdForOnboarding] = useState<number | null>(
    null
  );

  const [surecForm, setSurecForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [surecSubmitting, setSurecSubmitting] = useState(false);
  const [surecError, setSurecError] = useState<string | null>(null);
  const [surecInfo, setSurecInfo] = useState<string | null>(null);
  const [editingSurec, setEditingSurec] = useState<Surec | null>(null);
  const [surecPersonelSearch, setSurecPersonelSearch] = useState("");
  const [surecPersonelPickerOpen, setSurecPersonelPickerOpen] = useState(false);
  const surecSearchToolbarRef = useRef<HTMLDivElement>(null);
  const surecPersonelPickerRef = useRef<HTMLDivElement>(null);

  const [activePersonelTab, setActivePersonelTab] = useState<PersonelSurecTab>(
    normalizePersonelSurecTab(initialPersonelTab ?? "genel")
  );
  const [puantajSubdomain, setPuantajSubdomain] = useState<PuantajSubdomainId | null>(null);
  const [devamsizlikSubId, setDevamsizlikSubId] = useState<DevamsizlikSubId | null>(null);
  const [hakDuzeltmeOpen, setHakDuzeltmeOpen] = useState(
    initialOperation === "yillik-izin-hak-duzeltme"
  );
  const [pozisyonForm, setPozisyonForm] = useState<OrganizasyonFormState>(createOrganizasyonFormFromPersonel(null));
  const [pozisyonSubmitting, setPozisyonSubmitting] = useState(false);
  const [kaliciSubeSubmitting, setKaliciSubeSubmitting] = useState(false);
  const [yeniSubeId, setYeniSubeId] = useState("");
  const [subeGerekce, setSubeGerekce] = useState("");
  const [subeTransferError, setSubeTransferError] = useState<string | null>(null);
  const [subeTransferInfo, setSubeTransferInfo] = useState<string | null>(null);
  const [genelMutating, setGenelMutating] = useState(false);
  const [ucretMutating, setUcretMutating] = useState(false);
  const [belgeFileMutating, setBelgeFileMutating] = useState(false);
  const [pozisyonError, setPozisyonError] = useState<string | null>(null);
  const [pozisyonInfo, setPozisyonInfo] = useState<string | null>(null);
  const [openPozisyonPicker, setOpenPozisyonPicker] = useState<string | null>(null);

  const [belgeDurumDraft, setBelgeDurumDraft] = useState<Record<BelgeTuru, BelgeDurum>>(() =>
    createDefaultBelgeDurumDraft()
  );
  const [belgeDurumLoading, setBelgeDurumLoading] = useState(false);
  const [belgeDurumError, setBelgeDurumError] = useState<string | null>(null);
  const [belgeDurumInfo, setBelgeDurumInfo] = useState<string | null>(null);
  const [belgeDurumSaving, setBelgeDurumSaving] = useState(false);
  const personelContextLocked =
    genelMutating ||
    pozisyonSubmitting ||
    kaliciSubeSubmitting ||
    ucretMutating ||
    belgeDurumSaving ||
    belgeFileMutating;

  // Seçim listesi label'ları: yalnız Ad Soyad (gerçek ad-soyad çakışması varsa "Sicil X").
  const personelSelectLabels = useMemo(() => buildPersonelSelectLabels(personeller), [personeller]);

  const personelOptions = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: personelSelectLabels.get(personel.id) ?? formatPersonelLabel(personel)
      })),
    [personelSelectLabels, personeller]
  );

  const filteredSurecPersonelOptions = useMemo(() => {
    const query = normalizePersonelSearchText(surecPersonelSearch);

    if (!query) {
      return personelOptions;
    }

    const filteredPersoneller = personeller.filter((personel) => {
      const searchable = [
        personel.ad,
        personel.soyad,
        personel.sicil_no,
        personel.tc_kimlik_no,
        personel.telefon,
        personel.departman_adi,
        personel.gorev_adi
      ]
        .map(normalizePersonelSearchText)
        .join(" ");

      return searchable.includes(query);
    });

    const filteredOptions = filteredPersoneller.map((personel) => ({
      value: String(personel.id),
      label: personelSelectLabels.get(personel.id) ?? formatPersonelLabel(personel)
    }));

    if (surecForm.personelId && !filteredOptions.some((option) => option.value === surecForm.personelId)) {
      const selectedPersonel = personeller.find((personel) => String(personel.id) === surecForm.personelId);

      if (selectedPersonel) {
        return [
          {
            value: String(selectedPersonel.id),
            label: personelSelectLabels.get(selectedPersonel.id) ?? formatPersonelLabel(selectedPersonel)
          },
          ...filteredOptions
        ];
      }
    }

    return filteredOptions;
  }, [personelSelectLabels, personeller, surecForm.personelId, surecPersonelSearch]);

  const handleSurecPersonelPickerOpenChange = useCallback((isOpen: boolean) => {
    setSurecPersonelPickerOpen(isOpen);
  }, []);

  useEffect(() => {
    if (!surecPersonelPickerOpen) {
      return;
    }

    function handlePointerDown(event: MouseEvent) {
      const target = event.target;

      if (!(target instanceof Node)) {
        return;
      }

      if (surecSearchToolbarRef.current?.contains(target)) {
        return;
      }

      if (surecPersonelPickerRef.current?.contains(target)) {
        return;
      }

      setSurecPersonelPickerOpen(false);
    }

    document.addEventListener("mousedown", handlePointerDown);
    return () => document.removeEventListener("mousedown", handlePointerDown);
  }, [surecPersonelPickerOpen]);

  const personelMap = useMemo(() => new Map(personeller.map((personel) => [personel.id, personel])), [personeller]);

  const selectedSurecPersonel = useMemo(() => {
    const personelId = Number.parseInt(surecForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [personelMap, surecForm.personelId]);

  const toggleSurecPersonelSearch = useCallback(() => {
    if (personelContextLocked) {
      return;
    }
    setSurecPersonelPickerOpen((open) => !open);
  }, [personelContextLocked]);

  const isSelectedPersonelPasif = selectedSurecPersonel?.aktif_durum === "PASIF";
  const isSelectedPersonelDirectoryOnly = selectedSurecPersonel?.calisan_kapsami === "DIS_KAYNAK";
  const visiblePersonelSurecTabs = resolveVisiblePersonelSurecTabs(isSelectedPersonelDirectoryOnly);
  const canSubmitShellFinansZimmet = Boolean(selectedSurecPersonel)
    && !isSelectedPersonelPasif
    && !isSelectedPersonelDirectoryOnly;

  const zimmetPersonelIdForHook = selectedSurecPersonel?.id ?? 0;
  const zimmetPersonelValid = Boolean(selectedSurecPersonel);
  const {
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage
  } = usePersonelZimmetCreate(zimmetPersonelIdForHook, zimmetPersonelValid, canCreateZimmet, {
    canSubmit: canSubmitShellFinansZimmet
  });

  const {
    finansFields: maliFields,
    setFinansFields: setMaliFields,
    createPersonelFinansHandler,
    isFinansSubmitting: isMaliSubmitting,
    finansCreateErrorMessage: maliCreateErrorMessage
  } = usePersonelFinansCreate(zimmetPersonelIdForHook, zimmetPersonelValid, canCreateFinans, {
    canSubmit: canSubmitShellFinansZimmet,
    initialKalemTuru: "AVANS"
  });

  const {
    finansFields: cezaFields,
    setFinansFields: setCezaFields,
    createPersonelFinansHandler: createPersonelCezaHandler,
    isFinansSubmitting: isCezaSubmitting,
    finansCreateErrorMessage: cezaCreateErrorMessage
  } = usePersonelFinansCreate(zimmetPersonelIdForHook, zimmetPersonelValid, canCreateFinans, {
    canSubmit: canSubmitShellFinansZimmet,
    initialKalemTuru: "CEZA"
  });

  const selectedSurecPersonelLabel = selectedSurecPersonel
    ? personelSelectLabels.get(selectedSurecPersonel.id) ?? formatPersonelLabel(selectedSurecPersonel)
    : "Seçiniz";

  const hasPozisyonDiff = Boolean(
    selectedSurecPersonel && hasOrganizasyonFormDiff(pozisyonForm, selectedSurecPersonel)
  );
  const selectedSurecPersonelIdRef = useRef<number | null>(null);
  selectedSurecPersonelIdRef.current = selectedSurecPersonel?.id ?? null;

  useEffect(() => {
    if (isSelectedPersonelDirectoryOnly && !["genel", "pozisyon", "belgeler"].includes(activePersonelTab)) {
      setActivePersonelTab("genel");
    }
  }, [activePersonelTab, isSelectedPersonelDirectoryOnly]);

  useEffect(() => {
    if (!visiblePersonelSurecTabs.some((tab) => tab.id === activePersonelTab)) {
      setActivePersonelTab("genel");
    }
  }, [activePersonelTab, visiblePersonelSurecTabs]);

  const hasInitialSurecPersonel = typeof initialSurecPersonelId === "string" && initialSurecPersonelId.length > 0;
  const prevShellPersonelIdRef = useRef<string | null>(null);

  const activeDevamsizlikAltTurField =
    devamsizlikSubId ? DEVAMSIZLIK_ALT_TUR_CONFIG[devamsizlikSubId] : undefined;

  useEffect(() => {
    if (editingSurec) {
      return;
    }

    const pid = surecForm.personelId;
    const prevPid = prevShellPersonelIdRef.current;
    if (prevPid !== null && prevPid !== "" && prevPid !== pid) {
      setSurecInfo(null);
    }
    prevShellPersonelIdRef.current = pid;

    if (!hasInitialSurecPersonel) {
      setActivePersonelTab("genel");
    }
    setDevamsizlikSubId(null);
    setPuantajSubdomain(null);
    setHakDuzeltmeOpen(false);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
    setSurecError(null);
    setSurecPersonelPickerOpen(false);
  }, [editingSurec, hasInitialSurecPersonel, surecForm.personelId]);

  useEffect(() => {
    if (!editingSurec) {
      return;
    }
    const selection = resolveDevamsizlikEditSelection(
      editingSurec.surec_turu,
      editingSurec.alt_tur ?? ""
    );
    if (!selection) {
      return;
    }
    setActivePersonelTab("puantaj");
    setDevamsizlikSubId(selection.cardId);
    setPuantajSubdomain(selection.cardId);
    setHakDuzeltmeOpen(false);
    setSurecForm(toSurecFormState(editingSurec));
  }, [editingSurec]);

  useEffect(() => {
    setPozisyonForm(createOrganizasyonFormFromPersonel(selectedSurecPersonel));
    setPozisyonError(null);
    setPozisyonInfo(null);
    setOpenPozisyonPicker(null);
    setYeniSubeId("");
    setSubeGerekce("");
    setSubeTransferError(null);
    setSubeTransferInfo(null);
  }, [selectedSurecPersonel]);

  function selectSurecPersonel(personelId: string) {
    if (personelContextLocked) {
      return;
    }

    setSurecForm((prev) => ({ ...prev, personelId }));
    setSurecPersonelPickerOpen(false);
    setSurecPersonelSearch("");
    if (activePersonelTab === "belgeler") {
      setBelgeDurumInfo(null);
      setBelgeDurumError(null);
    }
  }

  function beginChangeSurecPersonel() {
    if (personelContextLocked) {
      return;
    }

    setSurecPersonelPickerOpen(true);
  }

  const showSurecPersonelPickerSurface =
    !selectedSurecPersonel || (surecPersonelPickerOpen && !personelContextLocked);

  function setSurecFormGuarded(updater: SetStateAction<SurecFormState>) {
    setSurecForm((prev) => {
      const next = typeof updater === "function" ? updater(prev) : updater;
      if (personelContextLocked && next.personelId !== prev.personelId) {
        return prev;
      }
      return next;
    });
  }

  function applyPersonelUpdateLocally(updated: Personel) {
    commitPersonelUpdateToCaches(updated);
    setPersoneller((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
  }

  function selectPersonelTab(tabId: PersonelSurecTab) {
    // Keep wage/pozisyon panel mounted until in-flight mutation settles so
    // personel-context lock cannot drop early via unmount.
    if (personelContextLocked && tabId !== activePersonelTab) {
      return;
    }

    setActivePersonelTab(tabId);
    setSurecError(null);
    setSurecInfo(null);
    setPozisyonError(null);
    setPozisyonInfo(null);
    setOpenPozisyonPicker(null);
    setBelgeDurumInfo(null);
    setBelgeDurumError(null);

    if (tabId === "puantaj") {
      setDevamsizlikSubId(null);
      setPuantajSubdomain(null);
      setHakDuzeltmeOpen(false);
      setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
      return;
    }

    if (tabId === "haftalik-kapanis" || tabId === "belge-takip") {
      setDevamsizlikSubId(null);
      setPuantajSubdomain(null);
      setHakDuzeltmeOpen(false);
      return;
    }

    if (tabId === "ayrilma") {
      setDevamsizlikSubId(null);
      setSurecForm((prev) => ({
        ...resetSurecFormKeepingPersonel(prev.personelId),
        surecTuru: "ISTEN_AYRILMA",
        altTur: "",
        ucretliMi: false
      }));
      return;
    }

    if (tabId === "belgeler") {
      setDevamsizlikSubId(null);
      return;
    }

    setDevamsizlikSubId(null);
  }

  function selectDevamsizlikSubCard(id: DevamsizlikSubId) {
    setDevamsizlikSubId(id);
    setPuantajSubdomain(id);
    setHakDuzeltmeOpen(false);
    const firstAlt = DEVAMSIZLIK_ALT_TUR_CONFIG[id].options[0]?.value ?? "";

    setSurecForm((prev) => applyDevamsizlikAltTur(prev, id, firstAlt, surecTuruOptions));
  }

  function openPuantajHakDuzeltme() {
    setDevamsizlikSubId(null);
    setPuantajSubdomain(null);
    setHakDuzeltmeOpen(true);
  }

  function backToPuantajHub() {
    setDevamsizlikSubId(null);
    setPuantajSubdomain(null);
    setHakDuzeltmeOpen(false);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
  }

  async function loadBootstrap() {
    setBootstrapLoading(true);
    setBootstrapError(null);

    try {
      const [
        departmanOptions,
        bolumOptions,
        birimOptions,
        gorevOptions,
        pozisyonOptions,
        personelTipiOptions,
        sgkIsverenOptions,
        calismaLokasyonuOptions,
        bagliAmirOptions,
        ucretTipiOptions,
        primKuraliOptions,
        surecTurleri,
        personelList,
        subeler
      ] = await Promise.all([
        fetchDepartmanOptions(),
        fetchBolumOptions(),
        fetchBirimOptions(),
        fetchGorevOptions(),
        fetchPozisyonOptions(),
        fetchPersonelTipiOptions(),
        fetchSgkIsverenOptions(),
        fetchCalismaLokasyonuOptions(),
        fetchBagliAmirOptions(),
        fetchUcretTipiOptions(),
        fetchPrimKuraliOptions(),
        fetchSurecTuruOptions(),
        fetchPersonellerListForSelect({ aktiflik: "tum" }),
        fetchYonetimSubeleri()
      ]);

      const aktifSubeler = subeler.filter((sube) => sube.durum === "AKTIF");
      const subeLabels = resolveSubeDisplayLabels(
        aktifSubeler.map((sube) => ({ id: sube.id, ad: sube.ad, tamAd: sube.tam_ad ?? null }))
      );

      setSubeOptions(
        aktifSubeler.map((sube) => ({
          id: sube.id,
          // Adaptive kanonik etiket: kısa ad benzersizse kısa ad, aynı kısa ad
          // birden fazla şirkette varsa şirket ile ayrışan `tam_ad`.
          label: subeLabels.get(sube.id) ?? sube.ad,
          sirketId: sube.sirket?.id ?? null
        }))
      );
      setSubeLoadError(null);

      setRefs({
        departmanOptions,
        bolumOptions,
        birimOptions,
        gorevOptions,
        pozisyonOptions,
        personelTipiOptions,
        sgkIsverenOptions,
        calismaLokasyonuOptions,
        bagliAmirOptions,
        ucretTipiOptions,
        primKuraliOptions
      });
      setSurecTuruOptions(surecTurleri);
      setPersoneller(personelList);
    } catch (error) {
      setBootstrapError(getApiErrorMessage(error, "Kayıt alanı yüklenemedi."));
      setSubeLoadError(getApiErrorMessage(error, "Şube listesi yüklenemedi."));
    } finally {
      setBootstrapLoading(false);
    }
  }

  async function handleBelgeDurumSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedSurecPersonel || selectedSurecPersonel.aktif_durum === "PASIF" || !canWriteBelgeDurum) {
      return;
    }
    if (belgeDurumSaving || belgeDurumLoading || belgeFileMutating) {
      return;
    }

    setBelgeDurumSaving(true);
    setBelgeDurumError(null);
    setBelgeDurumInfo(null);
    try {
      const items: BelgeDurumuItem[] = BELGE_TURU_KEYS.map((belge_turu) => ({
        belge_turu,
        durum: belgeDurumDraft[belge_turu]
      }));
      await putPersonelBelgeDurumu(selectedSurecPersonel.id, items);
      setBelgeDurumInfo("Belge durumu kaydedildi.");
    } catch (err) {
      setBelgeDurumError(getApiErrorMessage(err, "Belge durumu kaydedilemedi."));
    } finally {
      setBelgeDurumSaving(false);
    }
  }

  useEffect(() => {
    void loadBootstrap();
  }, []);

  useEffect(() => {
    if (activePersonelTab !== "belgeler") {
      return;
    }

    if (!canWriteBelgeDurum || !selectedSurecPersonel || selectedSurecPersonel.aktif_durum === "PASIF") {
      setBelgeDurumDraft(createDefaultBelgeDurumDraft());
      setBelgeDurumLoading(false);
      setBelgeDurumError(null);
      return;
    }

    let cancelled = false;
    setBelgeDurumLoading(true);
    setBelgeDurumError(null);

    void (async () => {
      try {
        const items = await fetchPersonelBelgeDurumu(selectedSurecPersonel.id);
        if (cancelled) {
          return;
        }
        const next = createDefaultBelgeDurumDraft();
        for (const row of items) {
          next[row.belge_turu] = row.durum;
        }
        setBelgeDurumDraft(next);
      } catch (err) {
        if (!cancelled) {
          setBelgeDurumError(getApiErrorMessage(err, "Belge durumu yüklenemedi."));
        }
      } finally {
        if (!cancelled) {
          setBelgeDurumLoading(false);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [activePersonelTab, canWriteBelgeDurum, selectedSurecPersonel?.id, selectedSurecPersonel?.aktif_durum]);

  useEffect(() => {
    if (!initialSurecPersonelId) {
      return;
    }

    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo("Seçili personel ile süreç girişine devam edebilirsin.");
    setSurecForm(resetSurecFormKeepingPersonel(initialSurecPersonelId));
    if (initialPersonelTab) {
      setActivePersonelTab(normalizePersonelSurecTab(initialPersonelTab));
    }
    if (initialOperation === "yillik-izin-hak-duzeltme") {
      setActivePersonelTab("puantaj");
      setHakDuzeltmeOpen(true);
      setDevamsizlikSubId(null);
      setPuantajSubdomain(null);
    }
    setOpenPersonelEditFromMissingGateway(initialKayitIntent === "personel-missing-info-gateway");
  }, [initialSurecPersonelId, initialPersonelTab, initialOperation, initialKayitIntent]);

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
    setPersonelFieldErrors({});
    setPersonelInfo(null);
    setCreatedPersonelIdForOnboarding(null);

    try {
      const created = await createPersonel(buildCreatePersonelPayload(personelForm));
      commitPersonelCreateToCaches(created);
      setPersoneller((prev) => [created, ...prev.filter((item) => item.id !== created.id)]);
      setPersonelForm(INITIAL_CREATE_PERSONEL_FORM);
      setPersonelFieldErrors({});
      setSurecForm(resetSurecFormKeepingPersonel(String(created.id)));
      if (canManageAccountOnboarding) {
        setCreatedPersonelIdForOnboarding(created.id);
        setPersonelInfo("Personel kaydı oluşturuldu. Hesap oluşturabilir veya süreç sekmesine geçebilirsiniz.");
        setSurecInfo("Personel seçildi. Süreç kaydına devam edebilirsin.");
      } else {
        setPersonelInfo("Personel kaydı oluşturuldu. Süreç sekmesine geçiliyor.");
        setSurecInfo("Personel seçildi. Süreç kaydına devam edebilirsin.");
        onTabChange("surec");
      }
    } catch (error) {
      const detail = getApiErrorDetail(error, "Personel kaydı oluşturulamadı.", {
        context: "personel-create"
      });
      setPersonelError(detail.message);
      if (detail.code === "DUPLICATE_TC_KIMLIK_NO") {
        setPersonelFieldErrors({ tcKimlikNo: detail.message });
      } else if (
        detail.status === 403 &&
        detail.code === "FORBIDDEN" &&
        (detail.message === "Seçilen şube aktif şube filtresiyle uyuşmuyor." ||
          detail.message === "Seçili şube için yetkiniz yok.")
      ) {
        setPersonelFieldErrors({ subeId: detail.message });
      }
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
    if (!editingSurec && selectedSurecPersonel?.aktif_durum === "PASIF") {
      setSurecError(
        activePersonelTab === "ayrilma"
          ? "Bu personel pasif; ayrılma kaydı eklenmez."
          : activePersonelTab === "puantaj"
            ? "Bu personel pasif; puantaj/izin kaydı eklenmez."
            : "Bu personel pasif; süreç kaydı eklenmez."
      );
      return;
    }

    setSurecSubmitting(true);
    setSurecError(null);
    setSurecInfo(null);

    let nextSurecPersonelId = surecForm.personelId;

    try {
      if (editingSurec) {
        const updatedPersonelId = editingSurec.personel_id;
        const updatedSurecId = editingSurec.id;
        await updateSurec(editingSurec.id, buildUpdateSurecPayload(surecForm));
        setSurecInfo("Süreç kaydı güncellendi.");
        deleteCacheEntry(dataCacheKeys.surecDetail(getActiveSube(), updatedSurecId));
        try {
          await refetchSurecCachesForPersonel(updatedPersonelId);
        } catch {
          /* Süreç listesi önbelleği yenilenemedi. */
        }
        dispatchRefreshBugunPersonelDurumu();
      } else {
        const payload = buildCreateSurecPayload(surecForm);
        nextSurecPersonelId = String(payload.personel_id);
        await createSurec(payload);
        setSurecInfo("Süreç kaydı eklendi.");
        try {
          await refetchSurecCachesForPersonel(payload.personel_id);
        } catch {
          /* Süreç listesi önbelleği yenilenemedi. */
        }
        if (["IZIN", "RAPOR", "IS_KAZASI", "DEVAMSIZLIK"].includes(payload.surec_turu)) {
          dispatchRefreshBugunPersonelDurumu();
        }
        if (payload.surec_turu === "ISTEN_AYRILMA") {
          try {
            const refreshed = await refetchPersonelDetailAfterIstenAyrilma(payload.personel_id);
            commitPersonelUpdateToCaches(refreshed);
            setPersoneller((prev) => prev.map((item) => (item.id === refreshed.id ? refreshed : item)));
            setSurecInfo(
              refreshed.aktif_durum === "PASIF"
                ? "Ayrılma kaydedildi; personel PASIF."
                : "Süreç kaydı eklendi."
            );
          } catch {
            /* Personel detay önbelleği / liste satırı güncellenemedi. */
          }
        }
      }

      setActivePersonelTab(resolvePersonelSurecTabForSurecTuru(surecForm.surecTuru));
      setEditingSurec(null);
      setSurecForm(resetSurecFormKeepingPersonel(nextSurecPersonelId));
    } catch (error) {
      setSurecError(getApiErrorMessage(error, "Süreç kaydı kaydedilemedi."));
    } finally {
      setSurecSubmitting(false);
    }
  }

  async function handlePozisyonSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!selectedSurecPersonel || pozisyonSubmitting || kaliciSubeSubmitting) {
      return;
    }

    if (!canSubmitPozisyon) {
      setPozisyonInfo(null);
      setPozisyonError("Bu işlem için yetkin bulunmuyor.");
      return;
    }

    const submitPersonelId = selectedSurecPersonel.id;
    const submitForm = { ...pozisyonForm };
    const submitBaseline = selectedSurecPersonel;

    setPozisyonSubmitting(true);
    setSurecPersonelPickerOpen(false);
    setPozisyonError(null);
    setPozisyonInfo(null);

    try {
      const result = await executeOrganizasyonPersonnelUpdate({
        personel: submitBaseline,
        form: submitForm,
        deps: {
          applyOrganizasyon: (personelId, payload) =>
            applyPersonelOrganizasyonDegisikligi(personelId, payload).then((res) => ({
              personel: res.personel
            })),
          createSurec: canCreateSurec ? createSurec : undefined
        }
      });

      const isCurrentSelection = selectedSurecPersonelIdRef.current === submitPersonelId;

      if (result.status === "no_op") {
        if (isCurrentSelection) {
          setPozisyonError(null);
          setPozisyonInfo("Organizasyon bilgisi değişmedi.");
        }
        return;
      }

      if (result.status === "validation_error") {
        if (isCurrentSelection) {
          setPozisyonInfo(null);
          setPozisyonError(result.message);
        }
        return;
      }

      if (result.status === "org_failed") {
        if (isCurrentSelection) {
          setPozisyonInfo(null);
          setPozisyonError(getApiErrorMessage(result.error, "Organizasyon güncellenemedi."));
        }
        return;
      }

      try {
        await refetchSurecCachesForPersonel(submitPersonelId);
      } catch {
        /* cache refresh soft-fail */
      }

      commitPersonelUpdateToCaches(result.updated);
      setPersoneller((prev) => prev.map((item) => (item.id === result.updated.id ? result.updated : item)));

      if (isCurrentSelection) {
        setPozisyonForm(createOrganizasyonFormFromPersonel(result.updated));
        setPozisyonError(null);
        setPozisyonInfo(result.surecWarning ?? "Görev / organizasyon güncellendi.");
      }
    } finally {
      setPozisyonSubmitting(false);
    }
  }

  async function handleKaliciSubeSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedSurecPersonel || kaliciSubeSubmitting || pozisyonSubmitting) {
      return;
    }
    if (!canTransferSube) {
      setSubeTransferInfo(null);
      setSubeTransferError("Kalıcı şube değişikliği için yetkin bulunmuyor.");
      return;
    }

    const submitPersonelId = selectedSurecPersonel.id;
    setKaliciSubeSubmitting(true);
    setSubeTransferError(null);
    setSubeTransferInfo(null);

    try {
      const result = await executeKaliciSubeDegisikligi({
        personel: selectedSurecPersonel,
        yeniSubeId,
        gerekce: subeGerekce,
        deps: {
          applyKaliciSube: (personelId, payload) =>
            applyPersonelKaliciSubeDegisikligi(personelId, payload)
        }
      });

      const isCurrentSelection = selectedSurecPersonelIdRef.current === submitPersonelId;

      if (result.status === "validation_error") {
        if (isCurrentSelection) {
          setSubeTransferError(result.message);
        }
        return;
      }

      if (result.status === "failed") {
        if (isCurrentSelection) {
          setSubeTransferError(getApiErrorMessage(result.error, "Şube değiştirilemedi."));
        }
        return;
      }

      commitPersonelUpdateToCaches(result.updated);
      setPersoneller((prev) => prev.map((item) => (item.id === result.updated.id ? result.updated : item)));
      try {
        await refetchSurecCachesForPersonel(submitPersonelId);
      } catch {
        /* cache refresh soft-fail */
      }

      if (isCurrentSelection) {
        setPozisyonForm(createOrganizasyonFormFromPersonel(result.updated));
        setYeniSubeId("");
        setSubeGerekce("");
        setSubeTransferInfo("Kalıcı şube değişikliği uygulandı.");
      }
    } finally {
      setKaliciSubeSubmitting(false);
    }
  }

  function resetSurecEditor() {
    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo(null);
    setActivePersonelTab("genel");
    setDevamsizlikSubId(null);
    setPuantajSubdomain(null);
    setHakDuzeltmeOpen(false);
    setSurecForm(resetSurecFormKeepingPersonel(surecForm.personelId));
  }

  const surecWorkspaceGridClassName = [
    "surec-workspace-grid",
    activePersonelTab !== "genel" ? "surec-workspace-grid--islem-modu" : ""
  ]
    .filter(Boolean)
    .join(" ");

  const resetPozisyonForm = useCallback(() => {
    setPozisyonForm(createOrganizasyonFormFromPersonel(selectedSurecPersonel));
    setPozisyonError(null);
    setPozisyonInfo(null);
    setOpenPozisyonPicker(null);
    setYeniSubeId("");
    setSubeGerekce("");
    setSubeTransferError(null);
    setSubeTransferInfo(null);
  }, [selectedSurecPersonel]);

  const footerModel = useMemo((): KayitModalFooterModel | null => {
    if (bootstrapLoading || bootstrapError) {
      return null;
    }

    if (activeTab === "yeni-kayit") {
      return {
        primaryLabel: primaryActionLabel,
        primaryFormId,
        primaryDisabled: personelSubmitting,
        secondaryLabel: "Vazgeç",
        onSecondaryClick: onClose,
        onOpenBulkImport
      };
    }

    if (!selectedSurecPersonel) {
      return null;
    }

    if (isSelectedPersonelPasif) {
      return {
        primaryLabel: "Süreci Kaydet",
        primaryFormId,
        primaryDisabled: true,
        secondaryLabel: "Kapat",
        onSecondaryClick: onClose
      };
    }

    if (activePersonelTab === "puantaj") {
      return {
        primaryLabel: "Süreci Kaydet",
        primaryFormId,
        primaryDisabled: surecSubmitting || !devamsizlikSubId || hakDuzeltmeOpen,
        secondaryLabel: "Kapat",
        onSecondaryClick: onClose
      };
    }

    if (activePersonelTab === "pozisyon") {
      if (isSelectedPersonelPasif || !canSubmitPozisyon) {
        return null;
      }

      return {
        primaryLabel: pozisyonSubmitting ? "Kaydediliyor..." : "Kaydet",
        primaryFormId: KAYIT_SUREC_POZISYON_FORM_ID,
        primaryDisabled: pozisyonSubmitting || kaliciSubeSubmitting || !hasPozisyonDiff,
        secondaryLabel: "Vazgeç",
        onSecondaryClick: resetPozisyonForm
      };
    }

    if (activePersonelTab === "mali") {
      if (isSelectedPersonelPasif || !canCreateFinans) {
        return null;
      }

      return {
        primaryLabel: isMaliSubmitting ? "Kaydediliyor..." : "Kaydet",
        primaryFormId: KAYIT_SUREC_MALI_FORM_ID,
        primaryDisabled: isMaliSubmitting
      };
    }

    if (activePersonelTab === "zimmet") {
      if (isSelectedPersonelPasif) {
        return null;
      }

      return {
        primaryLabel: isZimmetSubmitting ? "Kaydediliyor..." : "Kaydet",
        primaryFormId: KAYIT_SUREC_ZIMMET_FORM_ID,
        primaryDisabled: isZimmetSubmitting || !canCreateZimmet
      };
    }

    if (activePersonelTab === "ayrilma") {
      if (isSelectedPersonelPasif || !canCreateSurec) {
        return null;
      }

      return {
        primaryLabel: surecSubmitting ? "Kaydediliyor..." : "Ayrılmayı Kaydet",
        primaryFormId,
        primaryDisabled: surecSubmitting,
        secondaryLabel: "Vazgeç",
        onSecondaryClick: onClose
      };
    }

    if (activePersonelTab === "ceza") {
      if (isSelectedPersonelPasif || !canCreateFinans) {
        return null;
      }

      return {
        primaryLabel: isCezaSubmitting ? "Kaydediliyor..." : "Kaydet",
        primaryFormId: KAYIT_SUREC_CEZA_FORM_ID,
        primaryDisabled: isCezaSubmitting
      };
    }

    if (activePersonelTab === "belgeler") {
      if (selectedSurecPersonel.aktif_durum === "PASIF" || !canWriteBelgeDurum) {
        return null;
      }

      return {
        primaryLabel: belgeDurumSaving ? "Kaydediliyor..." : "Kaydet",
        primaryFormId: KAYIT_SUREC_BELGELER_FORM_ID,
        primaryDisabled: belgeDurumSaving || belgeDurumLoading || belgeFileMutating
      };
    }

    return null;
  }, [
    activePersonelTab,
    activeTab,
    belgeDurumLoading,
    belgeDurumSaving,
    belgeFileMutating,
    bootstrapError,
    bootstrapLoading,
    canCreateFinans,
    canCreateSurec,
    canCreateZimmet,
    canSubmitPozisyon,
    canWriteBelgeDurum,
    devamsizlikSubId,
    hakDuzeltmeOpen,
    hasPozisyonDiff,
    isCezaSubmitting,
    isMaliSubmitting,
    isSelectedPersonelPasif,
    isZimmetSubmitting,
    onClose,
    personelSubmitting,
    pozisyonSubmitting,
    primaryActionLabel,
    primaryFormId,
    resetPozisyonForm,
    selectedSurecPersonel,
    surecSubmitting
  ]);

  useLayoutEffect(() => {
    onFooterModelChange?.(footerModel);
  }, [footerModel, onFooterModelChange]);

  useLayoutEffect(() => {
    return () => {
      onFooterModelChange?.(null);
    };
  }, [onFooterModelChange]);

  return (
    <div
      className={`kayit-workspace${activeTab === "yeni-kayit" ? " kayit-workspace--personel-kayit" : ""}`}
    >
      <KayitSurecTabHeader activeTab={activeTab} onTabChange={onTabChange} />

      <div className="kayit-workspace-scroll-body" data-testid="kayit-workspace-scroll-body">
      {activeTab === "surec" && selectedSurecPersonel ? (
        <SurecInlineBackButton
          label={KAYIT_SUREC_PERSONEL_PICKER_LABEL}
          onClick={beginChangeSurecPersonel}
          testId="kayit-surec-personel-degistir"
          disabled={personelContextLocked}
        />
      ) : null}
      {activeTab === "surec" && selectedSurecPersonel ? (
        <KayitSurecPersonelContext personel={selectedSurecPersonel} />
      ) : null}
      {activeTab === "surec" && selectedSurecPersonel ? (
        <KayitSurecPersonelProcessNav
          tabs={visiblePersonelSurecTabs}
          activeTab={activePersonelTab}
          locked={personelContextLocked}
          onSelect={selectPersonelTab}
        />
      ) : null}
      {activeTab === "surec" && showSurecPersonelPickerSurface ? (
        <div className="surec-workspace-toolbar" ref={surecSearchToolbarRef}>
          <button
            type="button"
            data-testid="kayit-surec-personel-search-toggle"
            className="surec-workspace-search-toggle"
            aria-expanded={surecPersonelPickerOpen}
            aria-controls="surec-create-personel-picker"
            aria-label={surecPersonelPickerOpen ? "Personel aramayı kapat" : "Personel ara"}
            onClick={toggleSurecPersonelSearch}
          >
            <IconSearch />
          </button>
        </div>
      ) : null}

      {activeTab === "yeni-kayit" ? (
        <div className="kayit-workspace-grid kayit-workspace-grid--personel-form">
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
                    subeOptions={subeOptions}
                    subeLoadError={subeLoadError}
                    createErrorMessage={personelError}
                    fieldErrors={personelFieldErrors}
                    onFieldErrorClear={(field) => {
                      setPersonelFieldErrors((prev) => {
                        if (!prev[field]) {
                          return prev;
                        }
                        const next = { ...prev };
                        delete next[field];
                        return next;
                      });
                    }}
                    referenceError={null}
                    className="workspace-form-stack"
                    canManageUcret={canManageUcret}
                  />
                </form>
                {personelInfo ? <p className="workspace-success">{personelInfo}</p> : null}
                {createdPersonelIdForOnboarding != null && canManageAccountOnboarding ? (
                  <div className="workspace-success" data-testid="personel-create-hesap-onboarding-cta">
                    <p>İsterseniz personel hesabını güvenli aktivasyon ile oluşturabilirsiniz.</p>
                    <button
                      type="button"
                      className="universal-btn-save"
                      onClick={() => {
                        const id = createdPersonelIdForOnboarding;
                        onClose();
                        navigate(`/personeller/${id}?tab=genel-bilgiler`);
                      }}
                    >
                      Personel Hesabı Oluştur
                    </button>
                  </div>
                ) : null}
              </>
            ) : null}
          </section>
        </div>
      ) : (
        <div className={surecWorkspaceGridClassName}>
          <section className="workspace-surface-card">
            <div className="workspace-surface-header">
              <h3>{editingSurec ? `Süreç Düzenle #${editingSurec.id}` : "Süreç İşlemleri"}</h3>
              <p>İzin, rapor ve diğer süreç kayıtlarını bu sekmeden yönet.</p>
            </div>

            {bootstrapLoading ? <LoadingState label="Süreç alanı yükleniyor..." /> : null}
            {!bootstrapLoading && bootstrapError ? (
              <ErrorState message={bootstrapError} onRetry={() => void loadBootstrap()} />
            ) : null}

            {!bootstrapLoading && !bootstrapError ? (
              <>
                <>
                    <div className="surec-personel-picker" ref={surecPersonelPickerRef}>
                      <input
                        type="hidden"
                        name="surec-create-personel"
                        value={surecForm.personelId}
                        readOnly
                      />
                      {personelOptions.length > 0 ? (
                        showSurecPersonelPickerSurface ? (
                        <div data-testid="kayit-surec-personel-picker">
                          <AppSelectField
                            className="surec-personel-combobox"
                            label={KAYIT_SUREC_PERSONEL_PICKER_LABEL}
                            id="surec-create-personel-picker"
                            dataTestId="surec-create-personel-select"
                            value={surecForm.personelId}
                            disabled={personelContextLocked}
                            open={surecPersonelPickerOpen}
                            onOpenChange={handleSurecPersonelPickerOpenChange}
                            onChange={selectSurecPersonel}
                            options={filteredSurecPersonelOptions}
                            searchable
                            filterOptions={false}
                            searchValue={surecPersonelSearch}
                            onSearchValueChange={setSurecPersonelSearch}
                            searchPlaceholder="Ad/Soyad Veya Sicil No. Girin."
                            searchInputTestId="kayit-surec-personel-panel-search"
                            noResultsText="Aramaya uygun personel bulunamadı."
                            ariaLabel="Personel listesi"
                          />
                        </div>
                        ) : null
                      ) : (
                        !selectedSurecPersonel ? (
                          <p className="workspace-empty-hint">Personel listesi yüklenemedi veya boş.</p>
                        ) : null
                      )}
                    </div>

                    {selectedSurecPersonel ? (
                      <div className="surec-person-shell">
                        {activePersonelTab === "genel" ? (
                          <KayitSurecPersonelGenelPanel
                            personel={selectedSurecPersonel}
                            canUpdatePersonel={canUpdatePersonel}
                            canViewUcret={canViewUcret && !isSelectedPersonelDirectoryOnly}
                            personelRefs={refs}
                            onBusyChange={setGenelMutating}
                            onPersonelUpdated={applyPersonelUpdateLocally}
                            openEditOnMount={openPersonelEditFromMissingGateway}
                          />
                        ) : null}

                        {activePersonelTab === "puantaj" ? (
                          <KayitSurecPersonelPuantajPanel
                            personel={selectedSurecPersonel}
                            activeSubdomain={puantajSubdomain}
                            hakDuzeltmeOpen={hakDuzeltmeOpen}
                            canManageYillikIzinHak={canManageYillikIzinHak}
                            isPassive={isSelectedPersonelPasif}
                            onSelectDevamsizlikSub={selectDevamsizlikSubCard}
                            onOpenHakDuzeltme={openPuantajHakDuzeltme}
                            onBackToPuantajHub={backToPuantajHub}
                          >
                            {hakDuzeltmeOpen ? (
                              <YillikIzinHakDuzeltmePanel
                                personelId={selectedSurecPersonel.id}
                                enabled={canManageYillikIzinHak}
                              />
                            ) : null}

                            {devamsizlikSubId && !hakDuzeltmeOpen ? (
                              <>
                                <form id={KAYIT_SUREC_SUREC_FORM_ID} className="workspace-form" onSubmit={handleSurecSubmit}>
                                  <SurecFormFields
                                    form={surecForm}
                                    setForm={setSurecForm}
                                    surecTuruOptions={surecTuruOptions}
                                    personelOptions={personelOptions}
                                    showPersonelField={false}
                                    showSurecTuruField
                                    altTurField={activeDevamsizlikAltTurField}
                                    onAltTurSelect={
                                      devamsizlikSubId
                                        ? (prev, altTur) =>
                                            applyDevamsizlikAltTur(prev, devamsizlikSubId, altTur, surecTuruOptions)
                                        : undefined
                                    }
                                    useOperationControls
                                    errorMessage={surecError}
                                    referenceError={null}
                                    className="workspace-form-stack workspace-form-stack--compact"
                                  />
                                </form>

                                <div className="workspace-inline-actions">
                                  {surecInfo ? (
                                    <p className="workspace-success workspace-success--inline">{surecInfo}</p>
                                  ) : null}
                                </div>
                              </>
                            ) : null}
                          </KayitSurecPersonelPuantajPanel>
                        ) : null}

                        {activePersonelTab === "haftalik-kapanis" ? (
                          <KayitSurecPersonelHaftalikKapanisPanel personel={selectedSurecPersonel} />
                        ) : null}

                        {activePersonelTab === "belge-takip" ? (
                          <KayitSurecPersonelBelgeTakipPanel personel={selectedSurecPersonel} />
                        ) : null}

                        {activePersonelTab === "pozisyon" ? (
                          isSelectedPersonelPasif ? (
                            <div className="surec-person-placeholder">
                              <strong>Görev / Organizasyon</strong>
                              <p>Bu personel pasif; organizasyon değişikliği yapılamaz.</p>
                            </div>
                          ) : (
                            <KayitSurecPersonelOrganizasyonPanel
                              personel={selectedSurecPersonel}
                              form={pozisyonForm}
                              setForm={setPozisyonForm}
                              refs={refs}
                              subeOptions={subeOptions}
                              canSubmitOrg={canSubmitPozisyon}
                              canTransferSube={canTransferSube}
                              orgSubmitting={pozisyonSubmitting}
                              subeSubmitting={kaliciSubeSubmitting}
                              orgError={pozisyonError}
                              orgInfo={pozisyonInfo}
                              subeError={subeTransferError}
                              subeInfo={subeTransferInfo}
                              openPicker={openPozisyonPicker}
                              setOpenPicker={setOpenPozisyonPicker}
                              yeniSubeId={yeniSubeId}
                              setYeniSubeId={setYeniSubeId}
                              subeGerekce={subeGerekce}
                              setSubeGerekce={setSubeGerekce}
                              onOrgSubmit={handlePozisyonSubmit}
                              onSubeSubmit={handleKaliciSubeSubmit}
                            />
                          )
                        ) : null}

                        {activePersonelTab === "mali" ? (
                          selectedSurecPersonel ? (
                            isSelectedPersonelPasif ? (
                              <div className="surec-person-placeholder">
                                <strong>Finans</strong>
                                <p>Bu personel pasif; finans kaydı eklenmez.</p>
                              </div>
                            ) : canViewUcret || canCreateFinans ? (
                              <div className="surec-shell-panel" data-testid="kayit-surec-mali-stack">
                                {canViewUcret ? (
                                  <KayitSurecPersonelUcretPanel
                                    personel={selectedSurecPersonel}
                                    canManageUcret={canManageUcret}
                                    canUpdatePersonel={canUpdatePersonel}
                                    ucretTipiOptions={refs.ucretTipiOptions}
                                    isActive={activePersonelTab === "mali"}
                                    onBusyChange={setUcretMutating}
                                    onPersonelUpdated={applyPersonelUpdateLocally}
                                  />
                                ) : null}
                                {canCreateFinans ? (
                                  <KayitSurecPersonelFinansPanel
                                    title="Finans"
                                    personelLabel={selectedSurecPersonelLabel}
                                    formId={KAYIT_SUREC_MALI_FORM_ID}
                                    fieldNamePrefix="kayit-mali"
                                    fields={maliFields}
                                    setFields={setMaliFields}
                                    onSubmit={createPersonelFinansHandler}
                                    errorMessage={maliCreateErrorMessage}
                                    isSubmitting={isMaliSubmitting}
                                    hideActions
                                  />
                                ) : null}
                              </div>
                            ) : (
                              <div className="surec-person-placeholder">
                                <strong>Finans</strong>
                                <p>Bu işlem için yetkin yok. Mali kayıtları Finans ekranından yönet.</p>
                              </div>
                            )
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Finans</strong>
                              <p>Finans işlemleri için önce personel seç.</p>
                            </div>
                          )
                        ) : activePersonelTab === "zimmet" ? (
                          selectedSurecPersonel ? (
                            isSelectedPersonelPasif ? (
                              <div className="surec-person-placeholder">
                                <strong>Zimmet</strong>
                                <p>Bu personel pasif; zimmet kaydı eklenmez.</p>
                              </div>
                            ) : (
                            <div>
                              <PersonelZimmetCreateForm
                                formId={KAYIT_SUREC_ZIMMET_FORM_ID}
                                zimmetForm={zimmetForm}
                                setZimmetForm={setZimmetForm}
                                onSubmit={createZimmetHandler}
                                zimmetCreateErrorMessage={zimmetCreateErrorMessage}
                              />
                            </div>
                            )
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Zimmet</strong>
                              <p>Zimmet için önce personel seç.</p>
                            </div>
                          )
                        ) : activePersonelTab === "ayrilma" ? (
                          selectedSurecPersonel ? (
                            <>
                              {surecInfo ? (
                                <div className="workspace-inline-actions">
                                  <p className="workspace-success workspace-success--inline">{surecInfo}</p>
                                </div>
                              ) : null}
                              {selectedSurecPersonel.aktif_durum === "PASIF" ? (
                                <div className="surec-person-placeholder">
                                  <strong>Ayrılma</strong>
                                  <p>Bu personel pasif; ayrılma kaydı eklenmez.</p>
                                </div>
                              ) : (
                                <div className="surec-shell-panel">
                                  <p className="workspace-empty-hint">
                                    <strong>Ayrılma</strong> — {selectedSurecPersonelLabel}
                                  </p>
                                  <p className="workspace-empty-hint" data-testid="kayit-ayrilma-pasif-uyari">
                                    Kaydettiğinizde personel PASIF (işten ayrıldı) olur. Geçmiş kayıtlar silinmez.
                                  </p>
                                  <form
                                    id={KAYIT_SUREC_SUREC_FORM_ID}
                                    className="workspace-form"
                                    onSubmit={handleSurecSubmit}
                                  >
                                    <SurecFormFields
                                      form={surecForm}
                                      setForm={setSurecForm}
                                      surecTuruOptions={surecTuruOptions}
                                      personelOptions={personelOptions}
                                      showPersonelField={false}
                                      showSurecTuruField={false}
                                      showAltTurField={false}
                                      showUcretliField={false}
                                      useOperationControls
                                      errorMessage={surecError}
                                      referenceError={null}
                                      className="workspace-form-stack workspace-form-stack--compact"
                                    />
                                  </form>
                                </div>
                              )}
                            </>
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Ayrılma</strong>
                              <p>Ayrılma için önce personel seç.</p>
                            </div>
                          )
                        ) : activePersonelTab === "ceza" ? (
                          selectedSurecPersonel ? (
                            isSelectedPersonelPasif ? (
                              <div className="surec-person-placeholder">
                                <strong>Ceza</strong>
                                <p>Bu personel pasif; ceza kaydı eklenmez.</p>
                              </div>
                            ) : canCreateFinans ? (
                              <KayitSurecPersonelFinansPanel
                                title="Ceza"
                                personelLabel={selectedSurecPersonelLabel}
                                formId={KAYIT_SUREC_CEZA_FORM_ID}
                                fieldNamePrefix="kayit-ceza"
                                fields={cezaFields}
                                setFields={setCezaFields}
                                onSubmit={createPersonelCezaHandler}
                                errorMessage={cezaCreateErrorMessage}
                                isSubmitting={isCezaSubmitting}
                                isKalemLocked
                                hideActions
                              />
                            ) : (
                              <div className="surec-person-placeholder">
                                <strong>Ceza</strong>
                                <p>Bu işlem için yetkin yok. Ceza kayıtlarını Finans ekranından yönet.</p>
                              </div>
                            )
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Ceza</strong>
                              <p>Ceza için önce personel seç.</p>
                            </div>
                          )
                        ) : activePersonelTab === "belgeler" ? (
                          selectedSurecPersonel ? (
                            selectedSurecPersonel.aktif_durum === "PASIF" ? (
                              <div className="surec-person-placeholder">
                                <strong>Belgeler</strong>
                                <p>Bu personel pasif; belge durumu güncellenmez.</p>
                              </div>
                            ) : !canViewBelgeler ? (
                              <div className="surec-person-placeholder">
                                <strong>Belgeler</strong>
                                <p>Bu işlem için yetkin yok.</p>
                              </div>
                            ) : (
                              <div data-testid="kayit-surec-belgeler-panel">
                                {canWriteBelgeDurum ? (
                                  <>
                                    <p className="workspace-empty-hint">
                                      <strong>Dosya Evrak Durumu</strong> — {selectedSurecPersonelLabel}
                                    </p>
                                    {belgeDurumLoading ? (
                                      <p className="workspace-empty-hint">Belgeler yükleniyor…</p>
                                    ) : null}
                                    {belgeDurumError ? <p className="workspace-error">{belgeDurumError}</p> : null}
                                    {!belgeDurumLoading ? (
                                      <form
                                        id={KAYIT_SUREC_BELGELER_FORM_ID}
                                        className="workspace-form belge-durum-form"
                                        onSubmit={handleBelgeDurumSubmit}
                                      >
                                        {BELGE_TURU_KEYS.map((tur) => (
                                          <div key={tur} className="form-section belge-durum-row">
                                            <div className="form-label" id={`belge-label-${tur}`}>
                                              {BELGE_TURU_LABELS[tur]}
                                            </div>
                                            <div
                                              className="belge-durum-radios"
                                              role="radiogroup"
                                              aria-labelledby={`belge-label-${tur}`}
                                            >
                                              <label className="belge-durum-radio">
                                                <input
                                                  type="radio"
                                                  name={`belge-durum-${tur}`}
                                                  value="VAR"
                                                  checked={belgeDurumDraft[tur] === "VAR"}
                                                  disabled={belgeDurumSaving || belgeFileMutating}
                                                  onChange={() =>
                                                    setBelgeDurumDraft((prev) => ({ ...prev, [tur]: "VAR" }))
                                                  }
                                                />{" "}
                                                VAR
                                              </label>
                                              <label className="belge-durum-radio">
                                                <input
                                                  type="radio"
                                                  name={`belge-durum-${tur}`}
                                                  value="YOK"
                                                  checked={belgeDurumDraft[tur] === "YOK"}
                                                  disabled={belgeDurumSaving || belgeFileMutating}
                                                  onChange={() =>
                                                    setBelgeDurumDraft((prev) => ({ ...prev, [tur]: "YOK" }))
                                                  }
                                                />{" "}
                                                YOK
                                              </label>
                                            </div>
                                          </div>
                                        ))}
                                      </form>
                                    ) : null}
                                    {belgeDurumInfo ? (
                                      <p className="workspace-success workspace-success--inline">{belgeDurumInfo}</p>
                                    ) : null}

                                    <div className="belge-kayit-section-divider" aria-hidden="true" />
                                  </>
                                ) : null}

                                <PersonelBelgelerPanel
                                  personel={selectedSurecPersonel}
                                  isActive={activePersonelTab === "belgeler"}
                                  showBelgeDurumu={!canWriteBelgeDurum}
                                  showBelgeTakipLink
                                  onBusyChange={setBelgeFileMutating}
                                  externalBusy={belgeDurumSaving}
                                />
                              </div>
                            )
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Belgeler</strong>
                              <p>Belgeler için önce personel seç.</p>
                            </div>
                          )
                        ) : null}

                      </div>
                    ) : null}
                  </>
              </>
            ) : null}
          </section>

        </div>
      )}
      </div>
    </div>
  );
}
