import { useEffect, useMemo, useState, type FormEvent } from "react";
import { useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import type { KayitTab } from "../../../components/main-menu/MainMenu";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import { createPersonel, fetchPersonellerList, updatePersonel } from "../../../api/personeller.api";
import {
  fetchBagliAmirOptions,
  fetchDepartmanOptions,
  fetchGorevOptions,
  fetchPersonelTipiOptions,
  fetchPrimKuraliOptions,
  fetchSurecTuruOptions,
  fetchUcretTipiOptions
} from "../../../api/referans.api";
import { createSurec, updateSurec } from "../../../api/surecler.api";
import { PersonelCreateFields } from "../../../features/personeller/components/PersonelCreateFields";
import { PersonelZimmetCreateForm } from "../../../features/personeller/components/PersonelZimmetCreateForm";
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
import type { Personel } from "../../../types/personel";
import type { IdOption, KeyOption } from "../../../types/referans";
import type { Surec } from "../../../types/surec";

export const KAYIT_SUREC_PERSONEL_FORM_ID = "kayit-surec-personel-form";
export const KAYIT_SUREC_SUREC_FORM_ID = "kayit-surec-surec-form";
export const KAYIT_SUREC_ZIMMET_FORM_ID = "kayit-surec-zimmet-form";
export const KAYIT_SUREC_MALI_FORM_ID = "kayit-surec-mali-form";

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

function normalizeEnumKey(value: string) {
  return value.trim().replace(/-/g, "_").toUpperCase();
}

function formatPersonelLabel(personel: Personel) {
  const meta = [personel.departman_adi, personel.gorev_adi].filter(Boolean).join(" • ");
  return meta ? `${personel.ad} ${personel.soyad} • ${meta}` : `${personel.ad} ${personel.soyad}`;
}

function normalizePersonelSearchText(value: string | number | null | undefined) {
  return String(value ?? "").toLocaleLowerCase("tr-TR").trim();
}

function resetSurecFormKeepingPersonel(personelId: string) {
  return {
    ...INITIAL_SUREC_FORM,
    personelId
  };
}

type DevamsizlikSubId = "izin" | "rapor" | "is_kazasi" | "izinsiz" | "gec" | "erken";
type PersonelSurecTab =
  | "genel"
  | "izin-devamsizlik"
  | "pozisyon"
  | "belgeler"
  | "mali"
  | "zimmet"
  | "ceza"
  | "ayrilma";

type PozisyonFormState = {
  departmanId: string;
  gorevId: string;
  bagliAmirId: string;
  personelTipiId: string;
  effectiveDate: string;
  aciklama: string;
};

type PozisyonReferencePickerProps = {
  label: string;
  name: string;
  value: string;
  options: IdOption[];
  isOpen: boolean;
  required?: boolean;
  onChange: (value: string) => void;
  onOpenChange: (isOpen: boolean) => void;
};

type DevamsizlikSubCard = {
  id: DevamsizlikSubId;
  title: string;
  description: string;
  candidateKeys: string[];
};

type DevamsizlikAltTurConfig = {
  label: string;
  options: Array<{ value: string; label: string }>;
};

const DEVAMSIZLIK_SUB_CARDS: DevamsizlikSubCard[] = [
  {
    id: "izin",
    title: "İzin",
    description: "Planlı ya da onaylı izin kaydı",
    candidateKeys: ["IZIN"]
  },
  {
    id: "rapor",
    title: "Rapor",
    description: "Hastalık veya istirahat raporu kaydı",
    candidateKeys: ["RAPOR"]
  },
  {
    id: "is_kazasi",
    title: "İş Kazası",
    description: "İş kazasına bağlı devamsızlık kaydı",
    candidateKeys: ["IS_KAZASI"]
  },
  {
    id: "izinsiz",
    title: "İzinsiz Gelmedi",
    description: "Mazeretsiz işe gelmeme kaydı",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "gec",
    title: "Geç Geldi",
    description: "Mesai başlangıcından sonra giriş kaydı",
    candidateKeys: ["DEVAMSIZLIK"]
  },
  {
    id: "erken",
    title: "Erken Çıktı",
    description: "Mesai bitiminden önce çıkış kaydı",
    candidateKeys: ["DEVAMSIZLIK"]
  }
];

const PERSONEL_SUREC_TABS: Array<{ id: PersonelSurecTab; label: string }> = [
  { id: "genel", label: "Genel" },
  { id: "izin-devamsizlik", label: "İzin / Devamsızlık" },
  { id: "pozisyon", label: "Pozisyon" },
  { id: "belgeler", label: "Belgeler" },
  { id: "mali", label: "Mali İşlemler" },
  { id: "zimmet", label: "Zimmet" },
  { id: "ceza", label: "Ceza" },
  { id: "ayrilma", label: "Ayrılma" }
];

const DEVAMSIZLIK_ALT_TUR_CONFIG: Record<DevamsizlikSubId, DevamsizlikAltTurConfig> = {
  izin: {
    label: "İzin Türü",
    options: [
      { value: "YILLIK_IZIN", label: "Yıllık" },
      { value: "MAZERET_IZNI", label: "Mazeret" },
      { value: "UCRETSIZ_IZIN", label: "Ücretsiz" }
    ]
  },
  rapor: {
    label: "Rapor Türü",
    options: [{ value: "RAPORLU", label: "Raporlu" }]
  },
  is_kazasi: {
    label: "Kayıt Türü",
    options: [{ value: "IS_KAZASI_BILDIRIMI", label: "İş kazası bildirimi" }]
  },
  izinsiz: {
    label: "Gelmedi Türü",
    options: [{ value: "IZINSIZ_GELMEDI", label: "İzinsiz gelmedi" }]
  },
  gec: {
    label: "Geç Kalma Türü",
    options: [
      { value: "MAZERETLI_GEC_GELDI", label: "Mazeretli geç geldi" },
      { value: "MAZERETSIZ_GEC_GELDI", label: "Mazeretsiz geç geldi" }
    ]
  },
  erken: {
    label: "Erken Çıkış Türü",
    options: [
      { value: "MAZERETLI_ERKEN_CIKTI", label: "Mazeretli erken çıktı" },
      { value: "MAZERETSIZ_ERKEN_CIKTI", label: "Mazeretsiz erken çıktı" }
    ]
  }
};

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

function formatGeneralField(value: string | number | null | undefined) {
  if (value === null || value === undefined) {
    return "-";
  }

  const trimmed = String(value).trim();
  return trimmed.length > 0 ? trimmed : "-";
}

function formatMoneyField(value: number | null | undefined) {
  if (typeof value !== "number" || !Number.isFinite(value)) {
    return "-";
  }

  return new Intl.NumberFormat("tr-TR", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  }).format(value);
}

function getPersonelInitials(personel: Personel) {
  const adInitial = personel.ad.trim().charAt(0);
  const soyadInitial = personel.soyad.trim().charAt(0);
  return `${adInitial}${soyadInitial}`.toLocaleUpperCase("tr-TR");
}

function toOptionalIdValue(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "";
}

function createPozisyonFormFromPersonel(personel: Personel | null): PozisyonFormState {
  return {
    departmanId: toOptionalIdValue(personel?.departman_id),
    gorevId: toOptionalIdValue(personel?.gorev_id),
    bagliAmirId: toOptionalIdValue(personel?.bagli_amir_id),
    personelTipiId: toOptionalIdValue(personel?.personel_tipi_id),
    effectiveDate: "",
    aciklama: ""
  };
}

function optionLabel(options: Array<{ id: number; label: string }>, value: string, fallback: string) {
  if (!value) {
    return "-";
  }

  const option = options.find((item) => String(item.id) === value);
  return option?.label ?? fallback;
}

function parsePozisyonId(value: string) {
  return value ? Number.parseInt(value, 10) : null;
}

function PozisyonReferencePicker({
  label,
  name,
  value,
  options,
  isOpen,
  required = false,
  onChange,
  onOpenChange
}: PozisyonReferencePickerProps) {
  const selectedLabel = optionLabel(options, value, "Seçiniz");

  return (
    <div className="form-section surec-position-picker">
      <label className="form-label" id={`${name}-label`}>
        {label}
      </label>
      <button
        type="button"
        className="form-input surec-position-picker-trigger"
        role="combobox"
        aria-labelledby={`${name}-label`}
        aria-expanded={isOpen}
        aria-controls={`${name}-panel`}
        onClick={() => onOpenChange(!isOpen)}
      >
        <span>{selectedLabel === "-" ? "Seçiniz" : selectedLabel}</span>
        <span aria-hidden="true">⌄</span>
      </button>

      {isOpen ? (
        <div className="surec-position-picker-panel" id={`${name}-panel`}>
          {!required ? (
            <button
              type="button"
              className={`surec-position-picker-option${value === "" ? " is-active" : ""}`}
              onClick={() => {
                onChange("");
                onOpenChange(false);
              }}
            >
              Seçiniz
            </button>
          ) : null}
          {options.map((option) => {
            const optionValue = String(option.id);
            const isActive = value === optionValue;

            return (
              <button
                key={`${name}-${option.id}`}
                type="button"
                className={`surec-position-picker-option${isActive ? " is-active" : ""}`}
                onClick={() => {
                  onChange(optionValue);
                  onOpenChange(false);
                }}
              >
                {option.label}
              </button>
            );
          })}
        </div>
      ) : null}
    </div>
  );
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
  const canCreateZimmet = hasPermission("personeller.update");
  const canCreateFinans = hasPermission("finans.create");
  const canEditSurec = hasPermission("surecler.update");

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
  const [surecPersonelSearch, setSurecPersonelSearch] = useState("");
  const [surecPersonelPickerOpen, setSurecPersonelPickerOpen] = useState(false);

  const [activePersonelTab, setActivePersonelTab] = useState<PersonelSurecTab>("genel");
  const [devamsizlikSubId, setDevamsizlikSubId] = useState<DevamsizlikSubId | null>(null);
  const [pozisyonForm, setPozisyonForm] = useState<PozisyonFormState>(createPozisyonFormFromPersonel(null));
  const [pozisyonSubmitting, setPozisyonSubmitting] = useState(false);
  const [pozisyonError, setPozisyonError] = useState<string | null>(null);
  const [pozisyonInfo, setPozisyonInfo] = useState<string | null>(null);
  const [openPozisyonPicker, setOpenPozisyonPicker] = useState<string | null>(null);

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

  const personelMap = useMemo(() => new Map(personeller.map((personel) => [personel.id, personel])), [personeller]);

  const selectedSurecPersonel = useMemo(() => {
    const personelId = Number.parseInt(surecForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [personelMap, surecForm.personelId]);

  const zimmetPersonelIdForHook = selectedSurecPersonel?.id ?? 0;
  const zimmetPersonelValid = Boolean(selectedSurecPersonel);
  const {
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage
  } = usePersonelZimmetCreate(zimmetPersonelIdForHook, zimmetPersonelValid, canCreateZimmet, {
    canSubmit: zimmetPersonelValid
  });

  const {
    maliFields,
    setMaliFields,
    createPersonelFinansHandler,
    isMaliSubmitting,
    maliCreateErrorMessage
  } = usePersonelFinansCreate(zimmetPersonelIdForHook, zimmetPersonelValid, canCreateFinans, {
    canSubmit: zimmetPersonelValid
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
          { label: "Ücret Tipi", value: formatGeneralField(selectedSurecPersonel.ucret_tipi_adi) },
          { label: "Maaş Tutarı", value: formatMoneyField(selectedSurecPersonel.maas_tutari) },
          { label: "Prim Kuralı", value: formatGeneralField(selectedSurecPersonel.prim_kurali_adi) }
        ]
      }
    ];
  }, [selectedSurecPersonel]);

  const resolvedDevamsizlikSurecTuruKey = useMemo(() => {
    if (!devamsizlikSubId) {
      return null;
    }

    return resolveDevamsizlikSurecTuru(devamsizlikSubId);
  }, [devamsizlikSubId, surecTuruOptions]);

  const useShellSurecLayout = editingSurec === null;

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

    setActivePersonelTab("genel");
    setDevamsizlikSubId(null);
    setSurecForm((prev) => resetSurecFormKeepingPersonel(prev.personelId));
    setSurecError(null);
    setSurecInfo(null);
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
  }

  function openDevamsizlikTab(defaultSubId: DevamsizlikSubId | null = "izin") {
    const altTurConfig = defaultSubId ? DEVAMSIZLIK_ALT_TUR_CONFIG[defaultSubId] : null;
    setSurecError(null);
    setSurecInfo(null);
    setActivePersonelTab("izin-devamsizlik");
    setDevamsizlikSubId(defaultSubId);
    setSurecForm((prev) => ({
      ...resetSurecFormKeepingPersonel(prev.personelId),
      surecTuru: defaultSubId ? resolveDevamsizlikSurecTuru(defaultSubId) ?? "" : "",
      altTur: altTurConfig?.options[0]?.value ?? ""
    }));
  }

  function selectPersonelTab(tabId: PersonelSurecTab) {
    setActivePersonelTab(tabId);
    setSurecError(null);
    setSurecInfo(null);
    setPozisyonError(null);
    setPozisyonInfo(null);
    setOpenPozisyonPicker(null);

    if (tabId === "izin-devamsizlik") {
      const nextSubId = devamsizlikSubId ?? "izin";
      const altTurConfig = DEVAMSIZLIK_ALT_TUR_CONFIG[nextSubId];
      setDevamsizlikSubId(nextSubId);
      setSurecForm((prev) => ({
        ...resetSurecFormKeepingPersonel(prev.personelId),
        surecTuru: resolveDevamsizlikSurecTuru(nextSubId) ?? "",
        altTur: altTurConfig.options[0]?.value ?? ""
      }));
      return;
    }

    setDevamsizlikSubId(null);
  }

  function selectDevamsizlikSubCard(id: DevamsizlikSubId) {
    setDevamsizlikSubId(id);
    const resolvedKey = resolveDevamsizlikSurecTuru(id);
    const altTurConfig = DEVAMSIZLIK_ALT_TUR_CONFIG[id];

    setSurecForm((prev) => ({
      ...prev,
      surecTuru: resolvedKey ?? "",
      altTur: altTurConfig.options[0]?.value ?? ""
    }));
  }

  function resolveDevamsizlikSurecTuru(id: DevamsizlikSubId) {
    const card = DEVAMSIZLIK_SUB_CARDS.find((item) => item.id === id);
    if (!card) {
      return null;
    }

    return resolveSurecTuruKeyFromOptions(card.candidateKeys, surecTuruOptions);
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
    } catch (error) {
      setSurecError(error instanceof Error ? error.message : "Süreç kaydı kaydedilemedi.");
    } finally {
      setSurecSubmitting(false);
    }
  }

  async function handlePozisyonSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    if (!selectedSurecPersonel || pozisyonSubmitting) {
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

      setPersoneller((prev) => prev.map((item) => (item.id === updated.id ? updated : item)));
      setPozisyonForm(createPozisyonFormFromPersonel(updated));
      setPozisyonInfo("Pozisyon güncellendi.");
    } catch (error) {
      setPozisyonError(error instanceof Error ? error.message : "Pozisyon güncellenemedi.");
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

  const hasInitialSurecPersonel = typeof initialSurecPersonelId === "string" && initialSurecPersonelId.length > 0;
  const classicSurecFormLayout = editingSurec !== null || hasInitialSurecPersonel;

  const surecWorkspaceGridClassName = [
    "surec-workspace-grid",
    !classicSurecFormLayout && activePersonelTab !== "genel" ? "surec-workspace-grid--islem-modu" : ""
  ]
    .filter(Boolean)
    .join(" ");

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
        <div className={surecWorkspaceGridClassName}>
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
                    {!selectedSurecPersonel ? (
                      <>
                        <div className="surec-personel-picker">
                          <div className="surec-personel-picker-head">
                            <strong>Personel seçimi</strong>
                          </div>

                          {personelOptions.length > 0 ? (
                            <div className="surec-personel-combobox form-section">
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
                                onClick={() => setSurecPersonelPickerOpen((isOpen) => !isOpen)}
                              >
                                <span>{selectedSurecPersonelLabel}</span>
                                <span aria-hidden="true">⌄</span>
                              </button>

                              {surecPersonelPickerOpen ? (
                                <div className="surec-personel-combobox-panel" id="surec-personel-combobox-list">
                                  <input
                                    className="form-input surec-personel-combobox-search"
                                    type="search"
                                    value={surecPersonelSearch}
                                    onChange={(event) => setSurecPersonelSearch(event.target.value)}
                                    placeholder="Personel ara"
                                    autoFocus
                                  />
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
                        ) : null}

                        {activePersonelTab === "pozisyon" ? (
                          <div className="surec-position-panel">
                            <form className="workspace-form surec-position-form" onSubmit={handlePozisyonSubmit}>
                              <div className="surec-position-grid">
                                <PozisyonReferencePicker
                                  label="Bölüm"
                                  name="pozisyon-departman"
                                  value={pozisyonForm.departmanId}
                                  options={refs.departmanOptions}
                                  isOpen={openPozisyonPicker === "departman"}
                                  onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "departman" : null)}
                                  onChange={(value) => setPozisyonForm((prev) => ({ ...prev, departmanId: value }))}
                                  required
                                />
                                <PozisyonReferencePicker
                                  label="Görev / Unvan"
                                  name="pozisyon-gorev"
                                  value={pozisyonForm.gorevId}
                                  options={refs.gorevOptions}
                                  isOpen={openPozisyonPicker === "gorev"}
                                  onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "gorev" : null)}
                                  onChange={(value) => setPozisyonForm((prev) => ({ ...prev, gorevId: value }))}
                                  required
                                />
                                <PozisyonReferencePicker
                                  label="Bağlı Amir"
                                  name="pozisyon-bagli-amir"
                                  value={pozisyonForm.bagliAmirId}
                                  options={refs.bagliAmirOptions}
                                  isOpen={openPozisyonPicker === "bagli-amir"}
                                  onOpenChange={(isOpen) => setOpenPozisyonPicker(isOpen ? "bagli-amir" : null)}
                                  onChange={(value) => setPozisyonForm((prev) => ({ ...prev, bagliAmirId: value }))}
                                />
                                <PozisyonReferencePicker
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
                        ) : null}

                        {activePersonelTab === "mali" ? (
                          selectedSurecPersonel ? (
                            canCreateFinans ? (
                              <div>
                                <p className="workspace-empty-hint">
                                  <strong>Mali işlem</strong> — {selectedSurecPersonelLabel}
                                </p>
                                <form
                                  id={KAYIT_SUREC_MALI_FORM_ID}
                                  className="finans-form-grid"
                                  onSubmit={createPersonelFinansHandler}
                                >
                                  <FormField
                                    label="Dönem"
                                    name="kayit-mali-donem"
                                    type="month"
                                    value={maliFields.donem}
                                    onChange={(value) => setMaliFields((prev) => ({ ...prev, donem: value }))}
                                    required
                                  />
                                  <FormField
                                    label="Kalem Turu"
                                    name="kayit-mali-kalem"
                                    value={maliFields.kalemTuru}
                                    onChange={(value) => setMaliFields((prev) => ({ ...prev, kalemTuru: value }))}
                                    required
                                  />
                                  <FormField
                                    label="Tutar"
                                    name="kayit-mali-tutar"
                                    type="number"
                                    min={0.01}
                                    step="0.01"
                                    value={maliFields.tutar}
                                    onChange={(value) => setMaliFields((prev) => ({ ...prev, tutar: value }))}
                                    required
                                  />
                                  <FormField
                                    label="Açıklama"
                                    name="kayit-mali-aciklama"
                                    value={maliFields.aciklama}
                                    onChange={(value) => setMaliFields((prev) => ({ ...prev, aciklama: value }))}
                                  />
                                  {maliCreateErrorMessage ? (
                                    <p className="finans-form-error">{maliCreateErrorMessage}</p>
                                  ) : null}
                                </form>
                                <div className="universal-btn-group workspace-form-actions">
                                  <button
                                    type="submit"
                                    form={KAYIT_SUREC_MALI_FORM_ID}
                                    className="universal-btn-save"
                                    disabled={isMaliSubmitting}
                                  >
                                    {isMaliSubmitting ? "Kaydediliyor..." : "Kaydet"}
                                  </button>
                                </div>
                              </div>
                            ) : (
                              <div className="surec-person-placeholder">
                                <strong>Mali İşlemler</strong>
                                <p>
                                  Finans kalemi oluşturmak için hesabınızda finans oluşturma yetkisi olmalıdır. Liste ve
                                  diğer finans işlemleri için Finans modülünü kullanın.
                                </p>
                              </div>
                            )
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Mali İşlemler</strong>
                              <p>Finans kaydı eklemek için önce personel seçin.</p>
                            </div>
                          )
                        ) : activePersonelTab === "zimmet" ? (
                          selectedSurecPersonel ? (
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
                          ) : (
                            <div className="surec-person-placeholder">
                              <strong>Zimmet</strong>
                              <p>Zimmet eklemek için önce personel seçin.</p>
                            </div>
                          )
                        ) : ["belgeler", "ceza", "ayrilma"].includes(activePersonelTab) ? (
                          <div className="surec-person-placeholder">
                            <strong>{PERSONEL_SUREC_TABS.find((tab) => tab.id === activePersonelTab)?.label}</strong>
                            <p>Bu işlem ailesi merkezi akışa taşınacak. Şimdilik yerleşim sabitlendi.</p>
                          </div>
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
