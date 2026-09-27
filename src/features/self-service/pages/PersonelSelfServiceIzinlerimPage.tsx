import { useEffect, useState } from "react";
import { isApiRequestError, shouldPreferDemoApi } from "../../../api/api-client";
import { fetchMeYillikIzinBakiye } from "../../../api/me.api";
import { fetchSelfIzinler, type SelfIzinKaydi } from "../../../api/self-product.api";
import { LoadingState } from "../../../components/states/LoadingState";
import { formatIsoDateDetail } from "../../../lib/display/iso-date-format";
import { formatSurecStateLabel, formatSurecTuruLabel } from "../../../lib/display/enum-display";
import { SelfServiceFactList, type SelfServiceFact } from "../components/SelfServiceFactList";
import { buildSelfServiceYillikIzinView } from "../personel-self-service-yillik-izin-view";

type Status =
  | { kind: "loading" }
  | { kind: "ready"; rows: SelfServiceFact[]; note: string | null }
  | { kind: "empty"; message: string }
  | { kind: "error"; message: string };

export function PersonelSelfServiceIzinlerimPage() {
  const [status, setStatus] = useState<Status>({ kind: "loading" });
  const [aktif, setAktif] = useState<SelfIzinKaydi | null>(null);
  const [gecmis, setGecmis] = useState<SelfIzinKaydi[]>([]);

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      setStatus({ kind: "empty", message: "Demo modda izin bakiyesi yok." });
      return;
    }
    let cancelled = false;
    void fetchMeYillikIzinBakiye()
      .then((bakiye) => {
        if (cancelled) return;
        const view = buildSelfServiceYillikIzinView(bakiye);
        if (!view) {
          setStatus({
            kind: "empty",
            message: "İzin bakiyesi için işe giriş bilgisi bulunamadı."
          });
          return;
        }
        setStatus({
          kind: "ready",
          note: view.pendingMessage,
          rows: [
            { label: "İşe giriş", value: view.iseGirisLabel, testId: "personel-izin-ise-giris" },
            { label: "Kıdem", value: view.kidemCalisiyorLabel, testId: "personel-izin-kidem" },
            { label: "Toplam hak", value: view.toplamLabel, testId: "personel-izin-toplam" },
            { label: "Kullanılan", value: view.kullanilanLabel, testId: "personel-izin-kullanilan" },
            { label: "Kalan", value: view.kalanLabel, testId: "personel-izin-kalan" }
          ]
        });
      })
      .catch((cause) => {
        if (cancelled) return;
        const unbound =
          isApiRequestError(cause) &&
          (cause.code === "SELF_SERVICE_BINDING_REQUIRED" || cause.code === "FORBIDDEN");
        setStatus({
          kind: "error",
          message: unbound
            ? "İzin bakiyesi bu hesap için kapalı."
            : "İzin bakiyesi yüklenemedi. Tekrar deneyin."
        });
      });
    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (shouldPreferDemoApi()) {
      return;
    }
    let cancelled = false;
    void fetchSelfIzinler()
      .then((list) => {
        if (cancelled) return;
        setAktif(list.aktif);
        setGecmis(list.gecmis);
      })
      .catch(() => {
        if (!cancelled) {
          setAktif(null);
          setGecmis([]);
        }
      });
    return () => {
      cancelled = true;
    };
  }, []);

  function leaveFacts(item: SelfIzinKaydi, prefix: string): SelfServiceFact[] {
    const rows: SelfServiceFact[] = [
      { label: "Tür", value: formatSurecTuruLabel(item.izin_turu), testId: `${prefix}-tur` },
      { label: "Başlangıç", value: formatIsoDateDetail(item.baslangic), testId: `${prefix}-baslangic` },
      { label: "Bitiş", value: item.bitis ? formatIsoDateDetail(item.bitis) : "-", testId: `${prefix}-bitis` }
    ];
    if (typeof item.gun === "number") {
      rows.push({ label: "Gün", value: `${item.gun} gün`, testId: `${prefix}-gun` });
    }
    if (item.ise_donus) {
      rows.push({ label: "İşe dönüş", value: formatIsoDateDetail(item.ise_donus), testId: `${prefix}-donus` });
    }
    if (typeof item.bitime_kalan_gun === "number") {
      rows.push({
        label: "Bitime kalan",
        value: `${item.bitime_kalan_gun} gün`,
        testId: `${prefix}-kalan-gun`
      });
    }
    rows.push({ label: "Durum", value: formatSurecStateLabel(item.durum), testId: `${prefix}-durum` });
    return rows;
  }

  return (
    <section className="personel-mobile-shell pm-self-subpage" data-testid="personel-izinlerim-page">
      {status.kind === "loading" ? <LoadingState label="İzin bakiyesi yükleniyor..." /> : null}
      {status.kind === "ready" ? (
        <>
          {status.note ? (
            <p className="self-service-muted" data-testid="personel-izin-pending">
              {status.note}
            </p>
          ) : null}
          <SelfServiceFactList rows={status.rows} testId="personel-izin-facts" />
        </>
      ) : null}
      {status.kind === "empty" || status.kind === "error" ? (
        <p className="self-service-muted" data-testid="personel-izin-status">
          {status.message}
        </p>
      ) : null}
      {aktif ? (
        <section data-testid="personel-izin-aktif">
          <h3 className="pm-self-request__title">Aktif izin</h3>
          <SelfServiceFactList rows={leaveFacts(aktif, "personel-izin-aktif")} testId="personel-izin-aktif-facts" />
        </section>
      ) : null}
      {gecmis.length > 0 ? (
        <section data-testid="personel-izin-gecmis">
          <h3 className="pm-self-request__title">Geçmiş izinler</h3>
          <ul className="pm-self-request-list">
            {gecmis.map((item) => (
              <li key={item.id}>
                <SelfServiceFactList rows={leaveFacts(item, `personel-izin-${item.id}`)} />
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </section>
  );
}
