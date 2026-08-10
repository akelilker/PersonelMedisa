import { useCallback, useEffect, useState, type FormEvent } from "react";
import { getApiErrorMessage } from "../../../api/api-client";
import {
  fetchDisiplinVakalarList,
  ikIncelemeDisiplinVaka,
  islemsizKapatDisiplinVaka,
  nihaiKararDisiplinVaka,
  savunmaBelgeDisiplinVaka,
  savunmaTalepDisiplinVaka
} from "../../../api/disiplin-vakalar.api";
import { FormField } from "../../../components/form/FormField";
import { ErrorState } from "../../../components/states/ErrorState";
import { LoadingState } from "../../../components/states/LoadingState";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { DisiplinNihaiKarar, DisiplinVaka } from "../../../types/disiplin-vaka";

const LIFECYCLE_LABELS: Record<string, string> = {
  INCELEME_ADAYI: "İnceleme Adayı",
  IK_INCELEME: "İK İnceleme",
  SAVUNMA_BEKLENIYOR: "Savunma Bekleniyor",
  SAVUNMA_ALINDI: "Savunma Alındı",
  SAVUNMA_SUNULMADI: "Savunma Sunulmadı",
  KARAR_BEKLIYOR: "Karar Bekliyor",
  KARAR_VERILDI: "Karar Verildi",
  KAPANDI: "Kapandı",
  ISLEMSIZ_KAPATILDI: "İşlemsiz Kapatıldı"
};

const OLAY_TURU_LABELS: Record<string, string> = {
  HABERSIZ_GEC_KALMA: "Habersiz Geç Kalma",
  HABERSIZ_TAM_GUN_DEVAMSIZLIK: "Habersiz Tam Gün Devamsızlık",
  AYLIK_TEKRARLAYAN_GEC_KALMA: "Aylık Tekrarlayan Geç Kalma",
  GEC_KALMA: "Geç Kalma",
  TAM_GUN_DEVAMSIZLIK: "Tam Gün Devamsızlık"
};

const NIHAI_KARAR_OPTIONS: { value: DisiplinNihaiKarar; label: string }[] = [
  { value: "NO_ACTION", label: "İşlem Yok" },
  { value: "UYARI", label: "Uyarı" },
  { value: "CEZA", label: "Ceza" }
];

function formatLifecycleLabel(state: string) {
  return LIFECYCLE_LABELS[state] ?? state;
}

function formatOlayTuruLabel(value: string) {
  return OLAY_TURU_LABELS[value] ?? value;
}

function toDatetimeLocalValue(value: string | null) {
  if (!value) {
    return "";
  }
  return value.replace("T", " ").slice(0, 19);
}

function fromDatetimeLocalValue(value: string) {
  const trimmed = value.trim();
  if (!trimmed) {
    return "";
  }
  if (trimmed.includes("T")) {
    return trimmed.length === 16 ? `${trimmed.replace("T", " ")}:00` : trimmed.replace("T", " ");
  }
  return trimmed;
}

export function DisiplinVakaPanel({ surecId }: { surecId: number }) {
  const { hasPermission } = useRoleAccess();
  const canReview = hasPermission("disiplin.review");
  const canDefenseManage = hasPermission("disiplin.defense_manage");
  const canFinalDecision = hasPermission("disiplin.final_decision");
  const canCloseNoAction = canFinalDecision;

  const [vaka, setVaka] = useState<DisiplinVaka | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [savunmaDeadline, setSavunmaDeadline] = useState("");
  const [savunmaYer, setSavunmaYer] = useState("");
  const [savunmaKonu, setSavunmaKonu] = useState("");
  const [belgeSurecId, setBelgeSurecId] = useState("");
  const [nihaiKarar, setNihaiKarar] = useState<DisiplinNihaiKarar>("NO_ACTION");
  const [nihaiGerekce, setNihaiGerekce] = useState("");
  const [closeGerekce, setCloseGerekce] = useState("");

  const loadVaka = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const items = await fetchDisiplinVakalarList({ surec_id: surecId, open_only: 0 });
      const matched = items.find((item) => item.surec_id === surecId) ?? items[0] ?? null;
      setVaka(matched);
      if (matched) {
        setSavunmaDeadline(toDatetimeLocalValue(matched.savunma_deadline_at));
        setSavunmaYer(matched.savunma_yer ?? "");
        setSavunmaKonu(matched.savunma_konu ?? "");
      }
    } catch (error) {
      setErrorMessage(getApiErrorMessage(error, "Disiplin vakası yüklenemedi."));
      setVaka(null);
    } finally {
      setIsLoading(false);
    }
  }, [surecId]);

  useEffect(() => {
    void loadVaka();
  }, [loadVaka]);

  async function runAction(action: () => Promise<void>) {
    setActionError(null);
    setIsSubmitting(true);
    try {
      await action();
      await loadVaka();
    } catch (error) {
      setActionError(getApiErrorMessage(error, "Disiplin işlemi tamamlanamadı."));
    } finally {
      setIsSubmitting(false);
    }
  }

  function handleIkInceleme() {
    void runAction(async () => {
      if (!vaka) {
        return;
      }
      const updated = await ikIncelemeDisiplinVaka(vaka.id);
      setVaka(updated);
    });
  }

  function handleSavunmaTalep(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void runAction(async () => {
      if (!vaka) {
        return;
      }
      const updated = await savunmaTalepDisiplinVaka(vaka.id, {
        deadline_at: fromDatetimeLocalValue(savunmaDeadline),
        yer: savunmaYer.trim(),
        konu: savunmaKonu.trim()
      });
      setVaka(updated);
    });
  }

  function handleSavunmaBelge(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void runAction(async () => {
      if (!vaka) {
        return;
      }
      const parsed = Number.parseInt(belgeSurecId, 10);
      if (Number.isNaN(parsed) || parsed < 1) {
        throw new Error("Geçerli bir belge süreç ID girin.");
      }
      const updated = await savunmaBelgeDisiplinVaka(vaka.id, { belge_surec_id: parsed });
      setVaka(updated);
    });
  }

  function handleNihaiKarar(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    void runAction(async () => {
      if (!vaka) {
        return;
      }
      const updated = await nihaiKararDisiplinVaka(vaka.id, {
        nihai_karar: nihaiKarar,
        gerekce: nihaiGerekce.trim() || undefined
      });
      setVaka(updated);
    });
  }

  function handleIslemsizKapat() {
    void runAction(async () => {
      if (!vaka) {
        return;
      }
      const updated = await islemsizKapatDisiplinVaka(vaka.id, closeGerekce.trim() || undefined);
      setVaka(updated);
    });
  }

  if (isLoading) {
    return <LoadingState label="Disiplin vakası yükleniyor..." />;
  }

  if (errorMessage) {
    return <ErrorState message={errorMessage} onRetry={() => void loadVaka()} />;
  }

  if (!vaka) {
    return (
      <div className="surec-detail-card">
        <p>Bu süreç için disiplin vakası kaydı bulunamadı.</p>
      </div>
    );
  }

  const isTerminal =
    vaka.lifecycle_state === "KAPANDI" || vaka.lifecycle_state === "ISLEMSIZ_KAPATILDI";

  return (
    <div className="surec-detail-card" data-testid="disiplin-vaka-panel">
      <h3>Disiplin Vakası</h3>
      <p>
        <strong>Olay Türü:</strong> {formatOlayTuruLabel(vaka.olay_turu)}
      </p>
      <p>
        <strong>Yaşam Döngüsü:</strong> {formatLifecycleLabel(vaka.lifecycle_state)}
      </p>
      <p>
        <strong>Ham Dakika:</strong> {vaka.raw_dakika ?? "-"}
      </p>
      <p>
        <strong>Savunma Son Tarih:</strong> {vaka.savunma_deadline_at ?? "-"}
      </p>
      <p>
        <strong>Savunma Yeri:</strong> {vaka.savunma_yer ?? "-"}
      </p>
      <p>
        <strong>Savunma Konusu:</strong> {vaka.savunma_konu ?? "-"}
      </p>
      <p>
        <strong>Nihai Karar:</strong> {vaka.nihai_karar ?? "-"}
      </p>
      {vaka.nihai_karar_gerekce ? (
        <p>
          <strong>Gerekçe:</strong> {vaka.nihai_karar_gerekce}
        </p>
      ) : null}

      {actionError ? <p className="surec-form-error">{actionError}</p> : null}

      {!isTerminal && canReview ? (
        <div className="form-actions-row">
          <button
            type="button"
            className="universal-btn-aux"
            disabled={isSubmitting}
            onClick={handleIkInceleme}
          >
            İK İnceleme
          </button>
        </div>
      ) : null}

      {!isTerminal && canDefenseManage ? (
        <form className="surec-form-grid" onSubmit={handleSavunmaTalep}>
          <FormField
            label="Savunma Son Tarih"
            name="disiplin-savunma-deadline"
            type="text"
            placeholder="YYYY-MM-DD HH:MM:SS"
            value={savunmaDeadline}
            onChange={setSavunmaDeadline}
            required
          />
          <FormField
            label="Savunma Yeri"
            name="disiplin-savunma-yer"
            value={savunmaYer}
            onChange={setSavunmaYer}
            required
          />
          <FormField
            label="Savunma Konusu"
            name="disiplin-savunma-konu"
            value={savunmaKonu}
            onChange={setSavunmaKonu}
            required
          />
          <div className="form-actions-row">
            <button type="submit" className="universal-btn-save" disabled={isSubmitting}>
              Savunma Talep Et
            </button>
          </div>
        </form>
      ) : null}

      {!isTerminal && canDefenseManage ? (
        <form className="surec-form-grid" onSubmit={handleSavunmaBelge}>
          <FormField
            label="Belge Süreç ID"
            name="disiplin-belge-surec-id"
            type="number"
            min={1}
            value={belgeSurecId}
            onChange={setBelgeSurecId}
            required
          />
          <div className="form-actions-row">
            <button type="submit" className="universal-btn-aux" disabled={isSubmitting}>
              Savunma Belgesi Bağla
            </button>
          </div>
        </form>
      ) : null}

      {!isTerminal && canFinalDecision ? (
        <form className="surec-form-grid" onSubmit={handleNihaiKarar}>
          <FormField
            as="select"
            label="Nihai Karar"
            name="disiplin-nihai-karar"
            value={nihaiKarar}
            onChange={(value) => setNihaiKarar(value as DisiplinNihaiKarar)}
            selectOptions={NIHAI_KARAR_OPTIONS}
          />
          <FormField
            label="Gerekçe"
            name="disiplin-nihai-gerekce"
            value={nihaiGerekce}
            onChange={setNihaiGerekce}
          />
          <div className="form-actions-row">
            <button type="submit" className="universal-btn-save" disabled={isSubmitting}>
              Nihai Karar Ver
            </button>
          </div>
        </form>
      ) : null}

      {!isTerminal && canCloseNoAction ? (
        <div className="surec-form-grid">
          <FormField
            label="İşlemsiz Kapat Gerekçe"
            name="disiplin-close-gerekce"
            value={closeGerekce}
            onChange={setCloseGerekce}
          />
          <div className="form-actions-row">
            <button
              type="button"
              className="universal-btn-aux"
              disabled={isSubmitting}
              onClick={handleIslemsizKapat}
            >
              İşlemsiz Kapat
            </button>
          </div>
        </div>
      ) : null}
    </div>
  );
}
