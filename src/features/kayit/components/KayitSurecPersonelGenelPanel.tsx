import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import { getApiErrorMessage } from "../../../api/api-client";
import { fetchPersonelDetail, updatePersonel } from "../../../api/personeller.api";
import { createSurec } from "../../../api/surecler.api";
import type { PersonelReferenceBundle } from "../../../data/app-data.types";
import { dataCacheKeys, deleteCacheEntry, getActiveSube } from "../../../data/data-manager";
import { displayUcretTipiLabel } from "../../../lib/display/ucret-tipi-display";
import {
  computeHasLifecycleDiff,
  lifecycleSnapshotToPersonelPatch,
  snapshotFromLifecycleForm
} from "../../../lib/personel-lifecycle-diff";
import { useAuth } from "../../../state/auth.store";
import type { Personel } from "../../../types/personel";
import { PersonelInlineEditForm } from "../../personeller/components/personel-dosya/PersonelInlineEditForm";
import { parseOptionalPositiveInt } from "../../personeller/personel-create-utils";
import {
  buildBagliAmirContext,
  buildBagliAmirFormGuidance,
  buildBagliAmirSurecPayloads,
  buildPersonelUpdatePayload,
  personelToEditForm,
  pickLifecycleFormFields,
  type BagliAmirContext,
  type EditPersonelFormState
} from "../../personeller/personel-edit-utils";
import { formatGeneralField, formatMoneyField, getPersonelInitials } from "../kayit-surec-utils";

type KayitSurecPersonelGenelPanelProps = {
  personel: Personel;
  canUpdatePersonel: boolean;
  canManageUcret: boolean;
  canViewUcret: boolean;
  personelRefs: PersonelReferenceBundle;
  onPersonelUpdated: (updated: Personel) => void;
};

async function fetchBagliAmirContext(amirId: number): Promise<BagliAmirContext | null> {
  try {
    const amir = await fetchPersonelDetail(amirId);
    return buildBagliAmirContext(amir);
  } catch {
    return null;
  }
}

export function KayitSurecPersonelGenelPanel({
  personel,
  canUpdatePersonel,
  canManageUcret,
  canViewUcret,
  personelRefs,
  onPersonelUpdated
}: KayitSurecPersonelGenelPanelProps) {
  const { session } = useAuth();
  const activeSubeId = session?.active_sube_id ?? null;
  const isPasif = personel.aktif_durum === "PASIF";
  const canEdit = canUpdatePersonel && !isPasif;

  const [isEditing, setIsEditing] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [editErrorMessage, setEditErrorMessage] = useState<string | null>(null);
  const [editInfoMessage, setEditInfoMessage] = useState<string | null>(null);
  const [editForm, setEditForm] = useState<EditPersonelFormState>(() => personelToEditForm(personel));
  const [editBagliAmirContext, setEditBagliAmirContext] = useState<BagliAmirContext | null>(null);

  useEffect(() => {
    setEditForm(personelToEditForm(personel));
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditInfoMessage(null);
  }, [personel]);

  useEffect(() => {
    let cancelled = false;
    const amirId = personel.bagli_amir_id;
    if (typeof amirId !== "number") {
      setEditBagliAmirContext(null);
      return () => {
        cancelled = true;
      };
    }

    void (async () => {
      const context = await fetchBagliAmirContext(amirId);
      if (!cancelled) {
        setEditBagliAmirContext(context);
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [personel.bagli_amir_id]);

  const hasLifecycleDiff = useMemo(
    () => computeHasLifecycleDiff(personel, pickLifecycleFormFields(editForm)),
    [personel, editForm]
  );

  const editBagliAmirGuidance = useMemo(
    () => buildBagliAmirFormGuidance(editForm.departmanId, editBagliAmirContext, activeSubeId),
    [activeSubeId, editBagliAmirContext, editForm.departmanId]
  );

  const generalColumns = useMemo(
    () => [
      {
        items: [
          { label: "T.C. Kimlik No", value: formatGeneralField(personel.tc_kimlik_no) },
          { label: "Doğum Tarihi", value: formatGeneralField(personel.dogum_tarihi) },
          { label: "Doğum Yeri", value: formatGeneralField(personel.dogum_yeri) },
          { label: "Telefon", value: formatGeneralField(personel.telefon) },
          { label: "Kan Grubu", value: formatGeneralField(personel.kan_grubu) }
        ]
      },
      {
        items: [
          { label: "Acil Durum Kişisi", value: formatGeneralField(personel.acil_durum_kisi) },
          { label: "Acil Durum Telefon", value: formatGeneralField(personel.acil_durum_telefon) },
          { label: "Bölüm", value: formatGeneralField(personel.departman_adi) },
          { label: "Görev / Unvan", value: formatGeneralField(personel.gorev_adi) },
          { label: "Bağlı Amir", value: formatGeneralField(personel.bagli_amir_adi) }
        ]
      },
      {
        items: [
          { label: "Sicil No", value: formatGeneralField(personel.sicil_no) },
          { label: "İşe Giriş Tarihi", value: formatGeneralField(personel.ise_giris_tarihi) },
          { label: "Personel Tipi", value: formatGeneralField(personel.personel_tipi_adi) },
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

  const handleEditDepartmanChange = useCallback((departmanId: string) => {
    setEditForm((prev) => ({ ...prev, departmanId }));
  }, []);

  const handleEditBagliAmirChange = useCallback((bagliAmirId: string) => {
    setEditForm((prev) => ({ ...prev, bagliAmirId }));

    const amirId = parseOptionalPositiveInt(bagliAmirId);
    if (amirId === undefined) {
      setEditBagliAmirContext(null);
      return;
    }

    void (async () => {
      const context = await fetchBagliAmirContext(amirId);
      setEditBagliAmirContext(context);
      if (!context?.departmanId) {
        return;
      }
      setEditForm((prev) =>
        prev.bagliAmirId === bagliAmirId ? { ...prev, departmanId: context.departmanId } : prev
      );
    })();
  }, []);

  const discardEdit = useCallback(() => {
    setIsEditing(false);
    setEditErrorMessage(null);
    setEditForm(personelToEditForm(personel));
    const amirId = personel.bagli_amir_id;
    if (typeof amirId !== "number") {
      setEditBagliAmirContext(null);
      return;
    }
    void (async () => {
      setEditBagliAmirContext(await fetchBagliAmirContext(amirId));
    })();
  }, [personel]);

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canEdit || isSubmitting) {
      return;
    }

    setEditErrorMessage(null);
    setEditInfoMessage(null);

    if (hasLifecycleDiff && !editForm.effectiveDate.trim()) {
      setEditErrorMessage("Geçerlilik tarihi zorunludur.");
      return;
    }

    setIsSubmitting(true);
    const previousPersonel = personel;
    const body = buildPersonelUpdatePayload(editForm, hasLifecycleDiff);
    const lifecycleSnap = snapshotFromLifecycleForm(pickLifecycleFormFields(editForm));
    const optimistic: Personel = {
      ...personel,
      ad: body.ad ?? personel.ad,
      soyad: body.soyad ?? personel.soyad,
      telefon: body.telefon ?? personel.telefon,
      ...lifecycleSnapshotToPersonelPatch(lifecycleSnap)
    };
    onPersonelUpdated(optimistic);

    try {
      const updated = await updatePersonel(personel.id, body);
      deleteCacheEntry(dataCacheKeys.personelDetail(getActiveSube(), personel.id));
      onPersonelUpdated(updated);
      setEditForm(personelToEditForm(updated));
      setIsEditing(false);
      setEditInfoMessage("Personel bilgileri güncellendi.");

      if (hasLifecycleDiff && editForm.effectiveDate.trim()) {
        const payloads = buildBagliAmirSurecPayloads(
          previousPersonel,
          updated,
          editForm.effectiveDate.trim(),
          personelRefs.bagliAmirOptions
        );
        if (payloads.length > 0) {
          await Promise.allSettled(payloads.map((payload) => createSurec(payload)));
        }
      }
    } catch (error) {
      onPersonelUpdated(previousPersonel);
      setEditErrorMessage(getApiErrorMessage(error, "Personel kaydı güncellenemedi."));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <div className="surec-person-general-panel" data-testid="kayit-surec-personel-genel-panel">
      <div className="surec-person-general-head">
        <div>
          <p className="surec-shell-summary-kicker">Genel bilgiler</p>
          <h4 className="surec-person-general-title">
            {personel.ad} {personel.soyad}
          </h4>
        </div>
        <div className="surec-person-photo-box" aria-label="Personel fotoğrafı">
          <div className="surec-person-photo-avatar" aria-hidden="true">
            {getPersonelInitials(personel)}
          </div>
          <button type="button" className="surec-person-photo-action" disabled>
            Fotoğraf yükle
          </button>
        </div>
      </div>

      {canEdit && !isEditing ? (
        <div className="workspace-inline-actions">
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="kayit-surec-personel-duzenle"
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
        <p className="workspace-empty-hint">Bu personel pasif; genel bilgiler salt okunur izlenir.</p>
      ) : null}

      {editInfoMessage ? <p className="workspace-success">{editInfoMessage}</p> : null}

      {isEditing ? (
        <PersonelInlineEditForm
          editForm={editForm}
          setEditForm={setEditForm}
          handleEditDepartmanChange={handleEditDepartmanChange}
          handleEditBagliAmirChange={handleEditBagliAmirChange}
          editBagliAmirGuidance={editBagliAmirGuidance}
          personelRefs={personelRefs}
          hasLifecycleDiff={hasLifecycleDiff}
          editErrorMessage={editErrorMessage}
          isSubmitting={isSubmitting}
          canManageUcret={canManageUcret}
          onSubmit={(event) => void handleEditSubmit(event)}
          onDiscard={discardEdit}
        />
      ) : (
        <div className="surec-person-general-columns">
          {generalColumns.map((column, columnIndex) => (
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
      )}
    </div>
  );
}
