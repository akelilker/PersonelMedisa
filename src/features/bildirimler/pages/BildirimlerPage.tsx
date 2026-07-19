import { useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { SubeDetailListNotice } from "../../../components/states/SubeDetailListNotice";
import { getApiErrorMessage } from "../../../api/api-client";
import { fetchBirimAmiriSecenekleri, fetchGunlukOzet, completeGunlukTamamlama } from "../../../api/bildirimler.api";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAylikBildirimOnay } from "../../../hooks/useAylikBildirimOnay";
import { useBildirimler } from "../../../hooks/useBildirimler";
import { useGenelYoneticiBildirimOnayi } from "../../../hooks/useGenelYoneticiBildirimOnayi";
import { useHaftalikBildirimMutabakat } from "../../../hooks/useHaftalikBildirimMutabakat";
import { useAuth } from "../../../state/auth.store";
import {
  canCancelGunlukBildirim,
  canEditGunlukBildirim,
  canRequestCorrectionGunlukBildirim,
  canSubmitGunlukBildirim
} from "../../../lib/bildirim/gunluk-bildirim-actions";
import {
  getCurrentMonthValue,
  isAylikBildirimOnayApproveEnabled,
  resolveAylikBildirimOnayStatusMessage
} from "../../../lib/bildirim/aylik-bildirim-onay";
import {
  formatGenelYoneticiBildirimOnayiDate,
  formatGenelYoneticiBildirimOnayiState,
  isGenelYoneticiBildirimOnayiApproveEnabled,
  resolveGenelYoneticiBildirimOnayiBlockMessage
} from "../../../lib/bildirim/genel-yonetici-bildirim-onayi";
import {
  computeHaftaBitisFromMonday,
  isHaftalikMutabakatApproveEnabled,
  isMondayIsoDate,
  resolveHaftalikMutabakatStatusMessage
} from "../../../lib/bildirim/haftalik-mutabakat";
import {
  formatBildirimStateLabel
} from "../../../lib/display/enum-display";
import type { AylikBildirimOnayCounts } from "../../../types/aylik-bildirim-onay";
import type { Bildirim, BirimAmiriSecenegi, GunlukOzet } from "../../../types/bildirim";
import type { HaftalikBildirimMutabakatCounts } from "../../../types/haftalik-bildirim-mutabakat";
import type { Personel } from "../../../types/personel";
import type { IdOption } from "../../../types/referans";
import {
  formatGunlukKayitDayanak,
  formatGunlukKayitGunTipi,
  formatGunlukKayitHareketDurumu,
  formatGunlukKayitHesapEtkisi,
  resolveGunlukKayitPreset,
  type GunlukKayitOption,
  type GunlukKayitPreset
} from "../gunluk-kayit-presets";

const GUNLUK_KAYIT_CREATE_FORM_ID = "gunluk-kayit-create-form";
const GUNLUK_KAYIT_EDIT_FORM_ID = "gunluk-kayit-edit-form";
const GUNLUK_KAYIT_CORRECTION_FORM_ID = "gunluk-kayit-correction-form";
const KAYIT_SENARYOSU_LABEL = "Kayıt Senaryosu";

function digitsOnly(value: string | null | undefined) {
  return (value ?? "").replace(/\D+/g, "");
}

function buildTelHref(value: string | null | undefined) {
  const digits = digitsOnly(value);
  return digits ? `tel:${digits}` : null;
}

function formatPersonelOptionLabel(personel: Personel) {
  const title = `${personel.ad} ${personel.soyad}`;
  const meta = [personel.departman_adi, personel.gorev_adi, personel.telefon]
    .filter(Boolean)
    .join(" | ");
  return meta ? `${title} | ${meta}` : title;
}

function formatDepartmanLabel(
  departmanId: number | undefined,
  departmanLabel: string | undefined,
  departmanOptions: IdOption[]
) {
  if (departmanLabel) {
    return departmanLabel;
  }

  if (typeof departmanId !== "number") {
    return "-";
  }

  return departmanOptions.find((option) => option.id === departmanId)?.label ?? `#${departmanId}`;
}

type PersonelContextCardProps = {
  personel: Personel | null;
};

function PersonelContextCard({ personel }: PersonelContextCardProps) {
  if (!personel) {
    return null;
  }

  const personelCallHref = buildTelHref(personel.telefon);
  const emergencyCallHref = buildTelHref(personel.acil_durum_telefon);

  return (
    <div className="bildirim-personel-context">
      <strong>
        {personel.ad} {personel.soyad}
      </strong>
      <p>
        Bölüm: {personel.departman_adi ?? "-"}
        {personel.gorev_adi ? ` | Görev: ${personel.gorev_adi}` : ""}
      </p>
      <p>
        Telefon: {personel.telefon ?? "-"}
        {personel.kan_grubu ? ` | Kan Grubu: ${personel.kan_grubu}` : ""}
      </p>
      <p>
        Acil Durum: {personel.acil_durum_kisi ?? "-"}
        {personel.acil_durum_telefon ? ` | ${personel.acil_durum_telefon}` : ""}
      </p>
      {personelCallHref || emergencyCallHref ? (
        <div className="module-item-actions">
          {personelCallHref ? (
            <a className="universal-btn-aux" href={personelCallHref}>
              Personeli Ara
            </a>
          ) : null}
          {emergencyCallHref ? (
            <a className="universal-btn-aux" href={emergencyCallHref}>
              Acil Ara
            </a>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

type GunlukKayitModelCardProps = {
  preset: GunlukKayitPreset;
  title?: string;
};

function GunlukKayitModelCard({ preset, title = "Puantaj Yansıması" }: GunlukKayitModelCardProps) {
  return (
    <div className="bildirim-model-panel">
      <div className="bildirim-model-panel-head">
        <strong>{title}</strong>
        <span>{preset.label}</span>
      </div>
      <div className="bildirim-model-grid">
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Gün Tipi</span>
          <strong>{formatGunlukKayitGunTipi(preset.gunTipi)}</strong>
        </div>
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Hareket Durumu</span>
          <strong>{formatGunlukKayitHareketDurumu(preset.hareketDurumu)}</strong>
        </div>
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Dayanak</span>
          <strong>{formatGunlukKayitDayanak(preset.dayanak)}</strong>
        </div>
        <div className="bildirim-model-card">
          <span className="bildirim-model-label">Hesap Etkisi</span>
          <strong>{formatGunlukKayitHesapEtkisi(preset.hesapEtkisi)}</strong>
        </div>
      </div>
      <p className="bildirim-model-note">{preset.aciklama}</p>
    </div>
  );
}

function toSelectOptions(options: GunlukKayitOption[]) {
  return options.map((option) => ({ value: option.key, label: option.label }));
}

type KayitSenaryosuChoiceGroupProps = {
  name: string;
  value: string;
  options: GunlukKayitOption[];
  onSelect: (value: string) => void;
};

function KayitSenaryosuChoiceGroup({ name, value, options, onSelect }: KayitSenaryosuChoiceGroupProps) {
  return (
    <div className="form-section bildirim-kayit-senaryosu-field">
      <span className="form-label">{KAYIT_SENARYOSU_LABEL}</span>
      <div className="bildirim-kayit-senaryosu-group" role="group" aria-label={KAYIT_SENARYOSU_LABEL}>
        {options.map((option) => {
          const isActive = option.key === value;

          return (
            <button
              key={`${name}-${option.key}`}
              type="button"
              className={`bildirim-kayit-senaryosu-btn${isActive ? " is-active" : ""}`}
              aria-pressed={isActive}
              onClick={() => onSelect(option.key)}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

const HAFTALIK_MUTABAKAT_COUNT_LABELS: Array<{
  key: keyof HaftalikBildirimMutabakatCounts;
  label: string;
}> = [
  { key: "toplam", label: "Toplam" },
  { key: "taslak", label: "Taslak" },
  { key: "gonderildi", label: "Gönderildi" },
  { key: "duzeltme_istendi", label: "Düzeltme İstendi" },
  { key: "haftalik_mutabakata_alindi", label: "Mutabakata Alındı" },
  { key: "iptal", label: "İptal" },
  { key: "eksik_gun", label: "Eksik Gün" },
  { key: "tamamlanan_gun", label: "Tamamlanan Gün" }
];

const AYLIK_BILDIRIM_ONAY_COUNT_LABELS: Array<{
  key: keyof AylikBildirimOnayCounts;
  label: string;
}> = [
  { key: "toplam_bildirim", label: "Toplam Bildirim" },
  { key: "mutabakata_alinan", label: "Mutabakata Alınan" },
  { key: "mutabakatli_hafta", label: "Mutabakatlı Hafta" },
  { key: "eksik_hafta", label: "Eksik Hafta" },
  { key: "taslak", label: "Taslak" },
  { key: "duzeltme_istendi", label: "Düzeltme İstendi" },
  { key: "gonderildi", label: "Gönderildi" }
];

type HaftalikMutabakatPanelProps = {
  canApprove: boolean;
  enabled: boolean;
  subeId: number | null;
  birimAmiriUserId: number | null;
  contextMessage: string | null;
  onWeekApplied: (baslangic: string, bitis: string) => void;
  onApproved: () => void | Promise<void>;
  onBlokBildirimClick?: (bildirimId: number) => void;
};

function HaftalikMutabakatPanel({
  canApprove,
  enabled,
  subeId,
  birimAmiriUserId,
  contextMessage,
  onWeekApplied,
  onApproved,
  onBlokBildirimClick
}: HaftalikMutabakatPanelProps) {
  const userSelectedWeekRef = useRef(false);
  const {
    haftaBaslangic,
    setHaftaBaslangic,
    haftaBitis,
    ozet,
    isLoading,
    error,
    weekWarning,
    approveWeek,
    isApproving
  } = useHaftalikBildirimMutabakat({ enabled, subeId, birimAmiriUserId, onApproved });

  useEffect(() => {
    if (!userSelectedWeekRef.current || !ozet || !isMondayIsoDate(haftaBaslangic)) {
      return;
    }

    onWeekApplied(ozet.hafta_baslangic, ozet.hafta_bitis);
  }, [haftaBaslangic, onWeekApplied, ozet]);

  const handleWeekChange = (value: string) => {
    userSelectedWeekRef.current = true;
    setHaftaBaslangic(value);
  };

  const statusMessage = resolveHaftalikMutabakatStatusMessage(ozet);
  const approveEnabled = isHaftalikMutabakatApproveEnabled(canApprove, ozet);
  const displayedWeekEnd =
    haftaBitis ?? (isMondayIsoDate(haftaBaslangic) ? computeHaftaBitisFromMonday(haftaBaslangic) : null);

  return (
    <div className="state-card bildirim-mutabakat-panel" data-testid="haftalik-mutabakat-panel">
      <div className="bildirim-mutabakat-panel-head">
        <h3>Haftalık Mutabakat</h3>
        {displayedWeekEnd ? (
          <p className="bildirim-mutabakat-meta">Hafta bitişi: {displayedWeekEnd}</p>
        ) : null}
      </div>

      <FormField
        label="Hafta Başlangıcı"
        name="haftalik-mutabakat-hafta-baslangic"
        type="date"
        value={haftaBaslangic}
        onChange={handleWeekChange}
      />

      {!enabled && contextMessage ? <p className="bildirim-mutabakat-status">{contextMessage}</p> : null}
      {weekWarning ? <p className="bildirim-form-error">{weekWarning}</p> : null}
      {isLoading ? <LoadingState label="Haftalık mutabakat özeti yükleniyor..." /> : null}
      {!isLoading && error ? <p className="bildirim-form-error">{error}</p> : null}

      {!isLoading && ozet ? (
        <>
          <div className="bildirim-model-grid" data-testid="haftalik-mutabakat-counts">
            {HAFTALIK_MUTABAKAT_COUNT_LABELS.map(({ key, label }) => (
              <div key={key} className="bildirim-model-card" data-testid={`haftalik-mutabakat-count-${key}`}>
                <span className="bildirim-model-label">{label}</span>
                <strong>{ozet.counts[key] ?? 0}</strong>
              </div>
            ))}
          </div>

          {statusMessage ? (
            <p className="bildirim-mutabakat-status" data-testid="haftalik-mutabakat-status">
              {statusMessage}
            </p>
          ) : null}

          {typeof ozet.mevcut_mutabakat_id === "number" ? (
            <span data-testid="haftalik-mutabakat-id" hidden>
              {ozet.mevcut_mutabakat_id}
            </span>
          ) : null}

          {Array.isArray(ozet.bloklar) && ozet.bloklar.length > 0 ? (
            <ul className="bildirim-aylik-hafta-list" data-testid="haftalik-mutabakat-bloklar">
              {ozet.bloklar.map((blok, index) => {
                const key = `${blok.tur}-${blok.tarih ?? "x"}-${blok.bildirim_id ?? index}`;
                const clickable = typeof blok.bildirim_id === "number" && onBlokBildirimClick;
                return (
                  <li key={key} className="bildirim-aylik-hafta-item">
                    {clickable ? (
                      <button
                        type="button"
                        className="universal-btn-aux"
                        data-testid={`haftalik-mutabakat-blok-${blok.bildirim_id}`}
                        onClick={() => onBlokBildirimClick(blok.bildirim_id!)}
                      >
                        {blok.mesaj}
                        {blok.tarih ? ` (${blok.tarih})` : ""}
                      </button>
                    ) : (
                      <p>
                        {blok.mesaj}
                        {blok.tarih ? ` (${blok.tarih})` : ""}
                      </p>
                    )}
                  </li>
                );
              })}
            </ul>
          ) : null}

          {canApprove ? (
            <div className="bildirim-mutabakat-actions">
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="haftalik-mutabakat-approve"
                onClick={() => void approveWeek()}
                disabled={!approveEnabled || isApproving || Boolean(weekWarning)}
              >
                {isApproving ? "Onaylanıyor..." : "Haftayı Onayla"}
              </button>
            </div>
          ) : null}
        </>
      ) : null}
    </div>
  );
}

type AylikBildirimOnayPanelProps = {
  canApprove: boolean;
  enabled: boolean;
  subeId: number | null;
  birimAmiriUserId: number | null;
  contextMessage: string | null;
  ay: string;
  onAyChange: (value: string) => void;
};

function AylikBildirimOnayPanel({
  canApprove,
  enabled,
  subeId,
  birimAmiriUserId,
  contextMessage,
  ay,
  onAyChange
}: AylikBildirimOnayPanelProps) {
  const { setAy, ozet, isLoading, error, ayWarning, approveMonth, isApproving } =
    useAylikBildirimOnay({
      enabled,
      subeId,
      birimAmiriUserId,
      ay,
      onAyChange
    });

  const statusMessage = resolveAylikBildirimOnayStatusMessage(ozet);
  const approveEnabled = isAylikBildirimOnayApproveEnabled(canApprove, ozet);

  return (
    <div className="state-card bildirim-mutabakat-panel" data-testid="aylik-bildirim-onay-panel">
      <div className="bildirim-mutabakat-panel-head">
        <h3>Aylık Bildirim Onayı</h3>
        {ozet ? (
          <p className="bildirim-mutabakat-meta">
            Ay aralığı: {ozet.ay_baslangic} – {ozet.ay_bitis}
          </p>
        ) : null}
      </div>

      <FormField
        label="Ay"
        name="aylik-bildirim-onay-ay"
        type="month"
        value={ay}
        onChange={setAy}
      />

      {!enabled && contextMessage ? <p className="bildirim-mutabakat-status">{contextMessage}</p> : null}
      {ayWarning ? <p className="bildirim-form-error">{ayWarning}</p> : null}
      {isLoading ? <LoadingState label="Aylık bildirim onayı özeti yükleniyor..." /> : null}
      {!isLoading && error ? <p className="bildirim-form-error">{error}</p> : null}

      {!isLoading && ozet ? (
        <>
          <div className="bildirim-model-grid" data-testid="aylik-bildirim-onay-counts">
            {AYLIK_BILDIRIM_ONAY_COUNT_LABELS.map(({ key, label }) => (
              <div key={key} className="bildirim-model-card" data-testid={`aylik-bildirim-onay-count-${key}`}>
                <span className="bildirim-model-label">{label}</span>
                <strong>{ozet.counts[key]}</strong>
              </div>
            ))}
          </div>

          {ozet.haftalar.length > 0 ? (
            <ul className="bildirim-aylik-hafta-list" data-testid="aylik-bildirim-onay-haftalar">
              {ozet.haftalar.map((hafta) => (
                <li
                  key={`${hafta.hafta_baslangic}-${hafta.hafta_bitis}`}
                  className="bildirim-aylik-hafta-item"
                  data-testid={`aylik-bildirim-onay-hafta-${hafta.hafta_baslangic}`}
                >
                  <strong>
                    {hafta.hafta_baslangic} – {hafta.hafta_bitis}
                  </strong>
                  <p>
                    Bildirim: {hafta.bildirim_sayisi} | Mutabakata alınan: {hafta.mutabakata_alinan_sayisi}
                    {hafta.eksik_mi ? " | Eksik hafta" : ""}
                    {typeof hafta.mutabakat_id === "number" ? (
                      <span data-testid={`aylik-hafta-mutabakat-id-${hafta.hafta_baslangic}`} hidden>
                        {hafta.mutabakat_id}
                      </span>
                    ) : null}
                  </p>
                  {hafta.blok_nedeni ? <p>{hafta.blok_nedeni}</p> : null}
                </li>
              ))}
            </ul>
          ) : null}

          {statusMessage ? (
            <p className="bildirim-mutabakat-status" data-testid="aylik-bildirim-onay-status">
              {statusMessage}
            </p>
          ) : null}

          {typeof ozet.mevcut_onay_id === "number" ? (
            <span data-testid="aylik-bildirim-onay-id" hidden>
              {ozet.mevcut_onay_id}
            </span>
          ) : null}

          {canApprove ? (
            <div className="bildirim-mutabakat-actions">
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="aylik-bildirim-onay-approve"
                onClick={() => void approveMonth()}
                disabled={!approveEnabled || isApproving || Boolean(ayWarning)}
              >
                {isApproving ? "Onaya gönderiliyor..." : "Ayı Onaya Gönder"}
              </button>
            </div>
          ) : null}
        </>
      ) : null}
    </div>
  );
}

type GenelYoneticiBildirimOnayiPanelProps = {
  canView: boolean;
  canApprove: boolean;
  ay: string;
  subeId: number | null;
  birimAmiriUserId: number | null;
  subeLabel: string;
  birimAmiriLabel: string;
  contextMessage: string | null;
};

function GenelYoneticiBildirimOnayiPanel({
  canView,
  canApprove,
  ay,
  subeId,
  birimAmiriUserId,
  subeLabel,
  birimAmiriLabel,
  contextMessage
}: GenelYoneticiBildirimOnayiPanelProps) {
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);
  const {
    ozet,
    isLoading,
    error,
    successMessage,
    approve,
    isApproving,
    contextReady
  } = useGenelYoneticiBildirimOnayi({ canView, canApprove, ay, subeId, birimAmiriUserId });
  const blockMessage = resolveGenelYoneticiBildirimOnayiBlockMessage(ozet?.blok_nedeni ?? null);
  const approveEnabled = isGenelYoneticiBildirimOnayiApproveEnabled(
    canApprove,
    contextReady,
    ozet
  );
  const gyOnay = ozet?.genel_yonetici_bildirim_onayi ?? null;

  const handleApprove = async () => {
    await approve();
    setIsConfirmOpen(false);
  };

  return (
    <div
      className="state-card bildirim-mutabakat-panel"
      data-testid="genel-yonetici-bildirim-onay-panel"
      aria-busy={isLoading || isApproving}
    >
      <div className="bildirim-mutabakat-panel-head">
        <h3>Genel Yönetici Bildirim Onayı</h3>
        {ozet ? (
          <p className="bildirim-mutabakat-meta">
            Ay aralığı: {ozet.ay_baslangic} – {ozet.ay_bitis}
          </p>
        ) : null}
      </div>

      {!contextReady && contextMessage ? (
        <p className="bildirim-mutabakat-status" data-testid="genel-yonetici-bildirim-onay-context">
          {contextMessage}
        </p>
      ) : null}
      {isLoading ? <LoadingState label="Genel Yönetici bildirim onayı özeti yükleniyor..." /> : null}
      {!isLoading && error ? <p className="bildirim-form-error">{error}</p> : null}
      {successMessage ? <p className="yonetim-success">{successMessage}</p> : null}

      {!isLoading && ozet ? (
        <>
          <div className="bildirim-model-grid" data-testid="genel-yonetici-bildirim-onay-ozet">
            {[
              ["Ay", ozet.ay],
              ["Şube", subeLabel],
              ["Birim Amiri", birimAmiriLabel],
              ["Toplam Bildirim", String(ozet.counts.toplam_bildirim)],
              ["Mutabakata Alınan", String(ozet.counts.mutabakata_alinan)],
              ["Eksik Hafta", String(ozet.counts.eksik_hafta)],
              ["Aylık Bildirim Onay Durumu", ozet.aylik_bildirim_onayi?.state ?? "—"],
              ["Genel Yönetici Üst Onay Durumu", formatGenelYoneticiBildirimOnayiState(gyOnay?.state)],
              ["Onay Tarihi", formatGenelYoneticiBildirimOnayiDate(gyOnay?.onaylandi_at)]
            ].map(([label, value]) => (
              <div key={label} className="bildirim-model-card">
                <span className="bildirim-model-label">{label}</span>
                <strong>{value}</strong>
              </div>
            ))}
            {ozet.aylik_bildirim_onayi ? (
              <span data-testid="aylik-bildirim-onay-ref-id" hidden>
                {ozet.aylik_bildirim_onayi.id}
              </span>
            ) : null}
            {gyOnay ? (
              <span data-testid="genel-yonetici-bildirim-onay-id" hidden>
                {gyOnay.id}
              </span>
            ) : null}
          </div>

          {blockMessage ? (
            <p className="bildirim-mutabakat-status" data-testid="genel-yonetici-bildirim-onay-block">
              {blockMessage}
            </p>
          ) : null}

          {canApprove ? (
            <div className="bildirim-mutabakat-actions">
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="genel-yonetici-bildirim-onay-approve"
                onClick={() => setIsConfirmOpen(true)}
                disabled={!approveEnabled || isApproving}
              >
                {isApproving ? "Onaylanıyor..." : "Genel Yönetici Onayı Ver"}
              </button>
            </div>
          ) : null}
        </>
      ) : null}

      {isConfirmOpen ? (
        <AppModal
          title="Genel Yönetici Onayı"
          onClose={() => {
            if (!isApproving) setIsConfirmOpen(false);
          }}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={() => setIsConfirmOpen(false)}
                disabled={isApproving}
              >
                Vazgeç
              </button>
              <button
                type="button"
                className="universal-btn-save"
                onClick={() => void handleApprove()}
                disabled={isApproving}
              >
                {isApproving ? "Onaylanıyor..." : "Onayı Ver"}
              </button>
            </div>
          }
        >
          <p>
            Seçilen şube, birim amiri ve ay için Genel Yönetici bildirim onayı verilecek. Bu işlem
            mevcut sürümde geri alınamaz.
          </p>
        </AppModal>
      ) : null}
    </div>
  );
}

export function BildirimlerPage() {
  const {
    listQuery,
    updateDraft,
    bildirimler,
    hasNextPage,
    totalPages,
    isLoading,
    errorMessage,
    refetch,
    departmanOptions,
    gunlukKayitOptions,
    personelOptions,
    referenceError,
    isCreateModalOpen,
    openCreateModal,
    closeCreateModal,
    createForm,
    createPreview,
    setCreateForm,
    updateCreateDate,
    handleCreateDateInvalid,
    createErrorMessage,
    isCreateSubmitting,
    createBildirimHandler,
    editingBildirim,
    openEditModal,
    closeEditModal,
    editForm,
    editPreview,
    setEditForm,
    editErrorMessage,
    isEditSubmitting,
    updateBildirimHandler,
    cancelingBildirimId,
    cancelBildirimHandler,
    submittingBildirimId,
    submitBildirimHandler,
    correctingBildirim,
    openCorrectionModal,
    closeCorrectionModal,
    correctionReason,
    setCorrectionReason,
    correctionErrorMessage,
    isCorrectionSubmitting,
    requestCorrectionHandler,
    currentUserId,
    submitFilters,
    clearFilters,
    applyWeekRange,
    setPage
  } = useBildirimler();

  const { session } = useAuth();
  const { hasPermission, uiProfile } = useRoleAccess();
  const canCreateBildirim = hasPermission("gunluk_bildirim.create");
  const canCompleteDay = hasPermission("gunluk_bildirim.complete_day");
  const canOpenBildirimDetail = hasPermission("bildirimler.detail.view");
  const canViewHaftalikMutabakat = hasPermission("haftalik_mutabakat.view");
  const canApproveHaftalikMutabakat = hasPermission("haftalik_mutabakat.approve");
  const canViewAylikBildirimOnay = hasPermission("aylik_bildirim_onayi.view");
  const canApproveAylikBildirimOnay = hasPermission("aylik_bildirim_onayi.approve");
  const canViewGenelYoneticiBildirimOnay = hasPermission("genel_yonetici_bildirim_onayi.view");
  const canApproveGenelYoneticiBildirimOnay = hasPermission("genel_yonetici_bildirim_onayi.approve");
  const isBirimAmiri = uiProfile === "birim_amiri";
  const availableSubeler = useMemo(() => {
    const subeList = session?.sube_list ?? [];
    const allowedIds = session?.user.sube_ids ?? [];
    return allowedIds.length === 0
      ? subeList
      : subeList.filter((sube) => allowedIds.includes(sube.id));
  }, [session?.sube_list, session?.user.sube_ids]);
  const [selectedSubeId, setSelectedSubeId] = useState<number | null>(() =>
    session?.active_sube_id ?? null
  );
  const [selectedBirimAmiriUserId, setSelectedBirimAmiriUserId] = useState<number | null>(() =>
    isBirimAmiri ? session?.user.id ?? null : null
  );
  const [selectedAy, setSelectedAy] = useState(getCurrentMonthValue);
  const [birimAmiriSecenekleri, setBirimAmiriSecenekleri] = useState<BirimAmiriSecenegi[]>([]);
  const [isBirimAmiriSecenekleriLoading, setIsBirimAmiriSecenekleriLoading] = useState(false);
  const [birimAmiriSecenekleriError, setBirimAmiriSecenekleriError] = useState<string | null>(null);
  const [gunlukOzet, setGunlukOzet] = useState<GunlukOzet | null>(null);
  const [gunlukOzetError, setGunlukOzetError] = useState<string | null>(null);
  const [isGunlukOzetLoading, setIsGunlukOzetLoading] = useState(false);
  const [isCompletingDay, setIsCompletingDay] = useState(false);
  const [completeDayError, setCompleteDayError] = useState<string | null>(null);
  const [completeDaySuccess, setCompleteDaySuccess] = useState<string | null>(null);
  const [rosterSearch, setRosterSearch] = useState("");
  const location = useLocation();
  const navigate = useNavigate();

  const todayIso = useMemo(() => {
    const now = new Date();
    const y = now.getFullYear();
    const m = String(now.getMonth() + 1).padStart(2, "0");
    const d = String(now.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }, []);

  useEffect(() => {
    setSelectedSubeId(session?.active_sube_id ?? null);
    setSelectedBirimAmiriUserId(isBirimAmiri ? session?.user.id ?? null : null);
  }, [isBirimAmiri, session?.active_sube_id, session?.user.id]);

  useEffect(() => {
    if (isBirimAmiri) {
      setBirimAmiriSecenekleri([]);
      setBirimAmiriSecenekleriError(null);
      setIsBirimAmiriSecenekleriLoading(false);
      return;
    }

    setSelectedBirimAmiriUserId(null);
    setBirimAmiriSecenekleri([]);
    setBirimAmiriSecenekleriError(null);
    if (selectedSubeId === null) {
      setIsBirimAmiriSecenekleriLoading(false);
      return;
    }

    let current = true;
    setIsBirimAmiriSecenekleriLoading(true);
    void fetchBirimAmiriSecenekleri(selectedSubeId)
      .then((items) => {
        if (!current) return;
        setBirimAmiriSecenekleri(items);
        setSelectedBirimAmiriUserId(items.length === 1 ? items[0]!.user_id : null);
      })
      .catch((caught) => {
        if (!current) return;
        setBirimAmiriSecenekleriError(
          getApiErrorMessage(caught, "Birim amiri seçenekleri yüklenemedi.")
        );
      })
      .finally(() => {
        if (current) setIsBirimAmiriSecenekleriLoading(false);
      });

    return () => {
      current = false;
    };
  }, [isBirimAmiri, selectedSubeId]);

  const panelContextReady =
    selectedSubeId !== null && selectedBirimAmiriUserId !== null;
  const panelContextMessage = selectedSubeId === null
    ? "Verileri görüntülemek için şube seçin."
    : isBirimAmiriSecenekleriLoading
      ? "Birim amiri seçenekleri yükleniyor..."
      : birimAmiriSecenekleri.length === 0 && !isBirimAmiri
        ? "Seçilen şubede aktif birim amiri bulunamadı."
        : selectedBirimAmiriUserId === null
          ? "Verileri görüntülemek için birim amiri seçin."
          : null;
  const genelYoneticiContextMessage = selectedSubeId === null
    ? "Genel Yönetici onayı için şube seçin."
    : isBirimAmiriSecenekleriLoading
      ? "Birim amiri seçenekleri yükleniyor..."
      : birimAmiriSecenekleri.length === 0
        ? "Seçilen şubede aktif birim amiri bulunamadı."
        : selectedBirimAmiriUserId === null
          ? "Genel Yönetici onayı için birim amiri seçin."
          : null;
  const selectedSubeLabel =
    availableSubeler.find((sube) => sube.id === selectedSubeId)?.ad ?? "—";
  const selectedBirimAmiriLabel =
    birimAmiriSecenekleri.find((option) => option.user_id === selectedBirimAmiriUserId)?.ad_soyad ?? "—";

  const { draft } = listQuery;
  const page = listQuery.page;
  const ozetTarih = draft.tarih && /^\d{4}-\d{2}-\d{2}$/.test(draft.tarih) ? draft.tarih : todayIso;

  const refreshGunlukOzet = async () => {
    if (selectedSubeId === null) {
      setGunlukOzet(null);
      return;
    }
    if (!isBirimAmiri && selectedBirimAmiriUserId === null) {
      setGunlukOzet(null);
      return;
    }
    setIsGunlukOzetLoading(true);
    setGunlukOzetError(null);
    try {
      const data = await fetchGunlukOzet({
        tarih: ozetTarih,
        sube_id: selectedSubeId,
        birim_amiri_user_id: isBirimAmiri ? undefined : selectedBirimAmiriUserId ?? undefined
      });
      setGunlukOzet(data);
    } catch (caught) {
      setGunlukOzet(null);
      setGunlukOzetError(getApiErrorMessage(caught, "Günlük özet yüklenemedi."));
    } finally {
      setIsGunlukOzetLoading(false);
    }
  };

  useEffect(() => {
    void refreshGunlukOzet();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- refetch on context/tarih
  }, [isBirimAmiri, ozetTarih, selectedBirimAmiriUserId, selectedSubeId]);

  const filteredRoster = useMemo(() => {
    const rows = gunlukOzet?.personeller ?? [];
    const q = rosterSearch.trim().toLocaleLowerCase("tr-TR");
    if (!q) return rows;
    return rows.filter((row) => {
      const haystack = [row.ad_soyad, row.sicil_no, row.gorev_adi, row.departman_adi]
        .filter(Boolean)
        .join(" ")
        .toLocaleLowerCase("tr-TR");
      return haystack.includes(q);
    });
  }, [gunlukOzet?.personeller, rosterSearch]);

  const canCompleteToday =
    canCompleteDay &&
    gunlukOzet !== null &&
    !gunlukOzet.ozet.tamamlandi_mi &&
    gunlukOzet.ozet.taslak === 0 &&
    gunlukOzet.ozet.duzeltme_istendi === 0;

  async function handleCompleteDay() {
    if (!canCompleteToday || isCompletingDay) return;
    setIsCompletingDay(true);
    setCompleteDayError(null);
    setCompleteDaySuccess(null);
    try {
      await completeGunlukTamamlama({ tarih: ozetTarih });
      setCompleteDaySuccess("Bugünkü bildirim tamamlandı.");
      await refreshGunlukOzet();
      await refetch();
    } catch (caught) {
      setCompleteDayError(getApiErrorMessage(caught, "Gün tamamlanamadı."));
    } finally {
      setIsCompletingDay(false);
    }
  }

  const personelMap = useMemo(
    () => new Map(personelOptions.map((personel) => [personel.id, personel])),
    [personelOptions]
  );

  const personelSelectOptions = useMemo(
    () =>
      personelOptions.map((personel) => ({
        value: String(personel.id),
        label: formatPersonelOptionLabel(personel)
      })),
    [personelOptions]
  );

  const selectedCreatePersonel = useMemo(() => {
    const personelId = Number.parseInt(createForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [createForm.personelId, personelMap]);

  const selectedEditPersonel = useMemo(() => {
    const personelId = Number.parseInt(editForm.personelId, 10);
    return Number.isFinite(personelId) ? personelMap.get(personelId) ?? null : null;
  }, [editForm.personelId, personelMap]);

  useEffect(() => {
    const currentState = (location.state ?? null) as Record<string, unknown> | null;
    const prefillPersonelId =
      typeof currentState?.prefillPersonelId === "number"
        ? String(currentState.prefillPersonelId)
        : typeof currentState?.prefillPersonelId === "string"
          ? currentState.prefillPersonelId
          : "";

    if (!currentState?.openCreateModal && !prefillPersonelId) {
      return;
    }

    if (prefillPersonelId) {
      updateDraft({ personelId: prefillPersonelId });
    }

    if (canCreateBildirim && (currentState?.openCreateModal || prefillPersonelId)) {
      openCreateModal();
      if (prefillPersonelId) {
        const selected = personelOptions.find((option) => String(option.id) === prefillPersonelId);
        setCreateForm((prev) => ({
          ...prev,
          personelId: prefillPersonelId,
          departmanId:
            typeof selected?.departman_id === "number" ? String(selected.departman_id) : prev.departmanId
        }));
      }
    }

    const nextState = { ...currentState };
    delete nextState.openCreateModal;
    delete nextState.prefillPersonelId;

    navigate(location.pathname, {
      replace: true,
      state: Object.keys(nextState).length > 0 ? nextState : null
    });
  }, [
    canCreateBildirim,
    location.pathname,
    location.state,
    navigate,
    openCreateModal,
    personelOptions,
    setCreateForm,
    updateDraft
  ]);

  function handleCreateSubmit(event: FormEvent<HTMLFormElement>) {
    void createBildirimHandler(event, canCreateBildirim);
  }

  function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    if (!editingBildirim) {
      return;
    }
    void updateBildirimHandler(
      event,
      canEditGunlukBildirim(editingBildirim, hasPermission, currentUserId)
    );
  }

  function handleCreatePersonelChange(value: string) {
    const selected = personelOptions.find((option) => String(option.id) === value);
    setCreateForm((prev) => ({
      ...prev,
      personelId: value,
      departmanId:
        typeof selected?.departman_id === "number"
          ? String(selected.departman_id)
          : value
            ? prev.departmanId
            : ""
    }));
  }

  const createTitle = isBirimAmiri ? "Bildirim Gir" : "Yeni Bildirim";
  const createButtonLabel = isBirimAmiri ? "Bildirim Gir" : "Yeni Bildirim";
  const pageTitle = isBirimAmiri || canCreateBildirim ? "Bugünkü Personel Durumu" : "Günlük Bildirimler";

  return (
    <section className="bildirimler-page">
      <div className="bildirimler-header-row">
        <h2>{pageTitle}</h2>
        {canCreateBildirim ? (
          <button type="button" className="universal-btn-aux" onClick={openCreateModal}>
            {createButtonLabel}
          </button>
        ) : null}
      </div>

      <div className="state-card" data-testid="bildirim-gunluk-hero">
        <h3>{isBirimAmiri ? "Operasyonel gün durumu" : "Bildirim akışı"}</h3>
        <p>
          {isBirimAmiri
            ? "Personelinizin bugünkü durumunu girin, taslakları gönderin ve günü tamamlayın. Haftalık mutabakat için günlük tamamlama gerekir."
            : "Şube ve birim amiri bağlamında günlük bildirimleri, haftalık mutabakatı ve onay zincirini izleyin."}
        </p>
      </div>

      {(isBirimAmiri || canCreateBildirim || canViewHaftalikMutabakat) && selectedSubeId !== null ? (
        <div className="state-card" data-testid="gunluk-bildirim-ozet">
          <div className="bildirim-mutabakat-panel-head">
            <h3>Gün Özeti</h3>
            {canCompleteToday ? (
              <button
                type="button"
                className="universal-btn-aux"
                data-testid="gunluk-bildirim-tamamla"
                onClick={() => void handleCompleteDay()}
                disabled={isCompletingDay}
              >
                {isCompletingDay ? "Tamamlanıyor..." : "Bugünkü bildirimi tamamla"}
              </button>
            ) : null}
          </div>
          {isGunlukOzetLoading ? <LoadingState label="Gün özeti yükleniyor..." /> : null}
          {gunlukOzetError ? <p className="bildirim-form-error">{gunlukOzetError}</p> : null}
          {completeDayError ? <p className="bildirim-form-error">{completeDayError}</p> : null}
          {completeDaySuccess ? <p className="yonetim-success">{completeDaySuccess}</p> : null}
          {gunlukOzet ? (
            <>
              <div className="bildirim-model-grid" data-testid="gunluk-bildirim-ozet-cards">
                {[
                  ["Bugünün Tarihi", gunlukOzet.tarih],
                  ["Aktif Şube", gunlukOzet.sube_adi || selectedSubeLabel],
                  ["Sorumlu Amir", gunlukOzet.birim_amiri_adi || selectedBirimAmiriLabel],
                  ["Toplam Personel", String(gunlukOzet.ozet.toplam_personel)],
                  ["Bildirim Girilen", String(gunlukOzet.ozet.bildirim_girilen)],
                  ["Taslak", String(gunlukOzet.ozet.taslak)],
                  ["Düzeltme", String(gunlukOzet.ozet.duzeltme_istendi)],
                  ["Tamamlandı mı", gunlukOzet.ozet.tamamlandi_mi ? "Evet" : "Hayır"]
                ].map(([label, value]) => (
                  <div key={label} className="bildirim-model-card">
                    <span className="bildirim-model-label">{label}</span>
                    <strong>{value}</strong>
                  </div>
                ))}
              </div>

              <FormField
                label="Personel ara"
                name="gunluk-ozet-roster-search"
                value={rosterSearch}
                onChange={setRosterSearch}
                placeholder="Ad, sicil, görev, departman..."
              />

              {filteredRoster.length === 0 ? (
                <EmptyState
                  title="Kapsamda personel bulunamadı"
                  message="Seçili şube ve amir kapsamında aktif personel yok."
                />
              ) : (
                <div className="puantaj-etki-aday-table-wrap" data-testid="gunluk-ozet-personel-table">
                  <table className="puantaj-etki-aday-table">
                    <thead>
                      <tr>
                        <th>Ad Soyad</th>
                        <th>Sicil</th>
                        <th>Görev</th>
                        <th>Durum</th>
                        <th>Bildirim</th>
                        <th>Son İşlem</th>
                        <th>Aksiyon</th>
                      </tr>
                    </thead>
                    <tbody>
                      {filteredRoster.map((row) => (
                        <tr key={row.personel_id}>
                          <td>{row.ad_soyad}</td>
                          <td>{row.sicil_no ?? "—"}</td>
                          <td>{row.gorev_adi ?? "—"}</td>
                          <td>{row.durum_label}</td>
                          <td>{row.bildirim_turu ?? "—"}</td>
                          <td>{row.son_islem_at ?? "—"}</td>
                          <td>
                            {canCreateBildirim && row.bildirim_id == null ? (
                              <button
                                type="button"
                                className="universal-btn-aux"
                                onClick={() => {
                                  setCreateForm((prev) => ({
                                    ...prev,
                                    personelId: String(row.personel_id),
                                    tarih: ozetTarih
                                  }));
                                  openCreateModal();
                                }}
                              >
                                Bildirim Gir
                              </button>
                            ) : null}
                            {row.bildirim_id != null ? (
                              <button
                                type="button"
                                className="universal-btn-aux"
                                onClick={() => {
                                  const existing = bildirimler.find((item) => item.id === row.bildirim_id);
                                  if (existing && canEditGunlukBildirim(existing, hasPermission, currentUserId)) {
                                    openEditModal(existing, true);
                                  } else if (canOpenBildirimDetail) {
                                    navigate(`/bildirimler/${row.bildirim_id}`);
                                  }
                                }}
                              >
                                Düzenle
                              </button>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </>
          ) : null}
        </div>
      ) : null}

      <SubeDetailListNotice />

      {!isBirimAmiri &&
      (canViewHaftalikMutabakat || canViewAylikBildirimOnay || canViewGenelYoneticiBildirimOnay) ? (
        <div className="state-card" data-testid="bildirim-panel-context">
          <h3>Panel Bağlamı</h3>
          <div className="form-field-grid">
            <FormField
              as="select"
              label="Şube"
              name="bildirim-panel-sube"
              value={selectedSubeId === null ? "" : String(selectedSubeId)}
              onChange={(value) => {
                setSelectedSubeId(value ? Number.parseInt(value, 10) : null);
                setSelectedBirimAmiriUserId(null);
              }}
              placeholderOption={{ value: "", label: "Şube seçin" }}
              selectOptions={availableSubeler.map((sube) => ({
                value: String(sube.id),
                label: sube.ad
              }))}
            />
            <FormField
              as="select"
              label="Birim Amiri"
              name="bildirim-panel-birim-amiri"
              value={selectedBirimAmiriUserId === null ? "" : String(selectedBirimAmiriUserId)}
              onChange={(value) =>
                setSelectedBirimAmiriUserId(value ? Number.parseInt(value, 10) : null)
              }
              disabled={selectedSubeId === null || isBirimAmiriSecenekleriLoading}
              placeholderOption={{
                value: "",
                label: isBirimAmiriSecenekleriLoading ? "Yükleniyor..." : "Birim amiri seçin"
              }}
              selectOptions={birimAmiriSecenekleri.map((option) => ({
                value: String(option.user_id),
                label: option.ad_soyad
              }))}
            />
          </div>
          {birimAmiriSecenekleriError ? (
            <p className="bildirim-form-error">{birimAmiriSecenekleriError}</p>
          ) : null}
          {!birimAmiriSecenekleriError && panelContextMessage ? (
            <p className="bildirim-mutabakat-status">{panelContextMessage}</p>
          ) : null}
        </div>
      ) : null}

      {canViewHaftalikMutabakat ? (
        <HaftalikMutabakatPanel
          canApprove={canApproveHaftalikMutabakat}
          enabled={panelContextReady}
          subeId={selectedSubeId}
          birimAmiriUserId={selectedBirimAmiriUserId}
          contextMessage={panelContextMessage}
          onWeekApplied={applyWeekRange}
          onApproved={refetch}
          onBlokBildirimClick={(bildirimId) => {
            if (canOpenBildirimDetail) {
              navigate(`/bildirimler/${bildirimId}`);
            }
          }}
        />
      ) : null}

      {canViewAylikBildirimOnay ? (
        <AylikBildirimOnayPanel
          canApprove={canApproveAylikBildirimOnay}
          enabled={panelContextReady}
          subeId={selectedSubeId}
          birimAmiriUserId={selectedBirimAmiriUserId}
          contextMessage={panelContextMessage}
          ay={selectedAy}
          onAyChange={setSelectedAy}
        />
      ) : null}

      {canViewGenelYoneticiBildirimOnay ? (
        <GenelYoneticiBildirimOnayiPanel
          canView={canViewGenelYoneticiBildirimOnay}
          canApprove={canApproveGenelYoneticiBildirimOnay}
          ay={selectedAy}
          subeId={selectedSubeId}
          birimAmiriUserId={selectedBirimAmiriUserId}
          subeLabel={selectedSubeLabel}
          birimAmiriLabel={selectedBirimAmiriLabel}
          contextMessage={genelYoneticiContextMessage}
        />
      ) : null}

      <form className="form-filter-panel" onSubmit={submitFilters}>
        <div className="form-field-grid">
          {personelSelectOptions.length > 0 ? (
            <FormField
              as="select"
              label="Personel"
              name="bildirim-filter-personel"
              value={draft.personelId}
              onChange={(value) => updateDraft({ personelId: value })}
              placeholderOption={{ value: "", label: "Tümü" }}
              selectOptions={personelSelectOptions}
            />
          ) : (
            <EmptyState
              title="Kapsamda personel bulunamadı"
              message="Filtre için personel listesi yok."
            />
          )}
          {gunlukKayitOptions.length > 0 ? (
            <FormField
              as="select"
              label="Kayıt Senaryosu"
              name="bildirim-filter-turu"
              value={draft.bildirimTuru}
              onChange={(value) => updateDraft({ bildirimTuru: value })}
              placeholderOption={{ value: "", label: "Tümü" }}
              selectOptions={toSelectOptions(gunlukKayitOptions)}
            />
          ) : (
            <FormField
              label="Kayıt Senaryosu"
              name="bildirim-filter-turu-text"
              placeholder="Örn. GEC_GELDI, GELMEDI..."
              value={draft.bildirimTuru}
              onChange={(value) => updateDraft({ bildirimTuru: value })}
            />
          )}
          <FormField
            label="Tarih"
            name="bildirim-filter-tarih"
            type="date"
            value={draft.tarih}
            onChange={(value) => updateDraft({ tarih: value })}
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux">
            Filtrele
          </button>
          <button type="button" className="universal-btn-aux" onClick={clearFilters}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Günlük kayıt verileri yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState message={errorMessage} onRetry={() => void refetch()} />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length === 0 ? (
        <EmptyState
          title="Günlük kayıt bulunamadı"
          message="Seçilen tarih veya filtre için günlük kayıt bulunmuyor."
        />
      ) : null}

      {!isLoading && !errorMessage && bildirimler.length > 0 ? (
        <ul className="bildirimler-list">
          {bildirimler.map((bildirim: Bildirim) => {
            const personel =
              typeof bildirim.personel_id === "number" ? personelMap.get(bildirim.personel_id) ?? null : null;
            const personelCallHref = buildTelHref(personel?.telefon);
            const emergencyCallHref = buildTelHref(personel?.acil_durum_telefon);
            const preset = resolveGunlukKayitPreset(bildirim.bildirim_turu);
            const canEditRow = canEditGunlukBildirim(bildirim, hasPermission, currentUserId);
            const canCancelRow = canCancelGunlukBildirim(bildirim, hasPermission, currentUserId);
            const canSubmitRow = canSubmitGunlukBildirim(bildirim, hasPermission, currentUserId);
            const canRequestCorrectionRow = canRequestCorrectionGunlukBildirim(bildirim, hasPermission);
            const rowBusy =
              cancelingBildirimId === bildirim.id || submittingBildirimId === bildirim.id;

            return (
              <li key={bildirim.id} className="bildirimler-item">
                <div className="bildirimler-item-content">
                  <strong>{preset.label}</strong>
                  <p>Kayıt Durumu: {formatBildirimStateLabel(bildirim.state)}</p>
                  <p>Tarih: {bildirim.tarih ?? "-"}</p>
                  <p>
                    Personel:{" "}
                    {bildirim.personel_ad_soyad ??
                      (personel ? `${personel.ad} ${personel.soyad}` : "—")}
                  </p>
                  <p>
                    Bölüm:{" "}
                    {formatDepartmanLabel(
                      bildirim.departman_id,
                      bildirim.departman_adi ?? personel?.departman_adi,
                      departmanOptions
                    )}
                  </p>
                  {personel?.telefon ? <p>Telefon: {personel.telefon}</p> : null}
                  {bildirim.aciklama ? <p>Açıklama: {bildirim.aciklama}</p> : null}
                  <GunlukKayitModelCard preset={preset} title="Puantaj Katmanı" />
                </div>

                <div className="module-item-actions">
                  {personelCallHref ? (
                    <a className="universal-btn-aux" href={personelCallHref}>
                      Ara
                    </a>
                  ) : null}
                  {emergencyCallHref ? (
                    <a className="universal-btn-aux" href={emergencyCallHref}>
                      Acil Ara
                    </a>
                  ) : null}
                  {canOpenBildirimDetail ? (
                    <Link to={`/bildirimler/${bildirim.id}`} className="universal-btn-aux">
                      Detay
                    </Link>
                  ) : null}
                  {canEditRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openEditModal(bildirim, true)}
                      disabled={rowBusy}
                    >
                      Düzenle
                    </button>
                  ) : null}
                  {canSubmitRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void submitBildirimHandler(bildirim)}
                      disabled={rowBusy}
                    >
                      {submittingBildirimId === bildirim.id ? "Gönderiliyor..." : "Gönder"}
                    </button>
                  ) : null}
                  {canRequestCorrectionRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => openCorrectionModal(bildirim)}
                      disabled={rowBusy}
                    >
                      Düzeltme iste
                    </button>
                  ) : null}
                  {canCancelRow ? (
                    <button
                      type="button"
                      className="universal-btn-aux"
                      onClick={() => void cancelBildirimHandler(bildirim, true)}
                      disabled={rowBusy}
                    >
                      {cancelingBildirimId === bildirim.id ? "İptal Ediliyor..." : "İptal"}
                    </button>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      ) : null}

      <div className="module-pagination">
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => Math.max(1, prev - 1))}
          disabled={isLoading || page <= 1}
        >
          Önceki
        </button>
        <span className="module-page-info">
          Sayfa {page}
          {totalPages ? ` / ${totalPages}` : ""}
        </span>
        <button
          type="button"
          className="universal-btn-aux"
          onClick={() => setPage((prev) => prev + 1)}
          disabled={isLoading || !hasNextPage}
        >
          Sonraki
        </button>
      </div>

      <div className="module-links">
        <Link to="/">Ana ekrana dön</Link>
        <Link to="/surecler">Süreç takibe git</Link>
        <Link to="/puantaj">Puantaj ekranına git</Link>
      </div>

      {canCreateBildirim && isCreateModalOpen ? (
        <AppModal
          title={createTitle}
          onClose={closeCreateModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={GUNLUK_KAYIT_CREATE_FORM_ID}
                className="universal-btn-save"
                disabled={isCreateSubmitting}
              >
                {isCreateSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeCreateModal}
                disabled={isCreateSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={GUNLUK_KAYIT_CREATE_FORM_ID} className="bildirim-form-grid" onSubmit={handleCreateSubmit}>
            <FormField
              label="Tarih"
              name="bildirim-create-tarih"
              type="date"
              value={createForm.tarih}
              onChange={updateCreateDate}
              onInvalid={handleCreateDateInvalid}
              required
            />

            {personelSelectOptions.length > 0 ? (
              <FormField
                as="select"
                label="Personel"
                name="bildirim-create-personel"
                value={createForm.personelId}
                onChange={handleCreatePersonelChange}
                required
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={personelSelectOptions}
              />
            ) : (
              <EmptyState
                title="Kapsamda personel bulunamadı"
                message="Bildirim girmek için kapsamda aktif personel olmalıdır."
              />
            )}

            {personelSelectOptions.length > 0 ? (
              <FormField
                label="Bölüm"
                name="bildirim-create-departman-info"
                value={formatDepartmanLabel(
                  selectedCreatePersonel?.departman_id,
                  selectedCreatePersonel?.departman_adi,
                  departmanOptions
                )}
                onChange={() => undefined}
                disabled
              />
            ) : null}

            <PersonelContextCard personel={selectedCreatePersonel} />

            {gunlukKayitOptions.length > 0 ? (
              <KayitSenaryosuChoiceGroup
                name="bildirim-create-turu"
                value={createForm.bildirimTuru}
                options={gunlukKayitOptions}
                onSelect={(nextValue) => setCreateForm((prev) => ({ ...prev, bildirimTuru: nextValue }))}
              />
            ) : (
              <FormField
                label={KAYIT_SENARYOSU_LABEL}
                name="bildirim-create-turu-text"
                value={createForm.bildirimTuru}
                onChange={(value) => setCreateForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
              />
            )}

            <GunlukKayitModelCard preset={createPreview} />

            <FormField
              as="textarea"
              label="Not / Açıklama"
              name="bildirim-create-aciklama"
              value={createForm.aciklama}
              onChange={(value) => setCreateForm((prev) => ({ ...prev, aciklama: value }))}
              rows={3}
            />
            {createErrorMessage ? <p className="bildirim-form-error">{createErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {editingBildirim &&
      canEditGunlukBildirim(editingBildirim, hasPermission, currentUserId) ? (
        <AppModal
          title={`Günlük Kaydı Düzenle #${editingBildirim.id}`}
          onClose={closeEditModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={GUNLUK_KAYIT_EDIT_FORM_ID}
                className="universal-btn-save"
                disabled={isEditSubmitting}
              >
                {isEditSubmitting ? "Kaydediliyor..." : "Kaydet"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeEditModal}
                disabled={isEditSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form id={GUNLUK_KAYIT_EDIT_FORM_ID} className="bildirim-form-grid" onSubmit={handleEditSubmit}>
            <FormField
              label="Tarih"
              name="bildirim-edit-tarih"
              type="date"
              value={editForm.tarih}
              onChange={() => undefined}
              disabled
            />

            {personelSelectOptions.length > 0 ? (
              <FormField
                as="select"
                label="Personel"
                name="bildirim-edit-personel"
                value={editForm.personelId}
                onChange={() => undefined}
                disabled
                placeholderOption={{ value: "", label: "Seçiniz" }}
                selectOptions={personelSelectOptions}
              />
            ) : (
              <FormField
                label="Personel"
                name="bildirim-edit-personel-readonly"
                value={
                  editingBildirim?.personel_ad_soyad ??
                  (selectedEditPersonel
                    ? `${selectedEditPersonel.ad} ${selectedEditPersonel.soyad}`
                    : "—")
                }
                onChange={() => undefined}
                disabled
              />
            )}

            {personelSelectOptions.length > 0 ? (
              <FormField
                label="Bölüm"
                name="bildirim-edit-departman-info"
                value={formatDepartmanLabel(
                  selectedEditPersonel?.departman_id,
                  selectedEditPersonel?.departman_adi,
                  departmanOptions
                )}
                onChange={() => undefined}
                disabled
              />
            ) : (
              <FormField
                label="Bölüm"
                name="bildirim-edit-departman-readonly"
                value={
                  editingBildirim?.departman_adi ??
                  formatDepartmanLabel(
                    editingBildirim?.departman_id,
                    selectedEditPersonel?.departman_adi,
                    departmanOptions
                  )
                }
                onChange={() => undefined}
                disabled
              />
            )}

            <PersonelContextCard personel={selectedEditPersonel} />

            {gunlukKayitOptions.length > 0 ? (
              <KayitSenaryosuChoiceGroup
                name="bildirim-edit-turu"
                value={editForm.bildirimTuru}
                options={gunlukKayitOptions}
                onSelect={(nextValue) => setEditForm((prev) => ({ ...prev, bildirimTuru: nextValue }))}
              />
            ) : (
              <FormField
                label={KAYIT_SENARYOSU_LABEL}
                name="bildirim-edit-turu-text"
                value={editForm.bildirimTuru}
                onChange={(value) => setEditForm((prev) => ({ ...prev, bildirimTuru: value }))}
                required
              />
            )}

            <GunlukKayitModelCard preset={editPreview} />

            <FormField
              as="textarea"
              label="Not / Açıklama"
              name="bildirim-edit-aciklama"
              value={editForm.aciklama}
              onChange={(value) => setEditForm((prev) => ({ ...prev, aciklama: value }))}
              rows={3}
            />
            {editErrorMessage ? <p className="bildirim-form-error">{editErrorMessage}</p> : null}
            {referenceError ? <p className="bildirim-form-error">{referenceError}</p> : null}
          </form>
        </AppModal>
      ) : null}

      {correctingBildirim ? (
        <AppModal
          title={`Düzeltme İste #${correctingBildirim.id}`}
          onClose={closeCorrectionModal}
          footer={
            <div className="universal-btn-group modal-footer-actions">
              <button
                type="submit"
                form={GUNLUK_KAYIT_CORRECTION_FORM_ID}
                className="universal-btn-save"
                disabled={isCorrectionSubmitting}
              >
                {isCorrectionSubmitting ? "Gönderiliyor..." : "Gönder"}
              </button>
              <button
                type="button"
                className="universal-btn-cancel"
                onClick={closeCorrectionModal}
                disabled={isCorrectionSubmitting}
              >
                Vazgeç
              </button>
            </div>
          }
        >
          <form
            id={GUNLUK_KAYIT_CORRECTION_FORM_ID}
            className="bildirim-form-grid"
            onSubmit={(event) => void requestCorrectionHandler(event)}
          >
            <FormField
              as="textarea"
              label="Düzeltme Nedeni"
              name="bildirim-correction-reason"
              value={correctionReason}
              onChange={(value) => setCorrectionReason(value)}
              rows={4}
              required
            />
            {correctionErrorMessage ? (
              <p className="bildirim-form-error">{correctionErrorMessage}</p>
            ) : null}
          </form>
        </AppModal>
      ) : null}
    </section>
  );
}
