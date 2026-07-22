import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import { AppModal } from "../../../../components/modal/AppModal";
import { fetchPersonelBelgeDurumu } from "../../../../api/belgeler.api";
import {
  cancelPersonelBelgeKaydi,
  createPersonelBelgeKaydi,
  downloadPersonelBelgeDosya,
  fetchPersonelBelgeHistory,
  fetchPersonelBelgeKayitlari,
  replacePersonelBelgeDosya,
  updatePersonelBelgeKaydi
} from "../../../../api/personel-belge-kayitlari.api";
import { getApiErrorMessage } from "../../../../api/api-client";
import { useRoleAccess } from "../../../../hooks/use-role-access";
import type { Personel } from "../../../../types/personel";
import { BELGE_TURU_KEYS, BELGE_TURU_LABELS, type BelgeDurumuItem } from "../../../../types/belgeler";
import {
  createEmptyBelgeKaydiDraft,
  formatPersonelBelgeDisplayText,
  formatPersonelBelgeKayitDurumLabel,
  formatPersonelBelgeKayitTipiLabel,
  formatPersonelBelgeTakipDurumuLabel,
  PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE,
  PERSONEL_BELGE_KAYIT_TIPI_KEYS,
  PERSONEL_BELGE_KAYIT_TIPI_LABELS,
  readFileAsBase64Payload,
  takipDurumuClassName,
  type CreatePersonelBelgeKaydiPayload,
  type PersonelBelgeAuditKaydi,
  type PersonelBelgeKaydi,
  type PersonelBelgeKayitTipi,
  validatePersonelBelgeFileSelection
} from "../../../../types/personel-belge-kaydi";
import { DossierRecord, DossierSection } from "./personel-dosya-dossier";
import { formatIsoDateDetail } from "./personel-dosya-format-utils";

const CREATE_FORM_ID = "personel-belge-create-form";
const EDIT_FORM_ID = "personel-belge-edit-form";

function formatBelgeDurumLabel(durum: BelgeDurumuItem["durum"]) {
  return durum === "VAR" ? "Var" : "Yok";
}

function sortBelgeKayitlari(items: PersonelBelgeKaydi[]): PersonelBelgeKaydi[] {
  return [...items].sort((left, right) => {
    const leftDate = left.bitis_tarihi ?? "";
    const rightDate = right.bitis_tarihi ?? "";
    if (!leftDate && !rightDate) {
      return left.ad.localeCompare(right.ad, "tr");
    }
    if (!leftDate) {
      return 1;
    }
    if (!rightDate) {
      return -1;
    }
    return leftDate.localeCompare(rightDate);
  });
}

function formatAuditLabel(islem: string) {
  if (islem === "CREATED") return "Oluşturuldu";
  if (islem === "METADATA_UPDATED") return "Bilgiler güncellendi";
  if (islem === "FILE_REPLACED") return "Dosya değiştirildi";
  if (islem === "CANCELLED") return "İptal edildi";
  return islem;
}

export function PersonelBelgelerPanel({
  personel,
  isActive
}: {
  personel: Personel;
  isActive: boolean;
}) {
  const { hasPermission } = useRoleAccess();
  const canCreate = hasPermission("surecler.create");
  const canUpdate = hasPermission("surecler.update");
  const canCancel = hasPermission("surecler.cancel");
  const canWrite = canCreate || canUpdate || canCancel;

  const [items, setItems] = useState<BelgeDurumuItem[]>([]);
  const [belgeKayitlari, setBelgeKayitlari] = useState<PersonelBelgeKaydi[]>([]);
  const [iptalBelgeKayitlari, setIptalBelgeKayitlari] = useState<PersonelBelgeKaydi[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isBelgeKayitlariLoading, setIsBelgeKayitlariLoading] = useState(false);
  const [isIptalBelgeKayitlariLoading, setIsIptalBelgeKayitlariLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [belgeKayitlariErrorMessage, setBelgeKayitlariErrorMessage] = useState<string | null>(null);
  const [iptalBelgeKayitlariErrorMessage, setIptalBelgeKayitlariErrorMessage] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [createDraft, setCreateDraft] = useState<CreatePersonelBelgeKaydiPayload>(() =>
    createEmptyBelgeKaydiDraft()
  );
  const [createFile, setCreateFile] = useState<File | null>(null);
  const [createFileError, setCreateFileError] = useState<string | null>(null);
  const [isCreateSaving, setIsCreateSaving] = useState(false);

  const [editingKayit, setEditingKayit] = useState<PersonelBelgeKaydi | null>(null);
  const [editDraft, setEditDraft] = useState<CreatePersonelBelgeKaydiPayload>(() => createEmptyBelgeKaydiDraft());
  const [isEditSaving, setIsEditSaving] = useState(false);

  const [replaceKayit, setReplaceKayit] = useState<PersonelBelgeKaydi | null>(null);
  const [replaceFile, setReplaceFile] = useState<File | null>(null);
  const [replaceFileError, setReplaceFileError] = useState<string | null>(null);
  const [isReplaceSaving, setIsReplaceSaving] = useState(false);

  const [cancelKayit, setCancelKayit] = useState<PersonelBelgeKaydi | null>(null);
  const [cancelReason, setCancelReason] = useState("");
  const [isCancelSaving, setIsCancelSaving] = useState(false);

  const [historyKayit, setHistoryKayit] = useState<PersonelBelgeKaydi | null>(null);
  const [historyItems, setHistoryItems] = useState<PersonelBelgeAuditKaydi[]>([]);
  const [isHistoryLoading, setIsHistoryLoading] = useState(false);
  const [historyError, setHistoryError] = useState<string | null>(null);

  const [downloadingId, setDownloadingId] = useState<number | null>(null);

  const isPasif = personel.aktif_durum === "PASIF";
  const readOnly = isPasif || !canWrite;

  const loadBelgeKayitlari = useCallback(async () => {
    setIsBelgeKayitlariLoading(true);
    setBelgeKayitlariErrorMessage(null);
    try {
      const result = await fetchPersonelBelgeKayitlari(personel.id, { state: "AKTIF", limit: 50 });
      setBelgeKayitlari(result.items);
    } catch (err) {
      setBelgeKayitlari([]);
      setBelgeKayitlariErrorMessage(getApiErrorMessage(err, "Belge kayıtları yüklenemedi."));
    } finally {
      setIsBelgeKayitlariLoading(false);
    }
  }, [personel.id]);

  const loadIptalBelgeKayitlari = useCallback(async () => {
    setIsIptalBelgeKayitlariLoading(true);
    setIptalBelgeKayitlariErrorMessage(null);
    try {
      const result = await fetchPersonelBelgeKayitlari(personel.id, { state: "IPTAL", limit: 50 });
      setIptalBelgeKayitlari(result.items);
    } catch (err) {
      setIptalBelgeKayitlari([]);
      setIptalBelgeKayitlariErrorMessage(
        getApiErrorMessage(err, "İptal edilen belge kayıtları yüklenemedi.")
      );
    } finally {
      setIsIptalBelgeKayitlariLoading(false);
    }
  }, [personel.id]);

  useEffect(() => {
    let isCancelled = false;

    if (!isActive) {
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    fetchPersonelBelgeDurumu(personel.id)
      .then((fetchedItems) => {
        if (!isCancelled) {
          setItems(fetchedItems);
        }
      })
      .catch((err) => {
        if (!isCancelled) {
          setItems([]);
          setErrorMessage(getApiErrorMessage(err, "Belge durumu yüklenemedi."));
        }
      })
      .finally(() => {
        if (!isCancelled) {
          setIsLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [isActive, personel.id]);

  useEffect(() => {
    if (!isActive) {
      return;
    }
    void loadBelgeKayitlari();
    void loadIptalBelgeKayitlari();
  }, [isActive, loadBelgeKayitlari, loadIptalBelgeKayitlari]);

  useEffect(() => {
    if (!historyKayit) {
      setHistoryItems([]);
      setHistoryError(null);
      return;
    }

    let isCancelled = false;
    setIsHistoryLoading(true);
    setHistoryError(null);
    fetchPersonelBelgeHistory(historyKayit.id)
      .then((rows) => {
        if (!isCancelled) {
          setHistoryItems(rows);
        }
      })
      .catch((err) => {
        if (!isCancelled) {
          setHistoryItems([]);
          setHistoryError(getApiErrorMessage(err, "Belge geçmişi yüklenemedi."));
        }
      })
      .finally(() => {
        if (!isCancelled) {
          setIsHistoryLoading(false);
        }
      });

    return () => {
      isCancelled = true;
    };
  }, [historyKayit]);

  const hasAnyBelge = items.some((item) => item.durum === "VAR");
  const sortedBelgeKayitlari = useMemo(() => sortBelgeKayitlari(belgeKayitlari), [belgeKayitlari]);
  const sortedIptalBelgeKayitlari = useMemo(
    () => sortBelgeKayitlari(iptalBelgeKayitlari),
    [iptalBelgeKayitlari]
  );
  const showIptalBelgeKayitlariSection =
    !isIptalBelgeKayitlariLoading &&
    !iptalBelgeKayitlariErrorMessage &&
    sortedIptalBelgeKayitlari.length > 0;

  async function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canCreate || isPasif) {
      return;
    }

    const ad = createDraft.ad.trim();
    if (!ad) {
      setActionError("Ad alanı zorunludur.");
      return;
    }

    setIsCreateSaving(true);
    setActionError(null);
    setActionMessage(null);
    try {
      const payload: CreatePersonelBelgeKaydiPayload = {
        kayit_tipi: createDraft.kayit_tipi,
        ad,
        veren_kurum: createDraft.veren_kurum?.trim() || null,
        belge_no: createDraft.belge_no?.trim() || null,
        baslangic_tarihi: createDraft.baslangic_tarihi?.trim() || null,
        bitis_tarihi: createDraft.bitis_tarihi?.trim() || null,
        ek_ref: createDraft.ek_ref?.trim() || null,
        aciklama: createDraft.aciklama?.trim() || null
      };

      if (createFile) {
        const filePayload = await readFileAsBase64Payload(createFile);
        payload.dosya_adi = filePayload.dosya_adi;
        payload.dosya_mime = filePayload.dosya_mime;
        payload.dosya_icerik_base64 = filePayload.dosya_icerik_base64;
      }

      await createPersonelBelgeKaydi(personel.id, payload);
      setCreateDraft(createEmptyBelgeKaydiDraft());
      setCreateFile(null);
      setCreateFileError(null);
      setIsCreateOpen(false);
      setActionMessage("Belge kaydı eklendi.");
      await loadBelgeKayitlari();
    } catch (err) {
      setActionError(getApiErrorMessage(err, "Belge kaydı eklenemedi."));
    } finally {
      setIsCreateSaving(false);
    }
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!canUpdate || !editingKayit || isPasif) {
      return;
    }

    const ad = editDraft.ad.trim();
    if (!ad) {
      setActionError("Ad alanı zorunludur.");
      return;
    }

    setIsEditSaving(true);
    setActionError(null);
    setActionMessage(null);
    try {
      await updatePersonelBelgeKaydi(editingKayit.id, {
        kayit_tipi: editDraft.kayit_tipi,
        ad,
        veren_kurum: editDraft.veren_kurum?.trim() || null,
        belge_no: editDraft.belge_no?.trim() || null,
        baslangic_tarihi: editDraft.baslangic_tarihi?.trim() || null,
        bitis_tarihi: editDraft.bitis_tarihi?.trim() || null,
        ek_ref: editDraft.ek_ref?.trim() || null,
        aciklama: editDraft.aciklama?.trim() || null
      });
      setEditingKayit(null);
      setActionMessage("Belge bilgileri güncellendi.");
      await loadBelgeKayitlari();
    } catch (err) {
      setActionError(getApiErrorMessage(err, "Belge güncellenemedi."));
    } finally {
      setIsEditSaving(false);
    }
  }

  async function handleReplaceConfirm() {
    if (!canUpdate || !replaceKayit || !replaceFile || isPasif) {
      return;
    }

    setIsReplaceSaving(true);
    setActionError(null);
    setActionMessage(null);
    try {
      const filePayload = await readFileAsBase64Payload(replaceFile);
      await replacePersonelBelgeDosya(replaceKayit.id, filePayload);
      setReplaceKayit(null);
      setReplaceFile(null);
      setReplaceFileError(null);
      setActionMessage("Belge dosyası güncellendi.");
      await loadBelgeKayitlari();
    } catch (err) {
      setActionError(getApiErrorMessage(err, "Belge dosyası değiştirilemedi."));
    } finally {
      setIsReplaceSaving(false);
    }
  }

  async function handleCancelConfirm() {
    if (!canCancel || !cancelKayit || isPasif) {
      return;
    }

    const reason = cancelReason.trim();
    if (!reason) {
      setActionError("İptal nedeni zorunludur.");
      return;
    }

    setIsCancelSaving(true);
    setActionError(null);
    setActionMessage(null);
    try {
      await cancelPersonelBelgeKaydi(cancelKayit.id, { iptal_nedeni: reason });
      setCancelKayit(null);
      setCancelReason("");
      setActionMessage("Belge kaydı iptal edildi.");
      await Promise.all([loadBelgeKayitlari(), loadIptalBelgeKayitlari()]);
    } catch (err) {
      setActionError(getApiErrorMessage(err, "Belge kaydı iptal edilemedi."));
    } finally {
      setIsCancelSaving(false);
    }
  }

  async function handleDownload(kayit: PersonelBelgeKaydi) {
    setDownloadingId(kayit.id);
    setActionError(null);
    try {
      await downloadPersonelBelgeDosya(
        kayit.id,
        kayit.dosya?.orijinal_dosya_adi ?? `${kayit.ad}.pdf`
      );
    } catch (err) {
      setActionError(getApiErrorMessage(err, "Belge indirilemedi."));
    } finally {
      setDownloadingId(null);
    }
  }

  function openEditModal(kayit: PersonelBelgeKaydi) {
    setEditingKayit(kayit);
    setEditDraft({
      kayit_tipi: kayit.kayit_tipi,
      ad: kayit.ad,
      veren_kurum: kayit.veren_kurum ?? "",
      belge_no: kayit.belge_no ?? "",
      baslangic_tarihi: kayit.baslangic_tarihi ?? "",
      bitis_tarihi: kayit.bitis_tarihi ?? "",
      ek_ref: kayit.ek_ref ?? "",
      aciklama: kayit.aciklama ?? ""
    });
  }

  function handleCreateFileChange(file: File | null) {
    setCreateFile(file);
    setCreateFileError(file ? validatePersonelBelgeFileSelection(file) : null);
  }

  function handleReplaceFileChange(file: File | null) {
    setReplaceFile(file);
    setReplaceFileError(file ? validatePersonelBelgeFileSelection(file) : null);
  }

  return (
    <div className="personel-dosya-sections" data-testid="personel-belgeler-panel">
      <DossierSection
        title="Belge Durumu"
        description="Personel dosyasındaki zorunlu belgeler salt okunur izlenir; düzenleme kayıt ve süreç ekranından yapılır."
      >
        {isPasif ? (
          <DossierRecord label="Durum" value="Bu personel pasif; belge durumu salt okunur gösterilir." />
        ) : null}

        {isLoading ? <DossierRecord label="Durum" value="Belgeler yükleniyor..." /> : null}
        {!isLoading && errorMessage ? <DossierRecord label="Durum" value={errorMessage} /> : null}

        {!isLoading && !errorMessage ? (
          <>
            {!hasAnyBelge ? (
              <DossierRecord label="Kayıt" value="Henüz VAR olarak işaretlenmiş belge yok." />
            ) : null}
            {BELGE_TURU_KEYS.map((tur) => {
              const item = items.find((row) => row.belge_turu === tur);
              const durum = item?.durum ?? "YOK";
              return (
                <DossierRecord
                  key={tur}
                  label={BELGE_TURU_LABELS[tur]}
                  value={formatBelgeDurumLabel(durum)}
                />
              );
            })}
          </>
        ) : null}
      </DossierSection>

      <DossierSection
        title="Personel Belgeleri"
        description="Eğitim, sertifika ve resmi belge kayıtları bu bölümde yönetilir."
      >
        <div className="personel-belge-panel-head">
          <Link className="personeller-toolbar-module-link" to="/personeller/belge-takip" data-testid="personel-belge-takip-link">
            Belge Takip
          </Link>
          {canCreate && !isPasif ? (
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="personel-belge-yeni-btn"
              onClick={() => {
                setCreateDraft(createEmptyBelgeKaydiDraft());
                setCreateFile(null);
                setCreateFileError(null);
                setIsCreateOpen(true);
              }}
            >
              Yeni belge ekle
            </button>
          ) : null}
        </div>

        {readOnly && !isPasif ? (
          <DossierRecord label="Yetki" value="Bu personel için belge düzenleme yetkiniz yok." />
        ) : null}

        {actionMessage ? <DossierRecord label="Bilgi" value={actionMessage} /> : null}
        {actionError ? <DossierRecord label="Hata" value={actionError} /> : null}

        {isBelgeKayitlariLoading ? (
          <DossierRecord label="Durum" value="Belge kayıtları yükleniyor..." />
        ) : null}
        {!isBelgeKayitlariLoading && belgeKayitlariErrorMessage ? (
          <DossierRecord label="Durum" value={belgeKayitlariErrorMessage} />
        ) : null}
        {!isBelgeKayitlariLoading && !belgeKayitlariErrorMessage && sortedBelgeKayitlari.length === 0 ? (
          <DossierRecord label="Kayıt" value={PERSONEL_BELGE_KAYIT_EMPTY_MESSAGE} />
        ) : null}

        {!isBelgeKayitlariLoading && !belgeKayitlariErrorMessage && sortedBelgeKayitlari.length > 0 ? (
          <div className="personel-belge-kayit-table-wrap" data-testid="personel-belge-kayit-list">
            <table className="personel-belge-kayit-table">
              <thead>
                <tr>
                  <th>Tip</th>
                  <th>Ad</th>
                  <th>Belge no</th>
                  <th>Başlangıç</th>
                  <th>Bitiş</th>
                  <th>Takip</th>
                  <th>Yükleyen</th>
                  <th>Son güncelleme</th>
                  <th>İşlemler</th>
                </tr>
              </thead>
              <tbody>
                {sortedBelgeKayitlari.map((kayit) => (
                  <tr key={kayit.id} data-testid={`personel-belge-kayit-row-${kayit.id}`}>
                    <td>{formatPersonelBelgeKayitTipiLabel(kayit.kayit_tipi)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.ad)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.belge_no_masked)}</td>
                    <td>{formatIsoDateDetail(kayit.baslangic_tarihi)}</td>
                    <td>{formatIsoDateDetail(kayit.bitis_tarihi)}</td>
                    <td>
                      <span
                        className={takipDurumuClassName(kayit.takip_durumu)}
                        data-testid={`personel-belge-takip-${kayit.id}`}
                      >
                        {formatPersonelBelgeTakipDurumuLabel(kayit.takip_durumu)}
                      </span>
                    </td>
                    <td>{formatPersonelBelgeDisplayText(kayit.yukleyen_ad)}</td>
                    <td>{formatIsoDateDetail(kayit.updated_at ?? kayit.created_at)}</td>
                    <td>
                      <div className="personel-belge-actions">
                        {kayit.dosya?.var_mi ? (
                          <button
                            type="button"
                            className="universal-btn-aux"
                            data-testid={`personel-belge-indir-${kayit.id}`}
                            disabled={downloadingId === kayit.id}
                            onClick={() => void handleDownload(kayit)}
                          >
                            {downloadingId === kayit.id ? "İndiriliyor..." : "İndir"}
                          </button>
                        ) : (
                          <span className="personel-belge-file-meta">Dosya yok</span>
                        )}
                        <button
                          type="button"
                          className="universal-btn-aux"
                          data-testid={`personel-belge-gecmis-${kayit.id}`}
                          onClick={() => setHistoryKayit(kayit)}
                        >
                          Geçmiş
                        </button>
                        {canUpdate && !isPasif ? (
                          <button
                            type="button"
                            className="universal-btn-aux"
                            data-testid={`personel-belge-duzenle-${kayit.id}`}
                            onClick={() => openEditModal(kayit)}
                          >
                            Düzenle
                          </button>
                        ) : null}
                        {canUpdate && !isPasif ? (
                          <button
                            type="button"
                            className="universal-btn-aux"
                            data-testid={`personel-belge-dosya-degistir-${kayit.id}`}
                            onClick={() => {
                              setReplaceKayit(kayit);
                              setReplaceFile(null);
                              setReplaceFileError(null);
                            }}
                          >
                            Dosya değiştir
                          </button>
                        ) : null}
                        {canCancel && !isPasif ? (
                          <button
                            type="button"
                            className="universal-btn-aux"
                            data-testid={`personel-belge-iptal-${kayit.id}`}
                            onClick={() => {
                              setCancelKayit(kayit);
                              setCancelReason("");
                            }}
                          >
                            İptal
                          </button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </DossierSection>

      {showIptalBelgeKayitlariSection ? (
        <DossierSection
          title="İptal edilen belge kayıtları"
          description="İptal edilmiş belge kayıtları salt okunur geçmiş olarak gösterilir."
        >
          <div className="personel-belge-kayit-table-wrap" data-testid="personel-belge-kayit-iptal-list">
            <table className="personel-belge-kayit-table">
              <thead>
                <tr>
                  <th>Tip</th>
                  <th>Ad</th>
                  <th>Belge no</th>
                  <th>Bitiş</th>
                  <th>Durum</th>
                  <th>Açıklama</th>
                </tr>
              </thead>
              <tbody>
                {sortedIptalBelgeKayitlari.map((kayit) => (
                  <tr key={kayit.id} data-testid={`personel-belge-kayit-iptal-row-${kayit.id}`}>
                    <td>{formatPersonelBelgeKayitTipiLabel(kayit.kayit_tipi)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.ad)}</td>
                    <td>{formatPersonelBelgeDisplayText(kayit.belge_no_masked)}</td>
                    <td>{formatIsoDateDetail(kayit.bitis_tarihi)}</td>
                    <td>
                      <span className="personel-surec-state is-cancelled">
                        {formatPersonelBelgeKayitDurumLabel(kayit.durum)}
                      </span>
                    </td>
                    <td>{formatPersonelBelgeDisplayText(kayit.aciklama)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </DossierSection>
      ) : null}

      {isCreateOpen ? (
        <AppModal
          title="Yeni belge ekle"
          onClose={() => setIsCreateOpen(false)}
        footer={
          <>
            <button type="button" className="universal-btn-aux" onClick={() => setIsCreateOpen(false)}>
              Vazgeç
            </button>
            <button
              type="submit"
              form={CREATE_FORM_ID}
              className="universal-btn-save"
              disabled={isCreateSaving || Boolean(createFileError)}
              data-testid="personel-belge-create-submit"
            >
              {isCreateSaving ? "Kaydediliyor..." : "Kaydet"}
            </button>
          </>
        }
      >
        <form id={CREATE_FORM_ID} className="workspace-form" onSubmit={handleCreateSubmit}>
          {renderBelgeFormFields(createDraft, setCreateDraft)}
          <div className="form-section">
            <label className="form-label" htmlFor="personel-belge-create-dosya">
              Dosya (isteğe bağlı)
            </label>
            <input
              id="personel-belge-create-dosya"
              type="file"
              accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
              data-testid="personel-belge-create-dosya"
              onChange={(event) => handleCreateFileChange(event.target.files?.[0] ?? null)}
            />
            {createFile ? (
              <p className="personel-belge-file-meta">
                {createFile.name} · {(createFile.size / 1024).toFixed(1)} KB · {createFile.type || "bilinmiyor"}
              </p>
            ) : null}
            {createFileError ? (
              <p className="workspace-error" data-testid="personel-belge-file-error">
                {createFileError}
              </p>
            ) : null}
          </div>
        </form>
        </AppModal>
      ) : null}

      {editingKayit !== null ? (
        <AppModal
          title="Belge bilgilerini düzenle"
          onClose={() => setEditingKayit(null)}
        footer={
          <>
            <button type="button" className="universal-btn-aux" onClick={() => setEditingKayit(null)}>
              Vazgeç
            </button>
            <button
              type="submit"
              form={EDIT_FORM_ID}
              className="universal-btn-save"
              disabled={isEditSaving}
              data-testid="personel-belge-edit-submit"
            >
              {isEditSaving ? "Kaydediliyor..." : "Güncelle"}
            </button>
          </>
        }
      >
        <form id={EDIT_FORM_ID} className="workspace-form" onSubmit={handleEditSubmit}>
          {renderBelgeFormFields(editDraft, setEditDraft)}
        </form>
        </AppModal>
      ) : null}

      {replaceKayit !== null ? (
        <AppModal
          title="Belge dosyasını değiştir"
          onClose={() => setReplaceKayit(null)}
        footer={
          <>
            <button type="button" className="universal-btn-aux" onClick={() => setReplaceKayit(null)}>
              Vazgeç
            </button>
            <button
              type="button"
              className="universal-btn-save"
              disabled={isReplaceSaving || !replaceFile || Boolean(replaceFileError)}
              data-testid="personel-belge-replace-submit"
              onClick={() => void handleReplaceConfirm()}
            >
              {isReplaceSaving ? "Yükleniyor..." : "Dosyayı değiştir"}
            </button>
          </>
        }
      >
        <p className="workspace-empty-hint">
          {replaceKayit ? `${formatPersonelBelgeDisplayText(replaceKayit.ad)} için yeni dosya seçin.` : ""}
        </p>
        <div className="form-section">
          <label className="form-label" htmlFor="personel-belge-replace-dosya">
            Yeni dosya
          </label>
          <input
            id="personel-belge-replace-dosya"
            type="file"
            accept=".pdf,.png,.jpg,.jpeg,.webp,.doc,.docx"
            data-testid="personel-belge-replace-dosya"
            onChange={(event) => handleReplaceFileChange(event.target.files?.[0] ?? null)}
          />
          {replaceFile ? (
            <p className="personel-belge-file-meta">
              {replaceFile.name} · {(replaceFile.size / 1024).toFixed(1)} KB · {replaceFile.type || "bilinmiyor"}
            </p>
          ) : null}
          {replaceFileError ? (
            <p className="workspace-error" data-testid="personel-belge-file-error">
              {replaceFileError}
            </p>
          ) : null}
        </div>
        </AppModal>
      ) : null}

      {cancelKayit !== null ? (
        <AppModal
          title="Belge kaydını iptal et"
          onClose={() => setCancelKayit(null)}
        footer={
          <>
            <button type="button" className="universal-btn-aux" onClick={() => setCancelKayit(null)}>
              Vazgeç
            </button>
            <button
              type="button"
              className="universal-btn-save"
              disabled={isCancelSaving || !cancelReason.trim()}
              data-testid="personel-belge-cancel-submit"
              onClick={() => void handleCancelConfirm()}
            >
              {isCancelSaving ? "İptal ediliyor..." : "İptali onayla"}
            </button>
          </>
        }
      >
        <div className="form-section">
          <label className="form-label" htmlFor="personel-belge-cancel-neden">
            İptal nedeni
          </label>
          <textarea
            id="personel-belge-cancel-neden"
            value={cancelReason}
            data-testid="personel-belge-cancel-neden"
            onChange={(event) => setCancelReason(event.target.value)}
          />
        </div>
        </AppModal>
      ) : null}

      {historyKayit !== null ? (
        <AppModal
          title="Belge geçmişi"
          onClose={() => setHistoryKayit(null)}
        footer={
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="personel-belge-history-close"
            onClick={() => setHistoryKayit(null)}
          >
            Kapat
          </button>
        }
      >
        {isHistoryLoading ? <p className="workspace-empty-hint">Geçmiş yükleniyor...</p> : null}
        {historyError ? <p className="workspace-error">{historyError}</p> : null}
        {!isHistoryLoading && !historyError && historyItems.length === 0 ? (
          <p className="workspace-empty-hint">Henüz geçmiş kaydı yok.</p>
        ) : null}
        {!isHistoryLoading && !historyError && historyItems.length > 0 ? (
          <div className="personel-belge-kayit-table-wrap" data-testid="personel-belge-history-list">
            <table className="personel-belge-kayit-table">
              <thead>
                <tr>
                  <th>İşlem</th>
                  <th>Kullanıcı</th>
                  <th>Gerekçe</th>
                  <th>Tarih</th>
                </tr>
              </thead>
              <tbody>
                {historyItems.map((row) => (
                  <tr key={row.id} data-testid={`personel-belge-history-row-${row.id}`}>
                    <td>{formatAuditLabel(row.islem_turu)}</td>
                    <td>{formatPersonelBelgeDisplayText(row.yapan_kullanici_ad)}</td>
                    <td>{formatPersonelBelgeDisplayText(row.gerekce)}</td>
                    <td>{formatIsoDateDetail(row.created_at)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
        </AppModal>
      ) : null}
    </div>
  );
}

function renderBelgeFormFields(
  draft: CreatePersonelBelgeKaydiPayload,
  setDraft: (updater: (prev: CreatePersonelBelgeKaydiPayload) => CreatePersonelBelgeKaydiPayload) => void
) {
  return (
    <>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-tipi">
          Kayıt tipi
        </label>
        <select
          id="personel-belge-tipi"
          value={draft.kayit_tipi}
          onChange={(event) =>
            setDraft((prev) => ({
              ...prev,
              kayit_tipi: event.target.value as PersonelBelgeKayitTipi
            }))
          }
        >
          {PERSONEL_BELGE_KAYIT_TIPI_KEYS.map((tip) => (
            <option key={tip} value={tip}>
              {PERSONEL_BELGE_KAYIT_TIPI_LABELS[tip]}
            </option>
          ))}
        </select>
      </div>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-ad">
          Ad
        </label>
        <input
          id="personel-belge-ad"
          value={draft.ad}
          data-testid="personel-belge-ad"
          onChange={(event) => setDraft((prev) => ({ ...prev, ad: event.target.value }))}
        />
      </div>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-veren-kurum">
          Veren kurum
        </label>
        <input
          id="personel-belge-veren-kurum"
          value={draft.veren_kurum ?? ""}
          onChange={(event) => setDraft((prev) => ({ ...prev, veren_kurum: event.target.value }))}
        />
      </div>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-belge-no">
          Belge no
        </label>
        <input
          id="personel-belge-belge-no"
          value={draft.belge_no ?? ""}
          onChange={(event) => setDraft((prev) => ({ ...prev, belge_no: event.target.value }))}
        />
      </div>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-baslangic">
          Başlangıç tarihi
        </label>
        <input
          id="personel-belge-baslangic"
          type="date"
          value={draft.baslangic_tarihi ?? ""}
          onChange={(event) => setDraft((prev) => ({ ...prev, baslangic_tarihi: event.target.value }))}
        />
      </div>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-bitis">
          Bitiş / geçerlilik tarihi
        </label>
        <input
          id="personel-belge-bitis"
          type="date"
          value={draft.bitis_tarihi ?? ""}
          onChange={(event) => setDraft((prev) => ({ ...prev, bitis_tarihi: event.target.value }))}
        />
      </div>
      <div className="form-section">
        <label className="form-label" htmlFor="personel-belge-aciklama">
          Açıklama
        </label>
        <textarea
          id="personel-belge-aciklama"
          value={draft.aciklama ?? ""}
          onChange={(event) => setDraft((prev) => ({ ...prev, aciklama: event.target.value }))}
        />
      </div>
    </>
  );
}
