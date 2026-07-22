import { useEffect, useState } from "react";
import {
  dryRunSgkKatalogImport,
  fetchSgkKatalogBlockerRaporu,
  fetchSgkKatalogKaynaklar,
  fetchSgkKatalogSurumler,
  fetchSgkKatalogTamlik,
  previewSgkBildirimDonemi,
  previewSgkKismiSureli,
  validateSgkCokluNeden,
  validateSgkKatalogOnay,
  validateSgkOperasyonelKanit,
  validateSgkSurecEsleme,
  type SgkKatalogBlocker,
  type SgkKatalogBlockerRaporu,
  type SgkKatalogImportDryRun,
  type SgkKatalogTamlik
} from "../../../api/sgk-katalog-hazirlik.api";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";

type SubTab =
  | "tamlik"
  | "kaynaklar"
  | "operasyonel"
  | "import"
  | "esleme"
  | "coklu"
  | "belge"
  | "kismi"
  | "bildirim"
  | "onay";

const SUB_TABS: Array<{ key: SubTab; label: string }> = [
  { key: "tamlik", label: "Tamlık durumu" },
  { key: "kaynaklar", label: "Resmî kaynaklar" },
  { key: "operasyonel", label: "Operasyonel kanıtlar" },
  { key: "import", label: "Import dry-run" },
  { key: "esleme", label: "Süreç eşleme validation" },
  { key: "coklu", label: "Çoklu neden validation" },
  { key: "belge", label: "Belge gereksinimleri" },
  { key: "kismi", label: "Kısmi süreli blocker" },
  { key: "bildirim", label: "Bildirim dönemi blocker" },
  { key: "onay", label: "Onay readiness" }
];

function BlockerList({ items }: { items: SgkKatalogBlocker[] }) {
  if (items.length === 0) {
    return <p data-testid="sgk-katalog-blocker-empty">Blocker yok.</p>;
  }
  return (
    <ul className="yonetim-list" data-testid="sgk-katalog-blocker-list">
      {items.map((item) => (
        <li key={item.code + item.message} data-testid={`sgk-katalog-blocker-${item.code}`}>
          <strong>{item.code}</strong>: {item.message}
          {item.cozum_onerisi ? <div className="muted">Çözüm: {item.cozum_onerisi}</div> : null}
        </li>
      ))}
    </ul>
  );
}

export function SgkKatalogHazirlikPanel() {
  const { hasPermission } = useRoleAccess();
  const canView = hasPermission("bordro_on_izleme.view");
  const canMevzuat = hasPermission("mevzuat_parametreleri.view");
  const canOnayValidate = hasPermission("mevzuat_parametreleri.manage");

  const [subTab, setSubTab] = useState<SubTab>("tamlik");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tamlik, setTamlik] = useState<SgkKatalogTamlik | null>(null);
  const [kaynaklar, setKaynaklar] = useState<Array<Record<string, unknown>>>([]);
  const [surumTotal, setSurumTotal] = useState(0);
  const [blockerRapor, setBlockerRapor] = useState<SgkKatalogBlockerRaporu | null>(null);
  const [importResult, setImportResult] = useState<SgkKatalogImportDryRun | null>(null);
  const [esleme, setEsleme] = useState<Record<string, unknown> | null>(null);
  const [coklu, setCoklu] = useState<Record<string, unknown> | null>(null);
  const [operasyonel, setOperasyonel] = useState<Record<string, unknown> | null>(null);
  const [kismi, setKismi] = useState<Record<string, unknown> | null>(null);
  const [bildirim, setBildirim] = useState<Record<string, unknown> | null>(null);
  const [onay, setOnay] = useState<Record<string, unknown> | null>(null);

  useEffect(() => {
    if (!canView) return;
    let cancelled = false;
    setLoading(true);
    setError(null);
    void (async () => {
      try {
        const [t, k, s, b] = await Promise.all([
          fetchSgkKatalogTamlik(),
          fetchSgkKatalogKaynaklar({ page: 1, limit: 50 }),
          fetchSgkKatalogSurumler(),
          fetchSgkKatalogBlockerRaporu()
        ]);
        if (cancelled) return;
        setTamlik(t);
        setKaynaklar(k.items);
        setSurumTotal(s.total);
        setBlockerRapor(b);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof Error ? err.message : "SGK katalog hazırlık yüklenemedi.");
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [canView]);

  async function runImportDryRun() {
    if (!canMevzuat) return;
    setError(null);
    try {
      setImportResult(await dryRunSgkKatalogImport({ format: "JSON", rows: [] }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Import dry-run başarısız.");
    }
  }

  async function runEsleme() {
    setError(null);
    try {
      setEsleme(await validateSgkSurecEsleme({ surec_turu: "RAPOR", alt_tur: "Raporlu_Hastalik", mappings: [] }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Eşleme validation başarısız.");
    }
  }

  async function runCoklu() {
    setError(null);
    try {
      setCoklu(await validateSgkCokluNeden({ kodlar: ["15", "01"], kurallar: [] }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Çoklu neden validation başarısız.");
    }
  }

  async function runOperasyonel() {
    if (!canMevzuat) return;
    setError(null);
    try {
      setOperasyonel(
        await validateSgkOperasyonelKanit({
          dosya_adi: "ornek-ebildirge.png",
          sha256: "0".repeat(64),
          dosya_erisilebilir_mi: false,
          mevzuat_kaynagi_mi: false
        })
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Operasyonel kanıt validation başarısız.");
    }
  }

  async function runKismi() {
    setError(null);
    try {
      setKismi(await previewSgkKismiSureli({ yazili_kismi_sureli_sozlesme_var_mi: false }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Kısmi süreli preview başarısız.");
    }
  }

  async function runBildirim() {
    setError(null);
    try {
      setBildirim(await previewSgkBildirimDonemi({ bildirim_donem_tipi: "AY_15_SONRAKI_AY_14" }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Bildirim dönemi preview başarısız.");
    }
  }

  async function runOnay() {
    if (!canOnayValidate) return;
    setError(null);
    try {
      setOnay(
        await validateSgkKatalogOnay({
          current_state: "ONAY_BEKLIYOR",
          action: "APPROVE",
          actor_id: 1,
          hazirlayan_id: 1,
          mali_musavir_onayladi_mi: false,
          sirket_onayladi_mi: false
        })
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Onay validation başarısız.");
    }
  }

  if (!canView) {
    return <ErrorState message="SGK Katalog Hazırlık için bordro ön izleme yetkisi gerekir." />;
  }
  if (loading) return <LoadingState label="SGK katalog hazırlık yükleniyor…" />;
  if (error && !tamlik) return <ErrorState message={error} />;

  const blockers = blockerRapor?.blocker_detaylari ?? tamlik?.blocker_detaylari ?? [];

  return (
    <section data-testid="sgk-katalog-hazirlik-panel">
      <header className="yonetim-page-header">
        <h3>SGK Katalog Hazırlık</h3>
        <p data-testid="sgk-katalog-kaynak-tamlik-uyari">
          Kaynak tamlığı tamamlanmadı. Resmî katalog satırları gösterilmez; DOGRULANMIS_TAM seçilemez.
        </p>
      </header>

      {error ? (
        <p className="yonetim-error" data-testid="sgk-katalog-panel-error">
          {error}
        </p>
      ) : null}

      <nav className="raporlar-panel-nav" aria-label="SGK katalog hazırlık alt sekmeleri">
        {SUB_TABS.map((tab) => (
          <button
            key={tab.key}
            type="button"
            className={subTab === tab.key ? "is-active" : undefined}
            data-testid={`sgk-katalog-subtab-${tab.key}`}
            onClick={() => setSubTab(tab.key)}
          >
            {tab.label}
          </button>
        ))}
      </nav>

      {subTab === "tamlik" ? (
        <div data-testid="sgk-katalog-tamlik">
          <p>
            Durum: <strong>{tamlik?.tamlik_durumu ?? "TASLAK"}</strong> · Kod sayısı: {tamlik?.kod_sayisi ?? 0} · Kaynak:{" "}
            {tamlik?.kaynak_sayisi ?? 0} · Sürüm satırı: {surumTotal}
          </p>
          <p>Onaylanabilir mi: {tamlik?.onaylanabilir_mi ? "evet" : "hayır"}</p>
          <p data-testid="sgk-katalog-eksik-kanitlar">Eksik kanıtlar: {(tamlik?.eksik_kanitlar ?? []).join(", ") || "—"}</p>
          <BlockerList items={blockers} />
        </div>
      ) : null}

      {subTab === "kaynaklar" ? (
        <div data-testid="sgk-katalog-kaynaklar">
          {kaynaklar.length === 0 ? (
            <p data-testid="sgk-katalog-kaynak-empty">Resmî kaynak manifest satırı yok / eksik.</p>
          ) : (
            <ul>
              {kaynaklar.map((item) => (
                <li key={String(item.kaynak_id)}>{String(item.kaynak_id)} — {String(item.belge_basligi ?? "")}</li>
              ))}
            </ul>
          )}
        </div>
      ) : null}

      {subTab === "operasyonel" ? (
        <div data-testid="sgk-katalog-operasyonel">
          <p data-testid="sgk-katalog-operasyonel-ayrim">
            Operasyonel kanıt sınıfı: OPERASYONEL_DOGRULAMA_KANITI. Mevzuat kaynağı değildir; tek başına katalog
            tamlığını geçirmez.
          </p>
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-operasyonel-validate" onClick={() => void runOperasyonel()} disabled={!canMevzuat}>
            Metadata doğrula (validation-only)
          </button>
          {operasyonel ? (
            <pre data-testid="sgk-katalog-operasyonel-result">{JSON.stringify(operasyonel, null, 2)}</pre>
          ) : null}
        </div>
      ) : null}

      {subTab === "import" ? (
        <div data-testid="sgk-katalog-import">
          <p>Import yalnız dry-run. Yazma endpointi kapalı.</p>
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-import-dry-run" onClick={() => void runImportDryRun()} disabled={!canMevzuat}>
            Dry-run doğrula
          </button>
          <button type="button" className="universal-btn-secondary" data-testid="sgk-katalog-import-write" disabled>
            Import yaz (kapalı)
          </button>
          {importResult ? (
            <div data-testid="sgk-katalog-import-result">
              <p>payload_hash: {importResult.payload_hash}</p>
              <p>import_yapilabilir_mi: {String(importResult.import_yapilabilir_mi)}</p>
              <BlockerList items={importResult.blocker_detaylari ?? []} />
            </div>
          ) : null}
        </div>
      ) : null}

      {subTab === "esleme" ? (
        <div data-testid="sgk-katalog-esleme">
          <p>Gerçek süreç→kod seed yok; validation fail-closed.</p>
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-esleme-validate" onClick={() => void runEsleme()}>
            Eşleme doğrula
          </button>
          {esleme ? <pre data-testid="sgk-katalog-esleme-result">{JSON.stringify(esleme, null, 2)}</pre> : null}
        </div>
      ) : null}

      {subTab === "coklu" ? (
        <div data-testid="sgk-katalog-coklu">
          <p>Birleşik neden matrisi seed edilmedi.</p>
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-coklu-validate" onClick={() => void runCoklu()}>
            Çoklu neden doğrula
          </button>
          {coklu ? <pre data-testid="sgk-katalog-coklu-result">{JSON.stringify(coklu, null, 2)}</pre> : null}
        </div>
      ) : null}

      {subTab === "belge" ? (
        <div data-testid="sgk-katalog-belge">
          <p data-testid="sgk-katalog-belge-empty">
            Kod×belge matrisi resmi olarak kanıtlanmadı. Belge gereksinimleri katalog seed olmadan gösterilmez.
          </p>
          <BlockerList items={(tamlik?.blocker_detaylari ?? []).filter((b) => b.code === "SGK_KATALOG_TAMLIK_KANITI_EKSIK")} />
        </div>
      ) : null}

      {subTab === "kismi" ? (
        <div data-testid="sgk-katalog-kismi">
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-kismi-preview" onClick={() => void runKismi()}>
            Preview (hesap yok)
          </button>
          {kismi ? <pre data-testid="sgk-katalog-kismi-result">{JSON.stringify(kismi, null, 2)}</pre> : null}
        </div>
      ) : null}

      {subTab === "bildirim" ? (
        <div data-testid="sgk-katalog-bildirim">
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-bildirim-preview" onClick={() => void runBildirim()}>
            Preview (15–14 varsayılmaz)
          </button>
          {bildirim ? <pre data-testid="sgk-katalog-bildirim-result">{JSON.stringify(bildirim, null, 2)}</pre> : null}
        </div>
      ) : null}

      {subTab === "onay" ? (
        <div data-testid="sgk-katalog-onay">
          <p data-testid="sgk-katalog-onay-disabled-note">
            Onay/approve yazma kapalı. DOGRULANMIS_TAM seçeneği sunulmaz.
          </p>
          <button type="button" className="universal-btn-secondary" data-testid="sgk-katalog-approve" disabled>
            Onayla (disabled)
          </button>
          <button
            type="button"
            className="universal-btn-save"
            data-testid="sgk-katalog-onay-validate"
            onClick={() => void runOnay()}
            disabled={!canOnayValidate}
          >
            Transition doğrula
          </button>
          {onay ? <pre data-testid="sgk-katalog-onay-result">{JSON.stringify(onay, null, 2)}</pre> : null}
        </div>
      ) : null}
    </section>
  );
}
