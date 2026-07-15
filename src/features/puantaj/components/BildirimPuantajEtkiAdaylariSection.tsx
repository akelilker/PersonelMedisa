import { useEffect, useMemo, useState, type FormEvent } from "react";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { fetchBirimAmiriSecenekleri } from "../../../api/bildirimler.api";
import { getApiErrorMessage } from "../../../api/api-client";
import { useBildirimPuantajEtkiAdaylari } from "../../../hooks/useBildirimPuantajEtkiAdaylari";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";
import {
  canApplyBildirimPuantajEtkiAday,
  canDismissBildirimPuantajEtkiAday,
  canManualApplyBildirimPuantajEtkiAday,
  countUnicodeCharacters,
  formatBildirimPuantajEtkiAdayStateLabel,
  formatConflictDisplay,
  formatManualKararPreview,
  formatProjectedEtkiLabel,
  formatUygulamaModuLabel,
  getBildirimPuantajEtkiAdayStateBadgeClass,
  GEREKCE_MAX_LENGTH,
  MANUAL_KARAR_PRESET_OPTIONS,
  manualKararRequiresMiktar,
  validateDismissGerekce,
  validateManualGerekce,
  validateManualMiktar
} from "../../../lib/bildirim-puantaj-etki-aday/display";
import type {
  BildirimPuantajEtkiAdayDetail,
  BildirimPuantajEtkiAdayListItem,
  BildirimPuantajEtkiManualKararTuru
} from "../../../types/bildirim-puantaj-etki-aday";
import type { BirimAmiriSecenegi } from "../../../types/bildirim";

const APPLY_CONFIRM_WARNING =
  "Bu işlem aday etkisini günlük puantaja uygulayacak ve adayı UYGULANDI durumuna geçirecektir. Mevcut bir puantaj kaydı varsa üzerine yazılmaz.";

const MANUAL_APPLY_OVERWRITE_WARNING =
  "Bu işlem mevcut bir puantaj kaydının üzerine yazmaz. Aynı personel ve tarihte puantaj varsa işlem engellenir.";

const MANUAL_DEVAMSIZLIK_WARNING =
  "Bu karar günlük puantajda yevmiye kesintisi etkisi oluşturacaktır.";

const MANUAL_IZIN_RAPOR_INFO =
  "İzin ve rapor kayıtları ilgili personel süreci üzerinden işlenmelidir.";

function resolvePersonelDisplayName(detail: BildirimPuantajEtkiAdayDetail): string {
  const snapshot = detail.source_snapshot;
  if (snapshot && typeof snapshot === "object") {
    const adSoyad = snapshot.ad_soyad;
    if (typeof adSoyad === "string" && adSoyad.trim()) {
      return adSoyad.trim();
    }
    const personelAd = snapshot.personel_ad;
    if (typeof personelAd === "string" && personelAd.trim()) {
      return personelAd.trim();
    }
  }
  return String(detail.personel_id);
}

const STATE_FILTER_OPTIONS = [
  { value: "", label: "Tümü" },
  { value: "HAZIR", label: "Hazır" },
  { value: "INCELEME_GEREKLI", label: "İnceleme Gerekli" },
  { value: "UYGULANDI", label: "Uygulandı" },
  { value: "YOK_SAYILDI", label: "Yok Sayıldı" }
] as const;

const SUMMARY_CARDS = [
  { key: "toplam", label: "Toplam Aday" },
  { key: "hazir", label: "Hazır" },
  { key: "inceleme_gerekli", label: "İnceleme Gerekli" },
  { key: "uygulandi", label: "Uygulandı" },
  { key: "yok_sayildi", label: "Yok Sayıldı" }
] as const;

type DetailField = {
  label: string;
  value: string;
};

function DetailGroup({ title, fields }: { title: string; fields: DetailField[] }) {
  return (
    <div className="puantaj-etki-detail-group">
      <h4>{title}</h4>
      <dl className="puantaj-etki-detail-grid">
        {fields.map((field) => (
          <div key={`${title}-${field.label}`} className="puantaj-etki-detail-row">
            <dt>{field.label}</dt>
            <dd>{field.value}</dd>
          </div>
        ))}
      </dl>
    </div>
  );
}

function buildDetailGroups(detail: BildirimPuantajEtkiAdayDetail): Array<{ title: string; fields: DetailField[] }> {
  const snapshot = detail.source_snapshot;
  const snapshotAciklama =
    snapshot && typeof snapshot.aciklama === "string" && snapshot.aciklama.trim()
      ? snapshot.aciklama.trim()
      : null;

  return [
    {
      title: "Bildirim bilgileri",
      fields: [
        { label: "Tarih", value: detail.tarih },
        { label: "Personel", value: String(detail.personel_id) },
        { label: "Bildirim türü", value: detail.bildirim_turu },
        { label: "Bildirim açıklaması", value: detail.bildirim_aciklama ?? snapshotAciklama ?? "—" },
        { label: "Bildirim alt tür", value: detail.bildirim_alt_tur ?? "—" },
        { label: "Bildirim dakika", value: detail.bildirim_dakika != null ? String(detail.bildirim_dakika) : "—" }
      ]
    },
    {
      title: "Önerilen puantaj etkisi",
      fields: [
        { label: "Etki türü", value: detail.etki_turu },
        {
          label: "Değer",
          value:
            detail.etki_miktari != null
              ? `${detail.etki_miktari}${detail.etki_birimi ? ` ${detail.etki_birimi}` : ""}`
              : "—"
        },
        { label: "Projection sürümü", value: detail.projection_version ?? "—" }
      ]
    },
    {
      title: "Çakışma bilgisi",
      fields: [
        { label: "Çakışma kodu", value: detail.conflict_code ?? "—" },
        {
          label: "Çakışma açıklaması",
          value: formatConflictDisplay(detail.conflict_code, detail.conflict_detail)
        },
        {
          label: "Mevcut puantaj",
          value: detail.mevcut_puantaj_id != null ? String(detail.mevcut_puantaj_id) : "—"
        }
      ]
    },
    {
      title: "Süreç bilgisi",
      fields: [
        {
          label: "Genel Yönetici onay referansı",
          value: String(detail.genel_yonetici_bildirim_onayi_id)
        },
        { label: "Aylık onay referansı", value: String(detail.aylik_bildirim_onayi_id) },
        { label: "Kaynak kayıt referansı", value: String(detail.gunluk_bildirim_id) },
        { label: "Durum", value: formatBildirimPuantajEtkiAdayStateLabel(detail.state) }
      ]
    },
    {
      title: "Karar bilgisi",
      fields: [
        { label: "Uygulama modu", value: formatUygulamaModuLabel(detail.uygulama_modu) },
        {
          label: "Manuel karar türü",
          value: detail.manuel_karar_turu ?? "—"
        },
        {
          label: "Manuel karar miktarı",
          value: detail.manuel_karar_miktari != null ? String(detail.manuel_karar_miktari) : "—"
        },
        { label: "Karar veren", value: detail.karar_veren_user_id != null ? String(detail.karar_veren_user_id) : "—" },
        { label: "Karar zamanı", value: detail.karar_zamani ?? "—" },
        { label: "Karar gerekçesi", value: detail.karar_gerekcesi ?? "—" },
        {
          label: "Uygulanan puantaj",
          value: detail.uygulanan_puantaj_id != null ? String(detail.uygulanan_puantaj_id) : "—"
        }
      ]
    }
  ];
}

function AdayRowActions({
  item,
  canDismiss,
  onDetail,
  onDismiss
}: {
  item: BildirimPuantajEtkiAdayListItem;
  canDismiss: boolean;
  onDetail: (item: BildirimPuantajEtkiAdayListItem) => void;
  onDismiss: (item: BildirimPuantajEtkiAdayListItem) => void;
}) {
  const dismissVisible = canDismiss && canDismissBildirimPuantajEtkiAday(item.state);

  return (
    <div className="module-item-actions">
      <button
        type="button"
        className="universal-btn-aux"
        data-testid={`puantaj-etki-aday-detail-${item.id}`}
        onClick={() => onDetail(item)}
      >
        Detay
      </button>
      {dismissVisible ? (
        <button
          type="button"
          className="universal-btn-aux"
          data-testid={`puantaj-etki-aday-dismiss-${item.id}`}
          onClick={() => onDismiss(item)}
        >
          Yok Say
        </button>
      ) : null}
    </div>
  );
}

export function BildirimPuantajEtkiAdaylariSection() {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const canView = hasPermission("puantaj.bildirim_etki.view");
  const canDismiss = hasPermission("puantaj.bildirim_etki.dismiss");
  const canApply = hasPermission("puantaj.bildirim_etki.apply");
  const canResolveGyViaOnayApi = hasPermission("genel_yonetici_bildirim_onayi.view");
  const activeSubeId = session?.active_sube_id ?? null;
  const subeIds = session?.user.sube_ids ?? [];
  const subeList = session?.sube_list ?? [];

  const needsLocalSubeSelection =
    canView && activeSubeId === null && subeIds.length === 0 && subeList.length > 0;
  const hasNoAvailableSube = canView && activeSubeId === null && subeIds.length === 0 && subeList.length === 0;

  const availablePanelSubeler = useMemo(() => {
    if (subeIds.length === 0) {
      return subeList;
    }
    return subeList.filter((sube) => subeIds.includes(sube.id));
  }, [subeIds, subeList]);

  const [selectedLocalSubeId, setSelectedLocalSubeId] = useState<number | null>(null);
  const [birimAmiriSecenekleri, setBirimAmiriSecenekleri] = useState<BirimAmiriSecenegi[]>([]);
  const [selectedBirimAmiriUserId, setSelectedBirimAmiriUserId] = useState<number | null>(null);
  const [isBirimAmiriLoading, setIsBirimAmiriLoading] = useState(false);
  const [birimAmiriError, setBirimAmiriError] = useState<string | null>(null);

  const effectiveSubeId = useMemo(() => {
    if (typeof activeSubeId === "number" && activeSubeId > 0) {
      return activeSubeId;
    }
    if (needsLocalSubeSelection) {
      return selectedLocalSubeId;
    }
    return null;
  }, [activeSubeId, needsLocalSubeSelection, selectedLocalSubeId]);

  const {
    ay,
    setAy,
    draftFilters,
    updateDraftFilters,
    submitFilters,
    clearFilters,
    page,
    setPage,
    items,
    ozet,
    pagination,
    detail,
    detailId,
    isLoading,
    isDetailLoading,
    listError,
    ozetError,
    detailError,
    successMessage,
    infoMessage,
    dismissTarget,
    dismissGerekce,
    setDismissGerekce,
    dismissFieldError,
    dismissError,
    isDismissing,
    applyTarget,
    applyError,
    isApplying,
    manualTarget,
    manualKararTuru,
    setManualKararTuru,
    manualMiktar,
    setManualMiktar,
    manualGerekce,
    setManualGerekce,
    manualFieldError,
    manualError,
    isManualApplying,
    contextReady,
    refreshAll,
    openDetail,
    closeDetail,
    openDismissModal,
    closeDismissModal,
    dismissAday,
    openApplyModal,
    closeApplyModal,
    applyAday,
    openManualApplyModal,
    closeManualApplyModal,
    manualApplyAday
  } = useBildirimPuantajEtkiAdaylari({
    enabled: canView,
    canDismiss,
    canApply,
    canResolveGyViaOnayApi,
    subeId: effectiveSubeId,
    birimAmiriUserId: selectedBirimAmiriUserId
  });

  useEffect(() => {
    if (!canView) {
      setSelectedLocalSubeId(null);
      setSelectedBirimAmiriUserId(null);
      setBirimAmiriSecenekleri([]);
      setIsBirimAmiriLoading(false);
      setBirimAmiriError(null);
      return;
    }

    setSelectedBirimAmiriUserId(null);
    setBirimAmiriSecenekleri([]);
    setBirimAmiriError(null);
    if (effectiveSubeId === null) {
      setIsBirimAmiriLoading(false);
      return;
    }

    let current = true;
    setIsBirimAmiriLoading(true);
    void fetchBirimAmiriSecenekleri(effectiveSubeId)
      .then((options) => {
        if (!current) return;
        setBirimAmiriSecenekleri(options);
        setSelectedBirimAmiriUserId(options.length === 1 ? options[0]!.user_id : null);
      })
      .catch((caught) => {
        if (!current) return;
        setBirimAmiriError(getApiErrorMessage(caught, "Birim amiri seçenekleri yüklenemedi."));
      })
      .finally(() => {
        if (current) setIsBirimAmiriLoading(false);
      });

    return () => {
      current = false;
    };
  }, [canView, effectiveSubeId]);

  const contextMessage = useMemo(() => {
    if (hasNoAvailableSube) {
      return "Görüntülenecek şube bulunamadı.";
    }
    if (effectiveSubeId === null) {
      return "Verileri görüntülemek için şube seçin.";
    }
    if (isBirimAmiriLoading) {
      return "Birim amiri seçenekleri yükleniyor...";
    }
    if (birimAmiriError) {
      return null;
    }
    if (birimAmiriSecenekleri.length === 0) {
      return "Seçilen şubede aktif birim amiri bulunamadı.";
    }
    if (selectedBirimAmiriUserId === null) {
      return "Verileri görüntülemek için birim amiri seçin.";
    }
    return null;
  }, [
    birimAmiriError,
    birimAmiriSecenekleri.length,
    effectiveSubeId,
    hasNoAvailableSube,
    isBirimAmiriLoading,
    selectedBirimAmiriUserId
  ]);

  const gerekceValidationError = validateDismissGerekce(dismissGerekce);
  const gerekceCharCount = countUnicodeCharacters(dismissGerekce);
  const dismissSubmitDisabled =
    Boolean(gerekceValidationError) || isDismissing || isApplying || isManualApplying;
  const applySubmitDisabled = isApplying || isDismissing || isManualApplying;

  const manualGerekceValidationError = validateManualGerekce(manualGerekce);
  const manualGerekceCharCount = countUnicodeCharacters(manualGerekce);
  const manualMiktarValidationError = manualKararTuru
    ? validateManualMiktar(manualKararTuru, manualMiktar)
    : null;
  const manualSubmitDisabled =
    !manualKararTuru ||
    Boolean(manualGerekceValidationError) ||
    Boolean(manualMiktarValidationError) ||
    isManualApplying ||
    isApplying ||
    isDismissing;

  const manualPreview = manualKararTuru ? formatManualKararPreview(manualKararTuru) : null;

  const applySubeLabel = useMemo(() => {
    if (!applyTarget) {
      return "—";
    }
    const match = availablePanelSubeler.find((sube) => sube.id === applyTarget.sube_id);
    return match?.ad ?? String(applyTarget.sube_id);
  }, [applyTarget, availablePanelSubeler]);

  const applyBirimAmiriLabel = useMemo(() => {
    if (!applyTarget) {
      return "—";
    }
    const match = birimAmiriSecenekleri.find((option) => option.user_id === applyTarget.birim_amiri_user_id);
    return match?.ad_soyad ?? String(applyTarget.birim_amiri_user_id);
  }, [applyTarget, birimAmiriSecenekleri]);

  const detailApplyVisible =
    canApply &&
    detail != null &&
    !isDetailLoading &&
    !detailError &&
    canApplyBildirimPuantajEtkiAday(detail.state) &&
    !isApplying &&
    !isDismissing &&
    !isManualApplying;

  const detailManualApplyVisible =
    canApply &&
    detail != null &&
    !isDetailLoading &&
    !detailError &&
    canManualApplyBildirimPuantajEtkiAday(detail.state) &&
    !isApplying &&
    !isDismissing &&
    !isManualApplying;

  const manualSubeLabel = useMemo(() => {
    if (!manualTarget) {
      return "—";
    }
    const match = availablePanelSubeler.find((sube) => sube.id === manualTarget.sube_id);
    return match?.ad ?? String(manualTarget.sube_id);
  }, [manualTarget, availablePanelSubeler]);

  const manualBirimAmiriLabel = useMemo(() => {
    if (!manualTarget) {
      return "—";
    }
    const match = birimAmiriSecenekleri.find((option) => option.user_id === manualTarget.birim_amiri_user_id);
    return match?.ad_soyad ?? String(manualTarget.birim_amiri_user_id);
  }, [manualTarget, birimAmiriSecenekleri]);

  function handleManualKararChange(value: string) {
    const next = value as BildirimPuantajEtkiManualKararTuru | "";
    setManualKararTuru(next);
    if (!next || !manualKararRequiresMiktar(next)) {
      setManualMiktar("");
    }
  }

  function handleFilterSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    submitFilters();
  }

  if (!canView) {
    return null;
  }

  return (
    <div
      className="state-card bildirim-mutabakat-panel puantaj-etki-aday-panel"
      data-testid="puantaj-etki-aday-panel"
    >
      <div className="bildirim-mutabakat-panel-head">
        <div>
          <h3>Onaylı Bildirim Puantaj Etki Adayları</h3>
          <p className="bildirim-mutabakat-meta">
            Genel Yönetici onayından geçen bildirimlerin puantaja yansıtılmadan önceki inceleme kayıtları.
          </p>
        </div>
      </div>

      <div className="form-field-grid">
        {needsLocalSubeSelection ? (
          <FormField
            label="Şube"
            name="puantaj-etki-aday-sube"
            as="select"
            value={selectedLocalSubeId != null ? String(selectedLocalSubeId) : ""}
            onChange={(value) => setSelectedLocalSubeId(value ? Number.parseInt(value, 10) : null)}
            selectOptions={availablePanelSubeler.map((sube) => ({
              value: String(sube.id),
              label: sube.ad
            }))}
          />
        ) : null}
        <FormField
          label="Ay"
          name="puantaj-etki-aday-ay"
          type="month"
          value={ay}
          onChange={setAy}
        />
        <FormField
          label="Birim Amiri"
          name="puantaj-etki-aday-birim-amiri"
          as="select"
          value={selectedBirimAmiriUserId != null ? String(selectedBirimAmiriUserId) : ""}
          onChange={(value) => setSelectedBirimAmiriUserId(value ? Number.parseInt(value, 10) : null)}
          selectOptions={birimAmiriSecenekleri.map((option) => ({
            value: String(option.user_id),
            label: option.ad_soyad
          }))}
          disabled={isBirimAmiriLoading || birimAmiriSecenekleri.length === 0}
        />
      </div>

      {birimAmiriError ? <p className="puantaj-form-error">{birimAmiriError}</p> : null}
      {!birimAmiriError && contextMessage ? (
        <p className="bildirim-mutabakat-status" data-testid="puantaj-etki-aday-context">
          {contextMessage}
        </p>
      ) : null}

      {contextReady ? (
        <form className="form-filter-panel" onSubmit={handleFilterSubmit}>
          <div className="form-field-grid">
            <FormField
              label="Personel ID (filtre)"
              name="puantaj-etki-aday-filter-personel"
              type="number"
              min={1}
              value={draftFilters.personelId}
              onChange={(value) => updateDraftFilters({ personelId: value })}
            />
            <FormField
              label="Durum"
              name="puantaj-etki-aday-filter-state"
              as="select"
              value={draftFilters.state}
              onChange={(value) =>
                updateDraftFilters({ state: value as typeof draftFilters.state })
              }
              selectOptions={STATE_FILTER_OPTIONS.map((option) => ({
                value: option.value,
                label: option.label
              }))}
            />
          </div>
          <div className="form-actions-row">
            <button type="submit" className="universal-btn-aux" data-testid="puantaj-etki-aday-filter">
              Filtrele
            </button>
            <button type="button" className="universal-btn-aux" onClick={clearFilters}>
              Temizle
            </button>
          </div>
        </form>
      ) : null}

      {successMessage ? (
        <p className="puantaj-form-success" data-testid="puantaj-etki-aday-success">
          {successMessage}
        </p>
      ) : null}
      {infoMessage ? (
        <p className="puantaj-form-readonly" data-testid="puantaj-etki-aday-info">
          {infoMessage}
        </p>
      ) : null}

      {contextReady && ozet ? (
        <div className="bildirim-model-grid" data-testid="puantaj-etki-aday-counts">
          {SUMMARY_CARDS.map((card) => (
            <div key={card.key} className="bildirim-model-card" data-testid={`puantaj-etki-aday-count-${card.key}`}>
              <span className="bildirim-model-label">{card.label}</span>
              <strong>{ozet.aday_sayilari[card.key]}</strong>
            </div>
          ))}
        </div>
      ) : null}

      {contextReady && isLoading ? <LoadingState label="Puantaj etki adayları yükleniyor..." /> : null}
      {contextReady && !isLoading && (listError || ozetError) ? (
        <ErrorState
          message={listError ?? ozetError ?? "Veri yüklenemedi."}
          onRetry={() => void refreshAll()}
        />
      ) : null}
      {contextReady && !isLoading && !listError && !ozetError && items.length === 0 ? (
        <div data-testid="puantaj-etki-aday-empty">
          <EmptyState
            title="Puantaj etki adayı yok"
            message="Seçili kapsamda puantaj etki adayı bulunmuyor."
          />
        </div>
      ) : null}

      {contextReady && !isLoading && !listError && items.length > 0 ? (
        <>
          <div className="puantaj-etki-aday-table-wrap" data-testid="puantaj-etki-aday-table">
            <table className="puantaj-etki-aday-table">
              <thead>
                <tr>
                  <th>Tarih</th>
                  <th>Personel</th>
                  <th>Bildirim</th>
                  <th>Puantaj Etkisi</th>
                  <th>Durum</th>
                  <th>Çakışma</th>
                  <th>İşlem</th>
                </tr>
              </thead>
              <tbody>
                {items.map((item) => (
                  <tr key={item.id} data-testid={`puantaj-etki-aday-row-${item.id}`}>
                    <td>{item.tarih}</td>
                    <td>{item.personel_id}</td>
                    <td>{item.bildirim_turu}</td>
                    <td>{formatProjectedEtkiLabel(item)}</td>
                    <td>
                      <span
                        className={getBildirimPuantajEtkiAdayStateBadgeClass(item.state)}
                        data-testid={`puantaj-etki-aday-state-${item.id}`}
                      >
                        {formatBildirimPuantajEtkiAdayStateLabel(item.state)}
                      </span>
                      {item.state === "INCELEME_GEREKLI" ? (
                        <p
                          className="puantaj-etki-inceleme-uyari"
                          data-testid={`puantaj-etki-inceleme-uyari-${item.id}`}
                        >
                          Bu aday otomatik uygulanamaz ve muhasebe incelemesi gerektirir.
                        </p>
                      ) : null}
                    </td>
                    <td>{formatConflictDisplay(item.conflict_code)}</td>
                    <td>
                      <AdayRowActions
                        item={item}
                        canDismiss={canDismiss}
                        onDetail={openDetail}
                        onDismiss={openDismissModal}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          <ul className="puantaj-etki-aday-card-list" data-testid="puantaj-etki-aday-cards">
            {items.map((item) => (
              <li key={`card-${item.id}`} className="puantaj-etki-aday-card" data-testid={`puantaj-etki-aday-card-${item.id}`}>
                <div className="puantaj-etki-aday-card-head">
                  <strong>{item.tarih}</strong>
                  <span className={getBildirimPuantajEtkiAdayStateBadgeClass(item.state)}>
                    {formatBildirimPuantajEtkiAdayStateLabel(item.state)}
                  </span>
                </div>
                <p>Personel: {item.personel_id}</p>
                <p>Bildirim: {item.bildirim_turu}</p>
                <p>Puantaj etkisi: {formatProjectedEtkiLabel(item)}</p>
                <p>Çakışma: {formatConflictDisplay(item.conflict_code)}</p>
                {item.state === "INCELEME_GEREKLI" ? (
                  <p className="puantaj-etki-inceleme-uyari" data-testid={`puantaj-etki-inceleme-uyari-${item.id}`}>
                    Bu aday otomatik uygulanamaz ve muhasebe incelemesi gerektirir.
                  </p>
                ) : null}
                <AdayRowActions
                  item={item}
                  canDismiss={canDismiss}
                  onDetail={openDetail}
                  onDismiss={openDismissModal}
                />
              </li>
            ))}
          </ul>

          <div className="module-pagination" data-testid="puantaj-etki-aday-pagination">
            <button
              type="button"
              className="universal-btn-aux"
              onClick={() => setPage((prev) => Math.max(1, prev - 1))}
              disabled={isLoading || !pagination.hasPreviousPage}
            >
              Önceki
            </button>
            <span>
              Sayfa {pagination.page}
              {pagination.totalPages ? ` / ${pagination.totalPages}` : ""}
            </span>
            <button
              type="button"
              className="universal-btn-aux"
              onClick={() => setPage((prev) => prev + 1)}
              disabled={isLoading || !pagination.hasNextPage}
            >
              Sonraki
            </button>
          </div>
        </>
      ) : null}

      {detailId !== null ? (
        <AppModal title="Puantaj Etki Adayı Detayı" onClose={closeDetail}>
          <div data-testid="puantaj-etki-aday-detail-modal">
            {isDetailLoading ? <LoadingState label="Detay yükleniyor..." /> : null}
            {!isDetailLoading && detailError ? (
              <ErrorState message={detailError} onRetry={() => void refreshAll()} />
            ) : null}
            {!isDetailLoading && !detailError && detail ? (
              <>
                {detail.state === "INCELEME_GEREKLI" ? (
                  <p className="puantaj-etki-inceleme-uyari" data-testid="puantaj-etki-detail-inceleme-uyari">
                    Bu aday otomatik uygulanamaz ve muhasebe incelemesi gerektirir.
                  </p>
                ) : null}
                {buildDetailGroups(detail).map((group) => (
                  <DetailGroup key={group.title} title={group.title} fields={group.fields} />
                ))}
                <div className="form-actions-row">
                  {detailApplyVisible ? (
                    <button
                      type="button"
                      className="universal-btn-save"
                      data-testid="puantaj-etki-aday-detail-apply"
                      disabled={isApplying || isDismissing || isManualApplying}
                      onClick={() => openApplyModal(detail)}
                    >
                      Uygula
                    </button>
                  ) : null}
                  {detailManualApplyVisible ? (
                    <button
                      type="button"
                      className="universal-btn-save"
                      data-testid="puantaj-etki-aday-detail-manual-apply"
                      disabled={isApplying || isDismissing || isManualApplying}
                      onClick={() => openManualApplyModal(detail)}
                    >
                      İncele ve Uygula
                    </button>
                  ) : null}
                  {canDismiss && canDismissBildirimPuantajEtkiAday(detail.state) ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      data-testid="puantaj-etki-aday-detail-dismiss"
                      disabled={isApplying || isDismissing || isManualApplying}
                      onClick={() => openDismissModal(detail)}
                    >
                      Yok Say
                    </button>
                  ) : null}
                  <button
                    type="button"
                    className="universal-btn-aux"
                    onClick={closeDetail}
                    disabled={isApplying || isDismissing || isManualApplying}
                  >
                    Kapat
                  </button>
                </div>
              </>
            ) : null}
          </div>
        </AppModal>
      ) : null}

      {dismissTarget ? (
        <AppModal title="Puantaj Etki Adayını Yok Say" onClose={closeDismissModal}>
          <div data-testid="puantaj-etki-aday-dismiss-modal">
            <dl className="puantaj-etki-detail-grid">
              <div className="puantaj-etki-detail-row">
                <dt>Personel</dt>
                <dd>{dismissTarget.personel_id}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Tarih</dt>
                <dd>{dismissTarget.tarih}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Bildirim türü</dt>
                <dd>{dismissTarget.bildirim_turu}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Önerilen puantaj etkisi</dt>
                <dd>{formatProjectedEtkiLabel(dismissTarget)}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Mevcut durum</dt>
                <dd>{formatBildirimPuantajEtkiAdayStateLabel(dismissTarget.state)}</dd>
              </div>
              {dismissTarget.conflict_code ? (
                <div className="puantaj-etki-detail-row">
                  <dt>Çakışma</dt>
                  <dd>{formatConflictDisplay(dismissTarget.conflict_code)}</dd>
                </div>
              ) : null}
            </dl>

            <p className="puantaj-etki-terminal-uyari">
              Bu işlem adayı terminal olarak Yok Sayıldı durumuna geçirir.
            </p>

            <FormField
              as="textarea"
              label="Yok Sayma Gerekçesi"
              name="puantaj-etki-aday-dismiss-gerekce"
              value={dismissGerekce}
              onChange={setDismissGerekce}
              rows={4}
              required
            />
            {dismissFieldError || gerekceValidationError ? (
              <p className="puantaj-form-error">{dismissFieldError ?? gerekceValidationError}</p>
            ) : null}
            <p className="puantaj-etki-gerekce-counter" data-testid="puantaj-etki-gerekce-counter">
              {gerekceCharCount} / {GEREKCE_MAX_LENGTH}
            </p>
            {dismissError ? <p className="puantaj-form-error">{dismissError}</p> : null}

            <div className="form-actions-row">
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="puantaj-etki-aday-dismiss-submit"
                disabled={dismissSubmitDisabled}
                onClick={() => void dismissAday()}
              >
                {isDismissing ? "Yok sayılıyor..." : "Yok Say"}
              </button>
              <button
                type="button"
                className="universal-btn-aux"
                onClick={closeDismissModal}
                disabled={isDismissing || isApplying}
              >
                Vazgeç
              </button>
            </div>
          </div>
        </AppModal>
      ) : null}

      {applyTarget ? (
        <AppModal title="Puantaj Etki Adayını Uygula" onClose={closeApplyModal}>
          <div data-testid="puantaj-etki-aday-apply-modal">
            <dl className="puantaj-etki-detail-grid">
              <div className="puantaj-etki-detail-row">
                <dt>Personel</dt>
                <dd>{resolvePersonelDisplayName(applyTarget)}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Tarih</dt>
                <dd>{applyTarget.tarih}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Bildirim türü</dt>
                <dd>{applyTarget.bildirim_turu}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Puantaj etki türü</dt>
                <dd>{applyTarget.etki_turu}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Etki miktarı</dt>
                <dd>
                  {applyTarget.etki_miktari != null
                    ? `${applyTarget.etki_miktari}${applyTarget.etki_birimi ? ` ${applyTarget.etki_birimi}` : ""}`
                    : "—"}
                </dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Şube</dt>
                <dd>{applySubeLabel}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Birim amiri</dt>
                <dd>{applyBirimAmiriLabel}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Mevcut durum</dt>
                <dd>{formatBildirimPuantajEtkiAdayStateLabel(applyTarget.state)}</dd>
              </div>
            </dl>

            <p className="puantaj-etki-terminal-uyari">{APPLY_CONFIRM_WARNING}</p>
            {applyError ? <p className="puantaj-form-error">{applyError}</p> : null}

            <div className="form-actions-row">
              <button
                type="button"
                className="universal-btn-save"
                data-testid="puantaj-etki-aday-apply-submit"
                disabled={applySubmitDisabled}
                onClick={() => void applyAday()}
              >
                {isApplying ? "Uygulanıyor..." : "Uygula"}
              </button>
              <button
                type="button"
                className="universal-btn-aux"
                onClick={closeApplyModal}
                disabled={isApplying || isDismissing || isManualApplying}
              >
                Vazgeç
              </button>
            </div>
          </div>
        </AppModal>
      ) : null}

      {manualTarget ? (
        <AppModal title="Manuel İnceleme Kararı Uygula" onClose={closeManualApplyModal}>
          <div data-testid="puantaj-etki-aday-manual-apply-modal">
            <dl className="puantaj-etki-detail-grid">
              <div className="puantaj-etki-detail-row">
                <dt>Personel</dt>
                <dd>{resolvePersonelDisplayName(manualTarget)}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Tarih</dt>
                <dd>{manualTarget.tarih}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Şube</dt>
                <dd>{manualSubeLabel}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Birim amiri</dt>
                <dd>{manualBirimAmiriLabel}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Bildirim türü</dt>
                <dd>{manualTarget.bildirim_turu}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Kaynak açıklaması</dt>
                <dd>{manualTarget.bildirim_aciklama ?? "—"}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Çakışma kodu</dt>
                <dd>{manualTarget.conflict_code ?? "—"}</dd>
              </div>
              <div className="puantaj-etki-detail-row">
                <dt>Çakışma açıklaması</dt>
                <dd>{formatConflictDisplay(manualTarget.conflict_code, manualTarget.conflict_detail)}</dd>
              </div>
            </dl>

            <p className="puantaj-etki-terminal-uyari">{MANUAL_IZIN_RAPOR_INFO}</p>
            <p className="puantaj-etki-terminal-uyari">{MANUAL_APPLY_OVERWRITE_WARNING}</p>

            <FormField
              label="Manuel karar"
              name="puantaj-etki-aday-manual-karar"
              as="select"
              value={manualKararTuru}
              onChange={handleManualKararChange}
              selectOptions={[
                { value: "", label: "Seçiniz" },
                ...MANUAL_KARAR_PRESET_OPTIONS.map((option) => ({
                  value: option.value,
                  label: option.label
                }))
              ]}
              required
            />

            {manualKararTuru && manualKararRequiresMiktar(manualKararTuru) ? (
              <FormField
                label="Dakika"
                name="puantaj-etki-aday-manual-miktar"
                type="number"
                min={1}
                value={manualMiktar}
                onChange={setManualMiktar}
                required
              />
            ) : null}

            <FormField
              as="textarea"
              label="Karar Gerekçesi"
              name="puantaj-etki-aday-manual-gerekce"
              value={manualGerekce}
              onChange={setManualGerekce}
              rows={4}
              required
            />
            {manualFieldError || manualGerekceValidationError ? (
              <p className="puantaj-form-error">{manualFieldError ?? manualGerekceValidationError}</p>
            ) : null}
            {manualMiktarValidationError ? (
              <p className="puantaj-form-error">{manualMiktarValidationError}</p>
            ) : null}
            <p className="puantaj-etki-gerekce-counter" data-testid="puantaj-etki-manual-gerekce-counter">
              {manualGerekceCharCount} / {GEREKCE_MAX_LENGTH}
            </p>

            {manualPreview ? (
              <div className="puantaj-etki-detail-group" data-testid="puantaj-etki-manual-preview">
                <h4>Sonuç önizlemesi</h4>
                <dl className="puantaj-etki-detail-grid">
                  <div className="puantaj-etki-detail-row">
                    <dt>Hareket</dt>
                    <dd>{manualPreview.hareket}</dd>
                  </div>
                  <div className="puantaj-etki-detail-row">
                    <dt>Dayanak</dt>
                    <dd>{manualPreview.dayanak}</dd>
                  </div>
                  <div className="puantaj-etki-detail-row">
                    <dt>Hesap Etkisi</dt>
                    <dd>{manualPreview.hesapEtkisi}</dd>
                  </div>
                  <div className="puantaj-etki-detail-row">
                    <dt>Gün Tipi</dt>
                    <dd>{manualPreview.gunTipi}</dd>
                  </div>
                </dl>
              </div>
            ) : null}

            {manualKararTuru === "DEVAMSIZLIK_GUN" ? (
              <p className="puantaj-etki-terminal-uyari" data-testid="puantaj-etki-manual-devamsizlik-uyari">
                {MANUAL_DEVAMSIZLIK_WARNING}
              </p>
            ) : null}

            {manualError ? <p className="puantaj-form-error">{manualError}</p> : null}

            <div className="form-actions-row">
              <button
                type="button"
                className="universal-btn-save"
                data-testid="puantaj-etki-aday-manual-apply-submit"
                disabled={manualSubmitDisabled}
                onClick={() => void manualApplyAday()}
              >
                {isManualApplying ? "Uygulanıyor..." : "Uygula"}
              </button>
              <button
                type="button"
                className="universal-btn-aux"
                onClick={closeManualApplyModal}
                disabled={isManualApplying || isApplying || isDismissing}
              >
                Vazgeç
              </button>
            </div>
          </div>
        </AppModal>
      ) : null}
    </div>
  );
}
