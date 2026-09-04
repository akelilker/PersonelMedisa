import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import { fetchAttendanceToday, type AttendanceTodayResponse } from "../../../api/attendance-mobile.api";
import { fetchBirimGunlukDurum } from "../../../api/bildirimler.api";
import { fetchMe } from "../../../api/me.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { LoadingState } from "../../../components/states/LoadingState";
import { useAuth } from "../../../state/auth.store";
import type { BirimAmiriGunlukDurum } from "../../../types/bildirim";
import type { MeIdentity } from "../../../types/self-service";
import {
  formatBirimAmiriPersonelStatusLine,
  istanbulBusinessDate
} from "../birim-amiri-operational";

export function BirimAmiriOperationalHomePage() {
  const { session } = useAuth();
  const [loading, setLoading] = useState(true);
  const [today, setToday] = useState<AttendanceTodayResponse | null>(null);
  const [identity, setIdentity] = useState<MeIdentity | null>(null);
  const [unit, setUnit] = useState<BirimAmiriGunlukDurum | null>(null);
  const [unitError, setUnitError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    const tarih = istanbulBusinessDate();
    try {
      const unitData = await fetchBirimGunlukDurum({ tarih });
      setUnit(unitData);
      setUnitError(null);
    } catch (cause) {
      setUnit(null);
      setUnitError(cause instanceof Error ? cause.message : "Birim özeti yüklenemedi.");
    }

    if (!shouldPreferDemoApi()) {
      try {
        const [me, attendance] = await Promise.all([fetchMe(), fetchAttendanceToday()]);
        setIdentity(me);
        setToday(attendance);
      } catch (cause) {
        if (isApiRequestError(cause) && cause.code === "SELF_SERVICE_BINDING_REQUIRED") {
          setIdentity(null);
          setToday(null);
        } else {
          setIdentity(null);
          setToday(null);
        }
      }
    } else {
      setIdentity(null);
      setToday(null);
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  if (loading) {
    return <LoadingState label="Birim paneli yükleniyor..." />;
  }

  const personel = today?.personel ?? identity?.personel ?? null;
  const adSoyad = personel?.ad_soyad || session?.user.ad_soyad || "Birim Amiri";
  const gorev = personel?.gorev_ad ?? null;
  const orgLine = personel
    ? [personel.sube_ad, personel.bolum_ad, personel.birim_ad, personel.gorev_ad].filter(Boolean).join(" · ")
    : null;
  const ozet = unit?.ozet;
  const tamamlandi = unit?.tamamlandi_mi === true;

  return (
    <section className="personel-mobile-shell" data-testid="birim-amiri-operational-home">
      <header className="pm-header" data-testid="birim-amiri-home-header">
        <div className="pm-header-accent pm-header-accent--left" aria-hidden="true" />
        <div className="pm-header-main">
          <p className="pm-product-title">PERSONEL YÖN. SİST.</p>
          <p className="pm-page-title">ANASAYFA</p>
          <p className="pm-user-line">{adSoyad}</p>
          {orgLine ? <p className="pm-org-line">{orgLine}</p> : null}
        </div>
        <div className="pm-header-accent pm-header-accent--right" aria-hidden="true" />
      </header>

      <section className="pm-section" data-testid="birim-amiri-own-info">
        <h2 className="pm-section-title">Kendi Bilgilerim</h2>
        <dl className="self-service-dl">
          <div>
            <dt>Ad Soyad</dt>
            <dd>{adSoyad}</dd>
          </div>
          {gorev ? (
            <div>
              <dt>Görev</dt>
              <dd>{gorev}</dd>
            </div>
          ) : null}
          {orgLine ? (
            <div>
              <dt>Bölüm / Birim</dt>
              <dd>{orgLine}</dd>
            </div>
          ) : null}
        </dl>
        <div className="pm-attendance-grid pm-attendance-grid--readonly" data-testid="birim-amiri-own-attendance">
          <div className="pm-attendance-box">
            <p className="pm-box-label">Giriş Saati</p>
            <p className="pm-box-time">
              {today?.giris?.display_local_time ?? today?.giris?.local_time ?? "—"}
            </p>
          </div>
          <div className="pm-attendance-box">
            <p className="pm-box-label">Çıkış Saati</p>
            <p className="pm-box-time">
              {today?.cikis?.display_local_time ?? today?.cikis?.local_time ?? "—"}
            </p>
          </div>
        </div>
      </section>

      <section className="pm-section" data-testid="birim-amiri-unit-section">
        <h2 className="pm-section-title">Birimim</h2>
        {unitError ? <p className="self-service-muted">{unitError}</p> : null}
        {ozet ? (
          <ul className="pm-unit-summary" data-testid="birim-amiri-unit-summary">
            <li>
              <span>Toplam Personel</span>
              <strong data-testid="birim-count-toplam">{ozet.toplam_personel}</strong>
            </li>
            <li>
              <span>Geldi</span>
              <strong data-testid="birim-count-geldi">{ozet.geldi}</strong>
            </li>
            <li>
              <span>Gelmedi</span>
              <strong data-testid="birim-count-gelmedi">{ozet.gelmedi}</strong>
            </li>
            <li>
              <span>Geç Geldi</span>
              <strong data-testid="birim-count-gec-geldi">{ozet.gec_geldi}</strong>
            </li>
            <li>
              <span>İzinli / Raporlu</span>
              <strong data-testid="birim-count-izinli-raporlu">{ozet.izinli_raporlu}</strong>
            </li>
            <li>
              <span>Erken Çıktı</span>
              <strong data-testid="birim-count-erken-cikti">{ozet.erken_cikti}</strong>
            </li>
            <li>
              <span>Görevde</span>
              <strong data-testid="birim-count-gorevde">{ozet.gorevde}</strong>
            </li>
            <li>
              <span>Henüz Değerlendirilmedi</span>
              <strong data-testid="birim-count-henuz">{ozet.henuz_degerlendirilmedi}</strong>
            </li>
          </ul>
        ) : null}

        <div className="pm-unit-cta" data-testid="birim-amiri-daily-notification-cta">
          <Link to="/bildirimler" className="self-service-action" data-testid="birim-amiri-edit-daily">
            Günlük Bildirimi Düzenle
          </Link>
          {!tamamlandi ? (
            <Link to="/bildirimler" className="self-service-action" data-testid="birim-amiri-complete-daily">
              Günlük Bildirimi Tamamla
            </Link>
          ) : null}
        </div>

        <ul className="pm-unit-person-list" data-testid="birim-amiri-person-list">
          {(unit?.personeller ?? []).length === 0 ? (
            <li className="self-service-muted">Bugün biriminizde listelenecek personel yok.</li>
          ) : (
            (unit?.personeller ?? []).map((row) => (
              <li key={row.personel_id} data-testid={`birim-person-${row.personel_id}`}>
                <strong>{row.ad_soyad}</strong>
                <span>
                  {formatBirimAmiriPersonelStatusLine({
                    durum: row.durum,
                    gec_kalma_dakika: row.gec_kalma_dakika,
                    erken_cikis_dakika: row.erken_cikis_dakika,
                    giris_saati: row.giris_saati,
                    cikis_saati: row.cikis_saati
                  })}
                </span>
              </li>
            ))
          )}
        </ul>
      </section>

      <footer className="pm-footer" data-testid="personel-mobile-footer">
        <div className="pm-footer-accent pm-footer-accent--left" aria-hidden="true" />
        <span>PersonelMedisa</span>
        <div className="pm-footer-accent pm-footer-accent--right" aria-hidden="true" />
      </footer>
    </section>
  );
}
