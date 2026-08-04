import { useEffect, useMemo, useState } from "react";
import { ApiRequestError } from "../../../api/api-client";
import {
  approveSgkKatalog,
  approveSgkSirketPolitikasi,
  downloadSgkSirketPolitikasiSablonCsv,
  downloadSgkSurecEslemeSablonCsv,
  dryRunSgkKatalogImport,
  dryRunSgkSirketPolitikasi,
  dryRunSgkSurecEsleme,
  fetchSgkKatalogBlockerRaporu,
  fetchSgkKatalogKaynaklar,
  fetchSgkKatalogSurumler,
  fetchSgkKatalogTamlik,
  importSgkKatalog,
  importSgkSirketPolitikasi,
  importSgkSurecEsleme,
  previewSgkBildirimDonemi,
  previewSgkKismiSureli,
  submitSgkKatalog,
  submitSgkSirketPolitikasi,
  validateSgkCokluNeden,
  validateSgkKatalogOnay,
  validateSgkOperasyonelKanit,
  validateSgkSurecEsleme,
  SGK_AKTIFLIK_DURUMU_LABEL,
  SGK_TAMLIK_DURUMU_LABEL,
  type SgkKatalogBlocker,
  type SgkKatalogBlockerRaporu,
  type SgkKatalogImportDryRun,
  type SgkKatalogTamlik
} from "../../../api/sgk-katalog-hazirlik.api";
import { createSgkManuelKodOverride } from "../../../api/sgk-manuel-kod-override.api";
import { AppActionDialog } from "../../../components/modal/AppActionDialog";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { useAuth } from "../../../state/auth.store";

type SubTab =
  | "tamlik"
  | "kaynaklar"
  | "operasyonel"
  | "import"
  | "esleme"
  | "politika"
  | "coklu"
  | "belge"
  | "kismi"
  | "bildirim"
  | "onay";

type DialogKind =
  | "esleme-draft"
  | "esleme-submit"
  | "esleme-approve"
  | "katalog-import"
  | "politika-draft"
  | "politika-submit"
  | "politika-approve"
  | "manuel-override"
  | null;

const ESLEME_DRAFT_CONFIRM = "SUREC_ESLEME_DRAFT_ONAY";
const POLITIKA_DRAFT_CONFIRM = "SGK_POLITIKA_DRAFT_ONAY";

const DEFAULT_ESLEME_PACKAGE = JSON.stringify(
  { parent_surum_kodu: "", successor_surum_kodu: "", rows: [] },
  null,
  2
);

const DEFAULT_POLITIKA_PACKAGE = JSON.stringify(
  {
    sube_id: 1,
    surum_kodu: "",
    gecerlilik_baslangic: "",
    gecerlilik_bitis: null,
    bildirim_donem_tipi: "AY_15_SONRAKI_AY_14",
    degerler: []
  },
  null,
  2
);

const SUB_TABS: Array<{ key: SubTab; label: string }> = [
  { key: "tamlik", label: "Tamlık durumu" },
  { key: "kaynaklar", label: "Resmî kaynaklar" },
  { key: "operasyonel", label: "Operasyonel kanıtlar" },
  { key: "import", label: "Import dry-run" },
  { key: "esleme", label: "Süreç eşleme" },
  { key: "politika", label: "Şirket SGK politikası" },
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

function parseJsonPackage(text: string): Record<string, unknown> | null {
  try {
    const parsed = JSON.parse(text) as unknown;
    if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
      return null;
    }
    return parsed as Record<string, unknown>;
  } catch {
    return null;
  }
}

function summarizeHataliSatirlar(rows: unknown): string {
  if (!Array.isArray(rows) || rows.length === 0) {
    return "Hatalı satır yok.";
  }
  return rows
    .slice(0, 5)
    .map((row) => {
      const item = row as { row_index?: number; errors?: string[] };
      const errors = Array.isArray(item.errors) ? item.errors.join("; ") : "bilinmeyen hata";
      return `#${item.row_index ?? "?"}: ${errors}`;
    })
    .join(" · ");
}

export function SgkKatalogHazirlikPanel() {
  const { hasPermission } = useRoleAccess();
  const { session } = useAuth();
  const actorId = session?.user?.id ?? null;

  const canView = hasPermission("bordro_on_izleme.view");
  const canMevzuat = hasPermission("mevzuat_parametreleri.view");
  const canOnayValidate = hasPermission("mevzuat_parametreleri.manage");
  const canPrepare = hasPermission("sgk_karar_paketi.prepare");
  const canApprove = hasPermission("sgk_karar_paketi.approve");

  const [subTab, setSubTab] = useState<SubTab>("tamlik");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [tamlik, setTamlik] = useState<SgkKatalogTamlik | null>(null);
  const [kaynaklar, setKaynaklar] = useState<Array<Record<string, unknown>>>([]);
  const [surumTotal, setSurumTotal] = useState(0);
  const [blockerRapor, setBlockerRapor] = useState<SgkKatalogBlockerRaporu | null>(null);
  const [importResult, setImportResult] = useState<SgkKatalogImportDryRun | null>(null);
  const [eslemeValidate, setEslemeValidate] = useState<Record<string, unknown> | null>(null);
  const [coklu, setCoklu] = useState<Record<string, unknown> | null>(null);
  const [operasyonel, setOperasyonel] = useState<Record<string, unknown> | null>(null);
  const [kismi, setKismi] = useState<Record<string, unknown> | null>(null);
  const [bildirim, setBildirim] = useState<Record<string, unknown> | null>(null);
  const [onay, setOnay] = useState<Record<string, unknown> | null>(null);

  const [eslemePackageText, setEslemePackageText] = useState(DEFAULT_ESLEME_PACKAGE);
  const [eslemeDryRun, setEslemeDryRun] = useState<Record<string, unknown> | null>(null);
  const [eslemeSuccessorKodu, setEslemeSuccessorKodu] = useState("");
  const [eslemeSuccessorState, setEslemeSuccessorState] = useState<string | null>(null);
  const [eslemeHazirlayanId, setEslemeHazirlayanId] = useState<number | null>(null);
  const [eslemeActionResult, setEslemeActionResult] = useState<Record<string, unknown> | null>(null);

  const [politikaPackageText, setPolitikaPackageText] = useState(DEFAULT_POLITIKA_PACKAGE);
  const [politikaDryRun, setPolitikaDryRun] = useState<Record<string, unknown> | null>(null);
  const [politikaSurumKodu, setPolitikaSurumKodu] = useState("");
  const [politikaSurumState, setPolitikaSurumState] = useState<string | null>(null);
  const [politikaHazirlayanId, setPolitikaHazirlayanId] = useState<number | null>(null);
  const [politikaActionResult, setPolitikaActionResult] = useState<Record<string, unknown> | null>(null);

  const [dialog, setDialog] = useState<DialogKind>(null);
  const [dialogFieldValue, setDialogFieldValue] = useState("");
  const [dialogSubmitting, setDialogSubmitting] = useState(false);
  const [dialogError, setDialogError] = useState<string | null>(null);

  const [overridePersonelId, setOverridePersonelId] = useState("");
  const [overrideTargetType, setOverrideTargetType] = useState<"SUREC" | "GUNLUK_PUANTAJ">("SUREC");
  const [overrideTargetId, setOverrideTargetId] = useState("");
  const [overrideTarih, setOverrideTarih] = useState("");
  const [overrideYeniKod, setOverrideYeniKod] = useState("");
  const [overrideGerekce, setOverrideGerekce] = useState("");
  const [overrideBelgeId, setOverrideBelgeId] = useState("");
  const [overrideResult, setOverrideResult] = useState<Record<string, unknown> | null>(null);

  const [attestationResmi, setAttestationResmi] = useState(false);
  const [attestationBelirsiz, setAttestationBelirsiz] = useState(false);
  const [attestationKisitli, setAttestationKisitli] = useState(false);

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

  const eslemePreflightBlocker = useMemo(
    () =>
      (blockerRapor?.blocker_detaylari ?? []).find((b) => b.code === "SGK_SUREC_KOD_ESLEMESI_BULUNAMADI") ??
      null,
    [blockerRapor]
  );

  const eslemeApplyReady = eslemeDryRun?.apply_yapilabilir_mi === true;
  const eslemeApproved = eslemeSuccessorState === "ONAYLANDI";
  const politikaImportReady = politikaDryRun?.import_yapilabilir_mi === true;
  const politikaApproved = politikaSurumState === "ONAYLANDI";
  const eslemeSelfApproval =
    actorId !== null && eslemeHazirlayanId !== null && actorId === eslemeHazirlayanId;
  const politikaSelfApproval =
    actorId !== null && politikaHazirlayanId !== null && actorId === politikaHazirlayanId;

  function openDialog(kind: DialogKind) {
    setDialog(kind);
    setDialogFieldValue("");
    setDialogError(null);
  }

  function closeDialog() {
    if (dialogSubmitting) return;
    setDialog(null);
    setDialogFieldValue("");
    setDialogError(null);
  }

  async function runImportDryRun() {
    if (!canMevzuat) return;
    setError(null);
    try {
      setImportResult(await dryRunSgkKatalogImport({ format: "JSON", rows: [] }));
    } catch (err) {
      setError(err instanceof Error ? err.message : "Import dry-run başarısız.");
    }
  }

  async function runEslemeValidate() {
    setError(null);
    try {
      setEslemeValidate(
        await validateSgkSurecEsleme({ surec_turu: "RAPOR", alt_tur: "Raporlu_Hastalik", mappings: [] })
      );
    } catch (err) {
      setError(err instanceof Error ? err.message : "Eşleme validation başarısız.");
    }
  }

  async function runEslemeDryRun() {
    if (!canMevzuat) return;
    const body = parseJsonPackage(eslemePackageText);
    if (!body) {
      setError("Süreç eşleme paketi geçerli JSON olmalı.");
      return;
    }
    setError(null);
    setEslemeActionResult(null);
    try {
      const result = await dryRunSgkSurecEsleme(body);
      setEslemeDryRun(result);
      const successor = String(body.successor_surum_kodu ?? "");
      if (successor) {
        setEslemeSuccessorKodu(successor);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Süreç eşleme dry-run başarısız.");
    }
  }

  async function runPolitikaDryRun() {
    if (!canMevzuat) return;
    const body = parseJsonPackage(politikaPackageText);
    if (!body) {
      setError("Politika paketi geçerli JSON olmalı.");
      return;
    }
    setError(null);
    setPolitikaActionResult(null);
    try {
      const result = await dryRunSgkSirketPolitikasi(body);
      setPolitikaDryRun(result);
      const canonical = result.canonical_payload as Record<string, unknown> | null | undefined;
      const surumKodu = String(body.surum_kodu ?? canonical?.surum_kodu ?? "");
      if (surumKodu) {
        setPolitikaSurumKodu(surumKodu);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "Politika dry-run başarısız.");
    }
  }

  async function handleEslemeFile(file: File) {
    const text = await file.text();
    const parsed = parseJsonPackage(text);
    if (!parsed) {
      setError("Yüklenen dosya geçerli JSON paketi olmalı.");
      return;
    }
    setEslemePackageText(JSON.stringify(parsed, null, 2));
    setError(null);
  }

  async function handlePolitikaFile(file: File) {
    const text = await file.text();
    const parsed = parseJsonPackage(text);
    if (!parsed) {
      setError("Yüklenen dosya geçerli JSON paketi olmalı.");
      return;
    }
    setPolitikaPackageText(JSON.stringify(parsed, null, 2));
    setError(null);
  }

  async function runDialogAction() {
    if (!dialog) return;
    setDialogSubmitting(true);
    setDialogError(null);
    try {
      if (dialog === "esleme-draft") {
        if (dialogFieldValue.trim() !== ESLEME_DRAFT_CONFIRM) {
          setDialogError(`Onay metni tam olarak ${ESLEME_DRAFT_CONFIRM} olmalıdır.`);
          return;
        }
        const body = parseJsonPackage(eslemePackageText);
        if (!body || !eslemeDryRun) {
          setDialogError("Önce geçerli dry-run sonucu alın.");
          return;
        }
        const result = await importSgkSurecEsleme({
          ...body,
          confirmation_text: ESLEME_DRAFT_CONFIRM,
          esleme_payload_hash: eslemeDryRun.esleme_payload_hash
        });
        setEslemeActionResult(result);
        const kod = String(result.surum_kodu ?? body.successor_surum_kodu ?? "");
        setEslemeSuccessorKodu(kod);
        setEslemeSuccessorState(String(result.state ?? "TASLAK"));
        setEslemeHazirlayanId(actorId);
        closeDialog();
      } else if (dialog === "esleme-submit") {
        const kod = eslemeSuccessorKodu.trim();
        if (!kod) {
          setDialogError("Successor sürüm kodu gerekli.");
          return;
        }
        const result = await submitSgkKatalog({ surum_kodu: kod });
        setEslemeActionResult(result);
        setEslemeSuccessorState(String(result.state ?? "ONAY_BEKLIYOR"));
        closeDialog();
      } else if (dialog === "esleme-approve") {
        const kod = eslemeSuccessorKodu.trim();
        if (!kod) {
          setDialogError("Successor sürüm kodu gerekli.");
          return;
        }
        const result = await approveSgkKatalog({
          surum_kodu: kod,
          resmi_kaynaklar_incelendi_mi: attestationResmi,
          belirsiz_tarihler_uydurulmadi_mi: attestationBelirsiz,
          kisitli_kullanim_kabul_edildi_mi: attestationKisitli
        });
        setEslemeActionResult(result);
        setEslemeSuccessorState(String(result.state ?? "ONAYLANDI"));
        closeDialog();
      } else if (dialog === "katalog-import") {
        if (!importResult?.import_yapilabilir_mi) {
          setDialogError("Import dry-run import_yapilabilir_mi=false.");
          return;
        }
        const result = await importSgkKatalog({
          format: "JSON",
          rows: importResult.canonical_payload.rows,
          payload_hash: importResult.payload_hash,
          manifest_set_hash: importResult.manifest_set_hash
        });
        setEslemeActionResult(result);
        closeDialog();
      } else if (dialog === "politika-draft") {
        if (dialogFieldValue.trim() !== POLITIKA_DRAFT_CONFIRM) {
          setDialogError(`Onay metni tam olarak ${POLITIKA_DRAFT_CONFIRM} olmalıdır.`);
          return;
        }
        const body = parseJsonPackage(politikaPackageText);
        if (!body || !politikaDryRun) {
          setDialogError("Önce geçerli dry-run sonucu alın.");
          return;
        }
        const result = await importSgkSirketPolitikasi({
          ...body,
          confirmation_text: POLITIKA_DRAFT_CONFIRM,
          politika_hash: politikaDryRun.politika_hash
        });
        setPolitikaActionResult(result);
        setPolitikaSurumKodu(String(result.surum_kodu ?? body.surum_kodu ?? ""));
        setPolitikaSurumState(String(result.state ?? "TASLAK"));
        setPolitikaHazirlayanId(actorId);
        closeDialog();
      } else if (dialog === "politika-submit") {
        const kod = politikaSurumKodu.trim();
        if (!kod) {
          setDialogError("Politika sürüm kodu gerekli.");
          return;
        }
        const result = await submitSgkSirketPolitikasi({
          surum_kodu: kod,
          politika_hash: politikaDryRun?.politika_hash
        });
        setPolitikaActionResult(result);
        setPolitikaSurumState(String(result.state ?? "ONAY_BEKLIYOR"));
        closeDialog();
      } else if (dialog === "politika-approve") {
        const kod = politikaSurumKodu.trim();
        if (!kod) {
          setDialogError("Politika sürüm kodu gerekli.");
          return;
        }
        const result = await approveSgkSirketPolitikasi({
          surum_kodu: kod,
          politika_hash: politikaDryRun?.politika_hash
        });
        setPolitikaActionResult(result);
        setPolitikaSurumState(String(result.state ?? "ONAYLANDI"));
        closeDialog();
      } else if (dialog === "manuel-override") {
        const personelId = Number.parseInt(overridePersonelId, 10);
        const targetId = Number.parseInt(overrideTargetId, 10);
        const belgeId = Number.parseInt(overrideBelgeId, 10);
        if (!personelId || !targetId || !belgeId || !overrideTarih || !overrideYeniKod.trim() || !overrideGerekce.trim()) {
          setDialogError("Tüm zorunlu alanları doldurun.");
          return;
        }
        const idempotencyKey = `sgk-mko-${overrideTargetType}-${targetId}-${Date.now()}`;
        const result = await createSgkManuelKodOverride({
          target_type: overrideTargetType,
          target_id: targetId,
          personel_id: personelId,
          tarih: overrideTarih,
          yeni_eksik_gun_kodu: overrideYeniKod.trim(),
          gerekce: overrideGerekce.trim(),
          belge_id: belgeId,
          idempotency_key: idempotencyKey
        });
        setOverrideResult(result as unknown as Record<string, unknown>);
        closeDialog();
      }
    } catch (err) {
      const message =
        err instanceof ApiRequestError
          ? `${err.code ?? "HATA"}: ${err.message}`
          : err instanceof Error
            ? err.message
            : "İşlem başarısız.";
      setDialogError(message);
    } finally {
      setDialogSubmitting(false);
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
          actor_id: actorId ?? 1,
          resmi_kaynaklar_incelendi_mi: false,
          belirsiz_tarihler_uydurulmadi_mi: false,
          kisitli_kullanim_kabul_edildi_mi: false
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
  const tamlikDurumu = (tamlik?.tamlik_durumu ?? "TASLAK") as keyof typeof SGK_TAMLIK_DURUMU_LABEL;
  const kisitliOnayli = tamlikDurumu === "RESMI_KAYNAKLI_KISITLI";
  const importWriteAktif = tamlik?.import_yazma_aktif_mi === true;
  const approveAktif = tamlik?.approve_aktif_mi === true;

  return (
    <section data-testid="sgk-katalog-hazirlik-panel">
      <header className="yonetim-page-header">
        <h3>SGK Katalog Hazırlık</h3>
        {kisitliOnayli ? (
          <p data-testid="sgk-katalog-kisitli-badge" className="yonetim-badge">
            {SGK_TAMLIK_DURUMU_LABEL.RESMI_KAYNAKLI_KISITLI}: Resmî kod/ad doğrulandı; bazı kod bazlı tarih ve kullanım
            ayrıntıları belirsizdir.
          </p>
        ) : (
          <p data-testid="sgk-katalog-kaynak-tamlik-uyari">
            Kaynak tamlığı tamamlanmadı. Resmî katalog satırları gösterilmez; DOGRULANMIS_TAM seçilemez.
            TEYITSIZ ve tarihsel kodlar güncel kayıt ekranında seçilemez.
          </p>
        )}
        {kisitliOnayli ? (
          <p data-testid="sgk-katalog-kisitli-tarih-uyari" className="muted">
            Geçerlilik başlangıcı yoksa: Belirlenemedi. TEYITSIZ/KOSULLU alanlar otomatik izin sayılmaz; belirsiz
            kuralda MANUEL_INCELEME uygulanır.
          </p>
        ) : null}
        <p data-testid="sgk-katalog-aktiflik-etiketleri" className="muted">
          Aktiflik: {Object.values(SGK_AKTIFLIK_DURUMU_LABEL).join(" · ")}
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
            Durum: <strong data-testid="sgk-katalog-tamlik-durumu">{SGK_TAMLIK_DURUMU_LABEL[tamlikDurumu] ?? tamlikDurumu}</strong> · Kod sayısı: {tamlik?.kod_sayisi ?? 0} · Kaynak:{" "}
            {tamlik?.kaynak_sayisi ?? 0} · Sürüm satırı: {surumTotal}
          </p>
          <p>Onaylanabilir mi: {tamlik?.onaylanabilir_mi ? "evet" : "hayır"}</p>
          <p data-testid="sgk-katalog-dogrulanmis-tam-note" className="muted">
            DOGRULANMIS_TAM: {tamlik?.dogrulanmis_tam_secilebilir_mi ? "seçilebilir" : "seçilemez (tam kanıt gerekir)"}
          </p>
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
          <p>Import önce dry-run ile doğrulanır. Yazma {importWriteAktif ? "tamlık izin veriyorsa aktif" : "kapalı"}.</p>
          <button type="button" className="universal-btn-save" data-testid="sgk-katalog-import-dry-run" onClick={() => void runImportDryRun()} disabled={!canMevzuat}>
            Dry-run doğrula
          </button>
          <button
            type="button"
            className="universal-btn-secondary"
            data-testid="sgk-katalog-import-write"
            disabled={!importWriteAktif || !canPrepare || !importResult?.import_yapilabilir_mi}
            onClick={() => openDialog("katalog-import")}
          >
            Import yaz {importWriteAktif && canPrepare ? "(prepare)" : "(kapalı)"}
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
          <p data-testid="sgk-esleme-immutable-note" className="muted">
            Onaylanmış katalog sürümü (parent) değiştirilemez; eşleme yalnızca successor TASLAK sürümüne yazılır.
          </p>
          <p data-testid="sgk-esleme-decision-rules-note" className="muted">
            Karar kuralları: Kod kullanılmaz (DAHIL) · Ücret modeline göre · Ücret kesilsin mi seçimine göre ·
            Olay nedeninden türet · Yazılı kısmi sözleşme gerekli. DUSUR için kod zorunlu; DAHIL ile kod çelişki.
          </p>
          {eslemePreflightBlocker ? (
            <p data-testid="sgk-esleme-preflight-note" className="yonetim-error">
              Preflight: {eslemePreflightBlocker.message}
            </p>
          ) : null}
          <p data-testid="sgk-esleme-dual-control-note" className="muted">
            Onay adımında hazırlayan farklı olmalı; aynı kullanıcı kendi successor sürümünü onaylayamaz.
          </p>
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="sgk-esleme-sablon-download"
              disabled={!canMevzuat}
              onClick={() => void downloadSgkSurecEslemeSablonCsv().catch((err) => setError(err instanceof Error ? err.message : "Şablon indirilemedi."))}
            >
              Süreç Eşleme Şablonunu İndir
            </button>
            <button type="button" className="universal-btn-save" data-testid="sgk-katalog-esleme-validate" onClick={() => void runEslemeValidate()}>
              Eşleme doğrula (legacy)
            </button>
          </div>
          <label className="form-label" htmlFor="sgk-esleme-package">
            Eşleme paketi (JSON)
          </label>
          <textarea
            id="sgk-esleme-package"
            className="form-input"
            rows={8}
            data-testid="sgk-esleme-package-input"
            value={eslemePackageText}
            onChange={(event) => setEslemePackageText(event.target.value)}
          />
          <input
            type="file"
            accept=".json,application/json,text/csv,.csv"
            data-testid="sgk-esleme-package-file"
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (file) void handleEslemeFile(file);
              event.target.value = "";
            }}
          />
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-save"
              data-testid="sgk-esleme-dry-run"
              disabled={!canMevzuat}
              onClick={() => void runEslemeDryRun()}
            >
              Dry-run
            </button>
            <button
              type="button"
              className="universal-btn-secondary"
              data-testid="sgk-esleme-draft"
              disabled={!canPrepare || !eslemeApplyReady || eslemeApproved}
              onClick={() => openDialog("esleme-draft")}
            >
              TASLAK import
            </button>
            <button
              type="button"
              className="universal-btn-secondary"
              data-testid="sgk-esleme-submit"
              disabled={!canPrepare || !eslemeSuccessorKodu || eslemeApproved || eslemeSuccessorState === "ONAY_BEKLIYOR"}
              onClick={() => openDialog("esleme-submit")}
            >
              Submit successor
            </button>
            <button
              type="button"
              className="universal-btn-secondary"
              data-testid="sgk-esleme-approve"
              disabled={
                !canApprove ||
                eslemeSelfApproval ||
                eslemeApproved ||
                eslemeSuccessorState !== "ONAY_BEKLIYOR" ||
                !attestationResmi ||
                !attestationBelirsiz ||
                !attestationKisitli
              }
              onClick={() => openDialog("esleme-approve")}
            >
              Approve successor
            </button>
          </div>
          <div className="form-field-grid">
            <label>
              <input type="checkbox" data-testid="sgk-esleme-attest-resmi" checked={attestationResmi} onChange={(e) => setAttestationResmi(e.target.checked)} /> Resmî kaynaklar incelendi
            </label>
            <label>
              <input type="checkbox" data-testid="sgk-esleme-attest-belirsiz" checked={attestationBelirsiz} onChange={(e) => setAttestationBelirsiz(e.target.checked)} /> Belirsiz tarihler uydurulmadı
            </label>
            <label>
              <input type="checkbox" data-testid="sgk-esleme-attest-kisitli" checked={attestationKisitli} onChange={(e) => setAttestationKisitli(e.target.checked)} /> Kısıtlı kullanım kabul edildi
            </label>
          </div>
          {eslemeDryRun ? (
            <div data-testid="sgk-esleme-dry-run-result">
              <p>esleme_payload_hash: {String(eslemeDryRun.esleme_payload_hash ?? "—")}</p>
              <p>apply_yapilabilir_mi: {String(eslemeDryRun.apply_yapilabilir_mi ?? false)}</p>
              <p data-testid="sgk-esleme-hatali-summary">{summarizeHataliSatirlar(eslemeDryRun.hatali_satirlar)}</p>
            </div>
          ) : null}
          {eslemeValidate ? <pre data-testid="sgk-katalog-esleme-result">{JSON.stringify(eslemeValidate, null, 2)}</pre> : null}
          {eslemeActionResult ? (
            <pre data-testid="sgk-esleme-action-result">{JSON.stringify(eslemeActionResult, null, 2)}</pre>
          ) : null}
          {eslemeSuccessorState ? (
            <p data-testid="sgk-esleme-successor-state">
              Successor state: {eslemeSuccessorState}
              {eslemeSuccessorKodu ? ` · ${eslemeSuccessorKodu}` : ""}
            </p>
          ) : null}
        </div>
      ) : null}

      {subTab === "politika" ? (
        <div data-testid="sgk-katalog-politika">
          <p data-testid="sgk-politika-scope-note" className="muted">
            Şirket SGK politikası şube kapsamında yönetilir; sayfa açılışında otomatik yazma yapılmaz. Onaylı politika
            sürümü değiştirilemez.
          </p>
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-aux"
              data-testid="sgk-politika-sablon-download"
              disabled={!canMevzuat}
              onClick={() =>
                void downloadSgkSirketPolitikasiSablonCsv().catch((err) =>
                  setError(err instanceof Error ? err.message : "Şablon indirilemedi.")
                )
              }
            >
              Politika Şablonunu İndir
            </button>
          </div>
          <label className="form-label" htmlFor="sgk-politika-package">
            Politika paketi (JSON)
          </label>
          <textarea
            id="sgk-politika-package"
            className="form-input"
            rows={8}
            data-testid="sgk-politika-package-input"
            value={politikaPackageText}
            onChange={(event) => setPolitikaPackageText(event.target.value)}
          />
          <input
            type="file"
            accept=".json,application/json"
            data-testid="sgk-politika-package-file"
            onChange={(event) => {
              const file = event.target.files?.[0];
              if (file) void handlePolitikaFile(file);
              event.target.value = "";
            }}
          />
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-save"
              data-testid="sgk-politika-dry-run"
              disabled={!canMevzuat}
              onClick={() => void runPolitikaDryRun()}
            >
              Dry-run
            </button>
            <button
              type="button"
              className="universal-btn-secondary"
              data-testid="sgk-politika-draft"
              disabled={!canPrepare || !politikaImportReady || politikaApproved}
              onClick={() => openDialog("politika-draft")}
            >
              TASLAK import
            </button>
            <button
              type="button"
              className="universal-btn-secondary"
              data-testid="sgk-politika-submit"
              disabled={!canPrepare || !politikaSurumKodu || politikaApproved || politikaSurumState === "ONAY_BEKLIYOR"}
              onClick={() => openDialog("politika-submit")}
            >
              Submit
            </button>
            <button
              type="button"
              className="universal-btn-secondary"
              data-testid="sgk-politika-approve"
              disabled={
                !canApprove ||
                politikaSelfApproval ||
                politikaApproved ||
                politikaSurumState !== "ONAY_BEKLIYOR"
              }
              onClick={() => openDialog("politika-approve")}
            >
              Approve
            </button>
          </div>
          {politikaDryRun ? (
            <div data-testid="sgk-politika-dry-run-result">
              <p>politika_hash: {String(politikaDryRun.politika_hash ?? "—")}</p>
              <p>import_yapilabilir_mi: {String(politikaDryRun.import_yapilabilir_mi ?? false)}</p>
              <p data-testid="sgk-politika-hatali-summary">{summarizeHataliSatirlar(politikaDryRun.hatali_satirlar)}</p>
            </div>
          ) : null}
          {politikaActionResult ? (
            <pre data-testid="sgk-politika-action-result">{JSON.stringify(politikaActionResult, null, 2)}</pre>
          ) : null}
          {politikaSurumState ? (
            <p data-testid="sgk-politika-surum-state">
              Politika state: {politikaSurumState}
              {politikaSurumKodu ? ` · ${politikaSurumKodu}` : ""}
            </p>
          ) : null}
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
          <div className="sgk-manuel-override-form" data-testid="sgk-manuel-kod-override-form">
            <h4>SGK manuel kod override</h4>
            <label>
              Personel ID
              <input value={overridePersonelId} onChange={(e) => setOverridePersonelId(e.target.value)} data-testid="sgk-override-personel-id" />
            </label>
            <label>
              Hedef türü
              <select value={overrideTargetType} onChange={(e) => setOverrideTargetType(e.target.value as "SUREC" | "GUNLUK_PUANTAJ")} data-testid="sgk-override-target-type">
                <option value="SUREC">SUREC</option>
                <option value="GUNLUK_PUANTAJ">GUNLUK_PUANTAJ (mühür satır id)</option>
              </select>
            </label>
            <label>
              Hedef ID
              <input value={overrideTargetId} onChange={(e) => setOverrideTargetId(e.target.value)} data-testid="sgk-override-target-id" />
            </label>
            <label>
              Tarih
              <input type="date" value={overrideTarih} onChange={(e) => setOverrideTarih(e.target.value)} data-testid="sgk-override-tarih" />
            </label>
            <label>
              Yeni eksik gün kodu
              <input value={overrideYeniKod} onChange={(e) => setOverrideYeniKod(e.target.value)} data-testid="sgk-override-yeni-kod" />
            </label>
            <label>
              Gerekçe
              <textarea value={overrideGerekce} onChange={(e) => setOverrideGerekce(e.target.value)} data-testid="sgk-override-gerekce" />
            </label>
            <label>
              Belge ID (doğrulanmış SGK belgesi)
              <input value={overrideBelgeId} onChange={(e) => setOverrideBelgeId(e.target.value)} data-testid="sgk-override-belge-id" />
            </label>
            <button
              type="button"
              className="universal-btn-save"
              data-testid="sgk-manuel-kod-override-open"
              onClick={() => openDialog("manuel-override")}
            >
              Manuel override kaydet
            </button>
            {overrideResult ? <pre data-testid="sgk-manuel-kod-override-result">{JSON.stringify(overrideResult, null, 2)}</pre> : null}
          </div>
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
            Onay/approve {approveAktif ? "tamlık izin veriyorsa mümkün" : "kapalı"}. DOGRULANMIS_TAM seçeneği sunulmaz.
          </p>
          <button type="button" className="universal-btn-secondary" data-testid="sgk-katalog-approve" disabled={!approveAktif || !canApprove}>
            Onayla {approveAktif && canApprove ? "(attestation gerekir)" : "(disabled)"}
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

      {dialog === "esleme-draft" ? (
        <AppActionDialog
          open
          testId="sgk-esleme-draft-dialog"
          title="Süreç Eşleme TASLAK Import"
          description={`Onay metni olarak tam ${ESLEME_DRAFT_CONFIRM} yazın. Parent katalog değişmez.`}
          confirmLabel="TASLAK kaydet"
          submitLabel="Kaydediliyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          field={{
            label: "Onay metni",
            value: dialogFieldValue,
            onChange: setDialogFieldValue,
            required: true,
            placeholder: ESLEME_DRAFT_CONFIRM,
            testId: "sgk-esleme-draft-confirm-field"
          }}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "esleme-submit" ? (
        <AppActionDialog
          open
          testId="sgk-esleme-submit-dialog"
          title="Successor Katalog Submit"
          description={`${eslemeSuccessorKodu || "—"} sürümü ONAY_BEKLIYOR durumuna gönderilecek.`}
          confirmLabel="Submit"
          submitLabel="Gönderiliyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "esleme-approve" ? (
        <AppActionDialog
          open
          testId="sgk-esleme-approve-dialog"
          title="Successor Katalog Onay"
          description="Onaylayan hazırlayan farklı olmalı. Attestation bayrakları gönderilecek."
          confirmLabel="Onayla"
          submitLabel="Onaylanıyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "katalog-import" ? (
        <AppActionDialog
          open
          testId="sgk-katalog-import-dialog"
          title="Katalog Import Yaz"
          description="Dry-run payload hash ile katalog import yazılacak."
          confirmLabel="Import yaz"
          submitLabel="Yazılıyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "politika-draft" ? (
        <AppActionDialog
          open
          testId="sgk-politika-draft-dialog"
          title="Şirket SGK Politikası TASLAK Import"
          description={`Onay metni olarak tam ${POLITIKA_DRAFT_CONFIRM} yazın.`}
          confirmLabel="TASLAK kaydet"
          submitLabel="Kaydediliyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          field={{
            label: "Onay metni",
            value: dialogFieldValue,
            onChange: setDialogFieldValue,
            required: true,
            placeholder: POLITIKA_DRAFT_CONFIRM,
            testId: "sgk-politika-draft-confirm-field"
          }}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "politika-submit" ? (
        <AppActionDialog
          open
          testId="sgk-politika-submit-dialog"
          title="Politika Submit"
          description={`${politikaSurumKodu || "—"} ONAY_BEKLIYOR durumuna gönderilecek.`}
          confirmLabel="Submit"
          submitLabel="Gönderiliyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "politika-approve" ? (
        <AppActionDialog
          open
          testId="sgk-politika-approve-dialog"
          title="Politika Onay"
          description="Onaylayan hazırlayan farklı olmalı."
          confirmLabel="Onayla"
          submitLabel="Onaylanıyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}

      {dialog === "manuel-override" ? (
        <AppActionDialog
          open
          testId="sgk-manuel-kod-override-dialog"
          title="SGK Manuel Kod Override"
          description="Yetkili manuel eksik gün kodu override kaydı oluşturulacak. Önceki aktif kayıt SUPERSEDED olur."
          confirmLabel="Kaydet"
          submitLabel="Kaydediliyor..."
          isSubmitting={dialogSubmitting}
          errorMessage={dialogError}
          onConfirm={() => void runDialogAction()}
          onCancel={closeDialog}
        />
      ) : null}
    </section>
  );
}
