import { useEffect, useMemo, useState, type FormEvent, type KeyboardEvent } from "react";
import { useNavigate, useSearchParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { createDepartmanOption, fetchDepartmanOptions } from "../../../api/referans.api";
import { createSurec, type CreateSurecPayload } from "../../../api/surecler.api";
import {
  createYonetimKullanici,
  createYonetimSube,
  deleteYonetimSube,
  fetchYonetimKullanicilari,
  fetchYonetimSubeleri,
  updateYonetimKullanici,
  updateYonetimSube
} from "../../../api/yonetim.api";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { isRealYonetimKullaniciApi } from "../../../lib/yonetim/kullanici-api-contract";
import type { UserRole } from "../../../types/auth";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import type {
  KayitDurumu,
  KullaniciTipi,
  UpsertYonetimKullaniciPayload,
  UpsertYonetimSubePayload,
  YonetimKullanici,
  YonetimSube
} from "../../../types/yonetim";

type ActiveTab = "kullanicilar" | "subeler";
type YonetimViewMode = "card" | "list";

function resolveYonetimActiveTab(tabParam: string | null): ActiveTab {
  const normalized = tabParam?.trim().toLowerCase() ?? "";
  if (normalized === "subeler" || normalized === "sube") {
    return "subeler";
  }
  return "kullanicilar";
}

type KullaniciFormState = {
  username: string;
  password: string;
  kullaniciTipi: KullaniciTipi;
  personelId: string;
  adSoyad: string;
  telefon: string;
  rol: UserRole;
  subeIds: number[];
  varsayilanSubeId: string;
  durum: KayitDurumu;
  notlar: string;
};

type SubeFormState = {
  kod: string;
  ad: string;
  departmanIds: number[];
  durum: KayitDurumu;
};

const ROLE_LABELS: Record<UserRole, string> = {
  GENEL_YONETICI: "Genel Yönetici",
  BOLUM_YONETICISI: "Bölüm Yöneticisi",
  BIRIM_AMIRI: "Birim Amiri",
  MUHASEBE: "Muhasebe"
};

const KULLANICI_TIPI_LABELS: Record<KullaniciTipi, string> = {
  IC_PERSONEL: "İç Personel",
  HARICI: "Harici"
};

const DURUM_LABELS: Record<KayitDurumu, string> = {
  AKTIF: "Aktif",
  PASIF: "Pasif"
};

const INITIAL_KULLANICI_FORM: KullaniciFormState = {
  username: "",
  password: "",
  kullaniciTipi: "IC_PERSONEL",
  personelId: "",
  adSoyad: "",
  telefon: "",
  rol: "BIRIM_AMIRI",
  subeIds: [],
  varsayilanSubeId: "",
  durum: "AKTIF",
  notlar: ""
};

const INITIAL_SUBE_FORM: SubeFormState = {
  kod: "",
  ad: "",
  departmanIds: [],
  durum: "AKTIF"
};

const YONETIM_KULLANICI_FORM_ID = "yonetim-kullanici-form";
const YONETIM_SUBE_FORM_ID = "yonetim-sube-form";
const REAL_KULLANICI_API_UNSUPPORTED_HINT = "Bu alan V1 canlı API'de desteklenmiyor.";
const BIRIM_AMIRI_ATANDI_SUREC_TURU = "BIRIM_AMIRI_ATANDI";
const BIRIM_AMIRI_ATAMASI_KALDIRILDI_SUREC_TURU = "BIRIM_AMIRI_ATAMASI_KALDIRILDI";
const SUBE_YETKISI_DEGISTI_SUREC_TURU = "SUBE_YETKISI_DEGISTI";

function IconList(props: { className?: string }) {
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
      <path d="M4 7h16M4 12h16M4 17h16" />
    </svg>
  );
}

function IconGrid(props: { className?: string }) {
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
      <rect x="3" y="3" width="7" height="7" rx="1" />
      <rect x="14" y="3" width="7" height="7" rx="1" />
      <rect x="3" y="14" width="7" height="7" rx="1" />
      <rect x="14" y="14" width="7" height="7" rx="1" />
    </svg>
  );
}

function YonetimViewToggle(props: {
  label: string;
  value: YonetimViewMode;
  onChange: (mode: YonetimViewMode) => void;
}) {
  const nextMode: YonetimViewMode = props.value === "card" ? "list" : "card";
  const nextLabel = props.value === "card" ? "Liste görünümüne geç" : "Kart görünümüne geç";

  return (
    <button
      type="button"
      className="yonetim-view-toggle"
      aria-label={`${props.label}: ${nextLabel}`}
      title={nextLabel}
      onClick={() => props.onChange(nextMode)}
    >
      {props.value === "card" ? <IconList /> : <IconGrid />}
    </button>
  );
}

function isActivationKey(event: KeyboardEvent<HTMLElement>) {
  return event.key === "Enter" || event.key === " ";
}

function roleOptions() {
  return Object.entries(ROLE_LABELS).map(([value, label]) => ({ value, label }));
}

function formatNameToken(value: string) {
  if (!value) {
    return "";
  }

  return value
    .split("-")
    .map((part) => {
      if (!part) {
        return "";
      }

      return `${part.charAt(0).toLocaleUpperCase("tr-TR")}${part.slice(1).toLocaleLowerCase("tr-TR")}`;
    })
    .join("-");
}

function formatAdSoyad(value: string) {
  const parts = value
    .trim()
    .split(/\s+/)
    .filter(Boolean);

  if (parts.length === 0) {
    return "";
  }

  if (parts.length === 1) {
    return formatNameToken(parts[0]);
  }

  const soyad = parts.pop() ?? "";
  const adlar = parts.map(formatNameToken).join(" ");
  return `${adlar} ${soyad.toLocaleUpperCase("tr-TR")}`.trim();
}

function normalizeTelefonDigits(value: string) {
  return value.replace(/\D+/g, "").slice(0, 11);
}

function formatTelefon(value: string) {
  const digits = normalizeTelefonDigits(value);
  if (!digits) {
    return "";
  }

  const parts = [digits.slice(0, 4), digits.slice(4, 7), digits.slice(7, 9), digits.slice(9, 11)].filter(Boolean);
  return parts.join(" ");
}

function statusOptions() {
  return Object.entries(DURUM_LABELS).map(([value, label]) => ({ value, label }));
}

function sortIdOptions(options: IdOption[]) {
  return [...options].sort((left, right) => left.label.localeCompare(right.label, "tr"));
}

function mergeIdOptions(current: IdOption[], incoming: IdOption[]) {
  const optionMap = new Map<number, IdOption>();
  [...current, ...incoming].forEach((item) => optionMap.set(item.id, item));
  return sortIdOptions(Array.from(optionMap.values()));
}

function userFormFromItem(item: YonetimKullanici): KullaniciFormState {
  return {
    username: item.username ?? "",
    password: "",
    kullaniciTipi: item.kullanici_tipi,
    personelId: item.personel_id != null ? String(item.personel_id) : "",
    adSoyad: formatAdSoyad(item.ad_soyad),
    telefon: formatTelefon(item.telefon ?? ""),
    rol: item.rol,
    subeIds: item.sube_ids,
    varsayilanSubeId: item.varsayilan_sube_id != null ? String(item.varsayilan_sube_id) : "",
    durum: item.durum,
    notlar: item.notlar ?? ""
  };
}

function subeFormFromItem(item: YonetimSube): SubeFormState {
  return {
    kod: item.kod,
    ad: item.ad,
    departmanIds: item.departman_ids,
    durum: item.durum
  };
}

function toKullaniciPayload(form: KullaniciFormState, isEdit: boolean): UpsertYonetimKullaniciPayload {
  const realKullaniciApi = isRealYonetimKullaniciApi();
  const adSoyad = formatAdSoyad(form.adSoyad);
  if (!adSoyad) {
    throw new Error("Ad soyad zorunludur.");
  }

  const username = form.username.trim();
  if (!username) {
    throw new Error("Kullanıcı adı zorunludur.");
  }

  if (!isEdit && !form.password.trim()) {
    throw new Error("Geçici şifre zorunludur.");
  }

  if (!realKullaniciApi && form.kullaniciTipi === "IC_PERSONEL" && !form.personelId) {
    throw new Error("İç personel kullanıcıları için personel seçimi zorunludur.");
  }

  if (form.varsayilanSubeId && !form.subeIds.includes(Number.parseInt(form.varsayilanSubeId, 10))) {
    throw new Error("Varsayılan şube, yetki verilen şubeler içinde olmalıdır.");
  }

  const payload: UpsertYonetimKullaniciPayload = {
    username,
    ad_soyad: adSoyad,
    kullanici_tipi: realKullaniciApi ? "HARICI" : form.kullaniciTipi,
    rol: form.rol,
    sube_ids: form.subeIds,
    varsayilan_sube_id: form.varsayilanSubeId ? Number.parseInt(form.varsayilanSubeId, 10) : null,
    durum: form.durum
  };

  if (!realKullaniciApi) {
    payload.telefon = normalizeTelefonDigits(form.telefon) || undefined;
    payload.personel_id = form.personelId ? Number.parseInt(form.personelId, 10) : null;
    payload.notlar = form.notlar.trim() || undefined;
  }

  if (form.password.trim()) {
    payload.password = form.password;
  }

  return payload;
}

function toSubePayload(form: SubeFormState): UpsertYonetimSubePayload {
  const kod = form.kod.trim().toUpperCase();
  const ad = form.ad.trim();

  if (!kod || !ad) {
    throw new Error("Şube kodu ve şube adı zorunludur.");
  }

  if (form.departmanIds.length === 0) {
    throw new Error("En az bir departman seçilmelidir.");
  }

  return {
    kod,
    ad,
    departman_ids: form.departmanIds,
    durum: form.durum
  };
}

function formatSubeScopeLabel(subeIds: number[], subeNameMap: Map<number, string>) {
  if (subeIds.length === 0) {
    return "Tüm Şubeler";
  }

  return subeIds.map((subeId) => subeNameMap.get(subeId) ?? `Şube ${subeId}`).join(", ");
}

function normalizeNumberArray(values: number[]) {
  return [...values].sort((left, right) => left - right);
}

function areSameNumberArrays(left: number[], right: number[]) {
  const normalizedLeft = normalizeNumberArray(left);
  const normalizedRight = normalizeNumberArray(right);

  if (normalizedLeft.length !== normalizedRight.length) {
    return false;
  }

  return normalizedLeft.every((value, index) => value === normalizedRight[index]);
}

function formatVarsayilanSubeLabel(value: number | null | undefined, subeNameMap: Map<number, string>) {
  if (value == null) {
    return "Tanımsız";
  }

  return subeNameMap.get(value) ?? `Şube ${value}`;
}

function buildYonetimSurecLogPayloads(
  previous: YonetimKullanici | null,
  payload: UpsertYonetimKullaniciPayload,
  subeNameMap: Map<number, string>
): CreateSurecPayload[] {
  const today = new Date().toISOString().slice(0, 10);
  const oldPersonelId = previous?.personel_id ?? null;
  const newPersonelId = payload.personel_id ?? null;
  const oldIsBirimAmiri = previous?.rol === "BIRIM_AMIRI";
  const newIsBirimAmiri = payload.rol === "BIRIM_AMIRI";
  const logs: CreateSurecPayload[] = [];

  if (oldIsBirimAmiri && oldPersonelId != null && (!newIsBirimAmiri || newPersonelId !== oldPersonelId)) {
    logs.push({
      personel_id: oldPersonelId,
      surec_turu: BIRIM_AMIRI_ATAMASI_KALDIRILDI_SUREC_TURU,
      baslangic_tarihi: today,
      aciklama: "Birim Amiri Ataması Kaldırıldı."
    });
  }

  if (newIsBirimAmiri && newPersonelId != null && (!oldIsBirimAmiri || newPersonelId !== oldPersonelId)) {
    logs.push({
      personel_id: newPersonelId,
      surec_turu: BIRIM_AMIRI_ATANDI_SUREC_TURU,
      baslangic_tarihi: today,
      aciklama: "Birim Amiri Olarak Atandı."
    });
  }

  const scopeChanged =
    oldIsBirimAmiri &&
    newIsBirimAmiri &&
    oldPersonelId != null &&
    newPersonelId != null &&
    oldPersonelId === newPersonelId &&
    (!areSameNumberArrays(previous?.sube_ids ?? [], payload.sube_ids) ||
      (previous?.varsayilan_sube_id ?? null) !== (payload.varsayilan_sube_id ?? null));

  if (scopeChanged) {
    const oldScope = formatSubeScopeLabel(previous?.sube_ids ?? [], subeNameMap);
    const newScope = formatSubeScopeLabel(payload.sube_ids, subeNameMap);
    const oldDefault = formatVarsayilanSubeLabel(previous?.varsayilan_sube_id ?? null, subeNameMap);
    const newDefault = formatVarsayilanSubeLabel(payload.varsayilan_sube_id ?? null, subeNameMap);

    logs.push({
      personel_id: newPersonelId,
      surec_turu: SUBE_YETKISI_DEGISTI_SUREC_TURU,
      baslangic_tarihi: today,
      aciklama: `Bağlı Bölüm / Şube Yetkisi Değişti. Eski kapsam: ${oldScope}. Yeni kapsam: ${newScope}. Eski varsayılan şube: ${oldDefault}. Yeni varsayılan şube: ${newDefault}.`
    });
  }

  return logs;
}

function isCorruptedDisplayText(value: string) {
  return /(?:\?\?|\uFFFD)/u.test(value);
}

export function YonetimPaneliPage() {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const { hasPermission } = useRoleAccess();
  const canManageYonetimPanel = hasPermission("yonetim-paneli.manage");
  const realKullaniciApi = isRealYonetimKullaniciApi();
  const activeTab = resolveYonetimActiveTab(searchParams.get("tab"));

  function handleBackToAyarlar() {
    navigate("/");
  }

  const [kullaniciViewMode, setKullaniciViewMode] = useState<YonetimViewMode>("card");
  const [subeViewMode, setSubeViewMode] = useState<YonetimViewMode>("card");
  const [isKullaniciFormOpen, setIsKullaniciFormOpen] = useState(false);
  const [isSubeFormOpen, setIsSubeFormOpen] = useState(false);
  const [isDepartmanCreateOpen, setIsDepartmanCreateOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isAddingDepartman, setIsAddingDepartman] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [subeDeleteError, setSubeDeleteError] = useState<string | null>(null);

  const [kullanicilar, setKullanicilar] = useState<YonetimKullanici[]>([]);
  const [subeler, setSubeler] = useState<YonetimSube[]>([]);
  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);

  const [editingKullaniciId, setEditingKullaniciId] = useState<number | null>(null);
  const [editingSubeId, setEditingSubeId] = useState<number | null>(null);
  const [kullaniciForm, setKullaniciForm] = useState<KullaniciFormState>(INITIAL_KULLANICI_FORM);
  const [subeForm, setSubeForm] = useState<SubeFormState>(INITIAL_SUBE_FORM);
  const [yeniDepartmanAdi, setYeniDepartmanAdi] = useState("");

  const personelOptions = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: formatAdSoyad(`${personel.ad} ${personel.soyad}`)
      })),
    [personeller]
  );

  const subeNameMap = useMemo(() => new Map(subeler.map((sube) => [sube.id, sube.ad])), [subeler]);
  const personelDisplayNameMap = useMemo(
    () => new Map(personeller.map((personel) => [personel.id, formatAdSoyad(`${personel.ad} ${personel.soyad}`)])),
    [personeller]
  );
  const selectedDepartmanLabels = useMemo(
    () =>
      departmanOptions
        .filter((departman) => subeForm.departmanIds.includes(departman.id))
        .map((departman) => departman.label),
    [departmanOptions, subeForm.departmanIds]
  );
  const selectedDepartmanSummary = selectedDepartmanLabels.length > 0 ? selectedDepartmanLabels.join(", ") : "Departman seçimi";

  function formatKullaniciDisplayName(item: YonetimKullanici) {
    if (item.kullanici_tipi === "IC_PERSONEL" && item.personel_id != null) {
      return personelDisplayNameMap.get(item.personel_id) ?? formatAdSoyad(item.personel_ad_soyad ?? item.ad_soyad);
    }

    return formatAdSoyad(item.ad_soyad);
  }

  function formatKullaniciCardLabel(item: YonetimKullanici) {
    if (item.kullanici_tipi === "IC_PERSONEL" && item.personel_id != null) {
      const linkedPersonel = personeller.find((personel) => personel.id === item.personel_id);
      if (linkedPersonel) {
        const personelLabel = `${linkedPersonel.ad} ${linkedPersonel.soyad}`.trim();
        if (personelLabel && !isCorruptedDisplayText(personelLabel)) {
          return personelLabel;
        }
      }

      const fallback = (item.personel_ad_soyad ?? item.ad_soyad ?? "").trim();
      if (fallback && !isCorruptedDisplayText(fallback)) {
        return fallback;
      }

      return ROLE_LABELS[item.rol];
    }

    const adSoyad = (item.ad_soyad ?? "").trim();
    if (adSoyad && !isCorruptedDisplayText(adSoyad)) {
      return adSoyad;
    }

    return ROLE_LABELS[item.rol];
  }

  async function loadPanel() {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const [kullaniciList, subeList, personelList, departmanList] = await Promise.all([
        fetchYonetimKullanicilari(),
        fetchYonetimSubeleri(),
        fetchPersonellerList({ page: 1, limit: 250, aktiflik: "tum" }),
        fetchDepartmanOptions()
      ]);

      setKullanicilar(kullaniciList);
      setSubeler(subeList);
      setPersoneller(personelList.items);
      setDepartmanOptions(sortIdOptions(departmanList));
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Yönetim paneli yüklenemedi.");
    } finally {
      setIsLoading(false);
    }
  }

  useEffect(() => {
    void loadPanel();
  }, []);

  useEffect(() => {
    if (kullaniciForm.kullaniciTipi !== "IC_PERSONEL" || !kullaniciForm.personelId) {
      return;
    }

    const linkedPersonel = personeller.find((item) => item.id === Number.parseInt(kullaniciForm.personelId, 10));
    if (!linkedPersonel) {
      return;
    }

    setKullaniciForm((prev) => ({
      ...prev,
      adSoyad: formatAdSoyad(`${linkedPersonel.ad} ${linkedPersonel.soyad}`),
      telefon: formatTelefon(linkedPersonel.telefon ?? prev.telefon)
    }));
  }, [kullaniciForm.kullaniciTipi, kullaniciForm.personelId, personeller]);

  function resetKullaniciEditor() {
    setEditingKullaniciId(null);
    setKullaniciForm(INITIAL_KULLANICI_FORM);
    setIsKullaniciFormOpen(false);
  }

  function resetSubeEditor() {
    setEditingSubeId(null);
    setSubeForm(INITIAL_SUBE_FORM);
    setYeniDepartmanAdi("");
    setIsDepartmanCreateOpen(false);
    setIsSubeFormOpen(false);
    setSubeDeleteError(null);
  }

  function openYeniKullaniciForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setEditingKullaniciId(null);
    setKullaniciForm(INITIAL_KULLANICI_FORM);
    setIsKullaniciFormOpen(true);
  }

  function openKullaniciEditor(item: YonetimKullanici) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setEditingKullaniciId(item.id);
    setKullaniciForm(userFormFromItem(item));
    setIsKullaniciFormOpen(true);
  }

  function openYeniSubeForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setSubeDeleteError(null);
    setEditingSubeId(null);
    setSubeForm(INITIAL_SUBE_FORM);
    setYeniDepartmanAdi("");
    setIsDepartmanCreateOpen(false);
    setIsSubeFormOpen(true);
  }

  function openSubeEditor(item: YonetimSube) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setSubeDeleteError(null);
    setEditingSubeId(item.id);
    setSubeForm(subeFormFromItem(item));
    setYeniDepartmanAdi("");
    setIsDepartmanCreateOpen(false);
    setIsSubeFormOpen(true);
  }

  function toggleSubeSelection(subeId: number) {
    setKullaniciForm((prev) => {
      const nextSubeIds = prev.subeIds.includes(subeId)
        ? prev.subeIds.filter((id) => id !== subeId)
        : [...prev.subeIds, subeId];

      const nextDefault =
        prev.varsayilanSubeId && !nextSubeIds.includes(Number.parseInt(prev.varsayilanSubeId, 10))
          ? ""
          : prev.varsayilanSubeId;

      return {
        ...prev,
        subeIds: nextSubeIds,
        varsayilanSubeId: nextDefault
      };
    });
  }

  function toggleDepartmanSelection(departmanId: number) {
    setSubeForm((prev) => ({
      ...prev,
      departmanIds: prev.departmanIds.includes(departmanId)
        ? prev.departmanIds.filter((id) => id !== departmanId)
        : [...prev.departmanIds, departmanId]
    }));
  }

  async function handleKullaniciSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const payload = toKullaniciPayload(kullaniciForm, editingKullaniciId != null);
      const existingKullanici =
        editingKullaniciId != null ? kullanicilar.find((item) => item.id === editingKullaniciId) ?? null : null;
      const surecLogPayloads = buildYonetimSurecLogPayloads(existingKullanici, payload, subeNameMap);

      if (editingKullaniciId != null) {
        await updateYonetimKullanici(editingKullaniciId, payload);
        setSuccessMessage("Kullanıcı yetkileri güncellendi.");
      } else {
        await createYonetimKullanici(payload);
        setSuccessMessage("Kullanıcı kaydı oluşturuldu.");
      }

      if (surecLogPayloads.length > 0) {
        await Promise.all(surecLogPayloads.map((entry) => createSurec(entry)));
      }

      resetKullaniciEditor();
      await loadPanel();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Kullanıcı kaydı kaydedilemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleSubeSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const payload = toSubePayload(subeForm);
      if (editingSubeId != null) {
        await updateYonetimSube(editingSubeId, payload);
        setSuccessMessage("Şube tanımı güncellendi.");
      } else {
        await createYonetimSube(payload);
        setSuccessMessage("Şube tanımı eklendi.");
      }

      resetSubeEditor();
      await loadPanel();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Şube tanımı kaydedilemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleSubeDelete() {
    if (editingSubeId == null || isSubmitting || !canManageYonetimPanel) {
      return;
    }

    if (!window.confirm("Bu şubeyi silmek istediğinize emin misiniz?")) {
      return;
    }

    setIsSubmitting(true);
    setSubeDeleteError(null);
    setSuccessMessage(null);

    try {
      await deleteYonetimSube(editingSubeId);
      resetSubeEditor();
      setSuccessMessage("Şube tanımı silindi.");
      await loadPanel();
    } catch (error) {
      setSubeDeleteError(error instanceof Error ? error.message : "Şube silinemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleDepartmanAdd() {
    const ad = yeniDepartmanAdi.trim();
    if (!ad || isAddingDepartman) {
      return;
    }

    setIsAddingDepartman(true);
    setErrorMessage(null);
    setSuccessMessage(null);

    try {
      const created = await createDepartmanOption(ad);
      setDepartmanOptions((prev) => mergeIdOptions(prev, [created]));
      setSubeForm((prev) => ({
        ...prev,
        departmanIds: prev.departmanIds.includes(created.id) ? prev.departmanIds : [...prev.departmanIds, created.id]
      }));
      setYeniDepartmanAdi("");
      setIsDepartmanCreateOpen(false);
      setSuccessMessage(`"${created.label}" departmanı seçeneklere eklendi.`);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Departman eklenemedi.");
    } finally {
      setIsAddingDepartman(false);
    }
  }

  return (
    <section className="yonetim-page">
      {isLoading ? <LoadingState label="Yönetim paneli yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadPanel()} /> : null}
      {!isLoading && successMessage ? <p className="yonetim-success">{successMessage}</p> : null}

      {!isLoading && !errorMessage && activeTab === "kullanicilar" ? (
        <section className="yonetim-list-surface" aria-label="Kullanıcı yönetimi" data-testid="yonetim-section-kullanicilar">
          <button
            type="button"
            className="yonetim-content-back"
            data-testid="yonetim-back-ayarlar"
            onClick={handleBackToAyarlar}
          >
            ← Ayarlar
          </button>

          <div className="yonetim-list-header">
            <div className="yonetim-list-actions">
              <YonetimViewToggle
                label="Kullanıcılar görünümü"
                value={kullaniciViewMode}
                onChange={setKullaniciViewMode}
              />
            </div>
          </div>

          <div className="yonetim-create-row">
            <button
              type="button"
              className="yonetim-create-link"
              data-testid="yonetim-kullanici-yeni"
              onClick={openYeniKullaniciForm}
            >
              + Yeni Kullanıcı
            </button>
          </div>

          {kullanicilar.length === 0 ? (
            <EmptyState title="Kullanıcı kaydı yok" message="İlk kullanıcı atamasını buradan oluşturabilirsin." />
          ) : kullaniciViewMode === "card" ? (
            <div className="yonetim-card-grid yonetim-card-grid--users">
              {kullanicilar.map((item) => (
                <article
                  key={item.id}
                  className="yonetim-entity-card yonetim-entity-card--interactive"
                  role="button"
                  tabIndex={0}
                  onClick={() => openKullaniciEditor(item)}
                  onKeyDown={(event) => {
                    if (isActivationKey(event)) {
                      event.preventDefault();
                      openKullaniciEditor(item);
                    }
                  }}
                >
                  <div className="yonetim-card-meta">
                    <strong>{formatKullaniciCardLabel(item)}</strong>
                    <span>{formatSubeScopeLabel(item.sube_ids, subeNameMap)}</span>
                  </div>
                </article>
              ))}
            </div>
          ) : (
            <div className="yonetim-list-table-wrap">
              <table className="yonetim-list-table">
                <thead>
                  <tr>
                    <th>Ad Soyad</th>
                    <th>Kullanıcı Tipi</th>
                    <th>Rol</th>
                    <th>Şube Yetkisi</th>
                    <th>Varsayılan Şube</th>
                    <th>Durum</th>
                  </tr>
                </thead>
                <tbody>
                  {kullanicilar.map((item) => (
                    <tr
                      key={item.id}
                      className="yonetim-list-table-row"
                      role="button"
                      tabIndex={0}
                      onClick={() => openKullaniciEditor(item)}
                      onKeyDown={(event) => {
                        if (isActivationKey(event)) {
                          event.preventDefault();
                          openKullaniciEditor(item);
                        }
                      }}
                    >
                      <td className="yonetim-list-table-cell-strong">{formatKullaniciDisplayName(item)}</td>
                      <td>{KULLANICI_TIPI_LABELS[item.kullanici_tipi]}</td>
                      <td>{ROLE_LABELS[item.rol]}</td>
                      <td title={formatSubeScopeLabel(item.sube_ids, subeNameMap)}>
                        {formatSubeScopeLabel(item.sube_ids, subeNameMap)}
                      </td>
                      <td>{formatVarsayilanSubeLabel(item.varsayilan_sube_id, subeNameMap)}</td>
                      <td>{DURUM_LABELS[item.durum]}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : null}

      {!isLoading && !errorMessage && activeTab === "subeler" ? (
        <section className="yonetim-list-surface" aria-label="Şube yönetimi" data-testid="yonetim-section-subeler">
          <button
            type="button"
            className="yonetim-content-back"
            data-testid="yonetim-back-ayarlar"
            onClick={handleBackToAyarlar}
          >
            ← Ayarlar
          </button>

          <div className="yonetim-list-header">
            <div className="yonetim-list-actions">
              <YonetimViewToggle label="Şubeler görünümü" value={subeViewMode} onChange={setSubeViewMode} />
            </div>
          </div>

          <div className="yonetim-create-row">
            <button
              type="button"
              className="yonetim-create-link"
              data-testid="yonetim-sube-yeni"
              onClick={openYeniSubeForm}
            >
              + Yeni Şube
            </button>
          </div>

          {subeler.length === 0 ? (
            <EmptyState title="Şube tanımı yok" message="İlk şube kaydını buradan oluşturmaya başlayabilirsin." />
          ) : subeViewMode === "card" ? (
            <div className="yonetim-card-grid yonetim-card-grid--branches">
              {subeler.map((item) => (
                <article
                  key={item.id}
                  className="yonetim-entity-card yonetim-entity-card--branch-preview yonetim-entity-card--interactive"
                  role="button"
                  tabIndex={0}
                  onClick={() => openSubeEditor(item)}
                  onKeyDown={(event) => {
                    if (isActivationKey(event)) {
                      event.preventDefault();
                      openSubeEditor(item);
                    }
                  }}
                >
                  <div className="yonetim-card-meta">
                    <strong>{item.ad}</strong>
                    <span>{item.kod}</span>
                  </div>
                  <p>{item.departman_adlari.length}</p>
                  <p>{item.departman_adlari.join(", ") || "Departman tanımlı değil"}</p>
                  <p>Durum: {DURUM_LABELS[item.durum]}</p>
                </article>
              ))}
            </div>
          ) : (
            <div className="yonetim-list-table-wrap">
              <table className="yonetim-list-table">
                <thead>
                  <tr>
                    <th>Şube Adı</th>
                    <th>Kod</th>
                    <th>Departman Sayısı</th>
                    <th>Departmanlar</th>
                    <th>Durum</th>
                  </tr>
                </thead>
                <tbody>
                  {subeler.map((item) => (
                    <tr
                      key={item.id}
                      className="yonetim-list-table-row"
                      role="button"
                      tabIndex={0}
                      onClick={() => openSubeEditor(item)}
                      onKeyDown={(event) => {
                        if (isActivationKey(event)) {
                          event.preventDefault();
                          openSubeEditor(item);
                        }
                      }}
                    >
                      <td className="yonetim-list-table-cell-strong">{item.ad}</td>
                      <td>{item.kod}</td>
                      <td>{item.departman_adlari.length}</td>
                      <td title={item.departman_adlari.join(", ") || "Departman tanımlı değil"}>
                        {item.departman_adlari.join(", ") || "Departman tanımlı değil"}
                      </td>
                      <td>{DURUM_LABELS[item.durum]}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : null}

      {isKullaniciFormOpen ? (
        <AppModal
          title={editingKullaniciId != null ? "Kullanıcı Düzenle" : "Yeni Kullanıcı"}
          backLabel="Kullanıcı Yönetimi"
          onBack={resetKullaniciEditor}
          onClose={resetKullaniciEditor}
        >
          <form className="yonetim-form-stack" id={YONETIM_KULLANICI_FORM_ID} onSubmit={handleKullaniciSubmit}>
            <div className="form-field-grid">
              <FormField
                label="Kullanıcı Adı"
                name="yonetim-kullanici-username"
                value={kullaniciForm.username}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, username: value }))}
                required
              />
              <FormField
                label={editingKullaniciId != null ? "Geçici Şifre (boş bırakılırsa değişmez)" : "Geçici Şifre"}
                name="yonetim-kullanici-password"
                type="password"
                value={kullaniciForm.password}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, password: value }))}
                required={editingKullaniciId == null}
              />
              {!realKullaniciApi ? (
                <FormField
                  as="select"
                  label="Kullanıcı Tipi"
                  name="yonetim-kullanici-tipi"
                  value={kullaniciForm.kullaniciTipi}
                  onChange={(value) =>
                    setKullaniciForm((prev) => ({
                      ...prev,
                      kullaniciTipi: value as KullaniciTipi,
                      personelId: value === "HARICI" ? "" : prev.personelId
                    }))
                  }
                  selectOptions={[
                    { value: "IC_PERSONEL", label: "İç Personel" },
                    { value: "HARICI", label: "Harici" }
                  ]}
                />
              ) : null}
              <FormField
                as="select"
                label="Rol"
                name="yonetim-kullanici-rol"
                value={kullaniciForm.rol}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, rol: value as UserRole }))}
                selectOptions={roleOptions()}
              />
              <FormField
                as="select"
                label="Durum"
                name="yonetim-kullanici-durum"
                value={kullaniciForm.durum}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, durum: value as KayitDurumu }))}
                selectOptions={statusOptions()}
              />
              {realKullaniciApi ? (
                <p className="yonetim-hint" data-testid="yonetim-kullanici-real-api-hint">
                  {REAL_KULLANICI_API_UNSUPPORTED_HINT}
                </p>
              ) : null}
              {!realKullaniciApi && kullaniciForm.kullaniciTipi === "IC_PERSONEL" ? (
                <FormField
                  as="select"
                  label="Bağlı Personel"
                  name="yonetim-kullanici-personel"
                  value={kullaniciForm.personelId}
                  onChange={(value) => setKullaniciForm((prev) => ({ ...prev, personelId: value }))}
                  placeholderOption={{ value: "", label: "Seçiniz" }}
                  selectOptions={personelOptions}
                />
              ) : null}
              <FormField
                label="Ad Soyad"
                name="yonetim-kullanici-ad"
                value={kullaniciForm.adSoyad}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, adSoyad: value }))}
                disabled={!realKullaniciApi && kullaniciForm.kullaniciTipi === "IC_PERSONEL" && kullaniciForm.personelId !== ""}
                required
              />
              {!realKullaniciApi ? (
                <FormField
                  label="Telefon"
                  name="yonetim-kullanici-telefon"
                  type="tel"
                  value={kullaniciForm.telefon}
                  onChange={(value) => setKullaniciForm((prev) => ({ ...prev, telefon: formatTelefon(value) }))}
                  disabled={kullaniciForm.kullaniciTipi === "IC_PERSONEL" && kullaniciForm.personelId !== ""}
                />
              ) : null}
              <FormField
                as="select"
                label="Varsayılan Şube"
                name="yonetim-kullanici-varsayilan-sube"
                value={kullaniciForm.varsayilanSubeId}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, varsayilanSubeId: value }))}
                placeholderOption={{ value: "", label: "Tüm Şubeler / Seçimsiz" }}
                selectOptions={subeler
                  .filter((sube) => kullaniciForm.subeIds.includes(sube.id))
                  .map((sube) => ({ value: String(sube.id), label: sube.ad }))}
              />
              {!realKullaniciApi ? (
                <FormField
                  as="textarea"
                  label="Notlar"
                  name="yonetim-kullanici-notlar"
                  value={kullaniciForm.notlar}
                  onChange={(value) => setKullaniciForm((prev) => ({ ...prev, notlar: value }))}
                  placeholder="Opsiyonel açıklama"
                />
              ) : null}
            </div>

            <div className="yonetim-checkbox-section">
              <p className="yonetim-checkbox-title">Şube Yetkisi</p>
              <p className="yonetim-hint">Boş bırakırsan kullanıcı tüm şubelerde çalışır.</p>
              <div className="yonetim-selection-grid">
                {subeler.map((sube) => (
                  <button
                    key={sube.id}
                    type="button"
                    className={`yonetim-selection-pill${kullaniciForm.subeIds.includes(sube.id) ? " is-selected" : ""}`}
                    onClick={() => toggleSubeSelection(sube.id)}
                  >
                    <strong>{sube.ad}</strong>
                    <span>{sube.departman_adlari.join(", ") || "Departman tanımlı değil"}</span>
                  </button>
                ))}
              </div>
            </div>

            <div className="form-actions-row">
              <button type="submit" className="universal-btn-save" data-testid="yonetim-kullanici-kaydet">
                {editingKullaniciId != null ? "Kullanıcıyı Güncelle" : "Kullanıcıyı Kaydet"}
              </button>
              <button type="button" className="universal-btn-cancel" onClick={resetKullaniciEditor}>
                Vazgeç
              </button>
            </div>
          </form>
        </AppModal>
      ) : null}

      {isSubeFormOpen ? (
        <AppModal
          title={editingSubeId != null ? "Şube Düzenle" : "Yeni Şube"}
          backLabel="Şube Yönetimi"
          onBack={resetSubeEditor}
          onClose={resetSubeEditor}
        >
          <form className="yonetim-form-stack yonetim-form-stack--sube" id={YONETIM_SUBE_FORM_ID} onSubmit={handleSubeSubmit}>
            <div className="form-field-grid">
              <FormField
                label="Şube Kodu"
                name="yonetim-sube-kod"
                value={subeForm.kod}
                onChange={(value) => setSubeForm((prev) => ({ ...prev, kod: value }))}
                required
              />
              <FormField
                label="Şube Adı"
                name="yonetim-sube-ad"
                value={subeForm.ad}
                onChange={(value) => setSubeForm((prev) => ({ ...prev, ad: value }))}
                required
              />
              <div className="yonetim-durum-row">
                <span className="yonetim-durum-label">Durum</span>
                <div className="yonetim-durum-toggle" role="group" aria-label="Durum">
                  <button
                    type="button"
                    className={`yonetim-durum-btn${subeForm.durum === "AKTIF" ? " is-active" : ""}`}
                    aria-pressed={subeForm.durum === "AKTIF"}
                    onClick={() => setSubeForm((prev) => ({ ...prev, durum: "AKTIF" }))}
                  >
                    {DURUM_LABELS.AKTIF}
                  </button>
                  <button
                    type="button"
                    className={`yonetim-durum-btn${subeForm.durum === "PASIF" ? " is-active" : ""}`}
                    aria-pressed={subeForm.durum === "PASIF"}
                    onClick={() => setSubeForm((prev) => ({ ...prev, durum: "PASIF" }))}
                  >
                    {DURUM_LABELS.PASIF}
                  </button>
                </div>
              </div>
            </div>

            <div className="yonetim-checkbox-section">
              <div className="yonetim-departman-section-head">
                <p className="yonetim-checkbox-title">Departman Seçimi</p>
                <p className="yonetim-hint">
                  {selectedDepartmanLabels.length > 0
                    ? `Seçili: ${selectedDepartmanSummary}`
                    : "Departmanları aşağıdan seçin."}
                </p>
              </div>

              <div
                className="yonetim-selection-panel"
                id="yonetim-sube-departman-panel"
                data-testid="yonetim-sube-departman-panel"
              >
                <div className="yonetim-selection-panel-head">
                  <button
                    type="button"
                    className="yonetim-panel-action"
                    onClick={() => setIsDepartmanCreateOpen((prev) => !prev)}
                  >
                    + Yeni Departman
                  </button>
                </div>

                {isDepartmanCreateOpen ? (
                  <div className="yonetim-inline-add-row yonetim-inline-add-row--panel">
                    <input
                      className="form-input"
                      type="text"
                      value={yeniDepartmanAdi}
                      onChange={(event) => setYeniDepartmanAdi(event.target.value)}
                      placeholder="Yeni departman adı"
                    />
                    <button
                      type="button"
                      className="yonetim-inline-add-btn"
                      onClick={() => void handleDepartmanAdd()}
                      disabled={isAddingDepartman || yeniDepartmanAdi.trim().length === 0}
                    >
                      Ekle
                    </button>
                  </div>
                ) : null}

                <div className="yonetim-selection-grid yonetim-selection-grid--departmanlar">
                  {departmanOptions.map((departman) => (
                    <button
                      key={departman.id}
                      type="button"
                      className={`yonetim-selection-pill${subeForm.departmanIds.includes(departman.id) ? " is-selected" : ""}`}
                      data-testid={`yonetim-sube-departman-option-${departman.id}`}
                      onClick={() => toggleDepartmanSelection(departman.id)}
                    >
                      <strong>{departman.label}</strong>
                    </button>
                  ))}
                </div>
              </div>
            </div>

            {subeDeleteError ? (
              <p className="yonetim-inline-error" role="alert">
                {subeDeleteError}
              </p>
            ) : null}

            <div className="form-actions-row">
              <button type="submit" className="universal-btn-save" data-testid="yonetim-sube-kaydet">
                {editingSubeId != null ? "Şubeyi Güncelle" : "Şubeyi Kaydet"}
              </button>
              <button type="button" className="universal-btn-cancel" onClick={resetSubeEditor}>
                Vazgeç
              </button>
            </div>

            {editingSubeId != null && canManageYonetimPanel ? (
              <div className="form-actions-row form-actions-row--sube-delete">
                <button
                  type="button"
                  className="universal-btn-cancel"
                  data-testid="yonetim-sube-sil"
                  onClick={() => void handleSubeDelete()}
                  disabled={isSubmitting}
                >
                  Şubeyi Sil
                </button>
              </div>
            ) : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
