import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link, useNavigate, useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import { formatAktifDurumLabel } from "../../../lib/display/enum-display";
import type { Personel } from "../../../types/personel";

const PERSONEL_DOSYA_TABS = [
  { id: "genel-bilgiler", label: "Genel Bilgiler" },
  { id: "puantaj", label: "Puantaj" },
  { id: "izin-devamsizlik", label: "Izin & Devamsizlik" },
  { id: "zimmet-envanter", label: "Zimmet & Envanter" },
  { id: "surec-gecmisi", label: "Surec Gecmisi" }
] as const;

type PersonelDosyaTabId = (typeof PERSONEL_DOSYA_TABS)[number]["id"];

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

function DossierField({ label, value }: { label: string; value: string }) {
  return (
    <div className="personel-dosya-field">
      <span className="personel-dosya-field-label">{label}</span>
      <strong className="personel-dosya-field-value">{value}</strong>
    </div>
  );
}

function PersonelDosyaHero({
  personel,
  canEditPersonel,
  canViewSurecler,
  canCreateSurec,
  isActionMenuOpen,
  onToggleActionMenu,
  onCloseActionMenu,
  onStartEdit
}: {
  personel: Personel;
  canEditPersonel: boolean;
  canViewSurecler: boolean;
  canCreateSurec: boolean;
  isActionMenuOpen: boolean;
  onToggleActionMenu: () => void;
  onCloseActionMenu: () => void;
  onStartEdit: () => void;
}) {
  const navigate = useNavigate();

  const actionItems = useMemo(() => {
    const items: Array<{ id: string; label: string; onSelect: () => void }> = [];

    if (canCreateSurec || canViewSurecler) {
      items.push({
        id: "surec",
        label: canCreateSurec ? "Surec Baslat" : "Surecleri Ac",
        onSelect: () => {
          onCloseActionMenu();
          navigate("/surecler", {
            state: {
              prefillPersonelId: personel.id,
              ...(canCreateSurec ? { openCreateModal: true } : {})
            }
          });
        }
      });
    }

    if (canEditPersonel) {
      items.push({
        id: "duzenle",
        label: "Karti Duzenle",
        onSelect: () => {
          onCloseActionMenu();
          onStartEdit();
        }
      });
    }

    return items;
  }, [canCreateSurec, canEditPersonel, canViewSurecler, navigate, onCloseActionMenu, onStartEdit, personel.id]);

  return (
    <section className="personel-dosya-hero">
      <div className="personel-dosya-hero-head">
        <div className="personel-dosya-hero-copy">
          <p className="personel-dosya-kicker">Personel Dosyasi</p>
          <h3>{personel.ad} {personel.soyad}</h3>
          <p className="personel-dosya-sub">
            Bilgi merkezi gorunumu. Kart icerigi salt okunur dosya mantigiyla izlenir.
          </p>
        </div>

        {actionItems.length > 0 ? (
          <div className="personel-dosya-action-host">
            <button type="button" className="universal-btn-aux" onClick={onToggleActionMenu} aria-expanded={isActionMenuOpen}>
              Islemler
            </button>
            <div className={`settings-dropdown personel-dosya-action-menu${isActionMenuOpen ? " open" : ""}`}>
              {actionItems.map((item) => (
                <button key={item.id} type="button" onClick={item.onSelect}>
                  {item.label}
                </button>
              ))}
            </div>
          </div>
        ) : null}
      </div>

      <div className="personel-dosya-hero-grid">
        <DossierField label="Ad" value={personel.ad} />
        <DossierField label="Soyad" value={personel.soyad} />
        <DossierField label="Sicil No" value={formatDetailValue(personel.sicil_no)} />
        <DossierField label="Departman / Birim" value={formatReferenceValue(personel.departman_adi, personel.departman_id)} />
        <DossierField label="Gorev / Unvan" value={formatReferenceValue(personel.gorev_adi, personel.gorev_id)} />
        <DossierField label="Durum" value={formatAktifDurumLabel(personel.aktif_durum)} />
        <DossierField label="Ise Giris Tarihi" value={formatDetailValue(personel.ise_giris_tarihi)} />
      </div>
    </section>
  );
}

function PersonelKartPanelGenelBilgiler({ personel }: { personel: Personel }) {
  return (
    <div className="personel-detail-grid">
      <section className="personel-detail-section">
        <h3>Kimlik ve Iletisim</h3>
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
          <strong>Sube:</strong> {formatReferenceValue(personel.sube_adi, personel.sube_id)}
        </p>
      </section>

      <section className="personel-detail-section">
        <h3>Organizasyon ve Acil Durum</h3>
        <p>
          <strong>Personel Tipi:</strong> {formatReferenceValue(personel.personel_tipi_adi, personel.personel_tipi_id)}
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
        <p>
          <strong>Pasiflik Etiketi:</strong> {formatDetailValue(personel.pasiflik_durumu_etiketi)}
        </p>
      </section>
    </div>
  );
}

function PlaceholderPanel({
  title,
  description,
  actionLabel,
  actionTo,
  actionState,
  canOpen,
  noPermissionMessage
}: {
  title: string;
  description: string;
  actionLabel?: string;
  actionTo?: string;
  actionState?: Record<string, unknown>;
  canOpen?: boolean;
  noPermissionMessage?: string;
}) {
  return (
    <div className="personel-kart-placeholder">
      <h3>{title}</h3>
      <p>{description}</p>
      {actionLabel && actionTo ? (
        canOpen ? (
          <Link to={actionTo} state={actionState} className="universal-btn-aux">
            {actionLabel}
          </Link>
        ) : (
          <p className="personel-kart-placeholder-note">{noPermissionMessage ?? "Bu alani goruntuleme yetkiniz yok."}</p>
        )
      ) : (
        <p className="personel-kart-placeholder-note">Icerik bir sonraki keside baglanacak.</p>
      )}
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
  const canViewPuantaj = hasPermission("puantaj.view");

  const [activeTab, setActiveTab] = useState<PersonelDosyaTabId>("genel-bilgiler");
  const [isActionMenuOpen, setIsActionMenuOpen] = useState(false);

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
    setActiveTab("genel-bilgiler");
    setIsActionMenuOpen(false);
  }, [parsedPersonelId]);

  useEffect(() => {
    if (isEditing) {
      setIsActionMenuOpen(false);
    }
  }, [isEditing]);

  const aktifDurumOptions = [
    { value: "AKTIF", label: formatAktifDurumLabel("AKTIF") },
    { value: "PASIF", label: formatAktifDurumLabel("PASIF") }
  ];

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  const pageHeading =
    personel != null ? `${personel.ad} ${personel.soyad} personel dosyasi` : "Personel detayi";

  return (
    <section className="personel-detay-page personel-dosya-page" aria-label={pageHeading}>
      <h2 className="personeller-sr-only">{pageHeading}</h2>

      {isLoading ? <LoadingState label="Personel dosyasi yukleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && !personel ? (
        <EmptyState title="Personel bulunamadi" message="Belirtilen ID ile kayit bulunamadi." />
      ) : null}

      {!isLoading && !errorMessage && personel ? (
        <div className="personel-detail-card">
          <PersonelDosyaHero
            personel={personel}
            canEditPersonel={canEditPersonel}
            canViewSurecler={canViewSurecler}
            canCreateSurec={canCreateSurec}
            isActionMenuOpen={isActionMenuOpen}
            onToggleActionMenu={() => setIsActionMenuOpen((prev) => !prev)}
            onCloseActionMenu={() => setIsActionMenuOpen(false)}
            onStartEdit={() => setIsEditing(true)}
          />

          {isEditing ? (
            <form className="personel-edit-form" onSubmit={handleEditSubmit}>
              <div className="form-field-grid">
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
              </div>

              {editErrorMessage ? <p className="personel-create-error">{editErrorMessage}</p> : null}

              <div className="universal-btn-group">
                <button type="submit" className="universal-btn-save" disabled={isSubmitting}>
                  {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
                </button>
                <button type="button" className="universal-btn-cancel" onClick={discardEdit} disabled={isSubmitting}>
                  Vazgec
                </button>
              </div>
            </form>
          ) : (
            <>
              <div className="personel-kart-tablist" role="tablist" aria-label="Personel dosyasi sekmeleri">
                {PERSONEL_DOSYA_TABS.map((tab) => (
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
                id="personel-kart-panel-genel-bilgiler"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-genel-bilgiler"
                hidden={activeTab !== "genel-bilgiler"}
              >
                <PersonelKartPanelGenelBilgiler personel={personel} />
              </div>

              <div
                id="personel-kart-panel-puantaj"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-puantaj"
                hidden={activeTab !== "puantaj"}
              >
                <PlaceholderPanel
                  title="Puantaj Ozet Dosyasi"
                  description="Bu sekme sadece aylik net puantaj ozeti ve mevzuata dayali hesap ciktilarini gosterecek."
                  actionLabel="Puantaj ekranina git"
                  actionTo="/puantaj"
                  actionState={{ prefillPersonelId: personel.id }}
                  canOpen={canViewPuantaj}
                  noPermissionMessage="Puantaj goruntuleme yetkiniz yok."
                />
              </div>

              <div
                id="personel-kart-panel-izin-devamsizlik"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-izin-devamsizlik"
                hidden={activeTab !== "izin-devamsizlik"}
              >
                <div className="personel-detail-grid">
                  <section className="personel-detail-section">
                    <h3>Izin Hakki</h3>
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
                  </section>

                  <section className="personel-detail-section">
                    <h3>Devamsizlik Dosyasi</h3>
                    <p>Yasal dayanakli izin ve devamsizlik hareketleri bu sekmede kronolojik olarak izlenecek.</p>
                    <p className="personel-kart-placeholder-note">Icerik bir sonraki keside baglanacak.</p>
                  </section>
                </div>
              </div>

              <div
                id="personel-kart-panel-zimmet-envanter"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-zimmet-envanter"
                hidden={activeTab !== "zimmet-envanter"}
              >
                <PlaceholderPanel
                  title="Zimmet ve Envanter Dosyasi"
                  description="Kullaniciya teslim edilen ekipmanlar ve teslim geri alma hareketleri bu sekmede toplanacak."
                />
              </div>

              <div
                id="personel-kart-panel-surec-gecmisi"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-surec-gecmisi"
                hidden={activeTab !== "surec-gecmisi"}
              >
                <PlaceholderPanel
                  title="Surec Gecmisi"
                  description="Departman degisikligi, gorev gecisi, ucret hareketi ve diger personel olaylari bu sekmede zaman cizgisi mantigiyla yer alacak."
                  actionLabel={canCreateSurec ? "Surec Baslat" : canViewSurecler ? "Surecleri Ac" : undefined}
                  actionTo={canViewSurecler ? "/surecler" : undefined}
                  actionState={{
                    prefillPersonelId: personel.id,
                    ...(canCreateSurec ? { openCreateModal: true } : {})
                  }}
                  canOpen={canViewSurecler}
                  noPermissionMessage="Surec goruntuleme yetkiniz yok."
                />
              </div>
            </>
          )}
        </div>
      ) : null}
    </section>
  );
}
