import { useEffect, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import { formatAktifDurumLabel } from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";

const PERSONEL_KART_TABS = [
  { id: "genel", label: "Genel" },
  { id: "izin", label: "Izin ve hizmet" },
  { id: "puantaj", label: "Puantaj" },
  { id: "tesvik", label: "Tesvik" },
  { id: "finans", label: "Finans" }
] as const;

type PersonelKartTabId = (typeof PERSONEL_KART_TABS)[number]["id"];

function formatDetailValue(value: string | null | undefined) {
  if (typeof value !== "string") {
    return "-";
  }

  const trimmed = value.trim();
  return trimmed ? trimmed : "-";
}

function formatDetailNumber(value: number | null | undefined) {
  return typeof value === "number" ? String(value) : "-";
}

function formatReferenceValue(label?: string, id?: number) {
  if (label) {
    return label;
  }

  return typeof id === "number" ? `#${id}` : "-";
}

function digitsOnly(value: string | null | undefined) {
  return (value ?? "").replace(/\D+/g, "");
}

function buildTelHref(value: string | null | undefined) {
  const digits = digitsOnly(value);
  return digits ? `tel:${digits}` : null;
}

function PersonelKartPanelGenel({ personel }: { personel: Personel }) {
  return (
    <div className="personel-detail-grid">
      <section className="personel-detail-section">
        <h3>Ana kart</h3>
        <p>
          <strong>Ad Soyad:</strong> {personel.ad} {personel.soyad}
        </p>
        <p>
          <strong>T.C. Kimlik No:</strong> {personel.tc_kimlik_no}
        </p>
        <p>
          <strong>Telefon:</strong> {formatDetailValue(personel.telefon)}
        </p>
        <p>
          <strong>Dogum Tarihi:</strong> {formatDetailValue(personel.dogum_tarihi)}
        </p>
        <p>
          <strong>Dogum Yeri:</strong> {formatDetailValue(personel.dogum_yeri)}
        </p>
        <p>
          <strong>Kan Grubu:</strong> {formatDetailValue(personel.kan_grubu)}
        </p>
        <p>
          <strong>Sicil No:</strong> {formatDetailValue(personel.sicil_no)}
        </p>
        <p>
          <strong>Ise Giris Tarihi:</strong> {formatDetailValue(personel.ise_giris_tarihi)}
        </p>
        <p>
          <strong>Durum:</strong> {formatAktifDurumLabel(personel.aktif_durum)}
        </p>
      </section>

      <section className="personel-detail-section">
        <h3>Referanslar</h3>
        <p>
          <strong>Bolum:</strong> {formatReferenceValue(personel.departman_adi, personel.departman_id)}
        </p>
        <p>
          <strong>Gorev:</strong> {formatReferenceValue(personel.gorev_adi, personel.gorev_id)}
        </p>
        <p>
          <strong>Personel Tipi:</strong>{" "}
          {formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)}
        </p>
        <p>
          <strong>Bagli Amir:</strong> {formatReferenceValue(personel.bagli_amir_adi, personel.bagli_amir_id)}
        </p>
        <p>
          <strong>Acil Durum Kisisi:</strong> {formatDetailValue(personel.acil_durum_kisi)}
        </p>
        <p>
          <strong>Acil Durum Telefonu:</strong> {formatDetailValue(personel.acil_durum_telefon)}
        </p>
      </section>
    </div>
  );
}

function PersonelKartPanelIzin({ personel }: { personel: Personel }) {
  return (
    <div className="personel-detail-grid">
      <section className="personel-detail-section personel-kart-panel-wide">
        <h3>Sistem ozeti</h3>
        <p>
          <strong>Hizmet Suresi:</strong> {formatDetailValue(personel.hizmet_suresi)}
        </p>
        <p>
          <strong>Toplam Izin Hakki:</strong> {formatDetailNumber(personel.toplam_izin_hakki)}
        </p>
        <p>
          <strong>Kullanilan Izin:</strong> {formatDetailNumber(personel.kullanilan_izin)}
        </p>
        <p>
          <strong>Kalan Izin:</strong> {formatDetailNumber(personel.kalan_izin)}
        </p>
        <p>
          <strong>Pasiflik Etiketi:</strong> {formatDetailValue(personel.pasiflik_durumu_etiketi)}
        </p>
      </section>
    </div>
  );
}

export function PersonelDetayPage() {
  const { personelId } = useParams();
  const parsedPersonelId = Number.parseInt(personelId ?? "", 10);
  const hasValidId = !Number.isNaN(parsedPersonelId) && parsedPersonelId > 0;
  const { hasPermission } = useRoleAccess();
  const canEditPersonel = hasPermission("personeller.update");
  const canCreateSurec = hasPermission("surecler.create");
  const canViewSurecler = hasPermission("surecler.view") || hasPermission("surecler.view.sube");
  const canCreateBildirim = hasPermission("bildirimler.create");
  const canViewBildirimler = hasPermission("bildirimler.view");
  const canViewPuantaj = hasPermission("puantaj.view");
  const canViewFinans = hasPermission("finans.view");

  const [activeTab, setActiveTab] = useState<PersonelKartTabId>("genel");

  const {
    personel,
    isLoading,
    errorMessage,
    refetch,
    isEditing,
    setIsEditing,
    isSubmitting,
    editErrorMessage,
    editForm,
    setEditForm,
    discardEdit,
    updatePersonelHandler
  } = usePersonelDetail(parsedPersonelId, hasValidId);

  useEffect(() => {
    setActiveTab("genel");
  }, [parsedPersonelId]);

  const aktifDurumOptions = [
    { value: "AKTIF", label: formatAktifDurumLabel("AKTIF") },
    { value: "PASIF", label: formatAktifDurumLabel("PASIF") }
  ];

  const personelCallHref = buildTelHref(personel?.telefon);
  const emergencyCallHref = buildTelHref(personel?.acil_durum_telefon);

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  const pageHeading =
    personel != null ? `${personel.ad} ${personel.soyad} kisi karti` : "Personel detayi";

  return (
    <section className="personel-detay-page" aria-label={pageHeading}>
      <h2 className="personeller-sr-only">{pageHeading}</h2>

      {isLoading ? <LoadingState label="Personel detayi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadi" message="Belirtilen ID ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          {!isEditing ? (
            <>
              <div className="personel-detail-actions">
                {personelCallHref ? (
                  <a className="universal-btn-aux" href={personelCallHref}>
                    Personeli Ara
                  </a>
                ) : null}
                {emergencyCallHref ? (
                  <a className="universal-btn-aux" href={emergencyCallHref}>
                    Acil Kisiyi Ara
                  </a>
                ) : null}
                {canViewSurecler ? (
                  <Link
                    to="/surecler"
                    state={{
                      prefillPersonelId: personel.id,
                      ...(canCreateSurec ? { openCreateModal: true } : {})
                    }}
                    className="universal-btn-aux"
                  >
                    {canCreateSurec ? "Yeni Surec" : "Surecleri Ac"}
                  </Link>
                ) : null}
                {canViewBildirimler ? (
                  <Link
                    to="/bildirimler"
                    state={{
                      prefillPersonelId: personel.id,
                      ...(canCreateBildirim ? { openCreateModal: true } : {})
                    }}
                    className="universal-btn-aux"
                  >
                    {canCreateBildirim ? "Bildirim Olustur" : "Bildirimleri Ac"}
                  </Link>
                ) : null}
                {canViewPuantaj ? (
                  <Link to="/puantaj" state={{ prefillPersonelId: personel.id }} className="universal-btn-aux">
                    Puantaji Ac
                  </Link>
                ) : null}
                {canEditPersonel ? (
                  <button type="button" className="universal-btn-aux" onClick={() => setIsEditing(true)}>
                    Duzenle
                  </button>
                ) : null}
              </div>

              <div className="personel-kart-tablist" role="tablist" aria-label="Kisi karti sekmeleri">
                {PERSONEL_KART_TABS.map((tab) => (
                  <button
                    key={tab.id}
                    type="button"
                    role="tab"
                    id={`personel-kart-tab-${tab.id}`}
                    className={`personel-kart-tab${activeTab === tab.id ? " is-active" : ""}`}
                    aria-selected={activeTab === tab.id}
                    aria-controls={`personel-kart-panel-${tab.id}`}
                    tabIndex={activeTab === tab.id ? 0 : -1}
                    onClick={() => setActiveTab(tab.id)}
                  >
                    {tab.label}
                  </button>
                ))}
              </div>

              <div
                id="personel-kart-panel-genel"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-genel"
                hidden={activeTab !== "genel"}
              >
                <PersonelKartPanelGenel personel={personel} />
              </div>

              <div
                id="personel-kart-panel-izin"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-izin"
                hidden={activeTab !== "izin"}
              >
                <PersonelKartPanelIzin personel={personel} />
              </div>

              <div
                id="personel-kart-panel-puantaj"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-puantaj"
                hidden={activeTab !== "puantaj"}
              >
                <div className="personel-kart-placeholder">
                  <p>
                    Gunluk puantaj ve calisma ozeti bu sekmede toplanacak. Simdilik tam ekran modulu
                    kullanabilirsiniz.
                  </p>
                  {canViewPuantaj ? (
                    <Link to="/puantaj" state={{ prefillPersonelId: personel.id }} className="universal-btn-aux">
                      Puantaj modulune git
                    </Link>
                  ) : (
                    <p className="personel-kart-placeholder-note">Puantaj goruntuleme yetkiniz yok.</p>
                  )}
                </div>
              </div>

              <div
                id="personel-kart-panel-tesvik"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-tesvik"
                hidden={activeTab !== "tesvik"}
              >
                <div className="personel-kart-placeholder">
                  <p>Tesvik ve performans odemeleri ile ilgili kayitlar bu sekmede listelenecek.</p>
                  <p className="personel-kart-placeholder-note">Icerik yakinda baglanacak.</p>
                </div>
              </div>

              <div
                id="personel-kart-panel-finans"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-finans"
                hidden={activeTab !== "finans"}
              >
                <div className="personel-kart-placeholder">
                  <p>Prim, kesinti ve diger finans kalemleri bu sekmede ozetlenecek.</p>
                  {canViewFinans ? (
                    <Link to="/finans" className="universal-btn-aux">
                      Finans modulune git
                    </Link>
                  ) : (
                    <p className="personel-kart-placeholder-note">Finans goruntuleme yetkiniz yok.</p>
                  )}
                </div>
              </div>
            </>
          ) : (
            <form className="personel-edit-form" onSubmit={handleEditSubmit}>
              <FormField
                label="Ad"
                name="edit-ad"
                value={editForm.ad}
                onChange={(value) => setEditForm((prev) => ({ ...prev, ad: value }))}
                required
              />
              <FormField
                label="Soyad"
                name="edit-soyad"
                value={editForm.soyad}
                onChange={(value) => setEditForm((prev) => ({ ...prev, soyad: value }))}
                required
              />
              <FormField
                label="Telefon"
                name="edit-telefon"
                type="tel"
                value={editForm.telefon}
                onChange={(value) => setEditForm((prev) => ({ ...prev, telefon: value }))}
              />
              <FormField
                as="select"
                label="Durum"
                name="edit-aktif"
                value={editForm.aktifDurum}
                onChange={(value) =>
                  setEditForm((prev) => ({ ...prev, aktifDurum: value as "AKTIF" | "PASIF" }))
                }
                selectOptions={aktifDurumOptions}
              />

              {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

              <div className="form-actions-row">
                <button type="submit" className="universal-btn-aux" disabled={isSubmitting}>
                  {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
                </button>
                <button type="button" className="universal-btn-aux" onClick={discardEdit} disabled={isSubmitting}>
                  Vazgec
                </button>
              </div>
            </form>
          )}
        </div>
      ) : null}
    </section>
  );
}
