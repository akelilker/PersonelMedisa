import { useEffect, useMemo, useState, type FormEvent } from "react";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { createDepartmanOption, fetchDepartmanOptions } from "../../../api/referans.api";
import {
  createYonetimKullanici,
  createYonetimSube,
  fetchYonetimKullanicilari,
  fetchYonetimSubeleri,
  updateYonetimKullanici,
  updateYonetimSube
} from "../../../api/yonetim.api";
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
type SubeViewMode = "liste" | "form";

type KullaniciFormState = {
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

function roleOptions() {
  return Object.entries(ROLE_LABELS).map(([value, label]) => ({ value, label }));
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
    kullaniciTipi: item.kullanici_tipi,
    personelId: item.personel_id != null ? String(item.personel_id) : "",
    adSoyad: item.ad_soyad,
    telefon: item.telefon ?? "",
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

function toKullaniciPayload(form: KullaniciFormState): UpsertYonetimKullaniciPayload {
  const adSoyad = form.adSoyad.trim();
  if (!adSoyad) {
    throw new Error("Ad soyad zorunludur.");
  }

  if (form.kullaniciTipi === "IC_PERSONEL" && !form.personelId) {
    throw new Error("İç personel kullanıcıları için personel seçimi zorunludur.");
  }

  if (form.varsayilanSubeId && !form.subeIds.includes(Number.parseInt(form.varsayilanSubeId, 10))) {
    throw new Error("Varsayılan şube, yetki verilen şubeler içinde olmalıdır.");
  }

  return {
    ad_soyad: adSoyad,
    telefon: form.telefon.trim() || undefined,
    kullanici_tipi: form.kullaniciTipi,
    rol: form.rol,
    personel_id: form.personelId ? Number.parseInt(form.personelId, 10) : null,
    sube_ids: form.subeIds,
    varsayilan_sube_id: form.varsayilanSubeId ? Number.parseInt(form.varsayilanSubeId, 10) : null,
    durum: form.durum,
    notlar: form.notlar.trim() || undefined
  };
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

export function YonetimPaneliPage() {
  const [activeTab, setActiveTab] = useState<ActiveTab>("kullanicilar");
  const [subeViewMode, setSubeViewMode] = useState<SubeViewMode>("liste");
  const [isLoading, setIsLoading] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isAddingDepartman, setIsAddingDepartman] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

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
        label: `${personel.ad} ${personel.soyad}`
      })),
    [personeller]
  );

  const subeNameMap = useMemo(() => new Map(subeler.map((sube) => [sube.id, sube.ad])), [subeler]);

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
      adSoyad: `${linkedPersonel.ad} ${linkedPersonel.soyad}`,
      telefon: linkedPersonel.telefon ?? prev.telefon
    }));
  }, [kullaniciForm.kullaniciTipi, kullaniciForm.personelId, personeller]);

  function resetKullaniciEditor() {
    setEditingKullaniciId(null);
    setKullaniciForm(INITIAL_KULLANICI_FORM);
  }

  function resetSubeEditor() {
    setEditingSubeId(null);
    setSubeForm(INITIAL_SUBE_FORM);
    setYeniDepartmanAdi("");
    setSubeViewMode("liste");
  }

  function openYeniSubeForm() {
    setSuccessMessage(null);
    setErrorMessage(null);
    setEditingSubeId(null);
    setSubeForm(INITIAL_SUBE_FORM);
    setYeniDepartmanAdi("");
    setSubeViewMode("form");
  }

  function openSubeEditor(item: YonetimSube) {
    setSuccessMessage(null);
    setErrorMessage(null);
    setEditingSubeId(item.id);
    setSubeForm(subeFormFromItem(item));
    setYeniDepartmanAdi("");
    setSubeViewMode("form");
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
      const payload = toKullaniciPayload(kullaniciForm);
      if (editingKullaniciId != null) {
        await updateYonetimKullanici(editingKullaniciId, payload);
        setSuccessMessage("Kullanıcı yetkileri güncellendi.");
      } else {
        await createYonetimKullanici(payload);
        setSuccessMessage("Kullanıcı kaydı oluşturuldu.");
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
      setSuccessMessage(`"${created.label}" departmanı seçeneklere eklendi.`);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Departman eklenemedi.");
    } finally {
      setIsAddingDepartman(false);
    }
  }

  return (
    <section className="yonetim-page">
      <div className="yonetim-header-row">
        <p className="yonetim-kicker">Ayarlar</p>
        <h2>Yönetim Paneli</h2>
        <p>Uygulamayı kullanacak kişileri, rollerini ve şube kapsamlarını buradan yönet.</p>
      </div>

      <div className="yonetim-tabs" role="tablist" aria-label="Yönetim sekmeleri">
        <button
          type="button"
          data-testid="yonetim-tab-kullanicilar"
          className={`yonetim-tab-btn${activeTab === "kullanicilar" ? " is-active" : ""}`}
          onClick={() => setActiveTab("kullanicilar")}
        >
          Kullanıcılar
        </button>
        <button
          type="button"
          data-testid="yonetim-tab-subeler"
          className={`yonetim-tab-btn${activeTab === "subeler" ? " is-active" : ""}`}
          onClick={() => setActiveTab("subeler")}
        >
          Şubeler
        </button>
      </div>

      {isLoading ? <LoadingState label="Yönetim paneli yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadPanel()} /> : null}
      {!isLoading && successMessage ? <p className="yonetim-success">{successMessage}</p> : null}

      {!isLoading && !errorMessage && activeTab === "kullanicilar" ? (
        <div className="yonetim-panel-grid">
          <section className="yonetim-section-card">
            <div className="yonetim-section-copy">
              <span className="yonetim-section-eyebrow">Kullanıcı Atama</span>
              <h3>{editingKullaniciId != null ? "Kullanıcıyı Düzenle" : "Yeni Kullanıcı"}</h3>
              <p>Önce kişiyi belirle, sonra rolünü ve hangi şubelerde çalışacağını tanımla.</p>
            </div>

            <form className="yonetim-form-stack" onSubmit={handleKullaniciSubmit}>
              <div className="form-field-grid">
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
                {kullaniciForm.kullaniciTipi === "IC_PERSONEL" ? (
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
                  disabled={kullaniciForm.kullaniciTipi === "IC_PERSONEL" && kullaniciForm.personelId !== ""}
                  required
                />
                <FormField
                  label="Telefon"
                  name="yonetim-kullanici-telefon"
                  type="tel"
                  value={kullaniciForm.telefon}
                  onChange={(value) => setKullaniciForm((prev) => ({ ...prev, telefon: value }))}
                  disabled={kullaniciForm.kullaniciTipi === "IC_PERSONEL" && kullaniciForm.personelId !== ""}
                />
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
                <FormField
                  as="textarea"
                  label="Notlar"
                  name="yonetim-kullanici-notlar"
                  value={kullaniciForm.notlar}
                  onChange={(value) => setKullaniciForm((prev) => ({ ...prev, notlar: value }))}
                  placeholder="Opsiyonel açıklama"
                />
              </div>

              <div className="yonetim-checkbox-section">
                <p className="yonetim-checkbox-title">Şube Yetkisi</p>
                <p className="yonetim-hint">Boş bırakırsan kullanıcı tüm şubelerde çalışır.</p>
                <div className="yonetim-selection-grid">
                  {subeler.map((sube) => (
                    <button
                      key={sube.id}
                      type="button"
                      className={`yonetim-selection-pill${
                        kullaniciForm.subeIds.includes(sube.id) ? " is-selected" : ""
                      }`}
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
                  Temizle
                </button>
              </div>
            </form>
          </section>

          <section className="yonetim-section-card">
            <div className="yonetim-section-copy">
              <span className="yonetim-section-eyebrow">Mevcut Kullanıcılar</span>
              <h3>Rol ve kapsam görünümü</h3>
              <p>Bir kullanıcıyı seçip formu aynı panelde düzenleyebilirsin.</p>
            </div>

            {kullanicilar.length === 0 ? (
              <EmptyState title="Kullanıcı kaydı yok" message="İlk kullanıcı atamasını sol taraftaki formdan yap." />
            ) : (
              <div className="yonetim-card-grid">
                {kullanicilar.map((item) => (
                  <article key={item.id} className="yonetim-entity-card">
                    <div className="yonetim-card-meta">
                      <strong>{item.ad_soyad}</strong>
                      <span>
                        {KULLANICI_TIPI_LABELS[item.kullanici_tipi]} • {ROLE_LABELS[item.rol]}
                      </span>
                    </div>
                    <p>Durum: {DURUM_LABELS[item.durum]}</p>
                    <p>Telefon: {item.telefon ?? "-"}</p>
                    <p>Bağlı Personel: {item.personel_ad_soyad ?? "-"}</p>
                    <p>Yetki Kapsamı: {formatSubeScopeLabel(item.sube_ids, subeNameMap)}</p>
                    <p>
                      Varsayılan Şube:{" "}
                      {item.varsayilan_sube_id != null
                        ? subeNameMap.get(item.varsayilan_sube_id) ?? `Şube ${item.varsayilan_sube_id}`
                        : "Tanımsız"}
                    </p>
                    <div className="module-item-actions">
                      <button
                        type="button"
                        className="universal-btn-aux"
                        onClick={() => {
                          setEditingKullaniciId(item.id);
                          setKullaniciForm(userFormFromItem(item));
                          setSuccessMessage(null);
                          setErrorMessage(null);
                        }}
                      >
                        Düzenle
                      </button>
                    </div>
                  </article>
                ))}
              </div>
            )}
          </section>
        </div>
      ) : null}

      {!isLoading && !errorMessage && activeTab === "subeler" ? (
        subeViewMode === "liste" ? (
          <section className="yonetim-section-card">
            <div className="yonetim-section-copy yonetim-section-copy--row">
              <div>
                <span className="yonetim-section-eyebrow">Şube Yönetimi</span>
                <h3>Tanımlı şubeler</h3>
                <p>Şube kartını seçerek düzenle, yeni şube için üstteki aksiyonu kullan.</p>
              </div>
              <button
                type="button"
                className="universal-btn-aux yonetim-add-action"
                data-testid="yonetim-sube-yeni"
                onClick={openYeniSubeForm}
              >
                + Yeni Şube
              </button>
            </div>

            {subeler.length === 0 ? (
              <EmptyState title="Şube tanımı yok" message="İlk şube kaydını buradan oluşturmaya başlayabilirsin." />
            ) : (
              <div className="yonetim-card-grid yonetim-card-grid--branches">
                {subeler.map((item) => (
                  <article
                    key={item.id}
                    className="yonetim-entity-card yonetim-entity-card--branch"
                    role="button"
                    tabIndex={0}
                    onClick={() => openSubeEditor(item)}
                    onKeyDown={(event) => {
                      if (event.key === "Enter" || event.key === " ") {
                        event.preventDefault();
                        openSubeEditor(item);
                      }
                    }}
                  >
                    <div className="yonetim-card-meta">
                      <strong>{item.ad}</strong>
                      <span>{item.kod}</span>
                    </div>
                    <p>{item.departman_adlari.length} departman</p>
                    <p>{item.departman_adlari.join(", ") || "Departman tanımlı değil"}</p>
                    <p>Durum: {DURUM_LABELS[item.durum]}</p>
                  </article>
                ))}
              </div>
            )}
          </section>
        ) : (
          <section className="yonetim-section-card">
            <button type="button" className="yonetim-back-link" onClick={resetSubeEditor}>
              ← Şubelere Dön
            </button>

            <div className="yonetim-section-copy">
              <span className="yonetim-section-eyebrow">Şube Formu</span>
              <h3>{editingSubeId != null ? "Şubeyi Düzenle" : "Yeni Şube"}</h3>
              <p>Şube kodu, bağlı departmanlar ve durum bilgisini bu ekrandan yönet.</p>
            </div>

            <form className="yonetim-form-stack" onSubmit={handleSubeSubmit}>
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
                <FormField
                  as="select"
                  label="Durum"
                  name="yonetim-sube-durum"
                  value={subeForm.durum}
                  onChange={(value) => setSubeForm((prev) => ({ ...prev, durum: value as KayitDurumu }))}
                  selectOptions={statusOptions()}
                />
              </div>

              <div className="yonetim-checkbox-section">
                <p className="yonetim-checkbox-title">Departmanlar</p>
                <p className="yonetim-hint">Şube kapsamındaki departmanları seç. Yeni seçenek gerekirse sağdaki artı ile ekle.</p>
                <div className="yonetim-selection-grid">
                  {departmanOptions.map((departman) => (
                    <button
                      key={departman.id}
                      type="button"
                      className={`yonetim-selection-pill${
                        subeForm.departmanIds.includes(departman.id) ? " is-selected" : ""
                      }`}
                      onClick={() => toggleDepartmanSelection(departman.id)}
                    >
                      <strong>{departman.label}</strong>
                    </button>
                  ))}
                </div>

                <div className="yonetim-inline-add-row">
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
                    aria-label="Departman ekle"
                  >
                    +
                  </button>
                </div>
              </div>

              <div className="form-actions-row">
                <button type="submit" className="universal-btn-save" data-testid="yonetim-sube-kaydet">
                  {editingSubeId != null ? "Şubeyi Güncelle" : "Şubeyi Kaydet"}
                </button>
                <button type="button" className="universal-btn-cancel" onClick={resetSubeEditor}>
                  Vazgeç
                </button>
              </div>
            </form>
          </section>
        )
      ) : null}
    </section>
  );
}
