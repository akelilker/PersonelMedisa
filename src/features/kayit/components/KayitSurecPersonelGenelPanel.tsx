import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { getApiErrorMessage } from "../../../api/api-client";
import { updatePersonel } from "../../../api/personeller.api";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import { dataCacheKeys, deleteCacheEntry, getActiveSube } from "../../../data/data-manager";
import { displayUcretTipiLabel } from "../../../lib/display/ucret-tipi-display";
import { computeHasLifecycleDiff } from "../../../lib/personel-lifecycle-diff";
import type { Personel } from "../../../types/personel";
import { PersonelInlineEditForm } from "../../personeller/components/personel-dosya/PersonelInlineEditForm";
import {
  buildPersonelUpdatePayload,
  personelToEditForm,
  pickGenelLifecycleFormFields,
  type EditPersonelFormState
} from "../../personeller/personel-edit-utils";
import { formatGeneralField, formatMoneyField } from "../kayit-surec-utils";

type KayitSurecPersonelGenelPanelProps = {
  personel: Personel;
  canUpdatePersonel: boolean;
  canViewUcret: boolean;
  personelRefs: PersonelReferenceBundle;
  onBusyChange?: (busy: boolean) => void;
  onPersonelUpdated: (updated: Personel) => void;
  openEditOnMount?: boolean;
};

export function KayitSurecPersonelGenelPanel({
  personel,
  canUpdatePersonel,
  canViewUcret,
  personelRefs,
  onBusyChange,
  onPersonelUpdated,
  openEditOnMount = false
}: KayitSurecPersonelGenelPanelProps) {
  const isPasif = personel.aktif_durum === "PASIF";
  const canEdit = canUpdatePersonel && !isPasif;

  const [isEditing, setIsEditing] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [editInfoMessage, setEditInfoMessage] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditPersonelFormState>(() => personelToEditForm(personel));

  const personelIdRef = useRef(personel.id);
  personelIdRef.current = personel.id;
  const isSubmittingRef = useRef(false);
  const onBusyChangeRef = useRef(onBusyChange);
  onBusyChangeRef.current = onBusyChange;

  function publishBusy(next: boolean) {
    isSubmittingRef.current = next;
    onBusyChangeRef.current?.(next);
  }

  useEffect(() => {
    setEditForm(personelToEditForm(personel));
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditInfoMessage(null);
  }, [personel]);

  useEffect(() => {
    if (!openEditOnMount || !canEdit) {
      return;
    }
    setEditErrorMessage(null);
    setEditInfoMessage(null);
    setIsEditing(true);
  }, [openEditOnMount, canEdit, personel.id]);

  useEffect(() => {
    publishBusy(isSubmitting);
  }, [isSubmitting]);

  const genelLifecycleFields = useMemo(
    () => pickGenelLifecycleFormFields(editForm, personel),
    [editForm, personel]
  );

  const hasLifecycleDiff = useMemo(
    () => computeHasLifecycleDiff(personel, genelLifecycleFields),
    [personel, genelLifecycleFields]
  );

  const identityColumns = useMemo(
    () => [
      {
        items: [
          { label: "T.C. Kimlik No", value: formatGeneralField(personel.tc_kimlik_no) },
          { label: "Doğum Tarihi", value: formatGeneralField(personel.dogum_tarihi) },
          { label: "Doğum Yeri", value: formatGeneralField(personel.dogum_yeri) },
          { label: "Telefon", value: formatGeneralField(personel.telefon) },
          { label: "Kan Grubu", value: formatGeneralField(personel.kan_grubu) },
          { label: "Acil Durum Kişisi", value: formatGeneralField(personel.acil_durum_kisi) },
          { label: "Acil Durum Telefon", value: formatGeneralField(personel.acil_durum_telefon) }
        ]
      },
      {
        items: [
          { label: "Sicil No", value: formatGeneralField(personel.sicil_no) },
          { label: "İşe Giriş Tarihi", value: formatGeneralField(personel.ise_giris_tarihi) },
          {
            label: "Ücret Tipi",
            value: formatGeneralField(
              displayUcretTipiLabel(personel.ucret_tipi_adi, personel.ucret_tipi_id)
            )
          },
          {
            label: "Maaş (uyumluluk)",
            value: canViewUcret ? formatMoneyField(personel.maas_tutari) : "-"
          },
          { label: "Prim Kuralı", value: formatGeneralField(personel.prim_kurali_adi) }
        ]
      }
    ],
    [canViewUcret, personel]
  );

  const orgSummaryItems = useMemo(
    () => [
      { label: "Departman", value: formatGeneralField(personel.departman_adi) },
      { label: "Bölüm", value: formatGeneralField(personel.bolum_adi) },
      { label: "Birim", value: formatGeneralField(personel.birim_adi) },
      { label: "Görev / Unvan", value: formatGeneralField(personel.gorev_adi) },
      { label: "Pozisyon", value: formatGeneralField(personel.pozisyon_adi) },
      { label: "Bağlı Amir", value: formatGeneralField(personel.bagli_amir_adi) },
      { label: "Statü", value: formatGeneralField(personel.personel_tipi_adi) }
    ],
    [personel]
  );

  const discardEdit = useCallback(() => {
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditForm(personelToEditForm(personel));
  }, [personel]);

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canEdit || isSubmittingRef.current) {
      return;
    }

    setEditErrorMessage(null);
    setEditInfoMessage(null);

    if (hasLifecycleDiff && !editForm.effectiveDate.trim()) {
      setEditErrorMessage("Geçerlilik tarihi zorunludur.");
      return;
    }

    const requestPersonelId = personel.id;
    setIsSubmitting(true);
    publishBusy(true);

    const previousPersonel = personel;
    const body = buildPersonelUpdatePayload(editForm, hasLifecycleDiff, {
      includeWageFields: false,
      includeBagliAmir: false,
      currentPersonel: personel
    });
    const optimistic: Personel = {
      ...personel,
      calisan_kapsami: body.calisan_kapsami ?? personel.calisan_kapsami,
      tc_kimlik_no: body.tc_kimlik_no !== undefined ? body.tc_kimlik_no : personel.tc_kimlik_no,
      ad: body.ad ?? personel.ad,
      soyad: body.soyad !== undefined ? body.soyad : personel.soyad,
      dogum_tarihi: body.dogum_tarihi !== undefined ? body.dogum_tarihi : personel.dogum_tarihi,
      telefon: body.telefon !== undefined ? body.telefon : personel.telefon,
      sicil_no: body.sicil_no ?? personel.sicil_no,
      ise_giris_tarihi: body.ise_giris_tarihi ?? personel.ise_giris_tarihi,
      prim_kurali_id: body.prim_kurali_id !== undefined ? body.prim_kurali_id : personel.prim_kurali_id
    };
    onPersonelUpdated(optimistic);

    try {
      const updated = await updatePersonel(requestPersonelId, body);
      // Stale-response guard: person switched while PUT was in flight.
      if (personelIdRef.current !== requestPersonelId) {
        return;
      }
      deleteCacheEntry(dataCacheKeys.personelDetail(getActiveSube(), requestPersonelId));
      onPersonelUpdated(updated);
      setEditForm(personelToEditForm(updated));
      setIsEditing(false);
      setEditInfoMessage("Personel bilgileri güncellendi.");
    } catch (error) {
      if (personelIdRef.current === requestPersonelId) {
        onPersonelUpdated(previousPersonel);
        setEditErrorMessage(getApiErrorMessage(error, "Personel kaydı güncellenemedi."));
      }
    } finally {
      setIsSubmitting(false);
      // Publish from refs so unmount/tab switch cannot drop lock via stale closure.
      publishBusy(false);
    }
  }

  return (
    <div className="surec-person-general-panel" data-testid="kayit-surec-personel-genel-panel">
      <div className="surec-person-general-head">
        <h4 className="surec-shell-summary-kicker">Genel bilgiler</h4>
      </div>

      {canEdit && !isEditing ? (
        <div className="workspace-inline-actions">
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="kayit-surec-personel-duzenle"
            disabled={isSubmitting}
            onClick={() => {
              setEditErrorMessage(null);
              setEditInfoMessage(null);
              setIsEditing(true);
            }}
          >
            Personeli Düzenle
          </button>
        </div>
      ) : null}

      {isPasif ? (
        <p className="workspace-empty-hint" data-testid="kayit-surec-personel-genel-pasif-hint">
          Bu personel pasif; genel bilgiler salt okunur izlenir.
        </p>
      ) : null}

      {editInfoMessage ? <p className="workspace-success">{editInfoMessage}</p> : null}

      {isEditing ? (
        <PersonelInlineEditForm
          editForm={editForm}
          setEditForm={setEditForm}
          personelRefs={personelRefs}
          editErrorMessage={editErrorMessage}
          isSubmitting={isSubmitting}
          onSubmit={(event) => void handleEditSubmit(event)}
          onDiscard={discardEdit}
        />
      ) : (
        <div className="surec-person-general-columns">
          {identityColumns.map((column, columnIndex) => (
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
          <section
            className="surec-person-general-column"
            data-testid="kayit-surec-personel-genel-org-readonly"
          >
            <p className="personel-form-note personel-form-note--info">
              Organizasyon özeti salt okunur. Değişiklik için Süreç → Görev / Organizasyon sekmesini kullanın.
            </p>
            <div className="surec-shell-summary-grid">
              {orgSummaryItems.map((item) => (
                <div key={`org-${item.label}`} className="surec-shell-summary-item">
                  <span className="surec-shell-summary-label">{item.label}</span>
                  <strong className="surec-shell-summary-value">{item.value}</strong>
                </div>
              ))}
            </div>
          </section>
        </div>
      )}
    </div>
  );
}
