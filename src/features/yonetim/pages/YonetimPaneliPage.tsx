import { useEffect, useMemo, useState, type FormEvent } from "react";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import {
  createYonetimKullanici,
  createYonetimSube,
  fetchYonetimKullanicilari,
  fetchYonetimSubeleri,
  updateYonetimKullanici,
  updateYonetimSube
} from "../../../api/yonetim.api";
import { fetchPersonellerList } from "../../../api/personeller.api";
import { formatAktifDurumLabel, formatKullaniciTipiLabel, formatUserRoleLabel } from "../../../lib/display/enum-display";
import type { UserRole } from "../../../types/auth";
import type { Personel } from "../../../types/personel";
import type {
  KayitDurumu,
  KullaniciTipi,
  UpsertYonetimKullaniciPayload,
  UpsertYonetimSubePayload,
  YonetimKullanici,
  YonetimSube
} from "../../../types/yonetim";

type ActiveTab = "kullanicilar" | "subeler";

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
  departmanlar: string;
  durum: KayitDurumu;
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
  departmanlar: "",
  durum: "AKTIF"
};

function roleOptions() {
  return [
    { value: "GENEL_YONETICI", label: "Genel Yönetici" },
    { value: "BOLUM_YONETICISI", label: "Bölüm Yöneticisi" },
    { value: "BIRIM_AMIRI", label: "Birim Amiri" },
    { value: "MUHASEBE", label: "Muhasebe" }
  ];
}

function statusOptions() {
  return [
    { value: "AKTIF", label: "Aktif" },
    { value: "PASIF", label: "Pasif" }
  ];
}

function splitDepartments(value: string) {
  return value
    .split(",")
    .map((item) => item.trim())
    .filter(Boolean);
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
    departmanlar: item.departmanlar.join(", "),
    durum: item.durum
  };
}

function toKullaniciPayload(form: KullaniciFormState): UpsertYonetimKullaniciPayload {
  const adSoyad = form.adSoyad.trim();
  if (!adSoyad) {
    throw new Error("Ad soyad zorunludur.");
  }

  if (form.kullaniciTipi === "IC_PERSONEL" && !form.personelId) {
    throw new Error("İç personel kullanıcısı için personel seçilmelidir.");
  }

  if (form.varsayilanSubeId && !form.subeIds.includes(Number.parseInt(form.varsayilanSubeId, 10))) {
    throw new Error("Varsayılan şube seçimi yetki verilen şubeler içinde olmalıdır.");
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
    throw new Error("Şube kodu ve adı zorunludur.");
  }

  return {
    kod,
    ad,
    departmanlar: splitDepartments(form.departmanlar),
    durum: form.durum
  };
}

export function YonetimPaneliPage() {
  const [activeTab, setActiveTab] = useState<ActiveTab>("kullanicilar");
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);

  const [kullanicilar, setKullanicilar] = useState<YonetimKullanici[]>([]);
  const [subeler, setSubeler] = useState<YonetimSube[]>([]);
  const [personeller, setPersoneller] = useState<Personel[]>([]);

  const [editingKullaniciId, setEditingKullaniciId] = useState<number | null>(null);
  const [editingSubeId, setEditingSubeId] = useState<number | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [kullaniciForm, setKullaniciForm] = useState<KullaniciFormState>(INITIAL_KULLANICI_FORM);
  const [subeForm, setSubeForm] = useState<SubeFormState>(INITIAL_SUBE_FORM);

  const personelOptions = useMemo(
    () =>
      personeller.map((personel) => ({
        value: String(personel.id),
        label: `${personel.ad} ${personel.soyad}`
      })),
    [personeller]
  );

  const subeNameMap = useMemo(
    () => new Map(subeler.map((sube) => [sube.id, sube.ad])),
    [subeler]
  );

  async function loadPanel() {
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const [kullaniciList, subeList, personelList] = await Promise.all([
        fetchYonetimKullanicilari(),
        fetchYonetimSubeleri(),
        fetchPersonellerList({ page: 1, limit: 250, aktiflik: "tum" })
      ]);
      setKullanicilar(kullaniciList);
      setSubeler(subeList);
      setPersoneller(personelList.items);
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

      setKullaniciForm(INITIAL_KULLANICI_FORM);
      setEditingKullaniciId(null);
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

      setSubeForm(INITIAL_SUBE_FORM);
      setEditingSubeId(null);
      await loadPanel();
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Şube tanımı kaydedilemedi.");
    } finally {
      setIsSubmitting(false);
    }
  }

  function toggleSubeSelection(subeId: number) {
    setKullaniciForm((prev) => {
      const exists = prev.subeIds.includes(subeId);
      const nextSubeIds = exists ? prev.subeIds.filter((id) => id !== subeId) : [...prev.subeIds, subeId];
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

  return (
    <section className="yonetim-page">
      <div className="yonetim-header-row">
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
          Şube Tanımları
        </button>
      </div>

      {isLoading ? <LoadingState label="Yönetim paneli yükleniyor..." /> : null}
      {!isLoading && errorMessage ? <ErrorState message={errorMessage} onRetry={() => void loadPanel()} /> : null}
      {!isLoading && successMessage ? <p className="yonetim-success">{successMessage}</p> : null}

      {!isLoading && !errorMessage && activeTab === "kullanicilar" ? (
        <div className="yonetim-section-grid">
          <form className="form-filter-panel yonetim-form-panel" onSubmit={handleKullaniciSubmit}>
            <h3>{editingKullaniciId != null ? "Kullanıcı Düzenle" : "Yeni Kullanıcı"}</h3>
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
                selectOptions={subeler.map((sube) => ({ value: String(sube.id), label: sube.ad }))}
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
              <p className="yonetim-hint">Boş bırakılırsa kullanıcı tüm şubelerde çalışır.</p>
              <div className="yonetim-checkbox-grid">
                {subeler.map((sube) => (
                  <label key={sube.id} className="yonetim-checkbox-item">
                    <input
                      type="checkbox"
                      checked={kullaniciForm.subeIds.includes(sube.id)}
                      onChange={() => toggleSubeSelection(sube.id)}
                    />
                    <span>{sube.ad}</span>
                  </label>
                ))}
              </div>
            </div>

            <div className="form-actions-row">
              <button type="submit" className="universal-btn-save" data-testid="yonetim-kullanici-kaydet">
                {editingKullaniciId != null ? "Kullanıcıyı Güncelle" : "Kullanıcıyı Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => {
                  setEditingKullaniciId(null);
                  setKullaniciForm(INITIAL_KULLANICI_FORM);
                  setSuccessMessage(null);
                }}
              >
                Temizle
              </button>
            </div>
          </form>

          <div className="yonetim-list-panel">
            <h3>Mevcut Kullanıcılar</h3>
            <ul className="yonetim-entity-list">
              {kullanicilar.map((item) => (
                <li key={item.id} className="yonetim-entity-item">
                  <div className="yonetim-entity-copy">
                    <strong>{item.ad_soyad}</strong>
                    <p>
                      {formatKullaniciTipiLabel(item.kullanici_tipi)} | {formatUserRoleLabel(item.rol)} |{" "}
                      {formatAktifDurumLabel(item.durum)}
                    </p>
                    <p>Telefon: {item.telefon ?? "-"}</p>
                    <p>Bağlı Personel: {item.personel_ad_soyad ?? "-"}</p>
                    <p>
                      Yetkili Şubeler:{" "}
                      {item.sube_ids.length > 0
                        ? item.sube_ids.map((subeId) => subeNameMap.get(subeId) ?? `Şube ${subeId}`).join(", ")
                        : "Tüm Şubeler"}
                    </p>
                    <p>
                      Varsayılan Şube:{" "}
                      {item.varsayilan_sube_id != null
                        ? subeNameMap.get(item.varsayilan_sube_id) ?? `Şube ${item.varsayilan_sube_id}`
                        : "Tanımsız"}
                    </p>
                  </div>
                  <div className="module-item-actions">
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => {
                        setEditingKullaniciId(item.id);
                        setKullaniciForm(userFormFromItem(item));
                        setSuccessMessage(null);
                      }}
                    >
                      Düzenle
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}

      {!isLoading && !errorMessage && activeTab === "subeler" ? (
        <div className="yonetim-section-grid">
          <form className="form-filter-panel yonetim-form-panel" onSubmit={handleSubeSubmit}>
            <h3>{editingSubeId != null ? "Şube Düzenle" : "Yeni Şube"}</h3>
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
              <FormField
                as="textarea"
                label="Departmanlar"
                name="yonetim-sube-departmanlar"
                value={subeForm.departmanlar}
                onChange={(value) => setSubeForm((prev) => ({ ...prev, departmanlar: value }))}
                placeholder="Virgül ile ayır: Döşeme, Depolama"
              />
            </div>

            <div className="form-actions-row">
              <button type="submit" className="universal-btn-save" data-testid="yonetim-sube-kaydet">
                {editingSubeId != null ? "Şubeyi Güncelle" : "Şubeyi Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => {
                  setEditingSubeId(null);
                  setSubeForm(INITIAL_SUBE_FORM);
                  setSuccessMessage(null);
                }}
              >
                Temizle
              </button>
            </div>
          </form>

          <div className="yonetim-list-panel">
            <h3>Tanımlı Şubeler</h3>
            <ul className="yonetim-entity-list">
              {subeler.map((item) => (
                <li key={item.id} className="yonetim-entity-item">
                  <div className="yonetim-entity-copy">
                    <strong>
                      {item.kod} - {item.ad}
                    </strong>
                    <p>Durum: {formatAktifDurumLabel(item.durum)}</p>
                    <p>Departmanlar: {item.departmanlar.length > 0 ? item.departmanlar.join(", ") : "-"}</p>
                  </div>
                  <div className="module-item-actions">
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => {
                        setEditingSubeId(item.id);
                        setSubeForm(subeFormFromItem(item));
                        setSuccessMessage(null);
                      }}
                    >
                      Düzenle
                    </button>
                  </div>
                </li>
              ))}
            </ul>
          </div>
        </div>
      ) : null}
    </section>
  );
}
