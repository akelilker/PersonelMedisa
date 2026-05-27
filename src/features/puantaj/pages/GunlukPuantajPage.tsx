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
import { formatComplianceLevelLabel } from "../../../lib/display/enum-display";
import type {
  PuantajGunTipi,
  PuantajHareketDurumu,
  PuantajDayanak
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

const DURUMU_BILDIRDI_OPTIONS: Array<{ value: "evet" | "hayir"; label: string }> = [
  { value: "evet", label: "Evet" },
  { value: "hayir", label: "Hayır" }
];

const GUN_TIPI_LABELS: Record<PuantajGunTipi, string> = Object.fromEntries(
  GUN_TIPI_OPTIONS.map((option) => [option.value, option.label])
) as Record<PuantajGunTipi, string>;

const HAREKET_DURUMU_LABELS: Record<PuantajHareketDurumu, string> = Object.fromEntries(
  HAREKET_DURUMU_OPTIONS.map((option) => [option.value, option.label])
) as Record<PuantajHareketDurumu, string>;

type ChoiceOption<T extends string> = {
  value: T;
  label: string;
};

function PuantajChoiceGroup<T extends string>({
  label,
  name,
  value,
  options,
  onSelect,
  disabled = false
}: {
  label: string;
  name: string;
  value: T;
  options: Array<ChoiceOption<T>>;
  onSelect: (nextValue: T) => void;
  disabled?: boolean;
}) {
  return (
    <div className="form-section puantaj-choice-field">
      <span className="form-label">{label}</span>
      <div className="puantaj-choice-group" role="group" aria-label={label}>
        {options.map((option) => {
          const isActive = option.value === value;

          return (
            <button
              key={`${name}-${option.value || "empty"}`}
              type="button"
              className={`puantaj-choice-btn${isActive ? " is-active" : ""}`}
              aria-pressed={isActive}
              disabled={disabled}
              onClick={() => onSelect(option.value)}
            >
              {option.label}
            </button>
          );
        })}
      </div>
    </div>
  );
}

function formatTatilEkOdemeCarpani(carpani: number): string {
  if (carpani === 1) return "1";
  if (carpani === 1.5) return "1,5";
  if (carpani === 0) return "0";
  if (Number.isFinite(carpani)) return String(carpani).replace(".", ",");
  return String(carpani);
}

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

function formatHaftaAraligiOzet(bas: string | null, bit: string | null) {
  if (bas && bit) {
    return `${bas} – ${bit}`;
  }
  return "-";
}

function formatTurkcePara(value: number) {
  if (!Number.isFinite(value)) {
    return "-";
  }
  return `${value.toFixed(2)} TL`;
}

function formatGecErkenKesintiTuru(value: "GEC_KALMA" | "ERKEN_CIKMA") {
  return value === "ERKEN_CIKMA" ? "Erken Çıkma" : "Geç Kalma";
}

function formatOndalikSaat(value: number) {
  if (!Number.isFinite(value)) {
    return "-";
  }
  return value.toFixed(2);
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
  const canAmirKontrol = hasPermission("puantaj.amir_kontrol");
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
    isKontrolSubmitting,
    errorMessage,
    submitErrorMessage,
    submitQuery,
    clearQuery,
    refetchActive,
    submitPuantaj,
    markAmirKontrolEtti,
    entryRequiresSaatBilgisi,
    haftalikOzet,
    haftalikOzetDurumu,
    haftalikOzetEksikVeriNotu,
    devamsizlikKesintiOzet,
    gecErkenKesintiOzeti,
    gecErkenKesintiNotu,
    kesintiOzetNotu,
    tatilEkOdemeOzeti,
    tatilEkOdemeNotu,
    parasalEtkiOzeti,
    anaDetay
  } = usePuantaj();

  const isMuhurlendi = puantaj?.state === "MUHURLENDI";
  const canEditForm = canUpdatePuantaj && !isMuhurlendi;
  const canMarkAmirKontrol =
    !isMuhurlendi && (canUpdatePuantaj || canAmirKontrol) && Boolean(puantaj);

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
    void submitPuantaj(event, canEditForm);
  }

  const beklenenSaatBilgisiGosterilmeliMi =
    formState.entryHareketDurumu === "Gec_Geldi" || formState.entryHareketDurumu === "Erken_Cikti";

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
        <div className="puantaj-detail-card" data-testid="puantaj-ana-detay">
          <div className="form-field-grid">
            {anaDetay?.fields.map((field) => (
              <ReadonlyField key={field.label} label={field.label} value={field.value} />
            ))}
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

      {activeQuery && haftalikOzetDurumu === "gecersiz_tarih" ? (
        <div className="puantaj-detail-card">
          <h3>Haftalık Fazla Çalışma Özeti</h3>
          <p className="puantaj-form-readonly">{haftalikOzetEksikVeriNotu}</p>
        </div>
      ) : null}

      {activeQuery && haftalikOzetDurumu === "hazir" && haftalikOzet ? (
        <div className="puantaj-detail-card">
          <h3>Haftalık Fazla Çalışma Özeti</h3>
          {haftalikOzetEksikVeriNotu ? (
            <p className="puantaj-form-readonly">{haftalikOzetEksikVeriNotu}</p>
          ) : null}
          <div className="form-field-grid">
            <ReadonlyField
              label="Hafta Aralığı"
              value={formatHaftaAraligiOzet(haftalikOzet.hafta_baslangic, haftalikOzet.hafta_bitis)}
            />
            <ReadonlyField
              label="Toplam Net Çalışma (dk)"
              value={String(haftalikOzet.toplam_net_dakika)}
            />
            <ReadonlyField
              label="Normal Çalışma (dk)"
              value={String(haftalikOzet.normal_calisma_dakika)}
            />
            <ReadonlyField
              label="Fazla Çalışma (dk)"
              value={String(haftalikOzet.fazla_calisma_dakika)}
            />
            <ReadonlyField
              label="Fazla Çalışma (saat)"
              value={formatOndalikSaat(haftalikOzet.fazla_calisma_saat)}
            />
            <ReadonlyField label="Saatlik Ücret" value={formatTurkcePara(haftalikOzet.saatlik_ucret)} />
            <ReadonlyField
              label="Fazla Çalışma Tutarı"
              value={formatTurkcePara(haftalikOzet.fazla_calisma_tutari)}
            />
          </div>
        </div>
      ) : null}

      {activeQuery && puantaj && (devamsizlikKesintiOzet || gecErkenKesintiOzeti || gecErkenKesintiNotu) ? (
        <div className="puantaj-detail-card">
          <h3>Kesinti Ön İzleme</h3>
          {kesintiOzetNotu ? <p className="puantaj-form-readonly">{kesintiOzetNotu}</p> : null}
          {devamsizlikKesintiOzet ? (
            <div className="form-field-grid">
              <ReadonlyField label="Günlük Ücret" value={formatTurkcePara(devamsizlikKesintiOzet.gunluk_ucret)} />
              <ReadonlyField
                label="Devamsızlık Gün Sayısı"
                value={String(devamsizlikKesintiOzet.devamsizlik_gun_sayisi)}
              />
              <ReadonlyField
                label="Hafta Tatili Kaybı Gün Sayısı"
                value={String(devamsizlikKesintiOzet.hafta_tatili_kaybi_gun_sayisi)}
              />
              <ReadonlyField
                label="Toplam Gün Eşdeğeri"
                value={formatOndalikSaat(devamsizlikKesintiOzet.toplam_kesinti_gun_esdegeri)}
              />
              <ReadonlyField
                label="Toplam Kesinti Tutarı"
                value={formatTurkcePara(devamsizlikKesintiOzet.toplam_kesinti_tutari)}
              />
            </div>
          ) : null}
          {gecErkenKesintiOzeti ? (
            <div className="form-field-grid">
              <ReadonlyField
                label="Kesinti Türü"
                value={formatGecErkenKesintiTuru(gecErkenKesintiOzeti.tip)}
              />
              <ReadonlyField
                label="Gerçek Eksik Süre (dk)"
                value={String(gecErkenKesintiOzeti.gercek_eksik_dakika)}
              />
              <ReadonlyField
                label="Kesintiye Esas Süre (dk)"
                value={String(gecErkenKesintiOzeti.kesintiye_esas_dakika)}
              />
              <ReadonlyField
                label="Geç / Erken Kesinti Tutarı"
                value={formatTurkcePara(gecErkenKesintiOzeti.kesinti_tutari)}
              />
            </div>
          ) : null}
          {gecErkenKesintiNotu ? <p className="puantaj-form-readonly">{gecErkenKesintiNotu}</p> : null}
        </div>
      ) : null}

      {activeQuery && puantaj && tatilEkOdemeOzeti ? (
        <div className="puantaj-detail-card">
          <h3>Tatil Ek Ödeme Ön İzleme</h3>
          {tatilEkOdemeNotu ? <p className="puantaj-form-readonly">{tatilEkOdemeNotu}</p> : null}
          <div className="form-field-grid">
            <ReadonlyField
              label="Gün Türü"
              value={formatMappedValue(puantaj.gun_tipi, GUN_TIPI_LABELS)}
            />
            <ReadonlyField label="Günlük Ücret" value={formatTurkcePara(tatilEkOdemeOzeti.gunluk_ucret)} />
            <ReadonlyField label="Çarpan" value={formatTatilEkOdemeCarpani(tatilEkOdemeOzeti.carpani)} />
            <ReadonlyField
              label="Ek Ödeme Tutarı"
              value={formatTurkcePara(tatilEkOdemeOzeti.ek_odeme_tutari)}
            />
            {tatilEkOdemeOzeti.hafta_tatili_pazar_karar ? (
              <>
                <ReadonlyField
                  label="Hafta Tatili Hakkı"
                  value={
                    tatilEkOdemeOzeti.hafta_tatili_pazar_karar.hafta_tatili_hak_kazandi_mi
                      ? "Hak Kazandı"
                      : "Hak Kazanmadı"
                  }
                />
                <ReadonlyField
                  label="Manuel İnceleme"
                  value={
                    tatilEkOdemeOzeti.hafta_tatili_pazar_karar.manuel_inceleme_gerekli_mi
                      ? "Gerekli"
                      : "Gerekli Değil"
                  }
                />
                <ReadonlyField
                  label="Açıklama"
                  value={tatilEkOdemeOzeti.hafta_tatili_pazar_karar.aciklama}
                />
              </>
            ) : null}
          </div>
        </div>
      ) : null}

      {activeQuery && puantaj && parasalEtkiOzeti ? (
        <div className="puantaj-detail-card">
          <h3>Parasal Etki Ön İzleme</h3>
          {parasalEtkiOzeti.notlar.map((satir, i) => (
            <p key={`parasal-not-${i}`} className="puantaj-form-readonly">
              {satir}
            </p>
          ))}
          <div className="form-field-grid">
            <ReadonlyField
              label="Haftalık Fazla Çalışma Tutarı"
              value={formatTurkcePara(parasalEtkiOzeti.haftalik_fazla_calisma_tutari)}
            />
            <ReadonlyField
              label="Tatil Ek Ödeme Tutarı"
              value={formatTurkcePara(parasalEtkiOzeti.tatil_ek_odeme_tutari)}
            />
            <ReadonlyField
              label="Toplam Kesinti Tutarı"
              value={formatTurkcePara(parasalEtkiOzeti.devamsizlik_kesinti_tutari)}
            />
            <ReadonlyField
              label="Manuel İnceleme (Pazar / tatil)"
              value={parasalEtkiOzeti.manuel_inceleme_gerekli_mi ? "Gerekli" : "Gerekli değil"}
            />
            <ReadonlyField
              label="Ön İzleme Net Etki"
              value={
                parasalEtkiOzeti.net_etki_hesaplanabilir_mi && parasalEtkiOzeti.net_etki_tutari !== null
                  ? formatTurkcePara(parasalEtkiOzeti.net_etki_tutari)
                  : "Kesinleştirilemedi"
              }
            />
          </div>
        </div>
      ) : null}

      <div className="puantaj-edit-card">
        <h3>Günlük Kayıt Girişi</h3>

        {activeQuery && puantaj && canMarkAmirKontrol && puantaj.kontrol_durumu !== "AMIR_KONTROL_ETTI" ? (
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-aux"
              disabled={isSubmitting || isKontrolSubmitting}
              onClick={() => void markAmirKontrolEtti()}
            >
              {isKontrolSubmitting ? "Kaydediliyor..." : "Amir Kontrol Etti"}
            </button>
          </div>
        ) : null}

        <form className="puantaj-form-grid" onSubmit={handlePuantajSubmit}>
          <div className="form-field-grid">
            <PuantajChoiceGroup
              label="Gün Tipi"
              name="puantaj-gun-tipi"
              value={formState.entryGunTipi}
              onSelect={(value) => patchFormState({ entryGunTipi: value })}
              options={GUN_TIPI_OPTIONS}
              disabled={isLoading}
            />
            <PuantajChoiceGroup
              label="Hareket Durumu"
              name="puantaj-hareket-durumu"
              value={formState.entryHareketDurumu}
              onSelect={(hareketDurumu) => {
                patchFormState({
                  entryHareketDurumu: hareketDurumu,
                  ...(hareketDurumu === "Gelmedi"
                    ? {}
                    : {
                        entryDurumuBildirdiMi: "",
                        entryDurumBildirimAciklamasi: ""
                      })
                });
              }}
              options={HAREKET_DURUMU_OPTIONS}
              disabled={isLoading}
            />
            <PuantajChoiceGroup
              label="Dayanak"
              name="puantaj-dayanak"
              value={formState.entryDayanak}
              onSelect={(value) => patchFormState({ entryDayanak: value })}
              options={[{ value: "", label: "Yok / Belirtilmedi" }, ...DAYANAK_OPTIONS]}
              disabled={isLoading}
            />
          </div>

          {formState.entryHareketDurumu === "Gelmedi" ? (
            <div className="form-field-grid">
              <PuantajChoiceGroup
                label="Durumu Bildirdi mi?"
                name="puantaj-durumu-bildirdi-mi"
                value={formState.entryDurumuBildirdiMi}
                onSelect={(value) =>
                  patchFormState({
                    entryDurumuBildirdiMi: value,
                    ...(value === "evet" ? {} : { entryDurumBildirimAciklamasi: "" })
                  })
                }
                options={DURUMU_BILDIRDI_OPTIONS}
                disabled={isLoading}
              />
              {formState.entryDurumuBildirdiMi === "evet" ? (
                <FormField
                  as="textarea"
                  label="Açıklama"
                  name="puantaj-durum-bildirim-aciklamasi"
                  value={formState.entryDurumBildirimAciklamasi}
                  onChange={(value) => patchFormState({ entryDurumBildirimAciklamasi: value })}
                  rows={3}
                  disabled={isLoading}
                />
              ) : null}
            </div>
          ) : null}

          <div className="form-field-grid">
            <FormField
              label="Giriş Saati"
              name="puantaj-giris"
              type="time"
              value={formState.entryGirisSaati}
              onChange={(value) => patchFormState({ entryGirisSaati: value })}
              required={entryRequiresSaatBilgisi}
              disabled={isLoading || !entryRequiresSaatBilgisi}
            />
            <FormField
              label="Çıkış Saati"
              name="puantaj-cikis"
              type="time"
              value={formState.entryCikisSaati}
              onChange={(value) => patchFormState({ entryCikisSaati: value })}
              required={entryRequiresSaatBilgisi}
              disabled={isLoading || !entryRequiresSaatBilgisi}
            />
            <FormField
              label="Gerçek Mola (dk)"
              name="puantaj-mola"
              type="number"
              min={0}
              value={formState.entryGercekMolaDakika}
              onChange={(value) => patchFormState({ entryGercekMolaDakika: value })}
              disabled={isLoading || !entryRequiresSaatBilgisi}
            />
          </div>

          {beklenenSaatBilgisiGosterilmeliMi ? (
            <div className="form-field-grid">
              <FormField
                label="Beklenen Giriş Saati"
                name="puantaj-beklenen-giris"
                type="time"
                value={formState.entryBeklenenGirisSaati}
                onChange={(value) => patchFormState({ entryBeklenenGirisSaati: value })}
                disabled={isLoading}
              />
              <FormField
                label="Beklenen Çıkış Saati"
                name="puantaj-beklenen-cikis"
                type="time"
                value={formState.entryBeklenenCikisSaati}
                onChange={(value) => patchFormState({ entryBeklenenCikisSaati: value })}
                disabled={isLoading}
              />
            </div>
          ) : null}

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
              disabled={!activeQuery || !canEditForm || isLoading || isSubmitting || isKontrolSubmitting}
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
