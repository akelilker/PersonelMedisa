import { useCallback, useEffect, useMemo, useState } from "react";
import { isApiRequestError } from "../../../api/api-client";
import { createPersonelHesapOnboarding, fetchYonetimKullanicilari } from "../../../api/yonetim.api";
import type { PersonelHesapFirstLoginResult } from "../../../types/yonetim";
import { buildPersonelUsernameFromNames } from "../personelUsernameFromNames";

export type PersonelHesapOnboardingBoundUser = {
  id: number;
  must_change_password?: boolean;
  username?: string;
};

export type PersonelHesapOnboardingPanelProps = {
  personelId: number;
  ad?: string | null;
  soyad?: string | null;
  personelAktif: boolean;
  hasBoundUser?: boolean;
  boundUser?: PersonelHesapOnboardingBoundUser | null;
};

export function PersonelHesapOnboardingPanel({
  personelId,
  ad,
  soyad,
  personelAktif,
  hasBoundUser: hasBoundUserProp,
  boundUser: boundUserProp
}: PersonelHesapOnboardingPanelProps) {
  const [boundUser, setBoundUser] = useState<PersonelHesapOnboardingBoundUser | null>(boundUserProp ?? null);
  const [hasBoundUser, setHasBoundUser] = useState<boolean | undefined>(hasBoundUserProp);
  const [isLoadingBound, setIsLoadingBound] = useState(hasBoundUserProp === undefined);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [isWorking, setIsWorking] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [created, setCreated] = useState<PersonelHesapFirstLoginResult | null>(null);
  const [usernameCollision, setUsernameCollision] = useState(false);
  const [usernameOverride, setUsernameOverride] = useState("");

  const suggestedUsername = useMemo(() => buildPersonelUsernameFromNames(ad, soyad), [ad, soyad]);

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
      setBoundUser(match ? { id: match.id, must_change_password: match.must_change_password, username: match.username } : null);
      setHasBoundUser(match !== null);
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

  const eligible = personelAktif && suggestedUsername.length > 0 && hasBoundUser === false && !created;

  async function handleCreate() {
    if (!eligible || isWorking) return;

    setIsWorking(true);
    setErrorMessage(null);
    try {
      const override = usernameCollision && usernameOverride.trim() !== "" ? usernameOverride.trim() : undefined;
      const result = await createPersonelHesapOnboarding(personelId, override);
      setCreated(result);
      setBoundUser({
        id: result.user.id,
        must_change_password: result.user.must_change_password !== false,
        username: result.user.username
      });
      setHasBoundUser(true);
      setConfirmOpen(false);
      setUsernameCollision(false);
    } catch (error) {
      if (isApiRequestError(error) && error.code === "PERSONEL_USERNAME_COLLISION") {
        setUsernameCollision(true);
        setUsernameOverride((prev) => (prev.trim() !== "" ? prev : suggestedUsername));
        setErrorMessage(error.message || "Bu kullanıcı adı zaten kullanılıyor. Farklı bir kullanıcı adı belirleyin.");
      } else {
        setErrorMessage(
          isApiRequestError(error)
            ? error.message
            : error instanceof Error
              ? error.message
              : "Personel hesabı oluşturulamadı."
        );
      }
    } finally {
      setIsWorking(false);
    }
  }

  return (
    <div className="yonetim-workspace-panel" data-testid="personel-hesap-onboarding-panel">
      <p className="yonetim-workspace-panel-title">Personel hesabı</p>
      {isLoadingBound ? <p className="yonetim-hint">Hesap durumu yükleniyor…</p> : null}
      {!isLoadingBound && !personelAktif ? <p className="yonetim-hint">Pasif personel için hesap oluşturulamaz.</p> : null}
      {!isLoadingBound && personelAktif && !suggestedUsername ? <p className="yonetim-hint">Hesap oluşturmak için ad ve soyad zorunludur.</p> : null}

      {eligible && !confirmOpen ? (
        <button type="button" className="universal-btn-save" data-testid="personel-hesap-olustur" onClick={() => {
          setConfirmOpen(true);
          setErrorMessage(null);
          setUsernameCollision(false);
          setUsernameOverride("");
        }}>
          Personel Hesabı Oluştur
        </button>
      ) : null}

      {eligible && confirmOpen ? (
        <div className="yonetim-form-stack" data-testid="personel-hesap-onboarding-confirm">
          <p className="yonetim-hint">
            Hesap oluşturulduğunda personel, şirket kuralına göre belirlenen başlangıç şifresi ile ilk girişini yapar ve şifresini değiştirmesi gerekir.
          </p>
          <label className="form-field">
            <span>Kullanıcı adı</span>
            <input type="text" value={usernameCollision ? usernameOverride : suggestedUsername} readOnly={!usernameCollision} onChange={(event) => setUsernameOverride(event.target.value)} data-testid="personel-hesap-username" />
          </label>
          {usernameCollision ? <p className="yonetim-hint">Önerilen kullanıcı adı dolu. Farklı bir kullanıcı adı belirleyin; sistem otomatik sayı eklemez.</p> : null}
          <div className="yonetim-create-row">
            <button type="button" className="universal-btn-save" disabled={isWorking} onClick={() => void handleCreate()}>
              {isWorking ? "Oluşturuluyor…" : "Onayla ve Hesap Oluştur"}
            </button>
            <button type="button" className="yonetim-panel-action" disabled={isWorking} onClick={() => setConfirmOpen(false)}>Vazgeç</button>
          </div>
        </div>
      ) : null}

      {created ? (
        <div className="yonetim-form-stack" data-testid="personel-hesap-onboarding-first-login">
          <p>Kullanıcı adı: <strong>{created.user.username}</strong></p>
          <p data-testid="personel-hesap-ilk-giris-hazir"><strong>Hesap ilk girişe hazır</strong></p>
          <p className="yonetim-hint" role="status">İlk girişte şifresini değiştirmesi gerekir. Başlangıç şifresi şirket kuralına göre oluşturulur ve ilk girişte değiştirilir.</p>
        </div>
      ) : null}

      {!created && hasBoundUser === true && boundUser != null ? (
        <div className="yonetim-form-stack" data-testid="personel-hesap-aktif">
          <p><strong>Hesap Aktif</strong>{boundUser.username ? ` — ${boundUser.username}` : null}</p>
          {boundUser.must_change_password === true ? <p className="yonetim-hint" data-testid="personel-hesap-ilk-giris-bekliyor">İlk girişte şifresini değiştirmesi gerekir.</p> : null}
        </div>
      ) : null}

      {errorMessage ? <p className="auth-error" role="alert">{errorMessage}</p> : null}
    </div>
  );
}
