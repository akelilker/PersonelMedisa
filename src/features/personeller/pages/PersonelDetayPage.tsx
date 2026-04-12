import { useEffect, useMemo, useState, type FormEvent } from "react";
import { Link, useParams } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePersonelDetail } from "../../../hooks/usePersoneller";
import {
  formatAktifDurumLabel,
  formatZimmetKayitDurumuLabel,
  formatZimmetTeslimDurumuLabel,
  formatZimmetUrunTuruLabel,
  formatSurecStateLabel,
  formatSurecTuruLabel
} from "../../../lib/display/enum-display";
import type { KeyOption } from "../../../types/referans";
import type { Personel } from "../../../types/personel";
import type { Surec } from "../../../types/surec";
import { ZIMMET_TESLIM_DURUMU_OPTIONS, ZIMMET_URUN_TURU_OPTIONS, type Zimmet } from "../../../types/zimmet";

const PERSONEL_DOSYA_TABS = [
  { id: "genel-bilgiler", label: "Genel Bilgiler" },
  { id: "puantaj", label: "Puantaj" },
  { id: "izin-devamsizlik", label: "Izin & Devamsizlik" },
  { id: "zimmet-envanter", label: "Zimmet & Envanter" },
  { id: "surec-gecmisi", label: "Surec Gecmisi" }
] as const;

const PERSONEL_SUREC_FORM_ID = "personel-surec-form";
const PERSONEL_ZIMMET_FORM_ID = "personel-zimmet-form";

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

function keyOptionsToSelectOptions(options: KeyOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

function DossierField({
  label,
  value,
  valueClassName
}: {
  label: string;
  value: string;
  valueClassName?: string;
}) {
  return (
    <div className="personel-dosya-field">
      <span className="personel-dosya-field-label">{label}</span>
      <strong className={valueClassName ?? "personel-dosya-field-value"}>{value}</strong>
    </div>
  );
}

function PersonelDosyaHero({
  personel,
  canEditPersonel,
  canAccessSurecler,
  canCreateSurec,
  isActionMenuOpen,
  onToggleActionMenu,
  onCloseActionMenu,
  onStartEdit,
  onOpenSurecModal,
  onOpenSurecHistory
}: {
  personel: Personel;
  canEditPersonel: boolean;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isActionMenuOpen: boolean;
  onToggleActionMenu: () => void;
  onCloseActionMenu: () => void;
  onStartEdit: () => void;
  onOpenSurecModal: () => void;
  onOpenSurecHistory: () => void;
}) {
  const actionItems = useMemo(() => {
    const items: Array<{ id: string; label: string; onSelect: () => void }> = [];

    if (canCreateSurec) {
      items.push({
        id: "surec-ekle",
        label: "Surec Ekle",
        onSelect: () => {
          onCloseActionMenu();
          onOpenSurecModal();
        }
      });
    } else if (canAccessSurecler) {
      items.push({
        id: "surec-gecmisi",
        label: "Surec Gecmisini Ac",
        onSelect: () => {
          onCloseActionMenu();
          onOpenSurecHistory();
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
  }, [canAccessSurecler, canCreateSurec, canEditPersonel, onCloseActionMenu, onOpenSurecHistory, onOpenSurecModal, onStartEdit]);

  const durumLabel =
    personel.aktif_durum === "PASIF"
      ? formatDetailValue(personel.pasiflik_durumu_etiketi) !== "-"
        ? formatDetailValue(personel.pasiflik_durumu_etiketi)
        : formatAktifDurumLabel(personel.aktif_durum)
      : formatAktifDurumLabel(personel.aktif_durum);

  return (
    <section className="personel-dosya-hero">
      <div className="personel-dosya-hero-head">
        <div className="personel-dosya-hero-copy">
          <p className="personel-dosya-kicker">Personel Dosyasi</p>
          <h3>
            {personel.ad} {personel.soyad}
          </h3>
          <p className="personel-dosya-sub">
            Bilgi merkezi gorunumu. Kart icerigi salt okunur dosya mantigiyla izlenir.
          </p>
        </div>

        {actionItems.length > 0 ? (
          <div className="personel-dosya-action-host">
            <button
              type="button"
              className="universal-btn-aux"
              onClick={onToggleActionMenu}
              aria-expanded={isActionMenuOpen}
            >
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
        <DossierField
          label="Durum"
          value={durumLabel}
          valueClassName={
            personel.aktif_durum === "PASIF"
              ? "personel-dosya-field-value personel-dosya-field-value--danger"
              : "personel-dosya-field-value"
          }
        />
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

function PersonelSurecGecmisiPanel({
  canAccessSurecler,
  canCreateSurec,
  isLoading,
  errorMessage,
  surecler,
  onOpenCreateModal
}: {
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  surecler: Surec[];
  onOpenCreateModal: () => void;
}) {
  if (!canAccessSurecler) {
    return (
      <div className="personel-kart-placeholder">
        <h3>Surec Gecmisi</h3>
        <p>Bu dosya yalnizca surec goruntuleme yetkisi olan kullanicilar icin acilir.</p>
      </div>
    );
  }

  return (
    <div className="personel-surec-history">
      <div className="personel-surec-history-head">
        <div>
          <h3>Surec Gecmisi</h3>
          <p>Personelin tum surec hareketleri kronolojik olay gunlugu olarak izlenir.</p>
        </div>
        {canCreateSurec ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenCreateModal}>
            Surec Ekle
          </button>
        ) : null}
      </div>

      {isLoading ? <p className="personel-kart-placeholder-note">Surec gecmisi yukleniyor...</p> : null}
      {!isLoading && errorMessage ? <p className="personel-create-error">{errorMessage}</p> : null}
      {!isLoading && !errorMessage && surecler.length === 0 ? (
        <div className="personel-kart-placeholder">
          <h3>Kayit Bulunamadi</h3>
          <p>Bu personel icin henuz surec kaydi bulunmuyor.</p>
        </div>
      ) : null}

      {!isLoading && !errorMessage && surecler.length > 0 ? (
        <ul className="personel-surec-list">
          {surecler.map((surec) => (
            <li key={surec.id} className="personel-surec-item">
              <div className="personel-surec-item-head">
                <strong>{formatSurecTuruLabel(surec.surec_turu)}</strong>
                <span className={`personel-surec-state${surec.state === "IPTAL" ? " is-cancelled" : ""}`}>
                  {formatSurecStateLabel(surec.state)}
                </span>
              </div>
              <div className="personel-surec-item-meta">
                <span>Baslangic: {formatDetailValue(surec.baslangic_tarihi)}</span>
                <span>Bitis: {formatDetailValue(surec.bitis_tarihi)}</span>
              </div>
              <p className="personel-surec-item-note">{formatDetailValue(surec.aciklama)}</p>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}

function PersonelZimmetEnvanterPanel({
  canCreateZimmet,
  isLoading,
  errorMessage,
  zimmetler,
  onOpenCreateModal
}: {
  canCreateZimmet: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  zimmetler: Zimmet[];
  onOpenCreateModal: () => void;
}) {
  return (
    <div className="personel-zimmet-panel">
      <div className="personel-zimmet-head">
        <div>
          <h3>Zimmet ve Envanter Kayitlari</h3>
          <p>Kullaniciya teslim edilen ekipmanlar ve geri alinmis kayitlar bu listede izlenir.</p>
        </div>
        {canCreateZimmet ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenCreateModal}>
            Yeni Zimmet Ekle
          </button>
        ) : null}
      </div>

      {isLoading ? <p className="personel-kart-placeholder-note">Zimmet kayitlari yukleniyor...</p> : null}
      {!isLoading && errorMessage ? <p className="personel-create-error">{errorMessage}</p> : null}

      {!isLoading && !errorMessage && zimmetler.length === 0 ? (
        <div className="personel-kart-placeholder">
          <h3>Zimmet Kaydi Bulunamadi</h3>
          <p>Bu personel icin henuz zimmetlenmis urun kaydi bulunmuyor.</p>
        </div>
      ) : null}

      {!isLoading && !errorMessage && zimmetler.length > 0 ? (
        <div className="personel-zimmet-table-wrap">
          <table className="personel-zimmet-table">
            <thead>
              <tr>
                <th>Urun Turu</th>
                <th>Teslim Tarihi</th>
                <th>Teslim Eden</th>
                <th>Teslim Durumu</th>
                <th>Kayit Durumu</th>
                <th>Seri No / Aciklama</th>
              </tr>
            </thead>
            <tbody>
              {zimmetler.map((zimmet) => (
                <tr key={zimmet.id}>
                  <td className="personel-zimmet-cell-strong">{formatZimmetUrunTuruLabel(zimmet.urun_turu)}</td>
                  <td>{formatDetailValue(zimmet.teslim_tarihi)}</td>
                  <td>{formatDetailValue(zimmet.teslim_eden)}</td>
                  <td>{formatZimmetTeslimDurumuLabel(zimmet.teslim_durumu)}</td>
                  <td>
                    <span
                      className={`personel-zimmet-state${zimmet.zimmet_durumu === "IADE_EDILDI" ? " is-returned" : ""}`}
                    >
                      {formatZimmetKayitDurumuLabel(zimmet.zimmet_durumu)}
                    </span>
                  </td>
                  <td className="personel-zimmet-note-cell">{formatDetailValue(zimmet.aciklama)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
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
  const canAccessSurecler = canCreateSurec || canViewSurecler;
  const canViewPuantaj = hasPermission("puantaj.view");
  const canCreateZimmet = canEditPersonel;

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
    updatePersonelHandler,
    isSurecModalOpen,
    openSurecModal,
    closeSurecModal,
    surecForm,
    setSurecForm,
    createSurecHandler,
    isSurecSubmitting,
    surecCreateErrorMessage,
    surecHistory,
    isSurecHistoryLoading,
    surecHistoryErrorMessage,
    surecTuruOptions,
    surecReferenceErrorMessage,
    isZimmetModalOpen,
    openZimmetModal,
    closeZimmetModal,
    zimmetForm,
    setZimmetForm,
    createZimmetHandler,
    isZimmetSubmitting,
    zimmetCreateErrorMessage,
    zimmetHistory,
    isZimmetHistoryLoading,
    zimmetHistoryErrorMessage
  } = usePersonelDetail(parsedPersonelId, hasValidId, {
    canViewSurecler,
    canCreateSurec,
    canCreateZimmet
  });

  useEffect(() => {
    setActiveTab("genel-bilgiler");
    setIsActionMenuOpen(false);
  }, [parsedPersonelId]);

  useEffect(() => {
    if (isEditing || isSurecModalOpen || isZimmetModalOpen) {
      setIsActionMenuOpen(false);
    }
  }, [isEditing, isSurecModalOpen, isZimmetModalOpen]);

  const aktifDurumOptions = [
    { value: "AKTIF", label: formatAktifDurumLabel("AKTIF") },
    { value: "PASIF", label: formatAktifDurumLabel("PASIF") }
  ];

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    void updatePersonelHandler(event, canEditPersonel);
  }

  function handleSurecCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createSurecHandler(event);
  }

  function handleZimmetCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createZimmetHandler(event);
  }

  function handleOpenSurecModal() {
    setActiveTab("surec-gecmisi");
    openSurecModal();
  }

  function handleOpenSurecHistory() {
    setActiveTab("surec-gecmisi");
  }

  function handleOpenZimmetModal() {
    setActiveTab("zimmet-envanter");
    openZimmetModal();
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
            canAccessSurecler={canAccessSurecler}
            canCreateSurec={canCreateSurec}
            isActionMenuOpen={isActionMenuOpen}
            onToggleActionMenu={() => setIsActionMenuOpen((prev) => !prev)}
            onCloseActionMenu={() => setIsActionMenuOpen(false)}
            onStartEdit={() => setIsEditing(true)}
            onOpenSurecModal={handleOpenSurecModal}
            onOpenSurecHistory={handleOpenSurecHistory}
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
                <PersonelZimmetEnvanterPanel
                  canCreateZimmet={canCreateZimmet}
                  isLoading={isZimmetHistoryLoading}
                  errorMessage={zimmetHistoryErrorMessage}
                  zimmetler={zimmetHistory}
                  onOpenCreateModal={handleOpenZimmetModal}
                />
              </div>

              <div
                id="personel-kart-panel-surec-gecmisi"
                role="tabpanel"
                className="personel-kart-panel"
                aria-labelledby="personel-kart-tab-surec-gecmisi"
                hidden={activeTab !== "surec-gecmisi"}
              >
                <PersonelSurecGecmisiPanel
                  canAccessSurecler={canAccessSurecler}
                  canCreateSurec={canCreateSurec}
                  isLoading={isSurecHistoryLoading}
                  errorMessage={surecHistoryErrorMessage}
                  surecler={surecHistory}
                  onOpenCreateModal={handleOpenSurecModal}
                />
              </div>
            </>
          )}
        </div>
      ) : null}

      {personel && canCreateSurec && isSurecModalOpen ? (
        <AppModal
          title="Surec Ekle"
          onClose={closeSurecModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_SUREC_FORM_ID}
                className="universal-btn-save"
                disabled={isSurecSubmitting}
              >
                {isSurecSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeSurecModal}
                disabled={isSurecSubmitting}
              >
                Vazgec
              </button>
            </div>
          }
        >
          <form id={PERSONEL_SUREC_FORM_ID} className="personel-surec-form-grid" onSubmit={handleSurecCreateSubmit}>
            {surecTuruOptions.length > 0 ? (
              <FormField
                as="select"
                label="Surec Turu"
                name="personel-surec-turu"
                value={surecForm.surecTuru}
                onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholderOption={{ value: "", label: "Seciniz" }}
                selectOptions={keyOptionsToSelectOptions(surecTuruOptions)}
              />
            ) : (
              <FormField
                label="Surec Turu"
                name="personel-surec-turu-text"
                value={surecForm.surecTuru}
                onChange={(value) => setSurecForm((prev) => ({ ...prev, surecTuru: value }))}
                required
                placeholder="IZIN, RAPOR, ISTEN_AYRILMA"
              />
            )}
            <FormField
              label="Baslangic Tarihi"
              name="personel-surec-baslangic"
              type="date"
              value={surecForm.baslangicTarihi}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, baslangicTarihi: value }))}
              required
            />
            <FormField
              label="Bitis Tarihi"
              name="personel-surec-bitis"
              type="date"
              value={surecForm.bitisTarihi}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, bitisTarihi: value }))}
            />
            <FormField
              as="textarea"
              label="Aciklama"
              name="personel-surec-aciklama"
              value={surecForm.aciklama}
              onChange={(value) => setSurecForm((prev) => ({ ...prev, aciklama: value }))}
              rows={4}
            />
            {surecCreateErrorMessage ? <p className="personel-create-error">{surecCreateErrorMessage}</p> : null}
            {surecReferenceErrorMessage ? <p className="personel-create-error">{surecReferenceErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {personel && canCreateZimmet && isZimmetModalOpen ? (
        <AppModal
          title="Yeni Zimmet Ekle"
          onClose={closeZimmetModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={PERSONEL_ZIMMET_FORM_ID}
                className="universal-btn-save"
                disabled={isZimmetSubmitting}
              >
                {isZimmetSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeZimmetModal}
                disabled={isZimmetSubmitting}
              >
                Vazgec
              </button>
            </div>
          }
        >
          <form id={PERSONEL_ZIMMET_FORM_ID} className="personel-zimmet-form-grid" onSubmit={handleZimmetCreateSubmit}>
            <FormField
              as="select"
              label="Urun Turu"
              name="personel-zimmet-urun-turu"
              value={zimmetForm.urunTuru}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, urunTuru: value }))}
              required
              placeholderOption={{ value: "", label: "Seciniz" }}
              selectOptions={[...ZIMMET_URUN_TURU_OPTIONS]}
            />
            <FormField
              label="Teslim Tarihi"
              name="personel-zimmet-teslim-tarihi"
              type="date"
              value={zimmetForm.teslimTarihi}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimTarihi: value }))}
              required
            />
            <FormField
              label="Teslim Eden"
              name="personel-zimmet-teslim-eden"
              value={zimmetForm.teslimEden}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimEden: value }))}
              required
              placeholder="Birim Amiri veya IK gorevlisi"
            />
            <FormField
              as="select"
              label="Teslim Durumu"
              name="personel-zimmet-teslim-durumu"
              value={zimmetForm.teslimDurumu}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, teslimDurumu: value }))}
              required
              selectOptions={[...ZIMMET_TESLIM_DURUMU_OPTIONS]}
            />
            <FormField
              as="textarea"
              label="Seri No / Aciklama"
              name="personel-zimmet-aciklama"
              value={zimmetForm.aciklama}
              onChange={(value) => setZimmetForm((prev) => ({ ...prev, aciklama: value }))}
              rows={4}
            />
            {zimmetCreateErrorMessage ? <p className="personel-create-error">{zimmetCreateErrorMessage}</p> : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
