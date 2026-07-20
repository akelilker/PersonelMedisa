import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
  downloadBordroDevirSablonCsv,
  fetchBordroDevirListesi,
  fetchBordroHazirlikPreflight,
  fetchBordroNetMaasEksikleri,
  fetchBordroOnIzleme,
  geriGonderBordro,
  importBordroDevirler,
  kesinlestirBordro,
  submitBordroKontrol,
  type BordroDevirImportResult,
  type BordroDevirListItem,
  type BordroNetMaasEksikItem,
  type BordroOnIzlemeOzet
} from "../../../api/bordro-hazirlik.api";
import {
  approveSirketPolitika,
  createSirketPolitikaDraft,
  fetchSirketPolitikaKararOzeti,
  fetchSirketPolitikaKatalog,
  fetchSirketPolitikalari,
  submitSirketPolitika,
  type SirketCalismaPolitikasi,
  type SirketPolitikaDeger,
  type SirketPolitikaKararOzeti
} from "../../../api/sirket-calisma-politikasi.api";
import {
  calculateMaasHesaplamaSnapshot,
  fetchMaasHesaplamaAdayKalemler,
  type MaasHesaplamaIssue
} from "../../../api/maas-hesaplama.api";
import { fetchYonetimSubeleri } from "../../../api/yonetim.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useMaasHesaplama } from "../../../hooks/useMaasHesaplama";
import { currentMonthParts, parseAyValue } from "../../../lib/donem-kapanis/display";
import { useAuth } from "../../../state/auth.store";
import type { IdOption } from "../../../types/referans";
import { MaasHesaplamaMerkeziPage } from "./MaasHesaplamaMerkeziPage";

type TabKey = "veri-hazirlik" | "preflight" | "politika" | "devir" | "on-izleme" | "hesaplama";

type FilterState = {
  ay: string;
  subeId: string;
};

const INITIAL_FILTERS: FilterState = {
  ay: currentMonthParts().ay,
  subeId: ""
};

const TAB_LABELS: Record<TabKey, string> = {
  "veri-hazirlik": "Veri Hazırlık Durumu",
  preflight: "Preflight",
  politika: "Şirket Politikası",
  devir: "Devir Verileri",
  "on-izleme": "Bordro Ön İzleme",
  hesaplama: "Maaş Hesaplama"
};

function blockerItems(items: MaasHesaplamaIssue[]) {
  return items.filter((item) => item.severity === "BLOCKER");
}

function formatMoney(value: string | null | undefined): string {
  if (!value) return "—";
  const parsed = Number.parseFloat(value);
  if (!Number.isFinite(parsed)) return "—";
  return `${new Intl.NumberFormat("tr-TR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parsed)} TL`;
}

export function BordroHazirlikMerkeziPage() {
  const [searchParams] = useSearchParams();
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const canView = hasPermission("bordro_on_izleme.view");
  const canManageAday = hasPermission("maas_hesaplama_adaylari.manage");
  const canViewAday = hasPermission("maas_hesaplama_adaylari.view");
  const canManagePolicy = hasPermission("sirket_parametreleri.manage");
  const canApprove = hasPermission("bordro_kesinlestirme.approve");
  const canViewFinance = hasPermission("finans.view");

  const [filters, setFilters] = useState<FilterState>(INITIAL_FILTERS);
  const [subeOptions, setSubeOptions] = useState<IdOption[]>([]);
  const [activeTab, setActiveTab] = useState<TabKey>("veri-hazirlik");
  const [preflight, setPreflight] = useState<Awaited<ReturnType<typeof fetchBordroHazirlikPreflight>> | null>(null);
  const [onIzleme, setOnIzleme] = useState<BordroOnIzlemeOzet | null>(null);
  const [devirler, setDevirler] = useState<BordroDevirListItem[]>([]);
  const [netMaasEksikleri, setNetMaasEksikleri] = useState<BordroNetMaasEksikItem[]>([]);
  const [politikalar, setPolitikalar] = useState<SirketCalismaPolitikasi[]>([]);
  const [katalog, setKatalog] = useState<SirketPolitikaDeger[]>([]);
  const [policyForm, setPolicyForm] = useState<Record<string, string>>({});
  const [kararOzeti, setKararOzeti] = useState<SirketPolitikaKararOzeti | null>(null);
  const [selectedAdayId, setSelectedAdayId] = useState<number | null>(null);
  const [selectedKalemler, setSelectedKalemler] = useState<Awaited<ReturnType<typeof fetchMaasHesaplamaAdayKalemler>> | null>(null);
  const [kontrolNotu, setKontrolNotu] = useState("");
  const [geriGonderNotu, setGeriGonderNotu] = useState("");
  const [importCsv, setImportCsv] = useState("");
  const [importResult, setImportResult] = useState<BordroDevirImportResult | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const loadRequestIdRef = useRef(0);

  const parsedAy = parseAyValue(filters.ay);
  const subeId = filters.subeId ? Number.parseInt(filters.subeId, 10) : null;
  const yil = parsedAy?.yil ?? currentMonthParts().yil;
  const ay = parsedAy?.ay ?? currentMonthParts().ayNum;

  const {
    calculationPreflight,
    snapshots,
    refetch: refetchMaas
  } = useMaasHesaplama({
    enabled: Boolean(parsedAy && subeId && canView),
    filters: { ay: filters.ay, subeId: filters.subeId },
    yil,
    ay,
    subeId: Number.isFinite(subeId) ? subeId : null,
    loadCalculationCandidates: canManageAday
  });

  const activeSnapshot = snapshots[0] ?? null;
  const candidateGate = preflight?.candidate_gate;
  const candidateDisabled =
    !(candidateGate?.aktif ?? preflight?.hesaplanabilir_mi ?? false) ||
    !(calculationPreflight?.hesaplanabilir_mi ?? false);

  useEffect(() => {
    const tab = searchParams.get("tab");
    if (
      tab === "veri-hazirlik" ||
      tab === "preflight" ||
      tab === "politika" ||
      tab === "devir" ||
      tab === "on-izleme" ||
      tab === "hesaplama"
    ) {
      setActiveTab(tab);
    }
  }, [searchParams]);

  useEffect(() => {
    const sessionSubeler = (session?.sube_list ?? []).map((sube) => ({ id: sube.id, label: sube.ad }));
    if (sessionSubeler.length > 0) setSubeOptions(sessionSubeler);
    const activeSubeId = session?.active_sube_id;
    if (activeSubeId) {
      setFilters((prev) => (prev.subeId === String(activeSubeId) ? prev : { ...prev, subeId: String(activeSubeId) }));
    }
    void fetchYonetimSubeleri().then((items) => {
      if (items.length > 0) setSubeOptions(items.map((item) => ({ id: item.id, label: item.ad })));
    });
  }, [session?.active_sube_id, session?.sube_list]);

  const loadData = useCallback(async () => {
    if (!parsedAy || !subeId) return;
    const requestId = ++loadRequestIdRef.current;
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const [preflightData, onIzlemeData, devirData, politikaData, katalogData, netMaasData] = await Promise.all([
        fetchBordroHazirlikPreflight({ yil, ay, subeId }),
        fetchBordroOnIzleme({ yil, ay, subeId }),
        fetchBordroDevirListesi({ yil, ay, subeId, eksik: false }),
        fetchSirketPolitikalari(),
        fetchSirketPolitikaKatalog(),
        fetchBordroNetMaasEksikleri({ yil, ay, subeId })
      ]);
      if (requestId !== loadRequestIdRef.current) {
        return;
      }
      setPreflight(preflightData);
      setOnIzleme(onIzlemeData);
      setDevirler(devirData);
      setPolitikalar(politikaData);
      setKatalog(katalogData);
      setNetMaasEksikleri(netMaasData);
      const initialForm: Record<string, string> = {};
      katalogData.forEach((item: SirketPolitikaDeger) => {
        initialForm[item.parametre_kodu] = "";
      });
      setPolicyForm(initialForm);
      await refetchMaas();
    } catch (error) {
      if (requestId !== loadRequestIdRef.current) {
        return;
      }
      setErrorMessage(error instanceof Error ? error.message : "Bordro hazırlık verisi yüklenemedi.");
    } finally {
      if (requestId === loadRequestIdRef.current) {
        setIsLoading(false);
      }
    }
  }, [parsedAy, subeId, yil, ay, refetchMaas]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  useEffect(() => {
    if (!canApprove || !subeId) {
      setKararOzeti(null);
      return;
    }
    const pending = politikalar.find((item) => item.state === "ONAY_BEKLIYOR");
    if (!pending) {
      setKararOzeti(null);
      return;
    }
    void fetchSirketPolitikaKararOzeti(pending.id, subeId)
      .then(setKararOzeti)
      .catch(() => setKararOzeti(null));
  }, [canApprove, politikalar, subeId]);

  const blockers = useMemo(() => blockerItems(preflight?.items ?? []), [preflight]);
  const readinessDomains = preflight?.readiness_domains ?? [];

  if (!canView) {
    return <ErrorState message="Bordro hazırlık merkezine erişim yetkiniz yok." />;
  }

  async function handleCreatePolicyDraft() {
    if (!canManagePolicy) return;
    setActionMessage(null);
    try {
      const degerler = katalog.map((item) => ({
        parametre_kodu: item.parametre_kodu,
        ...(item.deger_tipi === "METIN"
          ? { metin_deger: policyForm[item.parametre_kodu] }
          : { sayisal_deger: policyForm[item.parametre_kodu] })
      }));
      await createSirketPolitikaDraft({
        gecerlilik_baslangic: `${yil}-${String(ay).padStart(2, "0")}-01`,
        aciklama: "S83 bordro hazırlık politikası taslağı",
        degerler
      });
      setActionMessage("Politika taslağı oluşturuldu.");
      await loadData();
      setActiveTab("politika");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Politika taslağı oluşturulamadı.");
    }
  }

  async function handleSubmitPolicy(id: number) {
    await submitSirketPolitika(id);
    setActionMessage("Politika onaya gönderildi.");
    await loadData();
  }

  async function handleApprovePolicy(id: number) {
    await approveSirketPolitika(id);
    setActionMessage("Politika onaylandı.");
    await loadData();
  }

  async function handleCalculateCandidate() {
    if (!activeSnapshot || !calculationPreflight?.calculation_input_hash) return;
    setActionMessage(null);
    try {
      await calculateMaasHesaplamaSnapshot({
        snapshot_id: activeSnapshot.id,
        expected_calculation_input_hash: calculationPreflight.calculation_input_hash
      });
      setActionMessage("Maaş adayı üretildi.");
      await Promise.all([loadData(), refetchMaas()]);
      setActiveTab("on-izleme");
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Maaş adayı üretilemedi.");
    }
  }

  async function handleSubmitKontrol() {
    if (!onIzleme?.calistirma?.id) return;
    await submitBordroKontrol(onIzleme.calistirma.id, kontrolNotu);
    setActionMessage("Muhasebe kontrolü onaya gönderildi.");
    await loadData();
  }

  async function handleGeriGonder() {
    if (!onIzleme?.calistirma?.id) return;
    await geriGonderBordro(onIzleme.calistirma.id, geriGonderNotu);
    setActionMessage("Bordro muhasebeye geri gönderildi.");
    await loadData();
  }

  async function handleKesinlestir() {
    if (!onIzleme?.calistirma?.id) return;
    await kesinlestirBordro(onIzleme.calistirma.id);
    setActionMessage("Bordro kesinleştirildi.");
    await loadData();
  }

  function parseImportRows() {
    return importCsv
      .split("\n")
      .map((line) => line.trim())
      .filter(Boolean)
      .map((line) => {
        const [sicil, gvMatrah, gv, sgk, aciklama] = line.split(/[;,]/).map((part) => part.trim());
        return {
          sicil,
          onceki_kumulatif_gelir_vergisi_matrahi: gvMatrah,
          onceki_kumulatif_gelir_vergisi: gv,
          ...(sgk ? { onceki_kumulatif_sgk_matrahi: sgk } : {}),
          ...(aciklama ? { aciklama } : {})
        };
      });
  }

  async function handleImportDryRun() {
    if (!subeId) return;
    const result = await importBordroDevirler({ yil, ay, subeId, dryRun: true, rows: parseImportRows() });
    setImportResult(result);
  }

  async function handleImportCommit() {
    if (!subeId) return;
    const result = await importBordroDevirler({ yil, ay, subeId, dryRun: false, rows: parseImportRows() });
    setImportResult(result);
    await loadData();
  }

  async function openAdayDetail(adayId: number) {
    setSelectedAdayId(adayId);
    const kalemler = await fetchMaasHesaplamaAdayKalemler(adayId);
    setSelectedKalemler(kalemler);
  }

  async function handleDownloadDevirSablon() {
    if (!subeId || !canViewAday) return;
    try {
      await downloadBordroDevirSablonCsv({ yil, ay, subeId, eksik: true });
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Devir şablonu indirilemedi.");
    }
  }

  return (
    <section className="yonetim-page donem-kapanis-page" data-testid="bordro-hazirlik-merkezi">
      <header className="yonetim-page-header">
        <h2>Bordro Hazırlık Merkezi</h2>
        <p>İş verisi hazırlık durumu, mevzuat/politika ayrımı, devir, preflight ve bordro ön izleme.</p>
      </header>

      <form
        className="form-filter-panel"
        data-testid="bordro-hazirlik-filters"
        onSubmit={(event) => {
          event.preventDefault();
          void loadData();
        }}
      >
        <div className="form-field-grid">
          <FormField
            label="Ay"
            name="bordro-hazirlik-ay"
            type="month"
            value={filters.ay}
            onChange={(value) => setFilters((prev) => ({ ...prev, ay: value }))}
          />
          <FormField
            as="select"
            label="Şube"
            name="bordro-hazirlik-sube"
            value={filters.subeId}
            onChange={(value) => setFilters((prev) => ({ ...prev, subeId: value }))}
            selectOptions={[
              { value: "", label: "Şube seçin" },
              ...subeOptions.map((sube) => ({ value: String(sube.id), label: sube.label }))
            ]}
          />
        </div>
        <div className="form-actions-row">
          <button type="submit" className="universal-btn-save" data-testid="bordro-hazirlik-submit">
            Yükle
          </button>
        </div>
      </form>

      <nav className="raporlar-panel-nav" aria-label="Bordro hazırlık sekmeleri">
        {(["veri-hazirlik", "preflight", "politika", "devir", "on-izleme", "hesaplama"] as TabKey[]).map((tab) => (
          <button
            key={tab}
            type="button"
            className={activeTab === tab ? "is-active" : undefined}
            data-testid={`bordro-hazirlik-tab-${tab}`}
            onClick={() => setActiveTab(tab)}
          >
            {TAB_LABELS[tab]}
          </button>
        ))}
      </nav>

      {actionMessage ? (
        <p className="yonetim-success" data-testid="bordro-hazirlik-action-success">
          {actionMessage}
        </p>
      ) : null}
      {errorMessage ? (
        <p className="yonetim-error" data-testid="bordro-hazirlik-action-error">
          {errorMessage}
        </p>
      ) : null}

      {isLoading && !preflight ? <LoadingState label="Bordro hazırlık verileri yükleniyor..." /> : null}

      {activeTab === "veri-hazirlik" && preflight ? (
        <section data-testid="bordro-veri-hazirlik">
          <div className="kapanis-ozet-grid">
            <div>
              <strong>Hesaplanabilir</strong>
              <p data-testid="bordro-hazirlik-hesaplanabilir">{preflight.hesaplanabilir_mi ? "Evet" : "Hayır"}</p>
            </div>
            <div>
              <strong>Blocker</strong>
              <p data-testid="bordro-hazirlik-blocker-count">{preflight.blocker_count}</p>
            </div>
            <div>
              <strong>Aday kapısı</strong>
              <p data-testid="bordro-candidate-gate-aktif">{candidateGate?.aktif ? "Açık" : "Kapalı"}</p>
            </div>
          </div>

          {candidateGate && !candidateGate.aktif ? (
            <section data-testid="bordro-candidate-gate-nedenleri" className="kapanis-issue-section">
              <h3>Maaş adayı üretimi engelleri</h3>
              <ul>
                {candidateGate.disabled_nedenleri.map((neden) => (
                  <li key={neden}>{neden}</li>
                ))}
              </ul>
            </section>
          ) : null}

          <section className="kapanis-issue-section" data-testid="bordro-readiness-domains">
            {readinessDomains.map((domain) => (
              <article key={domain.key} data-testid={`bordro-readiness-domain-${domain.key}`}>
                <strong>
                  {domain.label} — {domain.status}
                </strong>
                <p>
                  Eksik kayıt: {domain.eksik_kayit_sayisi} · Etkilenen personel: {domain.etkilenen_personel_sayisi}
                </p>
                <p>{domain.aciklama}</p>
                {domain.action_link ? (
                  <Link to={domain.action_link} data-testid={`bordro-readiness-link-${domain.key}`}>
                    İlgili ekrana git
                  </Link>
                ) : null}
              </article>
            ))}
          </section>

          <section data-testid="bordro-net-maas-eksik-list">
            <h3>Net maaş / ücret eksikleri</h3>
            <p className="personel-puantaj-summary-note">
              Mevzuat parametreleri buradan yönetilmez.{" "}
              <Link to="/yonetim-paneli?tab=mevzuat" data-testid="bordro-mevzuat-link">
                Yönetim → Mevzuat
              </Link>
            </p>
            <table className="yonetim-table">
              <thead>
                <tr>
                  <th>Personel</th>
                  <th>Sicil</th>
                  <th>Şube</th>
                  <th>Departman</th>
                  <th>Görev</th>
                  <th>İşe giriş</th>
                  <th>Durum</th>
                  <th>Kart</th>
                </tr>
              </thead>
              <tbody>
                {netMaasEksikleri.map((item, index) => (
                  <tr key={`${item.sicil_no}-${index}`} data-testid={`bordro-net-maas-row-${index}`}>
                    <td>{item.ad_soyad}</td>
                    <td>{item.sicil_no}</td>
                    <td>{item.sube_adi}</td>
                    <td>{item.departman_adi}</td>
                    <td>{item.gorev_adi}</td>
                    <td>{item.ise_giris_tarihi ?? "—"}</td>
                    <td>{item.net_maas_durumu}</td>
                    <td>
                      <Link to={item.action_link} data-testid={`bordro-net-maas-link-${index}`}>
                        Personel kartı
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {netMaasEksikleri.length === 0 ? <p>Eksik net maaş kaydı yok.</p> : null}
          </section>
        </section>
      ) : null}

      {activeTab === "preflight" && preflight ? (
        <section data-testid="bordro-hazirlik-preflight">
          <div className="kapanis-ozet-grid">
            <div>
              <strong>Hesaplanabilir</strong>
              <p>{preflight.hesaplanabilir_mi ? "Evet" : "Hayır"}</p>
            </div>
            <div>
              <strong>Blocker</strong>
              <p>{preflight.blocker_count}</p>
            </div>
          </div>
          <section className="kapanis-issue-section">
            {blockers.map((item) => (
              <article key={`${item.code}-${item.record_id ?? "x"}`} data-testid={`bordro-hazirlik-issue-${item.code}`}>
                <strong>{item.code}</strong>
                <p>{"kullanici_mesaji" in item && item.kullanici_mesaji ? String(item.kullanici_mesaji) : item.message}</p>
                {"action_link" in item && item.action_link ? (
                  <Link to={String(item.action_link)} data-testid={`bordro-hazirlik-issue-link-${item.code}`}>
                    Sorunu çöz
                  </Link>
                ) : null}
              </article>
            ))}
          </section>
        </section>
      ) : null}

      {activeTab === "politika" ? (
        <section data-testid="bordro-hazirlik-politika">
          <p className="personel-puantaj-summary-note">
            Mevzuat parametreleri yönetim panelinde kalır; burada yalnız şirket çalışma politikası yönetilir.{" "}
            <Link to="/yonetim-paneli?tab=mevzuat">Mevzuat paneli</Link>
          </p>
          {canManagePolicy ? (
            <div data-testid="bordro-politika-form">
              <p data-testid="bordro-politika-ornek-bicim">
                Katalog örnek biçim (değerler otomatik doldurulmaz):{" "}
                {katalog.map((item) => `${item.parametre_kodu}=${item.deger_tipi === "METIN" ? "METIN" : "0.00"}`).join(", ")}
              </p>
              {katalog.map((item) => (
                <label key={item.parametre_kodu}>
                  {item.etiket}
                  <input
                    id={`policy-${item.parametre_kodu}`}
                    value={policyForm[item.parametre_kodu] ?? ""}
                    onChange={(event) =>
                      setPolicyForm((prev) => ({ ...prev, [item.parametre_kodu]: event.target.value }))
                    }
                  />
                </label>
              ))}
              <button type="button" className="universal-btn-save" data-testid="bordro-politika-taslak-olustur" onClick={() => void handleCreatePolicyDraft()}>
                Taslak Oluştur
              </button>
            </div>
          ) : null}

          {canApprove && kararOzeti ? (
            <section data-testid="bordro-politika-karar-ozeti" className="kapanis-issue-section">
              <h3>Onay karar özeti</h3>
              <p>
                Revizyon #{kararOzeti.revision_no} · {kararOzeti.gecerlilik_baslangic}
                {kararOzeti.gecerlilik_bitis ? ` → ${kararOzeti.gecerlilik_bitis}` : ""}
              </p>
              <p>Etkilenen personel (şube): {kararOzeti.etkilenen_personel_sayisi}</p>
              <p>{kararOzeti.etkilenen_donem_ipucu}</p>
              <p>{kararOzeti.aday_snapshot_etki_notu}</p>
              {kararOzeti.eksik_parametreler.length > 0 ? (
                <p data-testid="bordro-politika-eksik-parametreler">
                  Eksik: {kararOzeti.eksik_parametreler.join(", ")}
                </p>
              ) : (
                <p>Zorunlu parametreler tamam.</p>
              )}
            </section>
          ) : null}

          <table className="yonetim-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Durum</th>
                <th>Geçerlilik</th>
                <th>İşlem</th>
              </tr>
            </thead>
            <tbody>
              {politikalar.map((politika) => (
                <tr key={politika.id} data-testid={`bordro-politika-row-${politika.id}`}>
                  <td>{politika.id}</td>
                  <td>{politika.state}</td>
                  <td>
                    {politika.gecerlilik_baslangic}
                    {politika.gecerlilik_bitis ? ` → ${politika.gecerlilik_bitis}` : ""}
                  </td>
                  <td>
                    {canManagePolicy && politika.state === "TASLAK" ? (
                      <button type="button" data-testid={`bordro-politika-submit-${politika.id}`} onClick={() => void handleSubmitPolicy(politika.id)}>
                        Onaya Gönder
                      </button>
                    ) : null}
                    {canApprove && politika.state === "ONAY_BEKLIYOR" ? (
                      <button type="button" data-testid={`bordro-politika-approve-${politika.id}`} onClick={() => void handleApprovePolicy(politika.id)}>
                        Onayla
                      </button>
                    ) : null}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : null}

      {activeTab === "devir" ? (
        <section data-testid="bordro-hazirlik-devir">
          {canViewAday ? (
            <p>
              <button type="button" data-testid="bordro-devir-sablon-indir" onClick={() => void handleDownloadDevirSablon()}>
                Eksik devir CSV şablonunu indir
              </button>
            </p>
          ) : null}
          <textarea
            aria-label="CSV devir içe aktarma"
            value={importCsv}
            onChange={(event) => setImportCsv(event.target.value)}
            placeholder="sicil;gv_matrah;gv;sgk_matrah;aciklama"
            data-testid="bordro-devir-import-csv"
          />
          <div>
            <button type="button" data-testid="bordro-devir-import-dry-run" onClick={() => void handleImportDryRun()}>
              Dry-run Ön İzleme
            </button>
            {canManageAday ? (
              <button type="button" data-testid="bordro-devir-import-commit" onClick={() => void handleImportCommit()}>
                İçe Aktar
              </button>
            ) : null}
          </div>
          {importResult ? (
            <section data-testid="bordro-devir-import-summary">
              <p>
                {importResult.dry_run ? "Ön izleme" : "İçe aktarma"}: {importResult.basarili_satir} başarılı,{" "}
                {importResult.hatali_satir} hatalı
              </p>
              <ul data-testid="bordro-devir-import-counts">
                <li>Eklenecek: {importResult.eklenecek ?? importResult.counts?.eklenecek ?? 0}</li>
                <li>Güncellenecek: {importResult.guncellenecek ?? importResult.counts?.guncellenecek ?? 0}</li>
                <li>Değişmeyecek: {importResult.degismeyecek ?? importResult.counts?.degismeyecek ?? 0}</li>
                <li>Hatalı: {importResult.hatali ?? importResult.counts?.hatali ?? 0}</li>
                <li>Eşleşmeyen: {importResult.eslesmeyen ?? importResult.counts?.eslesmeyen ?? 0}</li>
                <li>Duplicate: {importResult.duplicate ?? importResult.counts?.duplicate ?? 0}</li>
                <li>Kapsam dışı: {importResult.scope_disi ?? importResult.counts?.scope_disi ?? 0}</li>
              </ul>
              <table className="yonetim-table" data-testid="bordro-devir-import-rows">
                <thead>
                  <tr>
                    <th>Satır</th>
                    <th>Sicil</th>
                    <th>Sınıf</th>
                    <th>Hata</th>
                  </tr>
                </thead>
                <tbody>
                  {importResult.satirlar.map((row) => (
                    <tr key={`${row.satir}-${row.sicil ?? ""}`}>
                      <td>{row.satir}</td>
                      <td>{row.sicil ?? "—"}</td>
                      <td>{row.sinif ?? (row.ok ? "ok" : "hatali")}</td>
                      <td>{row.hata ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          ) : null}
          <table className="yonetim-table">
            <thead>
              <tr>
                <th>Personel</th>
                <th>Sicil</th>
                <th>Departman</th>
                <th>Durum</th>
              </tr>
            </thead>
            <tbody>
              {devirler.map((item, index) => (
                <tr key={`${item.personel.sicil}-${index}`} data-testid={`bordro-devir-row-${index}`}>
                  <td>
                    {item.personel.ad} {item.personel.soyad}
                  </td>
                  <td>{item.personel.sicil}</td>
                  <td>{item.personel.departman}</td>
                  <td>{item.dogrulama_durumu}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ) : null}

      {activeTab === "on-izleme" && onIzleme ? (
        <section data-testid="bordro-on-izleme">
          <div className="kapanis-ozet-grid" data-testid="bordro-on-izleme-ozet">
            <div>
              <strong>Dönem</strong>
              <p>{onIzleme.donem}</p>
            </div>
            <div>
              <strong>Toplam personel</strong>
              <p>{onIzleme.toplam_personel}</p>
            </div>
            <div>
              <strong>Blocker</strong>
              <p>{onIzleme.blocker_bulunan}</p>
            </div>
            {canViewFinance ? (
              <>
                <div>
                  <strong>Toplam net</strong>
                  <p data-testid="bordro-on-izleme-toplam-net">{formatMoney(onIzleme.toplam_net)}</p>
                </div>
                <div>
                  <strong>Toplam brüt</strong>
                  <p data-testid="bordro-on-izleme-toplam-brut">{formatMoney(onIzleme.toplam_brut)}</p>
                </div>
              </>
            ) : null}
          </div>

          {canManageAday ? (
            <div data-testid="bordro-muhasebe-actions">
              <label htmlFor="bordro-kontrol-notu">Muhasebe kontrol notu</label>
              <textarea id="bordro-kontrol-notu" value={kontrolNotu} onChange={(event) => setKontrolNotu(event.target.value)} />
              <button type="button" data-testid="bordro-kontrol-gonder" onClick={() => void handleSubmitKontrol()}>
                Kontrole / Onaya Gönder
              </button>
            </div>
          ) : null}

          {canApprove ? (
            <div data-testid="bordro-gy-actions">
              <label htmlFor="bordro-geri-gonder-notu">Geri gönderme notu</label>
              <textarea id="bordro-geri-gonder-notu" value={geriGonderNotu} onChange={(event) => setGeriGonderNotu(event.target.value)} />
              <button type="button" data-testid="bordro-geri-gonder" onClick={() => void handleGeriGonder()}>
                Muhasebeye Geri Gönder
              </button>
              <button
                type="button"
                data-testid="bordro-kesinlestir"
                disabled={(preflight?.blocker_count ?? 1) > 0}
                onClick={() => void handleKesinlestir()}
              >
                Kesinleştir
              </button>
            </div>
          ) : null}

          <table className="yonetim-table" data-testid="bordro-on-izleme-table">
            <thead>
              <tr>
                <th>Personel</th>
                <th>Sicil</th>
                <th>Departman</th>
                {canViewFinance ? (
                  <>
                    <th>Net</th>
                    <th>Brüt</th>
                  </>
                ) : null}
                <th>Correction</th>
                <th>Durum</th>
                <th>Detay</th>
              </tr>
            </thead>
            <tbody>
              {onIzleme.personel_satirlari.map((row) => (
                <tr key={row.aday_id} data-testid={`bordro-on-izleme-row-${row.aday_id}`}>
                  <td>{row.ad_soyad}</td>
                  <td>{row.sicil}</td>
                  <td>{row.departman_ad}</td>
                  {canViewFinance ? (
                    <>
                      <td>{formatMoney(row.net_odenecek)}</td>
                      <td>{formatMoney(row.brut_maas)}</td>
                    </>
                  ) : null}
                  <td data-testid={`bordro-correction-${row.aday_id}`}>
                    {row.aktif_correction_var_mi ? "Aktif" : "Yok"}
                  </td>
                  <td>{row.bordro_onay_durumu}</td>
                  <td>
                    <button type="button" data-testid={`bordro-aday-detay-${row.aday_id}`} onClick={() => void openAdayDetail(row.aday_id)}>
                      Kalemler
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>

          {selectedAdayId && selectedKalemler ? (
            <section data-testid="bordro-aday-kalemler">
              <h3>Aday #{selectedAdayId} kalemleri</h3>
              <table className="yonetim-table">
                <thead>
                  <tr>
                    <th>Kaynak</th>
                    <th>Açıklama</th>
                    <th>Tutar</th>
                    <th>Yön</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedKalemler.map((kalem) => (
                    <tr key={kalem.id} data-testid={`bordro-aday-kalem-${kalem.id}`}>
                      <td>{kalem.kalem_kodu ?? kalem.kod ?? "—"}</td>
                      <td>{formatMoney(String(kalem.tutar ?? 0))}</td>
                      <td>{kalem.tur ?? "—"}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          ) : null}
        </section>
      ) : null}

      {activeTab === "hesaplama" ? (
        <section data-testid="bordro-hazirlik-hesaplama">
          {canManageAday ? (
            <>
              <button
                type="button"
                className="universal-btn-save"
                data-testid="bordro-candidate-uret"
                disabled={candidateDisabled}
                onClick={() => void handleCalculateCandidate()}
              >
                Maaş Adayı Üret
              </button>
              {candidateDisabled && candidateGate?.disabled_nedenleri?.length ? (
                <ul data-testid="bordro-candidate-disabled-nedenleri">
                  {candidateGate.disabled_nedenleri.map((neden) => (
                    <li key={neden}>{neden}</li>
                  ))}
                </ul>
              ) : null}
            </>
          ) : null}
          <MaasHesaplamaMerkeziPage />
        </section>
      ) : null}
    </section>
  );
}
