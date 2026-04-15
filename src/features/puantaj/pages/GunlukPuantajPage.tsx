import { useCallback, useEffect, useState, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import { muhurleAylikPuantaj } from "../../../api/puantaj.api";
import { FormField } from "../../../components/form/FormField";
import { AppModal } from "../../../components/modal/AppModal";
import { EmptyState } from "../../../components/states/EmptyState";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { usePuantaj } from "../../../hooks/usePuantaj";
import { formatComplianceLevelLabel, formatPuantajStateLabel } from "../../../lib/display/enum-display";
import type {
  GunlukPuantaj,
  PuantajDayanak,
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajHesapEtkisi
} from "../../../types/puantaj";

const GUN_TIPI_OPTIONS: Array<{ value: PuantajGunTipi; label: string }> = [
  { value: "Normal_Is_Gunu", label: "Normal İş Günü" },
  { value: "Hafta_Tatili_Pazar", label: "Hafta Tatili Pazar" },
  { value: "UBGT_Resmi_Tatil", label: "UBGT Resmi Tatil" }
];

const HAREKET_DURUMU_OPTIONS: Array<{ value: PuantajHareketDurumu; label: string }> = [
  { value: "Geldi", label: "Geldi" },
  { value: "Gelmedi", label: "Gelmedi" },
  { value: "Gec_Geldi", label: "Geç Geldi" },
  { value: "Erken_Cikti", label: "Erken Çıktı" }
];

const DAYANAK_OPTIONS: Array<{ value: PuantajDayanak; label: string }> = [
  { value: "Yok_Izinsiz", label: "Yok / İzinsiz" },
  { value: "Ucretli_Izinli", label: "Ücretli İzinli" },
  { value: "Raporlu_Hastalik", label: "Raporlu Hastalık" },
  { value: "Raporlu_Is_Kazasi", label: "Raporlu İş Kazası" },
  { value: "Yillik_Izin", label: "Yıllık İzin" },
  { value: "Telafi_Calismasi", label: "Telafi Çalışması" }
];

const GUN_TIPI_LABELS: Record<PuantajGunTipi, string> = Object.fromEntries(
  GUN_TIPI_OPTIONS.map((option) => [option.value, option.label])
) as Record<PuantajGunTipi, string>;

const HAREKET_DURUMU_LABELS: Record<PuantajHareketDurumu, string> = Object.fromEntries(
  HAREKET_DURUMU_OPTIONS.map((option) => [option.value, option.label])
) as Record<PuantajHareketDurumu, string>;

const DAYANAK_LABELS: Record<PuantajDayanak, string> = Object.fromEntries(
  DAYANAK_OPTIONS.map((option) => [option.value, option.label])
) as Record<PuantajDayanak, string>;

const HESAP_ETKISI_LABELS: Record<PuantajHesapEtkisi, string> = {
  Kesinti_Yap: "Kesinti Yap",
  Tam_Yevmiye_Ver: "Tam Yevmiye Ver",
  Mesai_Yaz: "Mesai Yaz"
};

function humanizeFallback(value: string) {
  return value
    .split("_")
    .filter(Boolean)
    .map((token) => token.charAt(0).toUpperCase() + token.slice(1).toLowerCase())
    .join(" ");
}

function formatMappedValue<T extends string>(value: T | "" | null | undefined, labels: Record<string, string>) {
  if (!value) {
    return "-";
  }

  return labels[value] ?? humanizeFallback(value);
}

function formatSaatValue(value: string | null | undefined) {
  return value && value.trim() ? value : "-";
}

function formatDakikaValue(value: number | null | undefined) {
  return value !== undefined && value !== null ? String(value) : "-";
}

function formatHakKazanimi(value: boolean | null | undefined) {
  if (value === true) {
    return "Hak Kazandı";
  }

  if (value === false) {
    return "Hak Kazanmadı";
  }

  return "-";
}

function formatDayanakValue(value: GunlukPuantaj["dayanak"]) {
  if (!value) {
    return "Yok";
  }

  return formatMappedValue(value, DAYANAK_LABELS);
}

function ReadonlyField({ label, value }: { label: string; value: string }) {
  return (
    <div className="form-section">
      <span className="form-label">{label}</span>
      <div className="form-input puantaj-readonly-value">{value}</div>
    </div>
  );
}

function currentMonthValue() {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

export function GunlukPuantajPage() {
  const { hasPermission } = useRoleAccess();
  const canUpdatePuantaj = hasPermission("puantaj.update");
  const canMuhurle = hasPermission("puantaj.muhurle");
  const location = useLocation();
  const navigate = useNavigate();

  const {
    formState,
    patchFormState,
    activeQuery,
    puantaj,
    isLoading,
    isSubmitting,
    errorMessage,
    submitErrorMessage,
    submitQuery,
    clearQuery,
    refetchActive,
    submitPuantaj,
    entryRequiresSaatBilgisi
  } = usePuantaj();

  const isMuhurlendi = puantaj?.state === "MUHURLENDI";
  const canEdit = canUpdatePuantaj && !isMuhurlendi;

  const [isMuhurModalOpen, setIsMuhurModalOpen] = useState(false);
  const [muhurDonem, setMuhurDonem] = useState(currentMonthValue());
  const [isMuhurlemeSending, setIsMuhurlemeSending] = useState(false);
  const [muhurSonuc, setMuhurSonuc] = useState<string | null>(null);
  const [muhurHata, setMuhurHata] = useState<string | null>(null);

  const handleMuhurleConfirm = useCallback(async () => {
    const match = /^(\d{4})-(\d{2})$/.exec(muhurDonem);
    if (!match) {
      setMuhurHata("Geçerli bir dönem seçiniz (YYYY-AA).");
      return;
    }

    setIsMuhurlemeSending(true);
    setMuhurHata(null);
    setMuhurSonuc(null);

    try {
      const result = await muhurleAylikPuantaj({
        yil: Number.parseInt(match[1], 10),
        ay: Number.parseInt(match[2], 10)
      });
      setMuhurSonuc(`${result.donem} dönemi için ${result.muhurlenen_kayit_sayisi} kayıt mühürlendi.`);
      if (activeQuery) {
        void refetchActive();
      }
    } catch {
      setMuhurHata("Mühürleme işlemi başarısız oldu. Lütfen tekrar deneyin.");
    } finally {
      setIsMuhurlemeSending(false);
    }
  }, [muhurDonem, activeQuery, refetchActive]);

  useEffect(() => {
    const currentState = (location.state ?? null) as Record<string, unknown> | null;
    const prefillPersonelId =
      typeof currentState?.prefillPersonelId === "number"
        ? String(currentState.prefillPersonelId)
        : typeof currentState?.prefillPersonelId === "string"
          ? currentState.prefillPersonelId
          : "";

    if (!prefillPersonelId) {
      return;
    }

    patchFormState({ queryPersonelId: prefillPersonelId });

    const nextState = { ...currentState };
    delete nextState.prefillPersonelId;

    navigate(location.pathname, {
      replace: true,
      state: Object.keys(nextState).length > 0 ? nextState : null
    });
  }, [location.pathname, location.state, navigate, patchFormState]);

  function handleQuerySubmit(event: FormEvent<HTMLFormElement>) {
    void submitQuery(event);
  }

  function handlePuantajSubmit(event: FormEvent<HTMLFormElement>) {
    void submitPuantaj(event, canEdit);
  }

  return (
    <section className="puantaj-page">
      <div className="puantaj-header-row">
        <h2>Günlük Kayıt ve Puantaj</h2>
      </div>

      <form className="form-filter-panel" onSubmit={handleQuerySubmit}>
        <div className="form-field-grid">
          <FormField
            label="Personel ID"
            name="puantaj-query-personel"
            type="number"
            min={1}
            value={formState.queryPersonelId}
            onChange={(value) => patchFormState({ queryPersonelId: value })}
            required
          />
          <FormField
            label="Tarih"
            name="puantaj-query-tarih"
            type="date"
            value={formState.queryTarih}
            onChange={(value) => patchFormState({ queryTarih: value })}
            required
          />
        </div>

        <div className="form-actions-row">
          <button type="submit" className="universal-btn-aux" disabled={isLoading}>
            Kaydı Getir
          </button>
          <button type="button" className="universal-btn-aux" onClick={clearQuery} disabled={isLoading}>
            Temizle
          </button>
        </div>
      </form>

      {isLoading ? <LoadingState label="Puantaj verisi yükleniyor..." /> : null}

      {!isLoading && errorMessage ? (
        <ErrorState
          message={errorMessage}
          onRetry={activeQuery ? () => void refetchActive() : undefined}
        />
      ) : null}

      {!isLoading && !errorMessage && activeQuery && !puantaj ? (
        <EmptyState
          title="Kayıt bulunamadı"
          message="Seçilen gün için puantaj kaydı yok. Formu doldurarak günlük kaydı oluşturabilirsin."
        />
      ) : null}

      {!isLoading && !errorMessage && puantaj ? (
        <div className="puantaj-detail-card">
          <div className="form-field-grid">
            <ReadonlyField label="Personel ID" value={String(puantaj.personel_id)} />
            <ReadonlyField label="Tarih" value={puantaj.tarih} />
            <ReadonlyField label="Kayıt Durumu" value={formatPuantajStateLabel(puantaj.state)} />
            <ReadonlyField label="Gün Tipi" value={formatMappedValue(puantaj.gun_tipi, GUN_TIPI_LABELS)} />
            <ReadonlyField
              label="Hareket Durumu"
              value={formatMappedValue(puantaj.hareket_durumu, HAREKET_DURUMU_LABELS)}
            />
            <ReadonlyField label="Dayanak" value={formatDayanakValue(puantaj.dayanak)} />
            <ReadonlyField
              label="Hesap Etkisi"
              value={formatMappedValue(puantaj.hesap_etkisi, HESAP_ETKISI_LABELS)}
            />
            <ReadonlyField
              label="Hafta Tatili Hakkı"
              value={formatHakKazanimi(puantaj.hafta_tatili_hak_kazandi_mi)}
            />
            <ReadonlyField label="Giriş Saati" value={formatSaatValue(puantaj.giris_saati)} />
            <ReadonlyField label="Çıkış Saati" value={formatSaatValue(puantaj.cikis_saati)} />
            <ReadonlyField
              label="Gerçek Mola (dk)"
              value={formatDakikaValue(puantaj.gercek_mola_dakika)}
            />
            <ReadonlyField
              label="Hesaplanan Mola (dk)"
              value={formatDakikaValue(puantaj.hesaplanan_mola_dakika)}
            />
            <ReadonlyField
              label="Net Çalışma (dk)"
              value={formatDakikaValue(puantaj.net_calisma_suresi_dakika)}
            />
            <ReadonlyField
              label="Günlük Brüt Süre (dk)"
              value={formatDakikaValue(puantaj.gunluk_brut_sure_dakika)}
            />
          </div>

          {puantaj.compliance_uyarilari.length > 0 ? (
            <ul className="puantaj-alert-list">
              {puantaj.compliance_uyarilari.map((uyari, index) => (
                <li key={`${uyari.code}-${index}`}>
                  <strong>{formatComplianceLevelLabel(uyari.level ?? "UYARI")}:</strong> {uyari.message}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      ) : null}

      <div className="puantaj-edit-card">
        <h3>Günlük Kayıt Girişi</h3>

        <form className="puantaj-form-grid" onSubmit={handlePuantajSubmit}>
          <div className="form-field-grid">
            <FormField
              as="select"
              label="Gün Tipi"
              name="puantaj-gun-tipi"
              value={formState.entryGunTipi}
              onChange={(value) => patchFormState({ entryGunTipi: value as PuantajGunTipi })}
              selectOptions={GUN_TIPI_OPTIONS}
              required
            />
            <FormField
              as="select"
              label="Hareket Durumu"
              name="puantaj-hareket-durumu"
              value={formState.entryHareketDurumu}
              onChange={(value) => patchFormState({ entryHareketDurumu: value as PuantajHareketDurumu | "" })}
              selectOptions={HAREKET_DURUMU_OPTIONS}
              placeholderOption={{ value: "", label: "Seçiniz" }}
              required
            />
            <FormField
              as="select"
              label="Dayanak"
              name="puantaj-dayanak"
              value={formState.entryDayanak}
              onChange={(value) => patchFormState({ entryDayanak: value as PuantajDayanak | "" })}
              selectOptions={DAYANAK_OPTIONS}
              placeholderOption={{ value: "", label: "Yok / Belirtilmedi" }}
            />
          </div>

          <div className="form-field-grid">
            <FormField
              label="Giriş Saati"
              name="puantaj-giris"
              type="time"
              value={formState.entryGirisSaati}
              onChange={(value) => patchFormState({ entryGirisSaati: value })}
              required={entryRequiresSaatBilgisi}
              disabled={!entryRequiresSaatBilgisi}
            />
            <FormField
              label="Çıkış Saati"
              name="puantaj-cikis"
              type="time"
              value={formState.entryCikisSaati}
              onChange={(value) => patchFormState({ entryCikisSaati: value })}
              required={entryRequiresSaatBilgisi}
              disabled={!entryRequiresSaatBilgisi}
            />
            <FormField
              label="Gerçek Mola (dk)"
              name="puantaj-mola"
              type="number"
              min={0}
              value={formState.entryGercekMolaDakika}
              onChange={(value) => patchFormState({ entryGercekMolaDakika: value })}
              disabled={!entryRequiresSaatBilgisi}
            />
          </div>

          {!entryRequiresSaatBilgisi ? (
            <p className="puantaj-form-readonly">
              Bu hareket durumu için saat bilgisi zorunlu değil. Sistem puantajı gün tipi ve dayanakla okur.
            </p>
          ) : null}

          {submitErrorMessage ? <p className="puantaj-form-error">{submitErrorMessage}</p> : null}
          {isMuhurlendi ? (
            <p className="puantaj-form-readonly puantaj-muhur-uyari" data-testid="muhur-uyari">
              Bu kayıt mühürlenmiştir ve düzenlenemez.
            </p>
          ) : !canUpdatePuantaj ? (
            <p className="puantaj-form-readonly">Bu modülü sadece görüntüleme yetkin var.</p>
          ) : null}

          <div className="form-actions-row">
            <button
              type="submit"
              className="universal-btn-aux"
              disabled={!activeQuery || !canEdit || isSubmitting}
              data-testid="puantaj-kaydet"
            >
              {isSubmitting ? "Kaydediliyor..." : "Kaydet"}
            </button>
          </div>
        </form>
      </div>

      {canMuhurle ? (
        <div className="puantaj-muhur-section">
          <button
            type="button"
            className="universal-btn-aux puantaj-muhur-btn"
            data-testid="muhur-ay-kapat-btn"
            onClick={() => {
              setIsMuhurModalOpen(true);
              setMuhurSonuc(null);
              setMuhurHata(null);
            }}
          >
            Ayı Kapat / Mühürle
          </button>
        </div>
      ) : null}

      {isMuhurModalOpen ? (
        <AppModal
          onClose={() => setIsMuhurModalOpen(false)}
          title="Aylık Puantaj Mühürle"
        >
          <div className="muhur-modal-content" data-testid="muhur-modal">
          <p className="muhur-modal-uyari">
            <strong>Dikkat:</strong> Bu işlem geri alınamaz. Mühürlenmiş kayıtlar değiştirilemez ve silinemez.
          </p>
          <FormField
            label="Dönem (Yıl-Ay)"
            name="muhur-donem"
            type="month"
            value={muhurDonem}
            onChange={(value) => setMuhurDonem(value)}
            required
          />
          {muhurHata ? <p className="puantaj-form-error">{muhurHata}</p> : null}
          {muhurSonuc ? <p className="puantaj-form-success" data-testid="muhur-sonuc">{muhurSonuc}</p> : null}
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-aux"
              disabled={isMuhurlemeSending}
              data-testid="muhur-onayla-btn"
              onClick={() => void handleMuhurleConfirm()}
            >
              {isMuhurlemeSending ? "Mühürleniyor..." : "Onayla ve Mühürle"}
            </button>
            <button
              type="button"
              className="universal-btn-aux"
              onClick={() => setIsMuhurModalOpen(false)}
            >
              Vazgeç
            </button>
          </div>
        </div>
        </AppModal>
      ) : null}

      <div className="module-links">
        <Link to="/surecler">Süreç takibe dön</Link>
      </div>
    </section>
  );
}
