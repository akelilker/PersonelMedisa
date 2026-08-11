import { useEffect, useState } from "react";
import {
  fetchMe,
  fetchMeFazlaCalisma,
  fetchMePuantaj,
  fetchMeYillikIzinBakiye
} from "../../../api/me.api";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { LoadingState } from "../../../components/states/LoadingState";
import type {
  MeFazlaCalismaResponse,
  MeIdentity,
  MePuantajGun,
  MePuantajResponse,
  MeYillikIzinBakiye
} from "../../../types/self-service";
import "../self-service.css";

type PageStatus =
  | { kind: "loading" }
  | { kind: "unbound" }
  | { kind: "inactive" }
  | { kind: "demo" }
  | { kind: "error"; message: string }
  | {
      kind: "ready";
      identity: MeIdentity;
      today: MePuantajGun | null;
      month: MePuantajResponse | null;
      last12: MePuantajResponse | null;
      izin: MeYillikIzinBakiye | null;
      fazla: MeFazlaCalismaResponse | null;
      sectionErrors: string[];
    };

function todayYmdIstanbul(): string {
  try {
    return new Intl.DateTimeFormat("en-CA", {
      timeZone: "Europe/Istanbul",
      year: "numeric",
      month: "2-digit",
      day: "2-digit"
    }).format(new Date());
  } catch {
    return new Date().toISOString().slice(0, 10);
  }
}

function monthsAgoYmd(months: number, today: string): string {
  const [y, m, d] = today.split("-").map(Number);
  const date = new Date(Date.UTC(y, m - 1, d));
  date.setUTCMonth(date.getUTCMonth() - months);
  const yy = date.getUTCFullYear();
  const mm = String(date.getUTCMonth() + 1).padStart(2, "0");
  const dd = String(date.getUTCDate()).padStart(2, "0");
  return `${yy}-${mm}-${dd}`;
}

function formatMinutes(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value)) {
    return "—";
  }
  if (value === 0) {
    return "0 dk";
  }
  const hours = Math.floor(Math.abs(value) / 60);
  const mins = Math.abs(value) % 60;
  const sign = value < 0 ? "-" : "";
  if (hours === 0) {
    return `${sign}${mins} dk`;
  }
  if (mins === 0) {
    return `${sign}${hours} sa`;
  }
  return `${sign}${hours} sa ${mins} dk`;
}

function formatGun(value: number | null | undefined): string {
  if (value == null || !Number.isFinite(value)) {
    return "—";
  }
  return `${value} gün`;
}

export function PersonelSelfServiceHomePage() {
  const [status, setStatus] = useState<PageStatus>({ kind: "loading" });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      if (shouldPreferDemoApi()) {
        if (!cancelled) {
          setStatus({ kind: "demo" });
        }
        return;
      }

      try {
        const identity = await fetchMe();
        const today = todayYmdIstanbul();
        const from12 = monthsAgoYmd(12, today);
        const sectionErrors: string[] = [];

        const [monthResult, last12Result, izinResult, fazlaResult] = await Promise.allSettled([
          fetchMePuantaj(),
          fetchMePuantaj({ from: from12, to: today }),
          fetchMeYillikIzinBakiye({ referans_tarih: today }),
          fetchMeFazlaCalisma()
        ]);

        if (cancelled) {
          return;
        }

        const month = monthResult.status === "fulfilled" ? monthResult.value : null;
        if (monthResult.status === "rejected") {
          sectionErrors.push("Bu ay puantaj özeti yüklenemedi.");
        }

        const last12 = last12Result.status === "fulfilled" ? last12Result.value : null;
        if (last12Result.status === "rejected") {
          sectionErrors.push("Son 12 ay özeti yüklenemedi.");
        }

        const izin = izinResult.status === "fulfilled" ? izinResult.value : null;
        if (izinResult.status === "rejected") {
          sectionErrors.push("Yıllık izin bakiyesi yüklenemedi.");
        }

        const fazla = fazlaResult.status === "fulfilled" ? fazlaResult.value : null;
        if (fazlaResult.status === "rejected") {
          sectionErrors.push("Fazla çalışma özeti yüklenemedi.");
        }

        const todayFromMonth = month?.items.find((item) => item.tarih === today) ?? null;
        const todayFromYear = last12?.items.find((item) => item.tarih === today) ?? null;

        setStatus({
          kind: "ready",
          identity,
          today: todayFromMonth ?? todayFromYear,
          month,
          last12,
          izin,
          fazla,
          sectionErrors
        });
      } catch (error) {
        if (cancelled) {
          return;
        }
        if (isApiRequestError(error)) {
          if (error.code === "SELF_SERVICE_PERSONEL_INACTIVE") {
            setStatus({ kind: "inactive" });
            return;
          }
          // Binding required / schema missing / not found → same unbound surface.
          // E2E/demo without /me mock also lands here fail-closed.
          setStatus({ kind: "unbound" });
          return;
        }
        setStatus({ kind: "unbound" });
      }
    }

    void load();
    return () => {
      cancelled = true;
    };
  }, []);

  if (status.kind === "loading") {
    return <LoadingState label="Personel bilgileri yükleniyor..." />;
  }

  if (status.kind === "demo") {
    return (
      <section className="states-page" data-testid="personel-unbound-page">
        <h2>Personel self-service</h2>
        <p>Demo modda personel eşlemesi yok. Gerçek API ile bağlanmış hesapta özet burada görünür.</p>
      </section>
    );
  }

  if (status.kind === "unbound") {
    return (
      <section className="states-page" data-testid="personel-unbound-page">
        <h2>Hesabınız personel kaydıyla eşleştirilmemiş.</h2>
        <p>Yöneticiniz hesabınızı bir personel kaydına bağladıktan sonra bu ekran açılır.</p>
      </section>
    );
  }

  if (status.kind === "inactive") {
    return (
      <section className="states-page" data-testid="personel-inactive-page">
        <h2>Personel hesabınız aktif değil.</h2>
        <p>Aktif personel kaydı olmadan self-service özeti görüntülenemez.</p>
      </section>
    );
  }

  if (status.kind === "error") {
    return (
      <section className="states-page state-error" data-testid="personel-self-service-error">
        <h2>Özet yüklenemedi</h2>
        <p>{status.message}</p>
      </section>
    );
  }

  const { identity, today, month, last12, izin, fazla, sectionErrors } = status;
  const personelLabel = identity.personel.ad_soyad || identity.ad_soyad;

  return (
    <section className="self-service-home" data-testid="personel-self-service-page">
      <header className="self-service-home__header">
        <h2>{personelLabel}</h2>
        <p>
          {[identity.personel.departman_ad, identity.personel.gorev_ad, identity.personel.sube_ad]
            .filter(Boolean)
            .join(" · ") || "Personel self-service"}
        </p>
      </header>

      {sectionErrors.length > 0 ? (
        <div className="self-service-home__warnings" role="status">
          {sectionErrors.map((msg) => (
            <p key={msg}>{msg}</p>
          ))}
        </div>
      ) : null}

      <article className="state-card self-service-card">
        <h3>Bugün</h3>
        {today ? (
          <dl className="self-service-dl">
            <div>
              <dt>Tarih</dt>
              <dd>{today.tarih}</dd>
            </div>
            <div>
              <dt>Gün tipi</dt>
              <dd>{today.gun_tipi ?? "—"}</dd>
            </div>
            <div>
              <dt>Giriş / Çıkış</dt>
              <dd>
                {today.giris_saati ?? "—"} / {today.cikis_saati ?? "—"}
              </dd>
            </div>
            {(today.gec_kalma_dakika != null && today.gec_kalma_dakika > 0) ||
            (today.erken_cikis_dakika != null && today.erken_cikis_dakika > 0) ? (
              <div>
                <dt>Gecikme / Erken</dt>
                <dd>
                  {formatMinutes(today.gec_kalma_dakika)} / {formatMinutes(today.erken_cikis_dakika)}
                </dd>
              </div>
            ) : null}
          </dl>
        ) : (
          <p className="self-service-muted">Bugün için puantaj kaydı yok.</p>
        )}
      </article>

      <article className="state-card self-service-card">
        <h3>Bu Ay</h3>
        {month ? (
          <dl className="self-service-dl">
            <div>
              <dt>Çalışma günü</dt>
              <dd>{month.ozet.calisma_gun_adet}</dd>
            </div>
            <div>
              <dt>Gecikme</dt>
              <dd>
                {month.ozet.gec_kalma_adet} kez · {formatMinutes(month.ozet.gec_kalma_dakika_toplam)}
              </dd>
            </div>
            <div>
              <dt>Erken çıkış</dt>
              <dd>
                {month.ozet.erken_cikis_adet} kez · {formatMinutes(month.ozet.erken_cikis_dakika_toplam)}
              </dd>
            </div>
            <div>
              <dt>Fazla çalışma</dt>
              <dd>{formatMinutes(month.ozet.fazla_calisma_dakika_toplam)}</dd>
            </div>
          </dl>
        ) : (
          <p className="self-service-muted">Aylık özet yok.</p>
        )}
      </article>

      <article className="state-card self-service-card">
        <h3>Son 12 Ay</h3>
        {last12 ? (
          <dl className="self-service-dl">
            <div>
              <dt>Dönem</dt>
              <dd>
                {last12.from} — {last12.to}
              </dd>
            </div>
            <div>
              <dt>Çalışma günü</dt>
              <dd>{last12.ozet.calisma_gun_adet}</dd>
            </div>
            <div>
              <dt>Gecikme toplam</dt>
              <dd>{formatMinutes(last12.ozet.gec_kalma_dakika_toplam)}</dd>
            </div>
            <div>
              <dt>Fazla çalışma</dt>
              <dd>{formatMinutes(last12.ozet.fazla_calisma_dakika_toplam)}</dd>
            </div>
          </dl>
        ) : (
          <p className="self-service-muted">12 aylık özet yok.</p>
        )}
      </article>

      <article className="state-card self-service-card">
        <h3>Yıllık İzin</h3>
        {izin ? (
          <dl className="self-service-dl">
            <div>
              <dt>Efektif hak</dt>
              <dd>{formatGun(izin.efektif_hak_gun)}</dd>
            </div>
            <div>
              <dt>Kullanılan</dt>
              <dd>{formatGun(izin.kullanilan_gun)}</dd>
            </div>
            <div>
              <dt>Kalan</dt>
              <dd>{formatGun(izin.kalan_gun)}</dd>
            </div>
            <div>
              <dt>Mevcut yıl bandı</dt>
              <dd>{formatGun(izin.mevcut_yillik_hak_gun)}</dd>
            </div>
          </dl>
        ) : (
          <p className="self-service-muted">İzin bakiyesi yok.</p>
        )}
      </article>

      <article className="state-card self-service-card">
        <h3>Fazla Çalışma</h3>
        {fazla ? (
          <dl className="self-service-dl">
            <div>
              <dt>Yıl</dt>
              <dd>{fazla.yil}</dd>
            </div>
            <div>
              <dt>Kullanılan</dt>
              <dd>{formatMinutes(fazla.yillik.kullanilan_dakika)}</dd>
            </div>
            <div>
              <dt>Kalan</dt>
              <dd>{formatMinutes(fazla.yillik.kalan_dakika)}</dd>
            </div>
            <div>
              <dt>Limit</dt>
              <dd>{formatMinutes(fazla.yillik.yillik_limit_dakika)}</dd>
            </div>
            {fazla.donem_ozet ? (
              <div>
                <dt>Dönem (ay)</dt>
                <dd>
                  {formatMinutes(fazla.donem_ozet.fazla_calisma_dakika_toplam)} ·{" "}
                  {fazla.donem_ozet.calisma_gun_adet} gün
                </dd>
              </div>
            ) : null}
          </dl>
        ) : (
          <p className="self-service-muted">Fazla çalışma özeti yok.</p>
        )}
      </article>
    </section>
  );
}
