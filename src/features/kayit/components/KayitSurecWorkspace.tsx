import {
  useCallback,
  useEffect,
  useLayoutEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
  type KeyboardEvent
} from "react";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type { KayitTab } from "../../../components/main-menu/MainMenu";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import {
  commitPersonelCreateToCaches,
  commitPersonelUpdateToCaches,
  dataCacheKeys,
  deleteCacheEntry,
  getActiveSube,
  getSubeIdForApiRequest
} from "../../../data/data-manager";
import {
  createPersonel,
  fetchPersonellerList,
  updatePersonel
} from "../../../api/personeller.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchSurecTuruOptions,
  fetchUcretTipiOptions
} from "../../../api/referans.api";
import { createSurec, updateSurec } from "../../../api/surecler.api";
import { fetchPersonelBelgeDurumu, putPersonelBelgeDurumu } from "../../../api/belgeler.api";
import { getApiErrorDetail, getApiErrorMessage } from "../../../api/api-client";
import { PersonelCreateFields } from "../../../features/personeller/components/PersonelCreateFields";
import { PersonelZimmetCreateForm } from "../../../features/personeller/components/PersonelZimmetCreateForm";
import { KayitBelgeKayitlariSection } from "./KayitBelgeKayitlariSection";
import { KayitGatewayRedirectPanel } from "./KayitGatewayRedirectPanel";
import { KayitSurecPersonelFinansPanel } from "./KayitSurecPersonelFinansPanel";
import { KayitSurecPozisyonReferencePicker } from "./KayitSurecPozisyonReferencePicker";
import { KayitSurecTabHeader } from "./KayitSurecTabHeader";
import { buildCreatePersonelPayload } from "../../../features/personeller/personel-create-utils";
import { SurecFormFields } from "../../../features/surecler/components/SurecFormFields";
import {
  buildCreateSurecPayload,
  buildUpdateSurecPayload
} from "../../../features/surecler/surec-form-utils";
import { usePersonelFinansCreate } from "../../../hooks/useFinans";
import { INITIAL_CREATE_PERSONEL_FORM, usePersonelZimmetCreate, type CreatePersonelFormState } from "../../../hooks/usePersoneller";
import { INITIAL_SUREC_FORM, type SurecFormState } from "../../../hooks/useSurecler";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { displayUcretTipiLabel } from "../../../lib/display/ucret-tipi-display";
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
import { useKayitGatewayIntent } from "../hooks/useKayitGatewayIntent";
import { refetchPersonelDetailAfterIstenAyrilma, refetchSurecCachesForPersonel } from "../kayit-surec-cache";
import {
  createPozisyonFormFromPersonel,
  DEVAMSIZLIK_ALT_TUR_CONFIG,
  DEVAMSIZLIK_SUB_CARDS,
  KAYIT_SUREC_BELGELER_FORM_ID,
  KAYIT_SUREC_CEZA_FORM_ID,
  KAYIT_SUREC_MALI_FORM_ID,
  KAYIT_SUREC_PERSONEL_FORM_ID,
  KAYIT_SUREC_SUREC_FORM_ID,
  KAYIT_SUREC_ZIMMET_FORM_ID,
  PERSONEL_SUREC_TABS,
  type DevamsizlikSubId,
  type PersonelSurecTab,
  type PozisyonFormState
} from "../kayit-surec-constants";
import {
  formatGeneralField,
  formatMoneyField,
  formatPersonelLabel,
  getPersonelInitials,
  normalizePersonelSearchText,
  optionLabel,
  parsePozisyonId,
  resetSurecFormKeepingPersonel,
  resolveDevamsizlikSurecTuru,
  toOptionalIdValue
} from "../kayit-surec-utils";

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
  const { hasPermission } = useRoleAccess();
  const canCreatePersonel = hasPermission("personeller.create");
  const canCreateSurec = hasPermission("surecler.create");
  const canUpdatePersonel = hasPermission("personeller.update");
  const canCreateZimmet = canUpdatePersonel;
  const canCreateFinans = hasPermission("finans.create");
  const canEditSurec = hasPermission("surecler.update");
  /** Pozisyon: `updatePersonel` + `createSurec(POZISYON_DEGISTI)` — ikisi de zorunlu. */
  const canSubmitPozisyon = canUpdatePersonel && canCreateSurec;

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

  const [surecForm, setSurecForm] = useState<SurecFormState>(INITIAL_SUREC_FORM);
  const [surecSubmitting, setSurecSubmitting] = useState(false);
  const [surecError, setSurecError] = useState<string | null>(null);
  const [surecInfo, setSurecInfo] = useState<string | null>(null);
  const [editingSurec, setEditingSurec] = useState<Surec | null>(null);
  const [surecPersonelSearch, setSurecPersonelSearch] = useState("");
  const [surecPersonelPickerOpen, setSurecPersonelPickerOpen] = useState(false);
  const [surecSearchExpanded, setSurecSearchExpanded] = useState(false);
  const surecPersonelSearchInputRef = useRef<HTMLInputElement>(null);
  const surecSearchToolbarRef = useRef<HTMLDivElement>(null);
  const surecPersonelPickerRef = useRef<HTMLDivElement>(null);

  const [activePersonelTab, setActivePersonelTab] = useState<PersonelSurecTab>("genel");
  const [devamsizlikSubId, setDevamsizlikSubId] = useState<DevamsizlikSubId | null>(null);
  const [pozisyonForm, setPozisyonForm] = useState<PozisyonFormState>(createPozisyonFormFromPersonel(null));
  const [pozisyonSubmitting, setPozisyonSubmitting] = useState(false);
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

  const personelOptions = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: formatPersonelLabel(personel)
      })),
    [personeller]
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
      label: formatPersonelLabel(personel)
    }));

    if (surecForm.personelId && !filteredOptions.some((option) => option.value === surecForm.personelId)) {
      const selectedPersonel = personeller.find((personel) => String(personel.id) === surecForm.personelId);

      if (selectedPersonel) {
        return [{ value: String(selectedPersonel.id), label: formatPersonelLabel(selectedPersonel) }, ...filteredOptions];
      }
    }

    return filteredOptions;
  }, [personelOptions, personeller, surecForm.personelId, surecPersonelSearch]);

  const handleSurecPersonelComboboxKeyDownCapture = useCallback(
    (event: KeyboardEvent<HTMLDivElement>) => {
      if (!surecPersonelPickerOpen) {
        return;
      }
      const searchEl = surecPersonelSearchInputRef.current;
      if (!searchEl) {
        return;
      }
      const active = document.activeElement;
      if (active === searchEl || searchEl.contains(active)) {
        return;
      }
      if (event.nativeEvent.isComposing) {
        return;
      }

      const { key } = event;

      if (key === "Escape") {
        return;
      }
      if (key === "Tab") {
        return;
      }
      if (key.startsWith("Arrow")) {
        return;
      }
      if (key === "Enter" || key === "Home" || key === "End" || key === "PageDown" || key === "PageUp") {
        return;
      }

      if (key === "Backspace") {
        event.preventDefault();
        setSurecSearchExpanded(true);
        searchEl.focus({ preventScroll: true });
        setSurecPersonelSearch((prev) => prev.slice(0, -1));
        return;
      }

      if (key === "Delete") {
        event.preventDefault();
        setSurecSearchExpanded(true);
        searchEl.focus({ preventScroll: true });
        return;
      }

      if (key === " ") {
        return;
      }

      if (key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
        event.preventDefault();
        setSurecSearchExpanded(true);
        searchEl.focus({ preventScroll: true });
        setSurecPersonelSearch((prev) => prev + key);
      }
    },
    [surecPersonelPickerOpen]
  );

  useLayoutEffect(() => {
    if (!surecSearchExpanded) {
      return;
    }
    const id = window.requestAnimationFrame(() => {
      surecPersonelSearchInputRef.current?.focus({ preventScroll: true });
    });
    return () => window.cancelAnimationFrame(id);
  }, [surecSearchExpanded]);

  useEffect(() => {
    if (!surecSearchExpanded && !surecPersonelPickerOpen) {
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

      setSurecSearchExpanded(false);
      setSurecPersonelPickerOpen(false);
    }

    document.addEventListener("mousedown", handlePointerDown);
    return () => document.removeEventListener("mousedown", handlePointerDown);
  }, [surecPersonelPickerOpen, surecSearchExpanded]);

  const personelMap = useMemo(() => new Map(personeller.map((personel) => [personel.id, personel])), [personeller]);

  const selectedSurecPersonel = useMemo(() => {
    const personelId = Number.parseInt(surecForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [personelMap, surecForm.personelId]);

  const toggleSurecSearchExpanded = useCallback(() => {
    setSurecSearchExpanded((open) => {
      const next = !open;

      if (next && !selectedSurecPersonel) {
        setSurecPersonelPickerOpen(true);
      }

      return next;
    });
  }, [selectedSurecPersonel]);

  const isSelectedPersonelPasif = selectedSurecPersonel?.aktif_durum === "PASIF";
  const canSubmitShellFinansZimmet = Boolean(selectedSurecPersonel) && !isSelectedPersonelPasif;

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

  const selectedSurecPersonelLabel = selectedSurecPersonel ? formatPersonelLabel(selectedSurecPersonel) : "Seçiniz";

  const hasPozisyonDiff = Boolean(
    selectedSurecPersonel &&
      (pozisyonForm.departmanId !== toOptionalIdValue(selectedSurecPersonel.departman_id) ||
        pozisyonForm.gorevId !== toOptionalIdValue(selectedSurecPersonel.gorev_id) ||
        pozisyonForm.bagliAmirId !== toOptionalIdValue(selectedSurecPersonel.bagli_amir_id) ||
        pozisyonForm.personelTipiId !== toOptionalIdValue(selectedSurecPersonel.personel_tipi_id))
  );

  const selectedPersonelGeneralColumns = useMemo(() => {
    if (!selectedSurecPersonel) {
      return [];
    }

    return [
      {
        items: [
          { label: "T.C. Kimlik No", value: formatGeneralField(selectedSurecPersonel.tc_kimlik_no) },
          { label: "Doğum Tarihi", value: formatGeneralField(selectedSurecPersonel.dogum_tarihi) },
          { label: "Doğum Yeri", value: formatGeneralField(selectedSurecPersonel.dogum_yeri) },
          { label: "Telefon", value: formatGeneralField(selectedSurecPersonel.telefon) },
          { label: "Kan Grubu", value: formatGeneralField(selectedSurecPersonel.kan_grubu) }
        ]
      },
      {
        items: [
          { label: "Acil Durum Kişisi", value: formatGeneralField(selectedSurecPersonel.acil_durum_kisi) },
          { label: "Acil Durum Telefon", value: formatGeneralField(selectedSurecPersonel.acil_durum_telefon) },
          { label: "Bölüm", value: formatGeneralField(selectedSurecPersonel.departman_adi) },
          { label: "Görev / Unvan", value: formatGeneralField(selectedSurecPersonel.gorev_adi) },
          { label: "Bağlı Amir", value: formatGeneralField(selectedSurecPersonel.bagli_amir_adi) }
        ]
      },
      {
        items: [
          { label: "Sicil No", value: formatGeneralField(selectedSurecPersonel.sicil_no) },
          { label: "İşe Giriş Tarihi", value: formatGeneralField(selectedSurecPersonel.ise_giris_tarihi) },
          { label: "Personel Tipi", value: formatGeneralField(selectedSurecPersonel.personel_tipi_adi) },
          {
            label: "Ücret Tipi",
            value: formatGeneralField(
              displayUcretTipiLabel(
                selectedSurecPersonel.ucret_tipi_adi,
                selectedSurecPersonel.ucret_tipi_id
              )
            )
          },
          { label: "Net Maaş", value: formatMoneyField(selectedSurecPersonel.maas_tutari) },
          { label: "Prim Kuralı", value: formatGeneralField(selectedSurecPersonel.prim_kurali_adi) }
        ]
      }
    ];
  }, [selectedSurecPersonel]);

  const resolvedDevamsizlikSurecTuruKey = useMemo(() => {
    if (!devamsizlikSubId) {
      return null;
    }

    return resolveDevamsizlikSurecTuru(devamsizlikSubId, surecTuruOptions);
  }, [devamsizlikSubId, surecTuruOptions]);

  const useShellSurecLayout = editingSurec === null;

  const prevShellPersonelIdRef = useRef<string | null>(null);

  const hideSurecTuruFieldInShell =
    useShellSurecLayout &&
    !editingSurec &&
    activePersonelTab === "izin-devamsizlik" &&
    surecTuruOptions.length > 0 &&
    devamsizlikSubId !== null &&
    resolvedDevamsizlikSurecTuruKey !== null;
  const activeDevamsizlikAltTurField =
    useShellSurecLayout && devamsizlikSubId ? DEVAMSIZLIK_ALT_TUR_CONFIG[devamsizlikSubId] : undefined;

  useEffect(() => {
    if (!useShellSurecLayout || editingSurec) {
      return;
    }

    const pid = surecForm.personelId;
    const prevPid = prevShellPersonelIdRef.current;
    if (prevPid !== null && prevPid !== "" && prevPid !== pid) {
      setSurecInfo(null);
    }
    prevShellPersonelIdRef.current = pid;

    setActivePersonelTab("genel");
    setDevamsizlikSubId(null);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
    setSurecError(null);
    setSurecPersonelPickerOpen(false);
  }, [editingSurec, surecForm.personelId, useShellSurecLayout]);

  useEffect(() => {
    setPozisyonForm(createPozisyonFormFromPersonel(selectedSurecPersonel));
    setPozisyonError(null);
    setPozisyonInfo(null);
    setOpenPozisyonPicker(null);
  }, [selectedSurecPersonel]);

  function selectSurecPersonel(personelId: string) {
    setSurecForm((prev) => ({ ...prev, personelId }));
    setSurecPersonelPickerOpen(false);
    setSurecPersonelSearch("");
    setSurecSearchExpanded(false);
    if (activePersonelTab === "belgeler") {
      setBelgeDurumInfo(null);
      setBelgeDurumError(null);
    }
  }

  function selectPersonelTab(tabId: PersonelSurecTab) {
    setActivePersonelTab(tabId);
    setSurecError(null);
    setSurecInfo(null);
    setPozisyonError(null);
    setPozisyonInfo(null);
    setOpenPozisyonPicker(null);
    setBelgeDurumInfo(null);
    setBelgeDurumError(null);

    if (tabId === "izin-devamsizlik") {
      const nextSubId = devamsizlikSubId ?? "izin";
      const altTurConfig = DEVAMSIZLIK_ALT_TUR_CONFIG[nextSubId];
      setDevamsizlikSubId(nextSubId);
      setSurecForm((prev) => ({
        ...resetSurecFormKeepingPersonel(prev.personelId),
        surecTuru: resolveDevamsizlikSurecTuru(nextSubId, surecTuruOptions) ?? "",
        altTur: altTurConfig.options[0]?.value ?? ""
      }));
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
    const resolvedKey = resolveDevamsizlikSurecTuru(id, surecTuruOptions);
    const altTurConfig = DEVAMSIZLIK_ALT_TUR_CONFIG[id];

    setSurecForm((prev) => ({
      ...prev,
      surecTuru: resolvedKey ?? "",
      altTur: altTurConfig.options[0]?.value ?? ""
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
        surecTurleri,
        personelList,
        subeler
      ] = await Promise.all([
        fetchDepartmanOptions(),
        fetchGorevOptions(),
        fetchPersonelTipiOptions(),
        fetchBagliAmirOptions(),
        fetchUcretTipiOptions(),
        fetchSurecTuruOptions(),
        fetchPersonellerList({ page: 1, limit: 250, aktiflik: "tum" }),
        fetchYonetimSubeleri()
      ]);

      setSubeOptions(
        subeler
          .filter((sube) => sube.durum === "AKTIF")
          .map((sube) => ({ id: sube.id, label: sube.ad }))
      );
      setSubeLoadError(null);

      setRefs({
        departmanOptions,
        gorevOptions,
        personelTipiOptions,
        bagliAmirOptions,
        ucretTipiOptions,
        primKuraliOptions: []
      });
      setSurecTuruOptions(surecTurleri);
      setPersoneller(personelList.items);
    } catch (error) {
      setBootstrapError(getApiErrorMessage(error, "Kayıt alanı yüklenemedi."));
      setSubeLoadError(getApiErrorMessage(error, "Şube listesi yüklenemedi."));
    } finally {
      setBootstrapLoading(false);
    }
  }

  async function handleBelgeDurumSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedSurecPersonel || selectedSurecPersonel.aktif_durum === "PASIF" || !canCreateSurec) {
      return;
    }
    if (belgeDurumSaving || belgeDurumLoading) {
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

    if (!selectedSurecPersonel || selectedSurecPersonel.aktif_durum === "PASIF") {
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
  }, [activePersonelTab, selectedSurecPersonel?.id, selectedSurecPersonel?.aktif_durum]);

  useEffect(() => {
    if (!initialSurecPersonelId) {
      return;
    }

    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo("Seçili personel ile süreç girişine devam edebilirsin.");
    setSurecForm(resetSurecFormKeepingPersonel(initialSurecPersonelId));
  }, [initialSurecPersonelId]);

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

    try {
      const created = await createPersonel(buildCreatePersonelPayload(personelForm));
      commitPersonelCreateToCaches(created);
      setPersoneller((prev) => [created, ...prev.filter((item) => item.id !== created.id)]);
      setPersonelForm(INITIAL_CREATE_PERSONEL_FORM);
      setPersonelFieldErrors({});
      setSurecForm(resetSurecFormKeepingPersonel(String(created.id)));
      setPersonelInfo("Personel kaydı oluşturuldu. Süreç sekmesine geçiliyor.");
      setSurecInfo("Personel seçildi. Süreç kaydına devam edebilirsin.");
      onTabChange("surec");
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
          : activePersonelTab === "izin-devamsizlik"
            ? "Bu personel pasif; izin/devamsızlık kaydı eklenmez."
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
        if (payload.surec_turu === "ISTEN_AYRILMA") {
          try {
            const refreshed = await refetchPersonelDetailAfterIstenAyrilma(payload.personel_id);
            commitPersonelUpdateToCaches(refreshed);
            setPersoneller((prev) => prev.map((item) => (item.id === refreshed.id ? refreshed : item)));
          } catch {
            /* Personel detay önbelleği / liste satırı güncellenemedi. */
          }
        }
      }

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

    if (!selectedSurecPersonel || pozisyonSubmitting) {
      return;
    }

    if (!canSubmitPozisyon) {
      setPozisyonInfo(null);
      setPozisyonError("Bu işlem için yetkin bulunmuyor.");
      return;
    }

    if (selectedSurecPersonel.aktif_durum === "PASIF") {
      setPozisyonInfo(null);
      setPozisyonError("Bu personel pasif; pozisyon değişikliği yapılamaz.");
      return;
    }

    if (!hasPozisyonDiff) {
      setPozisyonError(null);
      setPozisyonInfo("Pozisyon bilgisi değişmedi.");
      return;
    }

    if (!pozisyonForm.effectiveDate) {
      setPozisyonInfo(null);
      setPozisyonError("Değişikliğin geçerli olacağı tarihi seç.");
      return;
    }

    if (!pozisyonForm.departmanId || !pozisyonForm.gorevId || !pozisyonForm.personelTipiId) {
      setPozisyonInfo(null);
      setPozisyonError("Bölüm, görev / unvan ve çalışma tipi boş bırakılamaz.");
      return;
    }

    setPozisyonSubmitting(true);
    setPozisyonError(null);
    setPozisyonInfo(null);

    const changes = [
      {
        label: "Bölüm",
        before: formatGeneralField(selectedSurecPersonel.departman_adi),
        after: optionLabel(refs.departmanOptions, pozisyonForm.departmanId, formatGeneralField(selectedSurecPersonel.departman_adi)),
        changed: pozisyonForm.departmanId !== toOptionalIdValue(selectedSurecPersonel.departman_id)
      },
      {
        label: "Görev / Unvan",
        before: formatGeneralField(selectedSurecPersonel.gorev_adi),
        after: optionLabel(refs.gorevOptions, pozisyonForm.gorevId, formatGeneralField(selectedSurecPersonel.gorev_adi)),
        changed: pozisyonForm.gorevId !== toOptionalIdValue(selectedSurecPersonel.gorev_id)
      },
      {
        label: "Bağlı Amir",
        before: formatGeneralField(selectedSurecPersonel.bagli_amir_adi),
        after: optionLabel(refs.bagliAmirOptions, pozisyonForm.bagliAmirId, formatGeneralField(selectedSurecPersonel.bagli_amir_adi)),
        changed: pozisyonForm.bagliAmirId !== toOptionalIdValue(selectedSurecPersonel.bagli_amir_id)
      },
      {
        label: "Çalışma Tipi",
        before: formatGeneralField(selectedSurecPersonel.personel_tipi_adi),
        after: optionLabel(refs.personelTipiOptions, pozisyonForm.personelTipiId, formatGeneralField(selectedSurecPersonel.personel_tipi_adi)),
        changed: pozisyonForm.personelTipiId !== toOptionalIdValue(selectedSurecPersonel.personel_tipi_id)
      }
    ].filter((item) => item.changed);

    const changeSummary = changes.map((item) => `${item.label}: ${item.before} -> ${item.after}`).join("; ");
    const aciklama = [changeSummary, pozisyonForm.aciklama.trim()].filter(Boolean).join(" | ");

    try {
      const updated = await updatePersonel(selectedSurecPersonel.id, {
        departman_id: parsePozisyonId(pozisyonForm.departmanId),
        gorev_id: parsePozisyonId(pozisyonForm.gorevId),
        bagli_amir_id: parsePozisyonId(pozisyonForm.bagliAmirId),
        personel_tipi_id: parsePozisyonId(pozisyonForm.personelTipiId) ?? undefined,
        effective_date: pozisyonForm.effectiveDate
      });

      await createSurec({
        personel_id: selectedSurecPersonel.id,
        surec_turu: "POZISYON_DEGISTI",
        baslangic_tarihi: pozisyonForm.effectiveDate,
        aciklama
      });

      try {
        await refetchSurecCachesForPersonel(selectedSurecPersonel.id);
      } catch {
        /* Önbellek yenilemesi başarısız. */
      }

      commitPersonelUpdateToCaches(updated);
      setPersoneller((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
      setPozisyonForm(createPozisyonFormFromPersonel(updated));
      setPozisyonInfo("Pozisyon güncellendi.");
    } catch (error) {
      setPozisyonError(getApiErrorMessage(error, "Pozisyon güncellenemedi."));
    } finally {
      setPozisyonSubmitting(false);
    }
  }

  function resetSurecEditor() {
    setEditingSurec(null);
    setSurecError(null);
    setSurecInfo(null);
    setActivePersonelTab("genel");
    setDevamsizlikSubId(null);
    setSurecForm(resetSurecFormKeepingPersonel(surecForm.personelId));
  }

  const {
    showGatewayMessage,
    gatewayActionLabel,
    gatewayInfoMessage,
    handleGatewayReturn
  } = useKayitGatewayIntent({ activeTab, initialIntent, initialReturnTo, onClose });

  const hasInitialSurecPersonel = typeof initialSurecPersonelId === "string" && initialSurecPersonelId.length > 0;
  const classicSurecFormLayout = editingSurec !== null || hasInitialSurecPersonel;

  const surecWorkspaceGridClassName = [
    "surec-workspace-grid",
    !classicSurecFormLayout && activePersonelTab !== "genel" ? "surec-workspace-grid--islem-modu" : ""
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <div
      className={`kayit-workspace${activeTab === "yeni-kayit" ? " kayit-workspace--personel-kayit" : ""}${
        activeTab === "surec" && !classicSurecFormLayout && !selectedSurecPersonel ? " kayit-workspace--surec-search" : ""
      }`}
    >
      <KayitSurecTabHeader activeTab={activeTab} onTabChange={onTabChange} />

      {activeTab === "surec" && !classicSurecFormLayout && !selectedSurecPersonel ? (
        <div className="surec-workspace-toolbar" ref={surecSearchToolbarRef}>
          <div className={`surec-workspace-search-field${surecSearchExpanded ? " is-expanded" : ""}`}>
            <input
              ref={surecPersonelSearchInputRef}
              id="kayit-surec-personel-search-input"
              data-testid="kayit-surec-personel-search-input"
              className="form-input surec-workspace-search-input"
              type="search"
              value={surecPersonelSearch}
              onChange={(event) => {
                const nextValue = event.target.value;
                setSurecPersonelSearch(nextValue);

                if (nextValue.trim() && !selectedSurecPersonel) {
                  setSurecPersonelPickerOpen(true);
                }
              }}
              onKeyDown={(event) => {
                if (event.key === "Escape") {
                  setSurecSearchExpanded(false);
                  setSurecPersonelPickerOpen(false);
                }
              }}
              placeholder="Personel ara"
              aria-label="Personel ara"
              tabIndex={surecSearchExpanded ? 0 : -1}
            />
          </div>
          <button
            type="button"
            data-testid="kayit-surec-personel-search-toggle"
            className="surec-workspace-search-toggle"
            aria-expanded={surecSearchExpanded}
            aria-controls="kayit-surec-personel-search-input"
            aria-label={surecSearchExpanded ? "Aramayı kapat" : "Personel ara"}
            onClick={toggleSurecSearchExpanded}
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
                {showGatewayMessage ? (
                  <KayitGatewayRedirectPanel
                    infoMessage={gatewayInfoMessage}
                    actionLabel={gatewayActionLabel}
                    onReturn={handleGatewayReturn}
                    onClose={onClose}
                  />
                ) : (
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
                      />
                    </form>
                    {personelInfo ? <p className="workspace-success">{personelInfo}</p> : null}
                    <div className="universal-btn-group workspace-form-actions">
                      <button type="submit" form={primaryFormId} className="universal-btn-save" disabled={personelSubmitting}>
                        {primaryActionLabel}
                      </button>
                      <button type="button" className="universal-btn-cancel" onClick={onClose}>
                        Vazgeç
                      </button>
                    </div>
                  </>
                )}
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
                    {!selectedSurecPersonel ? (
                      <>
                        <div className="surec-personel-picker" ref={surecPersonelPickerRef}>
                          {personelOptions.length > 0 ? (
                            <div
                              className="surec-personel-combobox form-section"
                              onKeyDownCapture={handleSurecPersonelComboboxKeyDownCapture}
                            >
                              <label className="form-label" id="surec-personel-combobox-label">
                                Personel
                              </label>
                              <button
                                type="button"
                                className="form-input surec-personel-combobox-trigger"
                                role="combobox"
                                aria-labelledby="surec-personel-combobox-label"
                                aria-expanded={surecPersonelPickerOpen}
                                aria-controls="surec-personel-combobox-list"
                                onClick={() => {
                                  setSurecPersonelPickerOpen((isOpen) => {
                                    const next = !isOpen;

                                    if (next) {
                                      setSurecSearchExpanded(true);
                                    }

                                    return next;
                                  });
                                }}
                              >
                                <span>{selectedSurecPersonelLabel}</span>
                                <span aria-hidden="true">⌄</span>
                              </button>

                              {surecPersonelPickerOpen ? (
                                <div className="surec-personel-combobox-panel" id="surec-personel-combobox-list">
                                  <div className="surec-personel-combobox-options" role="listbox" aria-label="Personel listesi">
                                    {filteredSurecPersonelOptions.length > 0 ? (
                                      filteredSurecPersonelOptions.map((option) => (
                                        <button
                                          key={option.value}
                                          type="button"
                                          role="option"
                                          aria-selected={surecForm.personelId === option.value}
                                          className={`surec-personel-combobox-option${surecForm.personelId === option.value ? " is-active" : ""}`}
                                          onClick={() => selectSurecPersonel(option.value)}
                                        >
                                          {option.label}
                                        </button>
                                      ))
                                    ) : (
                                      <p className="workspace-empty-hint">Aramaya uygun personel bulunamadı.</p>
                                    )}
                                  </div>
                                </div>
                              ) : null}
                            </div>
                          ) : (
                            <p className="workspace-empty-hint">Personel listesi yüklenemedi veya boş.</p>
                          )}
                        </div>

                      </>
                    ) : (
                      <div className="surec-person-shell">
                        <div className="surec-person-tabs" role="tablist" aria-label="Personel işlem sekmeleri">
                          {PERSONEL_SUREC_TABS.map((tab) => {
                            const isActive = activePersonelTab === tab.id;

                            return (
                              <button
                                key={tab.id}
                                type="button"
                                role="tab"
                                data-testid={`kayit-surec-subtab-${tab.id}`}
                                aria-selected={isActive}
                                className={`surec-person-tab${isActive ? " is-active" : ""}${tab.id === "izin-devamsizlik" ? " surec-shell-action-tile" : ""}`}
                                onClick={() => selectPersonelTab(tab.id)}
                              >
                                {tab.label}
                              </button>
                            );
                          })}
                        </div>

                        {activePersonelTab === "genel" ? (
                          <div className="surec-person-general-panel">
                            <div className="surec-person-general-head">
                              <div>
                                <p className="surec-shell-summary-kicker">Genel bilgiler</p>
                                <h4 className="surec-person-general-title">
                                  {selectedSurecPersonel.ad} {selectedSurecPersonel.soyad}
                                </h4>
                              </div>
                              <div className="surec-person-photo-box" aria-label="Personel fotoğrafı">
                                <div className="surec-person-photo-avatar" aria-hidden="true">
                                  {getPersonelInitials(selectedSurecPersonel)}
                                </div>
                                <button type="button" className="surec-person-photo-action" disabled>
                                  Fotoğraf yükle
                                </button>
                              </div>
                            </div>

                            <div className="surec-person-general-columns">
                              {selectedPersonelGeneralColumns.map((column, columnIndex) => (
                                <section key={`personel-general-column-${columnIndex}`} className="surec-person-general-column">
                                  <div className="surec-shell-summary-grid">
                                    {column.items.map((item) => (
                                      <div key={`${columnIndex}-${item.label}`} className="surec-shell-summary-item">
                                        <span className="surec-shell-summary-label">{item.label}</span>
                                        <strong className="surec-shell-summary-value">{item.value}</strong>
                                      </div>
                                    ))}
                                  </div>
                                </section>
                              ))}
                            </div>
                          </div>
                        ) : null}

                        {activePersonelTab === "izin-devamsizlik" ? (
                          isSelectedPersonelPasif ? (
                            <div className="surec-person-placeholder">
                              <strong>İzin / Devamsızlık</strong>
                              <p>Bu personel pasif; izin/devamsızlık kaydı eklenmez.</p>
                            </div>
                          ) : (
                            <div className="surec-shell-panel">
                              <div className="surec-devamsizlik-tiles" role="group" aria-label="İzin ve yokluk işlemleri">
                                {DEVAMSIZLIK_SUB_CARDS.map((card) => {
                                  const isActive = devamsizlikSubId === card.id;

                                  return (
                                    <button
                                      key={card.id}
                                      type="button"
                                      className={`surec-devamsizlik-tile${isActive ? " is-active" : ""}`}
                                      onClick={() => selectDevamsizlikSubCard(card.id)}
                                    >
                                      <span className="surec-devamsizlik-tile-title">{card.title}</span>
                                      <span className="surec-devamsizlik-tile-desc">{card.description}</span>
                                      <span className="surec-devamsizlik-tile-status">{isActive ? "Seçildi" : "Seç"}</span>
                                    </button>
                                  );
                                })}
                              </div>

                              {devamsizlikSubId ? (
                                <>
                                  <form id={KAYIT_SUREC_SUREC_FORM_ID} className="workspace-form" onSubmit={handleSurecSubmit}>
                                    <SurecFormFields
                                      form={surecForm}
                                      setForm={setSurecForm}
                                      surecTuruOptions={surecTuruOptions}
                                      personelOptions={personelOptions}
                                      showPersonelField={false}
                                      showSurecTuruField={!hideSurecTuruFieldInShell}
                                      altTurField={activeDevamsizlikAltTurField}
                                      useOperationControls
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
                                      Kaydet
                                    </button>
                                    <button type="button" className="universal-btn-cancel" onClick={onClose}>
                                      Vazgeç
                                    </button>
                                  </div>
                                </>
                              ) : null}
                            </div>
                          )
                        ) : null}

                        {activePersonelTab === "pozisyon" ? (
                          isSelectedPersonelPasif ? (
                            <div className="surec-person-placeholder">
                              <strong>Pozisyon</strong>
                              <p>Bu personel pasif; pozisyon değişikliği yapılamaz.</p>
                            </div>
                          ) : canSubmitPozisyon ? (
                            <div className="surec-position-panel">
                              <form className="workspace-form surec-position-form" onSubmit={handlePozisyonSubmit}>
                                <div className="surec-position-grid">
                                  <KayitSurecPozisyonReferencePicker
                                    label="Bölüm"
                                    name="pozisyon-departman"
                                    value={pozisyonForm.departmanId}
                                    options={refs.departmanOptions}
                                    isOpen={openPozisyonPicker === "departman"}
                                    onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "departman" : null)}
                                    onChange={(value) => setPozisyonForm((prev) => ({ ...prev, departmanId: value }))}
                                    required
                                  />
                                  <KayitSurecPozisyonReferencePicker
                                    label="Görev / Unvan"
                                    name="pozisyon-gorev"
                                    value={pozisyonForm.gorevId}
                                    options={refs.gorevOptions}
                                    isOpen={openPozisyonPicker === "gorev"}
                                    onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "gorev" : null)}
                                    onChange={(value) => setPozisyonForm((prev) => ({ ...prev, gorevId: value }))}
                                    required
                                  />
                                  <KayitSurecPozisyonReferencePicker
                                    label="Bağlı Amir"
                                    name="pozisyon-bagli-amir"
                                    value={pozisyonForm.bagliAmirId}
                                    options={refs.bagliAmirOptions}
                                    isOpen={openPozisyonPicker === "bagli-amir"}
                                    onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "bagli-amir" : null)}
                                    onChange={(value) => setPozisyonForm((prev) => ({ ...prev, bagliAmirId: value }))}
                                  />
                                  <KayitSurecPozisyonReferencePicker
                                    label="Çalışma Tipi"
                                    name="pozisyon-personel-tipi"
                                    value={pozisyonForm.personelTipiId}
                                    options={refs.personelTipiOptions}
                                    isOpen={openPozisyonPicker === "personel-tipi"}
                                    onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "personel-tipi" : null)}
                                    onChange={(value) => setPozisyonForm((prev) => ({ ...prev, personelTipiId: value }))}
                                    required
                                  />
                                </div>

                                <FormField
                                  label="Geçerlilik Tarihi"
                                  name="pozisyon-effective-date"
                                  type="date"
                                  value={pozisyonForm.effectiveDate}
                                  onChange={(value) => setPozisyonForm((prev) => ({ ...prev, effectiveDate: value }))}
                                  required={hasPozisyonDiff}
                                />

                                <FormField
                                  label="Açıklama"
                                  name="pozisyon-aciklama"
                                  as="textarea"
                                  value={pozisyonForm.aciklama}
                                  onChange={(value) => setPozisyonForm((prev) => ({ ...prev, aciklama: value }))}
                                  placeholder="Değişiklik notu"
                                  rows={2}
                                />

                                {pozisyonError ? <p className="workspace-error">{pozisyonError}</p> : null}
                                {pozisyonInfo ? <p className="workspace-success">{pozisyonInfo}</p> : null}

                                <div className="universal-btn-group workspace-form-actions">
                                  <button
                                    type="submit"
                                    className="universal-btn-save"
                                    disabled={pozisyonSubmitting || !hasPozisyonDiff}
                                  >
                                    Kaydet
                                  </button>
                                  <button
                                    type="button"
                                    className="universal-btn-cancel"
                                    onClick={() => setPozisyonForm(createPozisyonFormFromPersonel(selectedSurecPersonel))}
                                  >
                                    Vazgeç
                                  </button>
                                </div>
                              </form>
                            </div>
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Pozisyon</strong>
                              <p>
                                Bu işlem için yetkin yok. Pozisyon değişikliği personel kartını günceller ve süreç kaydı
                                oluşturur.
                              </p>
                            </div>
                          )
                        ) : null}

                        {activePersonelTab === "mali" ? (
                          selectedSurecPersonel ? (
                            isSelectedPersonelPasif ? (
                              <div className="surec-person-placeholder">
                                <strong>Mali İşlemler</strong>
                                <p>Bu personel pasif; mali kayıt eklenmez.</p>
                              </div>
                            ) : canCreateFinans ? (
                              <KayitSurecPersonelFinansPanel
                                title="Mali İşlemler"
                                personelLabel={selectedSurecPersonelLabel}
                                formId={KAYIT_SUREC_MALI_FORM_ID}
                                fieldNamePrefix="kayit-mali"
                                fields={maliFields}
                                setFields={setMaliFields}
                                onSubmit={createPersonelFinansHandler}
                                errorMessage={maliCreateErrorMessage}
                                isSubmitting={isMaliSubmitting}
                              />
                            ) : (
                              <div className="surec-person-placeholder">
                                <strong>Mali İşlemler</strong>
                                <p>Bu işlem için yetkin yok. Mali kayıtları Finans ekranından yönet.</p>
                              </div>
                            )
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Mali İşlemler</strong>
                              <p>Mali işlemler için önce personel seç.</p>
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
                              <div className="universal-btn-group workspace-form-actions">
                                <button
                                  type="submit"
                                  form={KAYIT_SUREC_ZIMMET_FORM_ID}
                                  className="universal-btn-save"
                                  disabled={isZimmetSubmitting || !canCreateZimmet}
                                >
                                  {isZimmetSubmitting ? "Kaydediliyor..." : "Kaydet"}
                                </button>
                              </div>
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

                                  <div className="universal-btn-group workspace-form-actions">
                                    <button
                                      type="submit"
                                      form={primaryFormId}
                                      className="universal-btn-save"
                                      disabled={surecSubmitting}
                                    >
                                      Kaydet
                                    </button>
                                    <button type="button" className="universal-btn-cancel" onClick={onClose}>
                                      Vazgeç
                                    </button>
                                  </div>
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
                            ) : canCreateSurec ? (
                              <div>
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
                                <div className="universal-btn-group workspace-form-actions">
                                  <button
                                    type="submit"
                                    form={KAYIT_SUREC_BELGELER_FORM_ID}
                                    className="universal-btn-save"
                                    disabled={belgeDurumSaving || belgeDurumLoading}
                                  >
                                    {belgeDurumSaving ? "Kaydediliyor..." : "Kaydet"}
                                  </button>
                                </div>

                                <div className="belge-kayit-section-divider" aria-hidden="true" />

                                <KayitBelgeKayitlariSection
                                  personelId={selectedSurecPersonel.id}
                                  personelLabel={selectedSurecPersonelLabel}
                                  isPersonelPasif={false}
                                  canWrite={canCreateSurec}
                                  isActive={activePersonelTab === "belgeler"}
                                />
                              </div>
                            ) : (
                              <div className="surec-person-placeholder">
                                <strong>Belgeler</strong>
                                <p>Bu işlem için yetkin yok.</p>
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
                    )}
                  </>
                )}
              </>
            ) : null}
          </section>

        </div>
      )}
    </div>
  );
}
