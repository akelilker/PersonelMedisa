import { useEffect, useMemo, useState, type FormEvent, type KeyboardEvent } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { AppSelectField } from "../../../components/form/AppSelect";
import { FormField } from "../../../components/form/FormField";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { isApiRequestError } from "../../../api/api-client";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { createDepartmanOption, fetchBirimOptions, fetchBolumOptions, fetchDepartmanOptions, fetchSgkIsverenCatalog } from "../../../api/referans.api";
import { createSurec, type CreateSurecPayload } from "../../../api/surecler.api";
import {
  createSirketSube,
  createYonetimKullanici,
  createYonetimSgkIsveren,
  createYonetimSirket,
  createYonetimSube,
  deleteSirketSube,
  deleteYonetimSgkIsveren,
  deleteYonetimSirket,
  deleteYonetimSube,
  fetchOrganizasyonReadiness,
  fetchYonetimKullanicilari,
  fetchYonetimSgkIsverenleri,
  fetchYonetimSirketleri,
  fetchYonetimSubeleri,
  resetYonetimKullaniciBaslangicSifresi,
  updateSirketSube,
  updateYonetimKullanici,
  updateYonetimSgkIsveren,
  updateYonetimSirket,
  updateYonetimSube
} from "../../../api/yonetim.api";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { KullaniciActorIdentityPanel } from "../components/KullaniciActorIdentityPanel";
import { KullaniciRoleSummaryPanel } from "../components/KullaniciRoleSummaryPanel";
import { MevzuatParametreleriPanel } from "../components/MevzuatParametreleriPanel";
import { SaklamaLegalHoldPanel } from "../components/SaklamaLegalHoldPanel";
import { YonetimOrgScopeFields } from "../components/YonetimSubeScopeField";
import { isRealYonetimKullaniciApi } from "../../../lib/yonetim/kullanici-api-contract";
import {
  PERSONEL_ACTIVATION_PENDING_LABEL,
  PERSONEL_FIRST_LOGIN_COMPLETE_LABEL,
  PERSONEL_FIRST_LOGIN_PENDING_LABEL,
  countPersonelFirstLoginStatus,
  matchesPersonelFirstLoginFilter,
  resolvePersonelFirstLoginLabel,
  type PersonelFirstLoginFilter
} from "../../../lib/yonetim/personel-first-login-status";
import type { UserRole } from "../../../types/auth";
import { ASSIGNABLE_USER_ROLES, WRITE_COMPANY_SCOPED_ROLES } from "../../../types/auth";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import { formatSurecTuruLabel, formatUserRoleLabel } from "../../../lib/display/enum-display";
import type {
  KayitDurumu,
  KullaniciTipi,
  OrganizasyonReadiness,
  UpsertYonetimKullaniciPayload,
  UpsertYonetimSgkIsverenPayload,
  UpsertYonetimSirketPayload,
  UpsertYonetimSubePayload,
  YonetimKullanici,
  YonetimOrgRelation,
  YonetimSgkIsveren,
  YonetimSirket,
  YonetimSube
} from "../../../types/yonetim";
import {
  filterSgkIsverenOptionsForSirket,
  resolveSgkIsverenAfterSirketChange,
  toSgkIsverenSelectOptions
} from "../../../lib/yonetim/sgk-isveren-options";

type ActiveTab = "kullanicilar" | "subeler" | "mevzuat" | "saklama";
type YonetimViewMode = "card" | "list";

function resolveYonetimActiveTab(tabParam: string | null): ActiveTab {
  const normalized = tabParam?.trim().toLowerCase() ?? "";
  if (normalized === "subeler" || normalized === "sube") {
    return "subeler";
  }
  if (normalized === "mevzuat") {
    return "mevzuat";
  }
  if (normalized === "saklama" || normalized === "legal-hold" || normalized === "retention") {
    return "saklama";
  }
  return "kullanicilar";
}

type KullaniciFormState = {
  username: string;
  kullaniciTipi: KullaniciTipi;
  personelId: string;
  adSoyad: string;
  telefon: string;
  rol: UserRole;
  subeIds: number[];
  bolumIds: number[];
  birimIds: number[];
  sirketIds: number[];
  sgkIsverenIds: number[];
  varsayilanSubeId: string;
  durum: KayitDurumu;
  notlar: string;
};

type SubeFormState = {
  kod: string;
  ad: string;
  departmanIds: number[];
  durum: KayitDurumu;
  /** Şirkete bağlı SGK işvereni seçimi ("" = bağlantı yok). */
  sgkIsverenId: string;
  muhasebeKisitAktif: boolean;
  muhasebeYetkiliUserIds: number[];
  sorumluYoneticiUserIds: number[];
};

type SgkIsverenFormState = {
  sirketId: string;
  kod: string;
  ad: string;
  durum: KayitDurumu;
};

type SirketFormState = {
  kod: string;
  ad: string;
  durum: KayitDurumu;
};

const KULLANICI_TIPI_LABELS: Record<KullaniciTipi, string> = {
  IC_PERSONEL: "İç Personel",
  HARICI: "Harici"
};

const DURUM_LABELS: Record<KayitDurumu, string> = {
  AKTIF: "Aktif",
  PASIF: "Pasif"
};

const FIRST_LOGIN_FILTER_OPTIONS: Array<{ value: PersonelFirstLoginFilter; label: string }> = [
  { value: "all", label: "Tümü" },
  { value: "pending", label: `${PERSONEL_ACTIVATION_PENDING_LABEL} / ${PERSONEL_FIRST_LOGIN_PENDING_LABEL}` },
  { value: "completed", label: PERSONEL_FIRST_LOGIN_COMPLETE_LABEL }
];

const INITIAL_KULLANICI_FORM: KullaniciFormState = {
  username: "",
  kullaniciTipi: "IC_PERSONEL",
  personelId: "",
  adSoyad: "",
  telefon: "",
  rol: "BIRIM_AMIRI",
  subeIds: [],
  bolumIds: [],
  birimIds: [],
  sirketIds: [],
  sgkIsverenIds: [],
  varsayilanSubeId: "",
  durum: "AKTIF",
  notlar: ""
};

const INITIAL_SUBE_FORM: SubeFormState = {
  kod: "",
  ad: "",
  departmanIds: [],
  durum: "AKTIF",
  sgkIsverenId: "",
  muhasebeKisitAktif: false,
  muhasebeYetkiliUserIds: [],
  sorumluYoneticiUserIds: []
};

const SORUMLU_YONETICI_ELIGIBLE_ROLES = new Set([
  "GENEL_YONETICI",
  "SISTEM_YONETICISI",
  "SUBE_YONETICISI",
  "BOLUM_YONETICISI",
  "BIRIM_AMIRI",
  "IK_SORUMLUSU"
]);

const INITIAL_SIRKET_FORM: SirketFormState = {
  kod: "",
  ad: "",
  durum: "AKTIF"
};

const INITIAL_SGK_ISVEREN_FORM: SgkIsverenFormState = {
  sirketId: "",
  kod: "",
  ad: "",
  durum: "AKTIF"
};

const YONETIM_KULLANICI_FORM_ID = "yonetim-kullanici-form";
const YONETIM_SUBE_FORM_ID = "yonetim-sube-form";
const YONETIM_SIRKET_FORM_ID = "yonetim-sirket-form";
const YONETIM_SGK_ISVEREN_FORM_ID = "yonetim-sgk-isveren-form";
const REAL_KULLANICI_API_UNSUPPORTED_HINT =
  "Telefon, notlar ve kullanıcı tipi V1 canlı API'de desteklenmiyor. Bağlı personel eşlemesi kaydedilir.";
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

function roleOptions(currentRole?: UserRole) {
  const roles = currentRole === "AUTH_SMOKE_READONLY"
    ? [currentRole, ...ASSIGNABLE_USER_ROLES]
    : ASSIGNABLE_USER_ROLES;

  return roles.map((value) => ({
    value,
    label: formatUserRoleLabel(value)
  }));
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
    kullaniciTipi: item.kullanici_tipi,
    personelId: item.personel_id != null ? String(item.personel_id) : "",
    adSoyad: formatAdSoyad(item.ad_soyad),
    telefon: formatTelefon(item.telefon ?? ""),
    rol: item.rol,
    subeIds: item.sube_ids,
    bolumIds: item.bolum_ids ?? [],
    birimIds: item.birim_ids ?? [],
    sirketIds: item.sirket_ids ?? [],
    sgkIsverenIds: item.sgk_isveren_ids ?? [],
    varsayilanSubeId: item.varsayilan_sube_id != null ? String(item.varsayilan_sube_id) : "",
    durum: item.durum,
    notlar: item.notlar ?? ""
  };
}

function subeFormFromItem(item: YonetimSube): SubeFormState {
  const selectedIds = item.muhasebe_yetkili_user_ids ?? [];
  return {
    kod: item.kod,
    ad: item.ad,
    departmanIds: item.departman_ids,
    durum: item.durum,
    sgkIsverenId: item.sgk_isveren?.id != null ? String(item.sgk_isveren.id) : "",
    muhasebeKisitAktif: Boolean(item.muhasebe_kisit_aktif) || selectedIds.length > 0,
    muhasebeYetkiliUserIds: selectedIds,
    sorumluYoneticiUserIds: item.sorumlu_yonetici_user_ids ?? []
  };
}

function toKullaniciPayload(
  form: KullaniciFormState,
  isEdit: boolean,
  effectiveVarsayilanSubeIds: number[] = form.subeIds
): UpsertYonetimKullaniciPayload {
  const realKullaniciApi = isRealYonetimKullaniciApi();
  const adSoyad = formatAdSoyad(form.adSoyad);
  if (!adSoyad) {
    throw new Error("Ad soyad zorunludur.");
  }

  const username = form.username.trim();
  if (!username) {
    throw new Error("Kullanıcı adı zorunludur.");
  }

  if (!isEdit && form.rol === "PERSONEL") {
    throw new Error(
      "PERSONEL hesapları Yönetim Paneli üzerinden oluşturulamaz. Personel kartındaki güvenli hesap onboarding akışını kullanın."
    );
  }

  if (!realKullaniciApi && form.kullaniciTipi === "IC_PERSONEL" && !form.personelId) {
    throw new Error("İç personel kullanıcıları için personel seçimi zorunludur.");
  }

  if (
    form.varsayilanSubeId &&
    !effectiveVarsayilanSubeIds.includes(Number.parseInt(form.varsayilanSubeId, 10))
  ) {
    throw new Error("Varsayılan şube, yetki verilen şubeler içinde olmalıdır.");
  }

  // Bos secim = hicbir yerde islem yapamayan aktif kullanici. Backend de ayni
  // kurali uygular; buradaki kontrol yalniz formu erken durdurur.
  if (
    (WRITE_COMPANY_SCOPED_ROLES as readonly string[]).includes(form.rol) &&
    form.sirketIds.length === 0
  ) {
    throw new Error("Bu rol için en az bir işlem şirketi seçilmelidir.");
  }

  if (
    form.rol === "MUHASEBE" &&
    form.subeIds.length === 0 &&
    form.sirketIds.length === 0 &&
    form.sgkIsverenIds.length === 0
  ) {
    throw new Error("Bu rol için en az bir şube, şirket veya SGK kapsamı zorunludur.");
  }

  const payload: UpsertYonetimKullaniciPayload = {
    username,
    ad_soyad: adSoyad,
    kullanici_tipi: realKullaniciApi ? "HARICI" : form.kullaniciTipi,
    rol: form.rol,
    sube_ids: form.subeIds,
    bolum_ids: form.bolumIds,
    birim_ids: form.birimIds,
    sirket_ids: form.sirketIds,
    sgk_isveren_ids: form.sgkIsverenIds,
    varsayilan_sube_id: form.varsayilanSubeId ? Number.parseInt(form.varsayilanSubeId, 10) : null,
    durum: form.durum
  };

  payload.personel_id = form.personelId ? Number.parseInt(form.personelId, 10) : null;

  if (!realKullaniciApi) {
    payload.telefon = normalizeTelefonDigits(form.telefon) || undefined;
    payload.notlar = form.notlar.trim() || undefined;
  }

  return payload;
}

function sirketFormFromItem(item: YonetimSirket): SirketFormState {
  return { kod: item.kod, ad: item.ad, durum: item.durum };
}

function toSirketPayload(form: SirketFormState, isEdit: boolean): UpsertYonetimSirketPayload {
  const kod = form.kod.trim().toUpperCase();
  const ad = form.ad.trim();

  if (!ad) {
    throw new Error("Şirket adı zorunludur.");
  }
  if (!isEdit && !kod) {
    throw new Error("Şirket kodu zorunludur.");
  }

  // `kod` is immutable after creation, so an edit payload omits it entirely.
  return isEdit ? { ad, durum: form.durum } : { kod, ad, durum: form.durum };
}

function toSubePayload(
  form: SubeFormState,
  sgkContext?: { allowedSgkIsverenIds: number[] }
): UpsertYonetimSubePayload {
  const kod = form.kod.trim().toUpperCase();
  const ad = form.ad.trim();

  if (!kod || !ad) {
    throw new Error("Şube kodu ve şube adı zorunludur.");
  }

  if (form.departmanIds.length === 0) {
    throw new Error("En az bir departman seçilmelidir.");
  }

  // Unchecked clears ACL even if stale multi-select state remains in the form.
  const muhasebeKisitAktif = form.muhasebeKisitAktif;
  const muhasebeYetkiliUserIds = muhasebeKisitAktif ? form.muhasebeYetkiliUserIds : [];

  if (muhasebeKisitAktif && muhasebeYetkiliUserIds.length === 0) {
    throw new Error("Muhasebe kısıtı açıkken en az bir muhasebe yetkilisi seçilmelidir.");
  }

  const payload: UpsertYonetimSubePayload = {
    kod,
    ad,
    departman_ids: form.departmanIds,
    durum: form.durum,
    muhasebe_kisit_aktif: muhasebeKisitAktif,
    muhasebe_yetkili_user_ids: muhasebeYetkiliUserIds,
    sorumlu_yonetici_user_ids: form.sorumluYoneticiUserIds
  };

  // Şirket bağlamı olmayan (legacy) ekranda alan gönderilmez: mevcut eşleme
  // kazara temizlenmesin. Şirket bağlamı varken seçim listeye karşı doğrulanır.
  if (sgkContext) {
    const selected = form.sgkIsverenId.trim();
    if (selected) {
      const parsed = Number.parseInt(selected, 10);
      if (!Number.isInteger(parsed) || !sgkContext.allowedSgkIsverenIds.includes(parsed)) {
        throw new Error("Seçilen SGK işvereni bu şirkete ait değil veya aktif değil.");
      }
      payload.sgk_isveren_id = parsed;
    } else {
      payload.sgk_isveren_id = null;
    }
  }

  return payload;
}

function sgkIsverenFormFromItem(item: YonetimSgkIsveren): SgkIsverenFormState {
  return {
    sirketId: item.sirket?.id != null ? String(item.sirket.id) : "",
    kod: item.kod ?? "",
    ad: item.ad,
    durum: item.durum
  };
}

function toSgkIsverenPayload(form: SgkIsverenFormState): UpsertYonetimSgkIsverenPayload {
  const kod = form.kod.trim();
  const ad = form.ad.trim();
  const sirketId = Number.parseInt(form.sirketId, 10);

  if (!Number.isInteger(sirketId) || sirketId <= 0) {
    throw new Error("SGK işvereni için şirket seçimi zorunludur.");
  }
  if (!kod) {
    throw new Error("SGK işveren kodu zorunludur.");
  }
  if (!ad) {
    throw new Error("SGK işveren adı zorunludur.");
  }

  return { sirket_id: sirketId, kod, ad, durum: form.durum };
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
      aciklama: formatSurecTuruLabel(BIRIM_AMIRI_ATAMASI_KALDIRILDI_SUREC_TURU)
    });
  }

  if (newIsBirimAmiri && newPersonelId != null && (!oldIsBirimAmiri || newPersonelId !== oldPersonelId)) {
    logs.push({
      personel_id: newPersonelId,
      surec_turu: BIRIM_AMIRI_ATANDI_SUREC_TURU,
      baslangic_tarihi: today,
      aciklama: formatSurecTuruLabel(BIRIM_AMIRI_ATANDI_SUREC_TURU)
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
  const [searchParams, setSearchParams] = useSearchParams();
  const { hasPermission } = useRoleAccess();
  const canManageYonetimPanel = hasPermission("yonetim-paneli.manage");
  const canOpenQrKiosk = hasPermission("qr.kiosk.display");
  const canViewMevzuat = hasPermission("mevzuat_parametreleri.view");
  const canManageMevzuat = hasPermission("mevzuat_parametreleri.manage");
  const canViewSaklama =
    hasPermission("legal_hold.manage") ||
    hasPermission("retention.destruction.approve") ||
    (hasPermission("retention.view") && hasPermission("yonetim-paneli.view"));
  const realKullaniciApi = isRealYonetimKullaniciApi();
  const requestedTab = resolveYonetimActiveTab(searchParams.get("tab"));
  let activeTab: ActiveTab = requestedTab;
  if (requestedTab === "mevzuat" && !canViewMevzuat) {
    activeTab = "kullanicilar";
  } else if (requestedTab === "saklama" && !canViewSaklama) {
    activeTab = "kullanicilar";
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
  // Submit failures must stay inside the open editor modal; the page-level
  // ErrorState renders behind it and hides the list behind the overlay.
  const [formErrorMessage, setFormErrorMessage] = useState<string | null>(null);
  const [sifreResetConfirmOpen, setSifreResetConfirmOpen] = useState(false);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [subeDeleteError, setSubeDeleteError] = useState<string | null>(null);
  const [isSubeDeleteDialogOpen, setIsSubeDeleteDialogOpen] = useState(false);
  const [subeDeleteDialogError, setSubeDeleteDialogError] = useState<string | null>(null);

  const [kullanicilar, setKullanicilar] = useState<YonetimKullanici[]>([]);
  const [firstLoginFilter, setFirstLoginFilter] = useState<PersonelFirstLoginFilter>("all");
  const [subeler, setSubeler] = useState<YonetimSube[]>([]);
  const [sirketler, setSirketler] = useState<YonetimSirket[]>([]);
  const [readiness, setReadiness] = useState<OrganizasyonReadiness | null>(null);
  const [isSirketFormOpen, setIsSirketFormOpen] = useState(false);
  const [editingSirketId, setEditingSirketId] = useState<number | null>(null);
  const [sirketForm, setSirketForm] = useState<SirketFormState>(INITIAL_SIRKET_FORM);
  const [isSirketDeleteDialogOpen, setIsSirketDeleteDialogOpen] = useState(false);
  const [sirketDeleteDialogError, setSirketDeleteDialogError] = useState<string | null>(null);
  const [personeller, setPersoneller] = useState<Personel[]>([]);
  const [departmanOptions, setDepartmanOptions] = useState<IdOption[]>([]);
  const [bolumOptions, setBolumOptions] = useState<IdOption[]>([]);
  const [birimOptions, setBirimOptions] = useState<IdOption[]>([]);
  // Full SGK catalog for user-scope grants — never derived from subeler.sgk_isveren defaults.
  const [sgkIsverenOptions, setSgkIsverenOptions] = useState<YonetimOrgRelation[]>([]);
  // Organisation management view of the same catalog: PASIF rows and owning
  // company included, because şube mapping and deactivation are managed here.
  const [sgkIsverenleri, setSgkIsverenleri] = useState<YonetimSgkIsveren[]>([]);
  // A failed catalog read must never look like "this company has no employer":
  // while the list is unavailable the branch form neither offers nor writes SGK.
  const [isSgkIsverenCatalogLoaded, setIsSgkIsverenCatalogLoaded] = useState(false);
  const [isSgkIsverenFormOpen, setIsSgkIsverenFormOpen] = useState(false);
  const [editingSgkIsverenId, setEditingSgkIsverenId] = useState<number | null>(null);
  const [sgkIsverenForm, setSgkIsverenForm] = useState<SgkIsverenFormState>(INITIAL_SGK_ISVEREN_FORM);
  const [isSgkIsverenDeleteDialogOpen, setIsSgkIsverenDeleteDialogOpen] = useState(false);
  const [sgkIsverenDeleteDialogError, setSgkIsverenDeleteDialogError] = useState<string | null>(null);
  const [sgkIsverenDeleteError, setSgkIsverenDeleteError] = useState<string | null>(null);

  const [editingKullaniciId, setEditingKullaniciId] = useState<number | null>(null);
  const [editingSubeId, setEditingSubeId] = useState<number | null>(null);
  const [kullaniciForm, setKullaniciForm] = useState<KullaniciFormState>(INITIAL_KULLANICI_FORM);
  const [subeForm, setSubeForm] = useState<SubeFormState>(INITIAL_SUBE_FORM);
  const [yeniDepartmanAdi, setYeniDepartmanAdi] = useState("");
  const [muhasebeYetkiliQuery, setMuhasebeYetkiliQuery] = useState("");
  const [sorumluYoneticiQuery, setSorumluYoneticiQuery] = useState("");

  const personelOptions = useMemo(
    () =>
      personeller
        .filter(
          (personel) =>
            personel.aktif_durum === "AKTIF" || String(personel.id) === kullaniciForm.personelId
        )
        .map((personel) => {
          const name = formatAdSoyad([personel.ad, personel.soyad].filter(Boolean).join(" "));
          const meta = [personel.departman_adi, personel.gorev_adi].filter(Boolean).join(" / ");
          return {
            value: String(personel.id),
            label: meta ? `${name} — ${meta}` : name
          };
        }),
    [personeller, kullaniciForm.personelId]
  );

  const showBagliPersonelSelector =
    realKullaniciApi ||
    kullaniciForm.kullaniciTipi === "IC_PERSONEL" ||
    kullaniciForm.rol === "PERSONEL";

  const isSecurePersonelCreatePath =
    editingKullaniciId == null && kullaniciForm.rol === "PERSONEL";

  // Scope summaries are a shared surface, so they use the company-qualified name.
  const subeNameMap = useMemo(() => new Map(subeler.map((sube) => [sube.id, sube.tam_ad])), [subeler]);
  const effectiveVarsayilanSubeIds = useMemo(() => {
    const ids = new Set(kullaniciForm.subeIds);
    for (const sube of subeler) {
      if (sube.sirket && kullaniciForm.sirketIds.includes(sube.sirket.id)) {
        ids.add(sube.id);
      }
    }
    return Array.from(ids);
  }, [kullaniciForm.subeIds, kullaniciForm.sirketIds, subeler]);

  // The hierarchy UI only opens once production branches are actually mapped to
  // companies; schema alone would render empty company cards over legacy data.
  const hierarchyMode = readiness?.data_ready === true;
  const selectedSirketIdParam = Number.parseInt(searchParams.get("sirket") ?? "", 10);
  const selectedSirketId =
    hierarchyMode && Number.isInteger(selectedSirketIdParam) && selectedSirketIdParam > 0
      ? selectedSirketIdParam
      : null;
  const selectedSirket = useMemo(
    () => sirketler.find((item) => item.id === selectedSirketId) ?? null,
    [sirketler, selectedSirketId]
  );
  const sirketSubeleri = useMemo(
    () => (selectedSirketId == null ? [] : subeler.filter((sube) => sube.sirket?.id === selectedSirketId)),
    [subeler, selectedSirketId]
  );

  const visibleSubeler = hierarchyMode && selectedSirketId != null ? sirketSubeleri : subeler;
  // Company detail is the one screen scoped to a single company, so it shows the
  // short name; every shared listing keeps the company-qualified name.
  const subeListLabel = (item: YonetimSube) => (selectedSirketId != null ? item.ad : item.tam_ad);

  const visibleSgkIsverenleri = useMemo(
    () =>
      selectedSirketId == null
        ? sgkIsverenleri
        : sgkIsverenleri.filter((item) => item.sirket?.id === selectedSirketId),
    [sgkIsverenleri, selectedSirketId]
  );
  // The branch being edited keeps its own employer visible even after it was
  // deactivated; a foreign-company selection is never offered.
  const editingSubeSgkIsverenId = useMemo(
    () =>
      editingSubeId == null
        ? null
        : subeler.find((item) => item.id === editingSubeId)?.sgk_isveren?.id ?? null,
    [editingSubeId, subeler]
  );
  const subeSgkIsverenOptions = useMemo(
    () => filterSgkIsverenOptionsForSirket(sgkIsverenleri, selectedSirketId, editingSubeSgkIsverenId),
    [sgkIsverenleri, selectedSirketId, editingSubeSgkIsverenId]
  );
  const subeSgkIsverenSelectOptions = useMemo(
    () => toSgkIsverenSelectOptions(subeSgkIsverenOptions),
    [subeSgkIsverenOptions]
  );
  const subeSgkIsverenAllowedIds = useMemo(
    () => subeSgkIsverenOptions.map((item) => item.id),
    [subeSgkIsverenOptions]
  );
  const sgkIsverenSirketSelectOptions = useMemo(
    () => sirketler.map((item) => ({ value: String(item.id), label: item.ad })),
    [sirketler]
  );

  // Company context changed (or the stored mapping is inconsistent): the stale
  // selection is dropped instead of being carried into the write payload. This
  // only runs with a loaded catalog — an unavailable catalog is not "no options".
  const hasSgkIsverenContext =
    isSgkIsverenCatalogLoaded && hierarchyMode && selectedSirketId != null;
  useEffect(() => {
    if (!isSubeFormOpen || !hasSgkIsverenContext) {
      return;
    }
    const next = resolveSgkIsverenAfterSirketChange(subeForm.sgkIsverenId, subeSgkIsverenOptions);
    if (next !== subeForm.sgkIsverenId) {
      setSubeForm((prev) => ({ ...prev, sgkIsverenId: next }));
    }
  }, [isSubeFormOpen, hasSgkIsverenContext, subeForm.sgkIsverenId, subeSgkIsverenOptions]);

  function openSirketDetay(sirketId: number) {
    const next = new URLSearchParams(searchParams);
    next.set("tab", "subeler");
    next.set("sirket", String(sirketId));
    setSearchParams(next);
  }

  function closeSirketDetay() {
    const next = new URLSearchParams(searchParams);
    next.delete("sirket");
    setSearchParams(next);
  }
  const personelDisplayNameMap = useMemo(
    () => new Map(personeller.map((personel) => [personel.id, formatAdSoyad([personel.ad, personel.soyad].filter(Boolean).join(" "))])),
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

  const eligibleMuhasebeOptions = useMemo(() => {
    const fromUsers = kullanicilar
      .filter((item) => item.rol === "MUHASEBE" && item.durum === "AKTIF")
      .map((item) => ({
        id: item.id,
        label: item.ad_soyad || item.username || `Kullanıcı #${item.id}`,
        username: item.username ?? "",
        eligible: true as boolean
      }));

    // Preserve stored-but-ineligible selections for fail-closed display.
    const editingSube =
      editingSubeId != null ? subeler.find((item) => item.id === editingSubeId) ?? null : null;
    const stored = (editingSube?.muhasebe_yetkilileri ?? []).map((item) => ({
      id: item.id,
      label: item.ad_soyad || item.username || `Kullanıcı #${item.id}`,
      username: item.username,
      eligible: item.eligible
    }));

    const byId = new Map<number, { id: number; label: string; username: string; eligible: boolean }>();
    [...fromUsers, ...stored].forEach((item) => {
      const existing = byId.get(item.id);
      if (!existing || (existing.eligible && !item.eligible)) {
        byId.set(item.id, item);
      } else if (!existing.eligible && item.eligible) {
        byId.set(item.id, item);
      }
    });

    return Array.from(byId.values()).sort((left, right) => left.label.localeCompare(right.label, "tr"));
  }, [kullanicilar, editingSubeId, subeler]);

  const filteredMuhasebeOptions = useMemo(() => {
    const query = muhasebeYetkiliQuery.trim().toLocaleLowerCase("tr-TR");
    if (!query) {
      return eligibleMuhasebeOptions;
    }
    return eligibleMuhasebeOptions.filter((item) => {
      const haystack = `${item.label} ${item.username}`.toLocaleLowerCase("tr-TR");
      return haystack.includes(query);
    });
  }, [eligibleMuhasebeOptions, muhasebeYetkiliQuery]);

  const selectedMuhasebeLabels = useMemo(
    () =>
      eligibleMuhasebeOptions
        .filter((item) => subeForm.muhasebeYetkiliUserIds.includes(item.id))
        .map((item) => (item.eligible ? item.label : `${item.label} (uygunsuz)`)),
    [eligibleMuhasebeOptions, subeForm.muhasebeYetkiliUserIds]
  );

  const eligibleSorumluYoneticiOptions = useMemo(() => {
    const fromUsers = kullanicilar
      .filter((item) => item.durum === "AKTIF" && SORUMLU_YONETICI_ELIGIBLE_ROLES.has(item.rol))
      .map((item) => ({
        id: item.id,
        label: item.ad_soyad || item.username || `Kullanıcı #${item.id}`,
        username: item.username ?? "",
        rol: item.rol,
        eligible: true as boolean
      }));

    const editingSube =
      editingSubeId != null ? subeler.find((item) => item.id === editingSubeId) ?? null : null;
    const stored = (editingSube?.sorumlu_yoneticiler ?? []).map((item) => ({
      id: item.id,
      label: item.ad_soyad || item.username || `Kullanıcı #${item.id}`,
      username: item.username,
      rol: item.rol,
      eligible: item.eligible
    }));

    const byId = new Map<
      number,
      { id: number; label: string; username: string; rol: string; eligible: boolean }
    >();
    [...fromUsers, ...stored].forEach((item) => {
      const existing = byId.get(item.id);
      if (!existing || (existing.eligible && !item.eligible)) {
        byId.set(item.id, item);
      } else if (!existing.eligible && item.eligible) {
        byId.set(item.id, item);
      }
    });

    return Array.from(byId.values()).sort((left, right) => left.label.localeCompare(right.label, "tr"));
  }, [kullanicilar, editingSubeId, subeler]);

  const filteredSorumluYoneticiOptions = useMemo(() => {
    const query = sorumluYoneticiQuery.trim().toLocaleLowerCase("tr-TR");
    if (!query) {
      return eligibleSorumluYoneticiOptions;
    }
    return eligibleSorumluYoneticiOptions.filter((item) => {
      const haystack = `${item.label} ${item.username} ${item.rol}`.toLocaleLowerCase("tr-TR");
      return haystack.includes(query);
    });
  }, [eligibleSorumluYoneticiOptions, sorumluYoneticiQuery]);

  const selectedSorumluYoneticiLabels = useMemo(
    () =>
      eligibleSorumluYoneticiOptions
        .filter((item) => subeForm.sorumluYoneticiUserIds.includes(item.id))
        .map((item) => (item.eligible ? item.label : `${item.label} (uygunsuz)`)),
    [eligibleSorumluYoneticiOptions, subeForm.sorumluYoneticiUserIds]
  );

  const firstLoginSummary = useMemo(() => countPersonelFirstLoginStatus(kullanicilar), [kullanicilar]);
  const filteredKullanicilar = useMemo(
    () => kullanicilar.filter((item) => matchesPersonelFirstLoginFilter(item, firstLoginFilter)),
    [kullanicilar, firstLoginFilter]
  );

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
        const personelLabel = [linkedPersonel.ad, linkedPersonel.soyad].filter(Boolean).join(" ");
        if (personelLabel && !isCorruptedDisplayText(personelLabel)) {
          return personelLabel;
        }
      }

      const fallback = (item.personel_ad_soyad ?? item.ad_soyad ?? "").trim();
      if (fallback && !isCorruptedDisplayText(fallback)) {
        return fallback;
      }

      return formatUserRoleLabel(item.rol);
    }

    const adSoyad = (item.ad_soyad ?? "").trim();
    if (adSoyad && !isCorruptedDisplayText(adSoyad)) {
      return adSoyad;
    }

    return formatUserRoleLabel(item.rol);
  }

  async function loadPanel() {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const [kullaniciList, subeList, personelList, departmanList, bolumList, birimList] = await Promise.all([
        fetchYonetimKullanicilari(),
        fetchYonetimSubeleri(),
        fetchPersonellerList({ page: 1, limit: 250, aktiflik: "tum" }),
        fetchDepartmanOptions(),
        fetchBolumOptions(),
        fetchBirimOptions()
      ]);

      setKullanicilar(kullaniciList);
      setSubeler(subeList);
      // SGK scope catalog is best-effort: org-location schema may be absent on legacy backends.
      try {
        const sgkCatalog = await fetchSgkIsverenCatalog();
        setSgkIsverenOptions(
          sgkCatalog
            .map((item) => ({ id: item.id, ad: item.ad, kod: item.kod }))
            .sort((left, right) => left.ad.localeCompare(right.ad, "tr"))
        );
      } catch {
        setSgkIsverenOptions([]);
      }
      // Readiness and the company list are best-effort: on a pre-migration
      // backend the panel must still render the legacy flat branch UI.
      let readinessState: OrganizasyonReadiness | null = null;
      try {
        readinessState = await fetchOrganizasyonReadiness();
      } catch {
        readinessState = null;
      }
      setReadiness(readinessState);
      if (readinessState?.schema_ready) {
        try {
          setSirketler(await fetchYonetimSirketleri());
        } catch {
          setSirketler([]);
        }
        // SGK employer catalog is best-effort too: migration 064 may be older than
        // the running panel, and şube yönetimi must still render.
        try {
          setSgkIsverenleri(await fetchYonetimSgkIsverenleri());
          setIsSgkIsverenCatalogLoaded(true);
        } catch {
          setSgkIsverenleri([]);
          setIsSgkIsverenCatalogLoaded(false);
        }
      } else {
        setSirketler([]);
        setSgkIsverenleri([]);
        setIsSgkIsverenCatalogLoaded(false);
      }
      setPersoneller(personelList.items);
      setDepartmanOptions(sortIdOptions(departmanList));
      setBolumOptions(sortIdOptions(bolumList));
      setBirimOptions(sortIdOptions(birimList));
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Yönetim paneli yüklenemedi.");
      setIsSgkIsverenCatalogLoaded(false);
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
      adSoyad: formatAdSoyad([linkedPersonel.ad, linkedPersonel.soyad].filter(Boolean).join(" ")),
      telefon: formatTelefon(linkedPersonel.telefon ?? prev.telefon)
    }));
  }, [kullaniciForm.kullaniciTipi, kullaniciForm.personelId, personeller]);

  function resetKullaniciEditor() {
    setEditingKullaniciId(null);
    setKullaniciForm(INITIAL_KULLANICI_FORM);
    setIsKullaniciFormOpen(false);
    setFormErrorMessage(null);
    setSifreResetConfirmOpen(false);
  }

  function resetSubeEditor() {
    setEditingSubeId(null);
    setSubeForm(INITIAL_SUBE_FORM);
    setYeniDepartmanAdi("");
    setMuhasebeYetkiliQuery("");
    setSorumluYoneticiQuery("");
    setIsDepartmanCreateOpen(false);
    setIsSubeFormOpen(false);
    setFormErrorMessage(null);
    setSubeDeleteError(null);
    setIsSubeDeleteDialogOpen(false);
    setSubeDeleteDialogError(null);
  }

  function openYeniKullaniciForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setEditingKullaniciId(null);
    setKullaniciForm(INITIAL_KULLANICI_FORM);
    setIsKullaniciFormOpen(true);
    setSifreResetConfirmOpen(false);
  }

  function openKullaniciEditor(item: YonetimKullanici) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setEditingKullaniciId(item.id);
    setKullaniciForm(userFormFromItem(item));
    setIsKullaniciFormOpen(true);
    setSifreResetConfirmOpen(false);
  }

  function openYeniSubeForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setSubeDeleteError(null);
    setEditingSubeId(null);
    setSubeForm(INITIAL_SUBE_FORM);
    setYeniDepartmanAdi("");
    setIsDepartmanCreateOpen(false);
    setIsSubeFormOpen(true);
  }

  function resetSirketEditor() {
    setEditingSirketId(null);
    setSirketForm(INITIAL_SIRKET_FORM);
    setIsSirketFormOpen(false);
    setFormErrorMessage(null);
    setIsSirketDeleteDialogOpen(false);
    setSirketDeleteDialogError(null);
  }

  function openYeniSirketForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setEditingSirketId(null);
    setSirketForm(INITIAL_SIRKET_FORM);
    setIsSirketFormOpen(true);
  }

  function openSirketEditor(item: YonetimSirket) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setEditingSirketId(item.id);
    setSirketForm(sirketFormFromItem(item));
    setIsSirketFormOpen(true);
  }

  async function handleSirketSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    setFormErrorMessage(null);
    setSuccessMessage(null);

    try {
      const payload = toSirketPayload(sirketForm, editingSirketId != null);
      if (editingSirketId != null) {
        await updateYonetimSirket(editingSirketId, payload);
        setSuccessMessage("Şirket tanımı güncellendi.");
      } else {
        await createYonetimSirket(payload);
        setSuccessMessage("Şirket tanımı eklendi.");
      }

      resetSirketEditor();
      await loadPanel();
    } catch (error) {
      setFormErrorMessage(error instanceof Error ? error.message : "Şirket tanımı kaydedilemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  async function confirmSirketDelete() {
    if (editingSirketId == null || isSubmitting || !canManageYonetimPanel) {
      return;
    }

    setIsSubmitting(true);
    setSirketDeleteDialogError(null);
    setSuccessMessage(null);

    try {
      await deleteYonetimSirket(editingSirketId);
      if (selectedSirketId === editingSirketId) {
        closeSirketDetay();
      }
      resetSirketEditor();
      setSuccessMessage("Şirket tanımı silindi.");
      await loadPanel();
    } catch (error) {
      setSirketDeleteDialogError(error instanceof Error ? error.message : "Şirket silinemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  function resetSgkIsverenEditor() {
    setEditingSgkIsverenId(null);
    setSgkIsverenForm(INITIAL_SGK_ISVEREN_FORM);
    setIsSgkIsverenFormOpen(false);
    setSgkIsverenDeleteError(null);
    setIsSgkIsverenDeleteDialogOpen(false);
    setSgkIsverenDeleteDialogError(null);
    setFormErrorMessage(null);
  }

  function openYeniSgkIsverenForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setSgkIsverenDeleteError(null);
    setEditingSgkIsverenId(null);
    // Şirket detayında şirket alanı hazır gelir; kullanıcı yine de değiştirebilir.
    setSgkIsverenForm({
      ...INITIAL_SGK_ISVEREN_FORM,
      sirketId: selectedSirketId != null ? String(selectedSirketId) : ""
    });
    setIsSgkIsverenFormOpen(true);
  }

  function openSgkIsverenEditor(item: YonetimSgkIsveren) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
    setSgkIsverenDeleteError(null);
    setEditingSgkIsverenId(item.id);
    setSgkIsverenForm(sgkIsverenFormFromItem(item));
    setIsSgkIsverenFormOpen(true);
  }

  async function handleSgkIsverenSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    setFormErrorMessage(null);
    setSuccessMessage(null);

    try {
      const payload = toSgkIsverenPayload(sgkIsverenForm);
      if (editingSgkIsverenId != null) {
        await updateYonetimSgkIsveren(editingSgkIsverenId, payload);
        setSuccessMessage("SGK işvereni güncellendi.");
      } else {
        await createYonetimSgkIsveren(payload);
        setSuccessMessage("SGK işvereni eklendi.");
      }

      resetSgkIsverenEditor();
      await loadPanel();
    } catch (error) {
      setFormErrorMessage(error instanceof Error ? error.message : "SGK işvereni kaydedilemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  function openSgkIsverenDeleteDialog() {
    if (editingSgkIsverenId == null || isSubmitting || !canManageYonetimPanel) {
      return;
    }
    setSgkIsverenDeleteError(null);
    setSgkIsverenDeleteDialogError(null);
    setIsSgkIsverenDeleteDialogOpen(true);
  }

  async function confirmSgkIsverenDelete() {
    if (editingSgkIsverenId == null || isSubmitting || !canManageYonetimPanel) {
      return;
    }

    setIsSubmitting(true);
    setSgkIsverenDeleteError(null);
    setSgkIsverenDeleteDialogError(null);
    setSuccessMessage(null);

    try {
      await deleteYonetimSgkIsveren(editingSgkIsverenId);
      setIsSgkIsverenDeleteDialogOpen(false);
      resetSgkIsverenEditor();
      setSuccessMessage("SGK işvereni silindi.");
      await loadPanel();
    } catch (error) {
      const message = error instanceof Error ? error.message : "SGK işvereni silinemedi.";
      setSgkIsverenDeleteError(message);
      setSgkIsverenDeleteDialogError(message);
    } finally {
      setIsSubmitting(false);
    }
  }

  function openSubeEditor(item: YonetimSube) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setFormErrorMessage(null);
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

  function toggleBolumSelection(bolumId: number) {
    setKullaniciForm((prev) => ({
      ...prev,
      bolumIds: prev.bolumIds.includes(bolumId)
        ? prev.bolumIds.filter((id) => id !== bolumId)
        : [...prev.bolumIds, bolumId]
    }));
  }

  function toggleBirimSelection(birimId: number) {
    setKullaniciForm((prev) => ({
      ...prev,
      birimIds: prev.birimIds.includes(birimId)
        ? prev.birimIds.filter((id) => id !== birimId)
        : [...prev.birimIds, birimId]
    }));
  }

  function toggleSirketSelection(sirketId: number) {
    setKullaniciForm((prev) => ({
      ...prev,
      sirketIds: prev.sirketIds.includes(sirketId)
        ? prev.sirketIds.filter((id) => id !== sirketId)
        : [...prev.sirketIds, sirketId]
    }));
  }

  function toggleSgkIsverenSelection(sgkIsverenId: number) {
    setKullaniciForm((prev) => ({
      ...prev,
      sgkIsverenIds: prev.sgkIsverenIds.includes(sgkIsverenId)
        ? prev.sgkIsverenIds.filter((id) => id !== sgkIsverenId)
        : [...prev.sgkIsverenIds, sgkIsverenId]
    }));
  }

  function toggleDepartmanSelection(departmanId: number) {
    setSubeForm((prev) => ({
      ...prev,
      departmanIds: prev.departmanIds.includes(departmanId)
        ? prev.departmanIds.filter((id) => id !== departmanId)
        : [...prev.departmanIds, departmanId]
    }));
  }

  function toggleMuhasebeYetkiliSelection(userId: number) {
    setSubeForm((prev) => ({
      ...prev,
      muhasebeYetkiliUserIds: prev.muhasebeYetkiliUserIds.includes(userId)
        ? prev.muhasebeYetkiliUserIds.filter((id) => id !== userId)
        : [...prev.muhasebeYetkiliUserIds, userId]
    }));
  }

  function toggleSorumluYoneticiSelection(userId: number) {
    setSubeForm((prev) => ({
      ...prev,
      sorumluYoneticiUserIds: prev.sorumluYoneticiUserIds.includes(userId)
        ? prev.sorumluYoneticiUserIds.filter((id) => id !== userId)
        : [...prev.sorumluYoneticiUserIds, userId]
    }));
  }

  async function handleKullaniciSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSubmitting) {
      return;
    }

    setIsSubmitting(true);
    setFormErrorMessage(null);
    setSuccessMessage(null);

    try {
      const payload = toKullaniciPayload(kullaniciForm, editingKullaniciId != null, effectiveVarsayilanSubeIds);
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
      if (isApiRequestError(error) && error.code === "PERSONEL_ALREADY_BOUND") {
        setFormErrorMessage("Bu personel kaydı başka bir kullanıcıya bağlı.");
      } else {
        setFormErrorMessage(error instanceof Error ? error.message : "Kullanıcı kaydı kaydedilemedi.");
      }
    } finally {
      setIsSubmitting(false);
    }
  }

  async function handleBaslangicSifresineSifirla() {
    if (isSubmitting || editingKullaniciId == null) {
      return;
    }

    setIsSubmitting(true);
    setFormErrorMessage(null);
    setSuccessMessage(null);

    try {
      await resetYonetimKullaniciBaslangicSifresi(editingKullaniciId);
      setSifreResetConfirmOpen(false);
      setSuccessMessage("Hesap başlangıç şifresine sıfırlandı. Kullanıcı ilk girişte şifresini değiştirecek.");
      resetKullaniciEditor();
      await loadPanel();
    } catch (error) {
      setFormErrorMessage(
        error instanceof Error ? error.message : "Başlangıç şifresine sıfırlama yapılamadı."
      );
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
    setFormErrorMessage(null);
    setSuccessMessage(null);

    try {
      // Şirket bağlamı varsa SGK seçimi o şirketin AKTIF kayıtlarına karşı,
      // backend'in uyguladığı invariantla aynı şekilde doğrulanır. Katalog
      // okunamadıysa alan hiç gönderilmez: mevcut eşleme korunur.
      const payload = toSubePayload(
        subeForm,
        hasSgkIsverenContext ? { allowedSgkIsverenIds: subeSgkIsverenAllowedIds } : undefined
      );
      // In hierarchy mode the parent company comes from the route/state, never
      // from a form field, so the nested endpoints own the write.
      if (editingSubeId != null) {
        if (selectedSirketId != null) {
          await updateSirketSube(selectedSirketId, editingSubeId, payload);
        } else {
          await updateYonetimSube(editingSubeId, payload);
        }
        setSuccessMessage("Şube tanımı güncellendi.");
      } else {
        if (selectedSirketId != null) {
          await createSirketSube(selectedSirketId, payload);
        } else {
          await createYonetimSube(payload);
        }
        setSuccessMessage("Şube tanımı eklendi.");
      }

      resetSubeEditor();
      await loadPanel();
    } catch (error) {
      setFormErrorMessage(error instanceof Error ? error.message : "Şube tanımı kaydedilemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  function openSubeDeleteDialog() {
    if (editingSubeId == null || isSubmitting || !canManageYonetimPanel) {
      return;
    }
    setSubeDeleteError(null);
    setSubeDeleteDialogError(null);
    setIsSubeDeleteDialogOpen(true);
  }

  function closeSubeDeleteDialog() {
    if (isSubmitting) {
      return;
    }
    setIsSubeDeleteDialogOpen(false);
    setSubeDeleteDialogError(null);
  }

  async function confirmSubeDelete() {
    if (editingSubeId == null || isSubmitting || !canManageYonetimPanel) {
      return;
    }

    setIsSubmitting(true);
    setSubeDeleteError(null);
    setSubeDeleteDialogError(null);
    setSuccessMessage(null);

    try {
      if (selectedSirketId != null) {
        await deleteSirketSube(selectedSirketId, editingSubeId);
      } else {
        await deleteYonetimSube(editingSubeId);
      }
      setIsSubeDeleteDialogOpen(false);
      resetSubeEditor();
      setSuccessMessage("Şube tanımı silindi.");
      await loadPanel();
    } catch (error) {
      const message = error instanceof Error ? error.message : "Şube silinemedi.";
      setSubeDeleteError(message);
      setSubeDeleteDialogError(message);
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
    setFormErrorMessage(null);
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
      setFormErrorMessage(error instanceof Error ? error.message : "Departman eklenemedi.");
    } finally {
      setIsAddingDepartman(false);
    }
  }

  return (
    <section className="yonetim-page">
      {isLoading ? <LoadingState label="Yönetim paneli yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadPanel()} /> : null}
      {!isLoading && successMessage ? <p className="yonetim-success">{successMessage}</p> : null}
      {!isLoading && !errorMessage && activeTab === "kullanicilar" && canOpenQrKiosk ? (
        <p className="yonetim-kiosk-link">
          <Link to="/qr-kiosk" data-testid="yonetim-qr-kiosk-link">
            QR Giriş Ekranı
          </Link>
        </p>
      ) : null}

      {!isLoading && !errorMessage && activeTab === "kullanicilar" ? (
        <section className="yonetim-list-surface" aria-label="Kullanıcı yönetimi" data-testid="yonetim-section-kullanicilar">
          <div className="yonetim-list-header">
            <div className="yonetim-list-actions">
              <YonetimViewToggle
                label="Kullanıcılar görünümü"
                value={kullaniciViewMode}
                onChange={setKullaniciViewMode}
              />
            </div>
          </div>

          <div className="yonetim-summary-grid" data-testid="yonetim-kullanici-first-login-summary">
            <article className="yonetim-summary-card">
              <span>{PERSONEL_FIRST_LOGIN_PENDING_LABEL}</span>
              <strong data-testid="yonetim-kullanici-first-login-pending-count">{firstLoginSummary.pending}</strong>
            </article>
            <article className="yonetim-summary-card">
              <span>{PERSONEL_FIRST_LOGIN_COMPLETE_LABEL}</span>
              <strong data-testid="yonetim-kullanici-first-login-completed-count">{firstLoginSummary.completed}</strong>
            </article>
          </div>

          <div className="yonetim-kullanici-first-login-filter" data-testid="yonetim-kullanici-first-login-filter">
            <FormField
              as="select"
              label="İlk giriş durumu"
              name="yonetim-kullanici-first-login-filter"
              value={firstLoginFilter}
              onChange={(value) =>
                setFirstLoginFilter(
                  value === "pending" || value === "completed" ? value : "all"
                )
              }
              selectOptions={FIRST_LOGIN_FILTER_OPTIONS}
            />
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

          {filteredKullanicilar.length === 0 ? (
            <EmptyState
              title={kullanicilar.length === 0 ? "Kullanıcı kaydı yok" : "Filtreye uygun kullanıcı yok"}
              message={
                kullanicilar.length === 0
                  ? "İlk kullanıcı atamasını buradan oluşturabilirsin."
                  : "İlk giriş filtresini değiştirerek diğer kullanıcıları görebilirsin."
              }
            />
          ) : kullaniciViewMode === "card" ? (
            <div className="yonetim-card-grid yonetim-card-grid--users">
              {filteredKullanicilar.map((item) => {
                const firstLoginLabel = resolvePersonelFirstLoginLabel(item);
                return (
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
                    {firstLoginLabel ? (
                      <span
                        className={
                          item.activation_required === true || item.must_change_password === true
                            ? "yonetim-first-login-badge yonetim-first-login-badge--pending"
                            : "yonetim-first-login-badge yonetim-first-login-badge--complete"
                        }
                        data-testid={`yonetim-kullanici-first-login-badge-${item.id}`}
                      >
                        {firstLoginLabel}
                      </span>
                    ) : null}
                  </div>
                </article>
                );
              })}
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
                    <th>İlk Giriş</th>
                  </tr>
                </thead>
                <tbody>
                  {filteredKullanicilar.map((item) => {
                    const firstLoginLabel = resolvePersonelFirstLoginLabel(item);
                    return (
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
                      <td>{formatUserRoleLabel(item.rol)}</td>
                      <td title={formatSubeScopeLabel(item.sube_ids, subeNameMap)}>
                        {formatSubeScopeLabel(item.sube_ids, subeNameMap)}
                      </td>
                      <td>{formatVarsayilanSubeLabel(item.varsayilan_sube_id, subeNameMap)}</td>
                      <td>{DURUM_LABELS[item.durum]}</td>
                      <td>
                        {firstLoginLabel ? (
                          <span
                            className={
                              item.activation_required === true || item.must_change_password === true
                                ? "yonetim-first-login-badge yonetim-first-login-badge--pending"
                                : "yonetim-first-login-badge yonetim-first-login-badge--complete"
                            }
                            data-testid={`yonetim-kullanici-first-login-badge-${item.id}`}
                          >
                            {firstLoginLabel}
                          </span>
                        ) : (
                          "—"
                        )}
                      </td>
                    </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </section>
      ) : null}

      {!isLoading && !errorMessage && activeTab === "subeler" ? (
        <section
          className="yonetim-list-surface"
          aria-label="Şirket ve şube yönetimi"
          data-testid="yonetim-section-subeler"
          data-mode={hierarchyMode ? (selectedSirketId != null ? "sirket-detay" : "sirketler") : "legacy"}
        >
          <div className="yonetim-list-header">
            <div className="yonetim-list-actions">
              <YonetimViewToggle label="Şubeler görünümü" value={subeViewMode} onChange={setSubeViewMode} />
            </div>
          </div>

          {!hierarchyMode && readiness?.schema_ready ? (
            <p className="yonetim-hint" data-testid="yonetim-organizasyon-readiness-note">
              Şirket hiyerarşisi şeması hazır, mevcut şubeler henüz şirketlere eşlenmedi. Eşleme
              tamamlanana kadar şube yönetimi mevcut düz listeyle sürüyor.
            </p>
          ) : null}

          {hierarchyMode && selectedSirketId != null ? (
            <nav className="yonetim-breadcrumb" aria-label="Şirket kırılımı" data-testid="yonetim-sirket-breadcrumb">
              <button type="button" className="yonetim-create-link" onClick={closeSirketDetay}>
                Şirketler
              </button>
              <span aria-hidden> › </span>
              <span>{selectedSirket?.ad ?? `Şirket ${selectedSirketId}`}</span>
            </nav>
          ) : null}

          <div className="yonetim-create-row">
            {hierarchyMode && selectedSirketId == null ? (
              <button
                type="button"
                className="yonetim-create-link"
                data-testid="yonetim-sirket-yeni"
                onClick={openYeniSirketForm}
              >
                + Yeni Şirket
              </button>
            ) : (
              <button
                type="button"
                className="yonetim-create-link"
                data-testid="yonetim-sube-yeni"
                onClick={openYeniSubeForm}
              >
                + Yeni Şube
              </button>
            )}
          </div>

          {hierarchyMode && selectedSirketId == null ? (
            sirketler.length === 0 ? (
              <EmptyState title="Şirket tanımı yok" message="İlk şirket kaydını buradan oluşturabilirsin." />
            ) : (
              <div className="yonetim-card-grid yonetim-card-grid--branches">
                {sirketler.map((item) => (
                  <article
                    key={item.id}
                    className="yonetim-entity-card yonetim-entity-card--branch-preview yonetim-entity-card--interactive"
                    role="button"
                    tabIndex={0}
                    data-testid={`yonetim-sirket-card-${item.id}`}
                    onClick={() => openSirketDetay(item.id)}
                    onKeyDown={(event) => {
                      if (isActivationKey(event)) {
                        event.preventDefault();
                        openSirketDetay(item.id);
                      }
                    }}
                  >
                    <div className="yonetim-card-meta">
                      <strong>{item.ad}</strong>
                      <span>{item.kod}</span>
                    </div>
                    <p>{item.sube_sayisi} şube</p>
                    <p>Durum: {DURUM_LABELS[item.durum]}</p>
                    <button
                      type="button"
                      className="yonetim-create-link"
                      data-testid={`yonetim-sirket-duzenle-${item.id}`}
                      onClick={(event) => {
                        event.stopPropagation();
                        openSirketEditor(item);
                      }}
                    >
                      Düzenle
                    </button>
                  </article>
                ))}
              </div>
            )
          ) : visibleSubeler.length === 0 ? (
            <EmptyState title="Şube tanımı yok" message="İlk şube kaydını buradan oluşturmaya başlayabilirsin." />
          ) : subeViewMode === "card" ? (
            <div className="yonetim-card-grid yonetim-card-grid--branches">
              {visibleSubeler.map((item) => (
                <article
                  key={item.id}
                  data-testid={`yonetim-sube-card-${item.id}`}
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
                    <strong>{subeListLabel(item)}</strong>
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
                  {visibleSubeler.map((item) => (
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
                      <td className="yonetim-list-table-cell-strong">{subeListLabel(item)}</td>
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

          {hierarchyMode ? (
            <div
              className="yonetim-checkbox-section"
              role="group"
              aria-label="SGK işverenleri"
              data-testid="yonetim-sgk-isveren-section"
            >
              <div className="yonetim-departman-section-head">
                <p className="yonetim-checkbox-title">SGK İşverenleri</p>
                <p className="yonetim-hint">
                  Şirket bazlı SGK işvereni kataloğu. Şube–SGK eşlemesi yalnız bu kayıtlar üzerinden
                  kurulur; şube adından, ilden veya lokasyondan türetilmez.
                </p>
              </div>
              <div className="yonetim-create-row">
                <button
                  type="button"
                  className="yonetim-create-link"
                  data-testid="yonetim-sgk-isveren-yeni"
                  onClick={openYeniSgkIsverenForm}
                >
                  + Yeni SGK İşvereni
                </button>
              </div>

              {!isSgkIsverenCatalogLoaded ? (
                <p className="yonetim-hint" role="status" data-testid="yonetim-sgk-isveren-load-error">
                  SGK işveren listesi şu anda okunamadı. Sayfa yenilendiğinde tekrar denenecek; mevcut
                  şube eşlemeleri korunur.
                </p>
              ) : visibleSgkIsverenleri.length === 0 ? (
                <EmptyState
                  title="SGK işvereni tanımı yok"
                  message={
                    selectedSirketId == null
                      ? "Henüz SGK işvereni kaydı yok. İlk kaydı buradan oluşturup şirkete bağlayabilirsin."
                      : "Bu şirkete bağlı SGK işvereni bulunmuyor. Yeni SGK işvereni oluşturup bu şirkete bağlayabilirsin."
                  }
                />
              ) : (
                <div className="yonetim-card-grid yonetim-card-grid--branches">
                  {visibleSgkIsverenleri.map((item) => (
                    <article
                      key={item.id}
                      className="yonetim-entity-card yonetim-entity-card--branch-preview"
                      data-testid={`yonetim-sgk-isveren-card-${item.id}`}
                    >
                      <div className="yonetim-card-meta">
                        <strong>{item.ad}</strong>
                        <span>{item.kod ?? "—"}</span>
                      </div>
                      <p>{item.sirket ? item.sirket.ad : "Şirket bağlantısı tanımlı değil"}</p>
                      <p>{item.sube_sayisi} şube</p>
                      <p>Durum: {DURUM_LABELS[item.durum]}</p>
                      <button
                        type="button"
                        className="yonetim-create-link"
                        data-testid={`yonetim-sgk-isveren-duzenle-${item.id}`}
                        onClick={() => openSgkIsverenEditor(item)}
                      >
                        Düzenle
                      </button>
                    </article>
                  ))}
                </div>
              )}
            </div>
          ) : null}
        </section>
      ) : null}

      {!isLoading && !errorMessage && activeTab === "mevzuat" && canViewMevzuat ? (
        <MevzuatParametreleriPanel canManage={canManageMevzuat} />
      ) : null}

      {!isLoading && !errorMessage && activeTab === "saklama" && canViewSaklama ? (
        <SaklamaLegalHoldPanel />
      ) : null}

      {isKullaniciFormOpen ? (
        <AppModal
          title={editingKullaniciId != null ? "Kullanıcı Düzenleme" : "Yeni Kullanıcı"}
          backLabel="Kullanıcı Yönetimi"
          onBack={resetKullaniciEditor}
          onClose={resetKullaniciEditor}
        >
          <form
            className="yonetim-form-stack yonetim-kullanici-workspace"
            id={YONETIM_KULLANICI_FORM_ID}
            onSubmit={handleKullaniciSubmit}
          >
            <fieldset className="yonetim-workspace-section">
              <legend>Kimlik ve erişim</legend>
              <div className="form-field-grid">
              <FormField
                label="Kullanıcı Adı"
                name="yonetim-kullanici-username"
                value={kullaniciForm.username}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, username: value }))}
                required
              />
              {!isSecurePersonelCreatePath ? (
                editingKullaniciId == null ? (
                  <p className="yonetim-hint" data-testid="yonetim-baslangic-sifresi-hint">
                    Yeni hesabın başlangıç şifresi, girdiğiniz ad soyad bilgisinden şirket kuralıyla
                    otomatik üretilir. Kullanıcı ilk girişte bu şifreyi kendi kalıcı şifresiyle değiştirir.
                  </p>
                ) : (
                  <div className="yonetim-form-stack" data-testid="yonetim-baslangic-sifresi-reset">
                    <p className="yonetim-hint">
                      Şifre bu formdan belirlenmez. Gerekiyorsa hesabı ilk giriş durumuna alın; kullanıcı
                      ad soyadından üretilen başlangıç şifresiyle girip kendi kalıcı şifresini belirler.
                    </p>
                    {!sifreResetConfirmOpen ? (
                      <button
                        type="button"
                        className="yonetim-panel-action"
                        data-testid="yonetim-kullanici-sifre-sifirla"
                        disabled={isSubmitting}
                        onClick={() => {
                          setFormErrorMessage(null);
                          setSifreResetConfirmOpen(true);
                        }}
                      >
                        Başlangıç Şifresine Sıfırla
                      </button>
                    ) : (
                      <div className="yonetim-create-row" data-testid="yonetim-kullanici-sifre-sifirla-confirm">
                        <p className="yonetim-hint">
                          Hesap, kullanıcının ad soyadından üretilen başlangıç şifresine döner ve kullanıcı
                          ilk girişte kendi kalıcı şifresini belirlemek zorunda kalır. Rol, yetki ve personel
                          bağlantısı değişmez.
                        </p>
                        <button
                          type="button"
                          className="universal-btn-save"
                          data-testid="yonetim-kullanici-sifre-sifirla-onayla"
                          disabled={isSubmitting}
                          onClick={() => void handleBaslangicSifresineSifirla()}
                        >
                          {isSubmitting ? "Sıfırlanıyor…" : "Onayla"}
                        </button>
                        <button
                          type="button"
                          className="yonetim-panel-action"
                          disabled={isSubmitting}
                          onClick={() => setSifreResetConfirmOpen(false)}
                        >
                          Vazgeç
                        </button>
                      </div>
                    )}
                  </div>
                )
              ) : (
                <p className="yonetim-hint" data-testid="yonetim-personel-secure-onboarding-hint">
                  PERSONEL rolü için şifre buradan atanmaz. Personel hesabını ilgili personel kartındaki
                  &quot;Personel Hesabı Oluştur&quot; akışı ile oluşturun; personel şifresini aktivasyon
                  bağlantısı üzerinden kendisi belirler.
                </p>
              )}
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
                selectOptions={roleOptions(kullaniciForm.rol)}
                disabled={kullaniciForm.rol === "AUTH_SMOKE_READONLY"}
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
              {showBagliPersonelSelector ? (
                <FormField
                  as="select"
                  label="Bağlı Personel"
                  name="yonetim-kullanici-personel"
                  value={kullaniciForm.personelId}
                  onChange={(value) => setKullaniciForm((prev) => ({ ...prev, personelId: value }))}
                  placeholderOption={{
                    value: "",
                    label: realKullaniciApi ? "Bağlantı yok" : "Seçiniz"
                  }}
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
              <KullaniciRoleSummaryPanel role={kullaniciForm.rol} />
            </fieldset>

            <fieldset className="yonetim-workspace-section">
              <legend>Organizasyon kapsamı</legend>
              <FormField
                as="select"
                label="Varsayılan Şube"
                name="yonetim-kullanici-varsayilan-sube"
                value={kullaniciForm.varsayilanSubeId}
                onChange={(value) => setKullaniciForm((prev) => ({ ...prev, varsayilanSubeId: value }))}
                placeholderOption={{ value: "", label: "Tüm Şubeler / Seçimsiz" }}
                selectOptions={subeler
                  .filter((sube) => effectiveVarsayilanSubeIds.includes(sube.id))
                  .map((sube) => ({ value: String(sube.id), label: sube.tam_ad }))}
              />
              <YonetimOrgScopeFields
                role={kullaniciForm.rol}
                subeler={subeler}
                bolumler={bolumOptions.map((b) => ({ id: b.id, ad: b.label }))}
                birimler={birimOptions.map((b) => ({ id: b.id, ad: b.label }))}
                selectedSubeIds={kullaniciForm.subeIds}
                selectedBolumIds={kullaniciForm.bolumIds}
                selectedBirimIds={kullaniciForm.birimIds}
                onToggleSube={toggleSubeSelection}
                onToggleBolum={toggleBolumSelection}
                onToggleBirim={toggleBirimSelection}
                sirketler={sirketler}
                selectedSirketIds={kullaniciForm.sirketIds}
                onToggleSirket={toggleSirketSelection}
                sgkIsverenler={sgkIsverenOptions}
                selectedSgkIsverenIds={kullaniciForm.sgkIsverenIds}
                onToggleSgkIsveren={toggleSgkIsverenSelection}
              />
            </fieldset>

            {editingKullaniciId != null ? (
              <fieldset className="yonetim-workspace-section">
                <legend>SGK yetkili kimliği</legend>
                <KullaniciActorIdentityPanel
                  userId={editingKullaniciId}
                  canManage={canManageYonetimPanel}
                  formatSubeScope={(subeIds) => formatSubeScopeLabel(subeIds, subeNameMap)}
                />
              </fieldset>
            ) : null}

            {formErrorMessage ? (
              <p className="yonetim-inline-error" role="alert" data-testid="yonetim-kullanici-form-error">
                {formErrorMessage}
              </p>
            ) : null}

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
            <p className="yonetim-hint" data-testid="yonetim-sube-form-sirket-hint">
              {selectedSirket
                ? `${selectedSirket.ad} şirketi altında kısa şube adı girin; şirket adını tekrar yazmayın.`
                : "Yalnızca kısa şube adı girin."}
            </p>
            <div className="form-field-grid">
              <FormField
                label="Şube Kodu"
                name="yonetim-sube-kod"
                value={subeForm.kod}
                onChange={(value) => setSubeForm((prev) => ({ ...prev, kod: value }))}
                required
                disabled={editingSubeId != null}
              />
              <FormField
                label="Şube kısa adı"
                name="yonetim-sube-ad"
                value={subeForm.ad}
                onChange={(value) => setSubeForm((prev) => ({ ...prev, ad: value }))}
                required
                placeholder="Ankara"
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

            <div
              className="yonetim-checkbox-section"
              role="group"
              aria-label="SGK işvereni"
              data-testid="yonetim-sube-sgk-isveren-section"
            >
              <div className="yonetim-departman-section-head">
                <p className="yonetim-checkbox-title">SGK İşvereni</p>
                <p className="yonetim-hint" data-testid="yonetim-sube-sgk-isveren-note">
                  {!hierarchyMode
                    ? "Şirket hiyerarşisi hazır olmadığı için SGK işvereni bu formdan değiştirilemez; mevcut eşleme korunur."
                    : !isSgkIsverenCatalogLoaded
                      ? "SGK işveren listesi bu oturumda okunamadı; mevcut eşleme korunur ve bu formdan değiştirilemez."
                      : subeSgkIsverenSelectOptions.length === 0
                        ? "Bu şirkete ait aktif SGK işvereni bulunmuyor. Aşağıdaki SGK İşverenleri bölümünden oluşturup şirkete bağlayabilirsiniz."
                        : "Yalnız bu şirkete ait aktif SGK işverenleri seçilebilir. Şube adı veya ilden eşleme türetilmez."}
                </p>
              </div>
              {hasSgkIsverenContext ? (
                <AppSelectField
                  label="Şube SGK İşvereni"
                  name="yonetim-sube-sgk-isveren"
                  value={subeForm.sgkIsverenId}
                  onChange={(value) => setSubeForm((prev) => ({ ...prev, sgkIsverenId: value }))}
                  placeholderOption={{ value: "", label: "Seçiniz" }}
                  options={subeSgkIsverenSelectOptions}
                  dataTestId="yonetim-sube-sgk-isveren"
                />
              ) : null}
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

            <div className="yonetim-checkbox-section" data-testid="yonetim-sube-muhasebe-kisit">
              <label className="yonetim-checkbox-row">
                <input
                  type="checkbox"
                  checked={subeForm.muhasebeKisitAktif}
                  data-testid="yonetim-sube-muhasebe-kisit-checkbox"
                  onChange={(event) => {
                    const checked = event.target.checked;
                    setSubeForm((prev) => ({
                      ...prev,
                      muhasebeKisitAktif: checked,
                      muhasebeYetkiliUserIds: checked ? prev.muhasebeYetkiliUserIds : []
                    }));
                    if (!checked) {
                      setMuhasebeYetkiliQuery("");
                    }
                  }}
                />
                <span>Verileri Sadece İlgili Muhasebe Yetkilileri Görebilsin.</span>
              </label>

              {subeForm.muhasebeKisitAktif ? (
                <div className="yonetim-selection-panel" data-testid="yonetim-sube-muhasebe-yetkili-panel">
                  <div className="yonetim-departman-section-head">
                    <p className="yonetim-checkbox-title">Muhasebe Yetkilileri</p>
                    <p className="yonetim-hint">
                      {selectedMuhasebeLabels.length > 0
                        ? `Seçili: ${selectedMuhasebeLabels.join(", ")}`
                        : "En az bir aktif muhasebe yetkilisi seçin."}
                    </p>
                  </div>
                  <input
                    className="form-input"
                    type="search"
                    value={muhasebeYetkiliQuery}
                    onChange={(event) => setMuhasebeYetkiliQuery(event.target.value)}
                    placeholder="Muhasebe yetkilisi ara"
                    data-testid="yonetim-sube-muhasebe-yetkili-search"
                  />
                  <div className="yonetim-selection-grid yonetim-selection-grid--departmanlar">
                    {filteredMuhasebeOptions.length === 0 ? (
                      <p className="yonetim-hint">Seçilebilir aktif muhasebe kullanıcısı yok.</p>
                    ) : (
                      filteredMuhasebeOptions.map((item) => (
                        <button
                          key={item.id}
                          type="button"
                          className={`yonetim-selection-pill${subeForm.muhasebeYetkiliUserIds.includes(item.id) ? " is-selected" : ""}`}
                          data-testid={`yonetim-sube-muhasebe-yetkili-option-${item.id}`}
                          disabled={!item.eligible && !subeForm.muhasebeYetkiliUserIds.includes(item.id)}
                          onClick={() => {
                            if (!item.eligible && !subeForm.muhasebeYetkiliUserIds.includes(item.id)) {
                              return;
                            }
                            toggleMuhasebeYetkiliSelection(item.id);
                          }}
                        >
                          <strong>
                            {item.label}
                            {!item.eligible ? " (uygunsuz)" : ""}
                          </strong>
                        </button>
                      ))
                    )}
                  </div>
                </div>
              ) : null}
            </div>

            <div className="yonetim-checkbox-section" data-testid="yonetim-sube-sorumlu-yonetici">
              <div className="yonetim-departman-section-head">
                <p className="yonetim-checkbox-title">Sorumlu Yönetici(ler)</p>
                <p className="yonetim-hint">
                  Şube sorumluluk atamasıdır; şube yetkisi / user_subeler erişim kapsamından ayrıdır.
                  Boş bırakılabilir. Personel şube veya fiziksel lokasyon değişmez.
                  {selectedSorumluYoneticiLabels.length > 0
                    ? ` Seçili: ${selectedSorumluYoneticiLabels.join(", ")}`
                    : " Yöneticisiz şube geçerlidir."}
                </p>
              </div>
              <div className="yonetim-selection-panel" data-testid="yonetim-sube-sorumlu-yonetici-panel">
                <input
                  className="form-input"
                  type="search"
                  value={sorumluYoneticiQuery}
                  onChange={(event) => setSorumluYoneticiQuery(event.target.value)}
                  placeholder="Sorumlu yönetici ara"
                  data-testid="yonetim-sube-sorumlu-yonetici-search"
                />
                <div className="yonetim-selection-grid yonetim-selection-grid--departmanlar">
                  {filteredSorumluYoneticiOptions.length === 0 ? (
                    <p className="yonetim-hint">Seçilebilir uygun kullanıcı yok.</p>
                  ) : (
                    filteredSorumluYoneticiOptions.map((item) => (
                      <button
                        key={item.id}
                        type="button"
                        className={`yonetim-selection-pill${subeForm.sorumluYoneticiUserIds.includes(item.id) ? " is-selected" : ""}`}
                        data-testid={`yonetim-sube-sorumlu-yonetici-option-${item.id}`}
                        disabled={!item.eligible && !subeForm.sorumluYoneticiUserIds.includes(item.id)}
                        onClick={() => {
                          if (!item.eligible && !subeForm.sorumluYoneticiUserIds.includes(item.id)) {
                            return;
                          }
                          toggleSorumluYoneticiSelection(item.id);
                        }}
                      >
                        <strong>
                          {item.label}
                          {!item.eligible ? " (uygunsuz)" : ""}
                        </strong>
                      </button>
                    ))
                  )}
                </div>
              </div>
            </div>

            {formErrorMessage ? (
              <p className="yonetim-inline-error" role="alert" data-testid="yonetim-sube-form-error">
                {formErrorMessage}
              </p>
            ) : null}

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
                  onClick={openSubeDeleteDialog}
                  disabled={isSubmitting}
                >
                  Şubeyi Sil
                </button>
              </div>
            ) : null}
          </form>
        </AppModal>
      ) : null}

      {isSirketFormOpen ? (
        <AppModal
          title={editingSirketId != null ? "Şirket Düzenle" : "Yeni Şirket"}
          backLabel="Şirket ve Şube Yönetimi"
          onBack={resetSirketEditor}
          onClose={resetSirketEditor}
        >
          <form className="yonetim-form-stack" id={YONETIM_SIRKET_FORM_ID} onSubmit={handleSirketSubmit}>
            <div className="form-field-grid">
              <FormField
                label="Şirket Kodu"
                name="yonetim-sirket-kod"
                value={sirketForm.kod}
                onChange={(value) => setSirketForm((prev) => ({ ...prev, kod: value }))}
                required
                disabled={editingSirketId != null}
              />
              <FormField
                label="Şirket Adı"
                name="yonetim-sirket-ad"
                value={sirketForm.ad}
                onChange={(value) => setSirketForm((prev) => ({ ...prev, ad: value }))}
                required
                placeholder="Medisa"
              />
              <div className="yonetim-durum-row">
                <span className="yonetim-durum-label">Durum</span>
                <div className="yonetim-durum-toggle" role="group" aria-label="Durum">
                  <button
                    type="button"
                    className={`yonetim-durum-btn${sirketForm.durum === "AKTIF" ? " is-active" : ""}`}
                    aria-pressed={sirketForm.durum === "AKTIF"}
                    onClick={() => setSirketForm((prev) => ({ ...prev, durum: "AKTIF" }))}
                  >
                    {DURUM_LABELS.AKTIF}
                  </button>
                  <button
                    type="button"
                    className={`yonetim-durum-btn${sirketForm.durum === "PASIF" ? " is-active" : ""}`}
                    aria-pressed={sirketForm.durum === "PASIF"}
                    onClick={() => setSirketForm((prev) => ({ ...prev, durum: "PASIF" }))}
                  >
                    {DURUM_LABELS.PASIF}
                  </button>
                </div>
              </div>
            </div>

            {formErrorMessage ? (
              <p className="yonetim-inline-error" role="alert" data-testid="yonetim-sirket-form-error">
                {formErrorMessage}
              </p>
            ) : null}

            <div className="form-actions-row">
              <button type="submit" className="universal-btn-save" data-testid="yonetim-sirket-kaydet">
                {editingSirketId != null ? "Şirketi Güncelle" : "Şirketi Kaydet"}
              </button>
              <button type="button" className="universal-btn-cancel" onClick={resetSirketEditor}>
                Vazgeç
              </button>
            </div>

            {editingSirketId != null && canManageYonetimPanel ? (
              <div className="form-actions-row">
                <button
                  type="button"
                  className="universal-btn-cancel"
                  data-testid="yonetim-sirket-sil"
                  onClick={() => {
                    setSirketDeleteDialogError(null);
                    setIsSirketDeleteDialogOpen(true);
                  }}
                  disabled={isSubmitting}
                >
                  Şirketi Sil
                </button>
              </div>
            ) : null}
          </form>
        </AppModal>
      ) : null}

      {isSirketDeleteDialogOpen ? (
        <AppActionDialog
          open
          testId="yonetim-sirket-delete-dialog"
          title="Şirketi Sil"
          description="Bu şirketi silmek istediğinize emin misiniz?"
          confirmLabel="Şirketi Sil"
          submitLabel="Siliniyor..."
          destructive
          isSubmitting={isSubmitting}
          errorMessage={sirketDeleteDialogError}
          onConfirm={confirmSirketDelete}
          onCancel={() => {
            if (!isSubmitting) {
              setIsSirketDeleteDialogOpen(false);
              setSirketDeleteDialogError(null);
            }
          }}
        />
      ) : null}

      {isSgkIsverenFormOpen ? (
        <AppModal
          title={editingSgkIsverenId != null ? "SGK İşvereni Düzenle" : "Yeni SGK İşvereni"}
          backLabel="Şirket ve Şube Yönetimi"
          onBack={resetSgkIsverenEditor}
          onClose={resetSgkIsverenEditor}
        >
          <form className="yonetim-form-stack" id={YONETIM_SGK_ISVEREN_FORM_ID} onSubmit={handleSgkIsverenSubmit}>
            <div className="form-field-grid">
              <AppSelectField
                label="Şirket"
                name="yonetim-sgk-isveren-sirket"
                value={sgkIsverenForm.sirketId}
                onChange={(value) => setSgkIsverenForm((prev) => ({ ...prev, sirketId: value }))}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                options={sgkIsverenSirketSelectOptions}
                dataTestId="yonetim-sgk-isveren-sirket"
              />
              <FormField
                label="SGK İşveren Kodu"
                name="yonetim-sgk-isveren-kod"
                value={sgkIsverenForm.kod}
                onChange={(value) => setSgkIsverenForm((prev) => ({ ...prev, kod: value }))}
                required
              />
              <FormField
                label="SGK İşveren Adı"
                name="yonetim-sgk-isveren-ad"
                value={sgkIsverenForm.ad}
                onChange={(value) => setSgkIsverenForm((prev) => ({ ...prev, ad: value }))}
                required
                placeholder="MEDISA BURSA"
              />
              <div className="yonetim-durum-row">
                <span className="yonetim-durum-label">Durum</span>
                <div className="yonetim-durum-toggle" role="group" aria-label="Durum">
                  <button
                    type="button"
                    className={`yonetim-durum-btn${sgkIsverenForm.durum === "AKTIF" ? " is-active" : ""}`}
                    aria-pressed={sgkIsverenForm.durum === "AKTIF"}
                    onClick={() => setSgkIsverenForm((prev) => ({ ...prev, durum: "AKTIF" }))}
                  >
                    {DURUM_LABELS.AKTIF}
                  </button>
                  <button
                    type="button"
                    className={`yonetim-durum-btn${sgkIsverenForm.durum === "PASIF" ? " is-active" : ""}`}
                    aria-pressed={sgkIsverenForm.durum === "PASIF"}
                    onClick={() => setSgkIsverenForm((prev) => ({ ...prev, durum: "PASIF" }))}
                  >
                    {DURUM_LABELS.PASIF}
                  </button>
                </div>
              </div>
            </div>

            <p className="yonetim-hint" data-testid="yonetim-sgk-isveren-form-hint">
              {editingSgkIsverenId != null
                ? "Şirket değişikliği, bağlı şube veya personel kayıtlarının şirket tutarlılığını bozacaksa sunucu tarafında reddedilir."
                : "Pasif SGK işvereni yeni bir şubeye bağlanamaz. Bağlı kaydı silmek yerine pasife alın."}
            </p>

            {formErrorMessage ? (
              <p className="yonetim-inline-error" role="alert" data-testid="yonetim-sgk-isveren-form-error">
                {formErrorMessage}
              </p>
            ) : null}

            {sgkIsverenDeleteError ? (
              <p className="yonetim-inline-error" role="alert">
                {sgkIsverenDeleteError}
              </p>
            ) : null}

            <div className="form-actions-row">
              <button type="submit" className="universal-btn-save" data-testid="yonetim-sgk-isveren-kaydet">
                {editingSgkIsverenId != null ? "SGK İşverenini Güncelle" : "SGK İşverenini Kaydet"}
              </button>
              <button type="button" className="universal-btn-cancel" onClick={resetSgkIsverenEditor}>
                Vazgeç
              </button>
            </div>

            {editingSgkIsverenId != null && canManageYonetimPanel ? (
              <div className="form-actions-row">
                <button
                  type="button"
                  className="universal-btn-cancel"
                  data-testid="yonetim-sgk-isveren-sil"
                  onClick={openSgkIsverenDeleteDialog}
                  disabled={isSubmitting}
                >
                  SGK İşverenini Sil
                </button>
              </div>
            ) : null}
          </form>
        </AppModal>
      ) : null}

      {isSgkIsverenDeleteDialogOpen ? (
        <AppActionDialog
          open
          testId="yonetim-sgk-isveren-delete-dialog"
          title="SGK İşverenini Sil"
          description="Bu SGK işverenini silmek istediğinize emin misiniz? Şube, personel veya yetki kapsamına bağlı bir kayıt silinemez; bunun yerine pasife alın."
          confirmLabel="SGK İşverenini Sil"
          submitLabel="Siliniyor..."
          destructive
          isSubmitting={isSubmitting}
          errorMessage={sgkIsverenDeleteDialogError}
          onConfirm={confirmSgkIsverenDelete}
          onCancel={() => {
            if (!isSubmitting) {
              setIsSgkIsverenDeleteDialogOpen(false);
              setSgkIsverenDeleteDialogError(null);
            }
          }}
        />
      ) : null}

      {isSubeDeleteDialogOpen ? (
        <AppActionDialog
          open
          testId="yonetim-sube-delete-dialog"
          title="Şubeyi Sil"
          description="Bu şubeyi silmek istediğinize emin misiniz?"
          confirmLabel="Şubeyi Sil"
          submitLabel="Siliniyor..."
          destructive
          isSubmitting={isSubmitting}
          errorMessage={subeDeleteDialogError}
          onConfirm={confirmSubeDelete}
          onCancel={closeSubeDeleteDialog}
        />
      ) : null}
    </section>
  );
}
