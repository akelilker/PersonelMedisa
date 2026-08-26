import { useCallback, useEffect, useState } from "react";
import { isApiRequestError } from "../../../api/api-client";
import {
  createPersonelHesapOnboarding,
  fetchPersonelAktivasyonMeta,
  fetchYonetimKullanicilari,
  reissuePersonelAktivasyon
} from "../../../api/yonetim.api";
import type {
  PersonelActivationInvitationMeta,
  PersonelHesapOnboardingResult
} from "../../../types/yonetim";

export type PersonelHesapOnboardingBoundUser = {
  id: number;
  activation_required?: boolean;
  username?: string;
  username_source?: string;
};

export type PersonelHesapOnboardingPanelProps = {
  personelId: number;
  sicilNo?: string | null;
  personelAktif: boolean;
  hasBoundUser?: boolean;
  boundUser?: PersonelHesapOnboardingBoundUser | null;
};

function formatUtcLabel(value: string | undefined): string {
  if (!value) {
    return "—";
  }
  const parsed = Date.parse(value.includes("T") ? value : `${value}Z`);
  if (Number.isNaN(parsed)) {
    return value;
  }
  return new Date(parsed).toLocaleString("tr-TR");
}

export function PersonelHesapOnboardingPanel({
  personelId,
  sicilNo,
  personelAktif,
  hasBoundUser: hasBoundUserProp,
  boundUser: boundUserProp
}: PersonelHesapOnboardingPanelProps) {
  const [boundUser, setBoundUser] = useState<PersonelHesapOnboardingBoundUser | null>(
    boundUserProp ?? null
  );
  const [hasBoundUser, setHasBoundUser] = useState<boolean | undefined>(hasBoundUserProp);
  const [isLoadingBound, setIsLoadingBound] = useState(hasBoundUserProp === undefined);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [isWorking, setIsWorking] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [issued, setIssued] = useState<PersonelHesapOnboardingResult | null>(null);
  const [copyStatus, setCopyStatus] = useState<string | null>(null);
  const [meta, setMeta] = useState<PersonelActivationInvitationMeta | null>(null);

  const resolveBoundUser = useCallback(async () => {
    if (boundUserProp != null || hasBoundUserProp === true || hasBoundUserProp === false) {
      setBoundUser(boundUserProp ?? null);
      setHasBoundUser(hasBoundUserProp ?? Boolean(boundUserProp));
      setIsLoadingBound(false);
      return;
    }

    setIsLoadingBound(true);
    setErrorMessage(null);
    try {
      const users = await fetchYonetimKullanicilari();
      const match = users.find((item) => item.personel_id === personelId) ?? null;
      if (match) {
        setBoundUser({
          id: match.id,
          activation_required: match.activation_required,
          username: match.username,
          username_source: match.username_source
        });
        setHasBoundUser(true);
      } else {
        setBoundUser(null);
        setHasBoundUser(false);
      }
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Bağlı hesap bilgisi okunamadı.");
      setHasBoundUser(undefined);
    } finally {
      setIsLoadingBound(false);
    }
  }, [boundUserProp, hasBoundUserProp, personelId]);

  useEffect(() => {
    void resolveBoundUser();
  }, [resolveBoundUser]);

  useEffect(() => {
    if (!boundUser || boundUser.activation_required !== true) {
      setMeta(null);
      return;
    }

    let cancelled = false;
    void (async () => {
      try {
        const response = await fetchPersonelAktivasyonMeta(boundUser.id);
        if (!cancelled) {
          setMeta(response.activation_invitation);
        }
      } catch {
        if (!cancelled) {
          setMeta(null);
        }
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [boundUser]);

  const sicil = String(sicilNo ?? "").trim();
  const eligible = personelAktif && sicil.length > 0 && hasBoundUser === false && !issued;

  async function handleCreate() {
    if (!eligible || isWorking) {
      return;
    }
    setIsWorking(true);
    setErrorMessage(null);
    setCopyStatus(null);
    try {
      const result = await createPersonelHesapOnboarding(personelId);
      setIssued(result);
      setBoundUser({
        id: result.user.id,
        activation_required: true,
        username: result.user.username,
        username_source: result.user.username_source
      });
      setHasBoundUser(true);
      setConfirmOpen(false);
      setMeta({
        created_at_utc: result.activation.created_at_utc,
        expires_at_utc: result.activation.expires_at_utc,
        is_expired: false,
        is_valid: true
      });
    } catch (error) {
      setErrorMessage(
        isApiRequestError(error)
          ? error.message
          : error instanceof Error
            ? error.message
            : "Personel hesabı oluşturulamadı."
      );
    } finally {
      setIsWorking(false);
    }
  }

  async function handleReissue() {
    if (!boundUser || isWorking) {
      return;
    }
    setIsWorking(true);
    setErrorMessage(null);
    setCopyStatus(null);
    try {
      const result = await reissuePersonelAktivasyon(boundUser.id);
      setIssued(result);
      setBoundUser({
        id: result.user.id,
        activation_required: true,
        username: result.user.username,
        username_source: result.user.username_source
      });
      setMeta({
        created_at_utc: result.activation.created_at_utc,
        expires_at_utc: result.activation.expires_at_utc,
        is_expired: false,
        is_valid: true
      });
    } catch (error) {
      setErrorMessage(
        isApiRequestError(error)
          ? error.message
          : error instanceof Error
            ? error.message
            : "Aktivasyon bağlantısı yenilenemedi."
      );
    } finally {
      setIsWorking(false);
    }
  }

  function resolveDisplayActivationUrl(rawUrl: string): string {
    if (rawUrl.startsWith("http://") || rawUrl.startsWith("https://")) {
      return rawUrl;
    }
    return `${window.location.origin}${rawUrl.startsWith("/") ? "" : "/"}${rawUrl}`;
  }

  async function handleCopyLink() {
    const rawUrl = issued?.activation.activation_url;
    if (!rawUrl) {
      return;
    }
    const url = resolveDisplayActivationUrl(rawUrl);
    try {
      await navigator.clipboard.writeText(url);
      setCopyStatus("Bağlantı panoya kopyalandı.");
    } catch {
      setCopyStatus("Kopyalama başarısız. Bağlantıyı manuel seçin.");
    }
  }

  const activationPending = boundUser?.activation_required === true;
  const accountActive = hasBoundUser === true && boundUser != null && boundUser.activation_required !== true;

  return (
    <div className="yonetim-workspace-panel" data-testid="personel-hesap-onboarding-panel">
      <p className="yonetim-workspace-panel-title">Personel hesabı</p>

      {isLoadingBound ? <p className="yonetim-hint">Hesap durumu yükleniyor…</p> : null}

      {!isLoadingBound && !personelAktif ? (
        <p className="yonetim-hint">Pasif personel için hesap oluşturulamaz.</p>
      ) : null}

      {!isLoadingBound && personelAktif && !sicil ? (
        <p className="yonetim-hint">Hesap oluşturmak için sicil numarası zorunludur.</p>
      ) : null}

      {eligible && !confirmOpen ? (
        <button
          type="button"
          className="universal-btn-save"
          data-testid="personel-hesap-olustur"
          onClick={() => {
            setConfirmOpen(true);
            setErrorMessage(null);
          }}
        >
          Personel Hesabı Oluştur
        </button>
      ) : null}

      {eligible && confirmOpen ? (
        <div className="yonetim-form-stack" data-testid="personel-hesap-onboarding-confirm">
          <p className="yonetim-hint">
            Personel şifresini aktivasyon bağlantısı üzerinden kendisi belirleyecektir.
          </p>
          <label className="form-field">
            <span>Kullanıcı adı (sicil)</span>
            <input type="text" value={sicil} readOnly />
          </label>
          <div className="yonetim-create-row">
            <button type="button" className="universal-btn-save" disabled={isWorking} onClick={() => void handleCreate()}>
              {isWorking ? "Oluşturuluyor…" : "Onayla ve Bağlantı Oluştur"}
            </button>
            <button type="button" className="yonetim-panel-action" disabled={isWorking} onClick={() => setConfirmOpen(false)}>
              Vazgeç
            </button>
          </div>
        </div>
      ) : null}

      {issued ? (
        <div className="yonetim-form-stack" data-testid="personel-hesap-onboarding-issued">
          <p>
            Kullanıcı adı: <strong>{issued.user.username}</strong>
          </p>
          <label className="form-field">
            <span>Aktivasyon bağlantısı (yalnızca bir kez gösterilir)</span>
            <input type="text" readOnly value={resolveDisplayActivationUrl(issued.activation.activation_url)} />
          </label>
          <p className="yonetim-hint" role="status">
            Bu bağlantı güvenlik nedeniyle daha sonra tekrar görüntülenemez.
            Kaybolursa yeni bağlantı oluşturabilirsiniz.
          </p>
          <button type="button" className="universal-btn-save" onClick={() => void handleCopyLink()}>
            Bağlantıyı Kopyala
          </button>
          {copyStatus ? <p className="yonetim-hint">{copyStatus}</p> : null}
        </div>
      ) : null}

      {!issued && activationPending ? (
        <div className="yonetim-form-stack" data-testid="personel-hesap-aktivasyon-bekliyor">
          <p>
            <strong>Aktivasyon Bekliyor</strong>
            {boundUser?.username ? ` — ${boundUser.username}` : null}
          </p>
          {meta ? (
            <p className="yonetim-hint">
              Oluşturulma: {formatUtcLabel(meta.created_at_utc)}
              {" · "}
              Son geçerlilik: {formatUtcLabel(meta.expires_at_utc)}
              {meta.is_expired ? " (süresi dolmuş)" : null}
            </p>
          ) : (
            <p className="yonetim-hint">Bekleyen aktivasyon meta bilgisi yükleniyor veya yok.</p>
          )}
          <button
            type="button"
            className="universal-btn-save"
            disabled={isWorking}
            data-testid="personel-aktivasyon-yenile"
            onClick={() => void handleReissue()}
          >
            {isWorking ? "Oluşturuluyor…" : "Yeni Aktivasyon Bağlantısı Oluştur"}
          </button>
        </div>
      ) : null}

      {!issued && accountActive ? (
        <p data-testid="personel-hesap-aktif">
          <strong>Hesap Aktif</strong>
          {boundUser?.username ? ` — ${boundUser.username}` : null}
        </p>
      ) : null}

      {errorMessage ? (
        <p className="auth-error" role="alert">
          {errorMessage}
        </p>
      ) : null}
    </div>
  );
}
