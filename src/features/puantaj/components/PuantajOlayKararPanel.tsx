import { useCallback, useEffect, useState } from "react";
import {
  fetchPuantajOlayKararlariList,
  upsertPuantajOlayKarar
} from "../../../api/puantaj-olay-kararlari.api";
import { getApiErrorMessage } from "../../../api/api-client";
import { FormField } from "../../../components/form/FormField";
import { useRoleAccess } from "../../../hooks/use-role-access";
import type { GunlukPuantaj } from "../../../types/puantaj";
import type {
  PuantajOlayKarar,
  PuantajOlayKararDegeri,
  PuantajOlayTuru
} from "../../../types/puantaj-olay-karar";

const LATE_TOLERANCE_MAX_MINUTE = 35;

type PuantajOlayKararPanelProps = {
  personelId: number;
  tarih: string;
  puantaj: GunlukPuantaj | null;
};

type EventView = {
  olayTuru: PuantajOlayTuru;
  rawDakika: number;
  label: string;
};

function formatKarar(karar: string | null | undefined): string {
  if (!karar) return "Yok";
  switch (karar) {
    case "BEKLIYOR":
      return "Bekliyor";
    case "KESINTI_UYGULA":
      return "Kesinti uygula";
    case "TOLERANS_UYGULA":
      return "Tolerans uygula";
    case "OFFICIAL_PROCESS_REQUIRED":
      return "Resmi süreç gerekli";
    default:
      return karar;
  }
}

function availableActions(olayTuru: PuantajOlayTuru, rawDakika: number): PuantajOlayKararDegeri[] {
  if (olayTuru === "ERKEN_CIKIS") {
    return ["KESINTI_UYGULA", "OFFICIAL_PROCESS_REQUIRED"];
  }
  if (rawDakika <= LATE_TOLERANCE_MAX_MINUTE) {
    return ["KESINTI_UYGULA", "TOLERANS_UYGULA", "OFFICIAL_PROCESS_REQUIRED"];
  }
  return ["KESINTI_UYGULA", "OFFICIAL_PROCESS_REQUIRED"];
}

export function PuantajOlayKararPanel({ personelId, tarih, puantaj }: PuantajOlayKararPanelProps) {
  const { hasPermission } = useRoleAccess();
  const canView = hasPermission("puantaj.olay_karar.view");
  const canDecide = hasPermission("puantaj.olay_karar.decide");
  const [items, setItems] = useState<PuantajOlayKarar[]>([]);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
  const [gerekce, setGerekce] = useState("");
  const [isLoading, setIsLoading] = useState(false);
  const [isSaving, setIsSaving] = useState(false);

  const events: EventView[] = [];
  const lateRaw = Number(puantaj?.gec_kalma_dakika ?? 0);
  const earlyRaw = Number(puantaj?.erken_cikis_dakika ?? 0);
  if (lateRaw > 0) {
    events.push({ olayTuru: "GEC_KALMA", rawDakika: lateRaw, label: "Geç kalma" });
  }
  if (earlyRaw > 0) {
    events.push({ olayTuru: "ERKEN_CIKIS", rawDakika: earlyRaw, label: "Erken çıkış" });
  }

  const load = useCallback(async () => {
    if (!canView || personelId < 1 || !tarih) {
      setItems([]);
      return;
    }
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const rows = await fetchPuantajOlayKararlariList({
        personel_id: personelId,
        from: tarih,
        to: tarih
      });
      setItems(rows);
    } catch (error) {
      setErrorMessage(getApiErrorMessage(error, "Olay kararları yüklenemedi."));
    } finally {
      setIsLoading(false);
    }
  }, [canView, personelId, tarih]);

  useEffect(() => {
    void load();
  }, [load]);

  if (!canView || events.length === 0) {
    return null;
  }

  async function submitKarar(olayTuru: PuantajOlayTuru, rawDakika: number, karar: PuantajOlayKararDegeri) {
    if (!canDecide) {
      return;
    }
    setIsSaving(true);
    setErrorMessage(null);
    setSuccessMessage(null);
    try {
      await upsertPuantajOlayKarar({
        personel_id: personelId,
        tarih,
        olay_turu: olayTuru,
        raw_dakika: rawDakika,
        karar,
        gerekce: gerekce.trim() || undefined,
        durumu_bildirdi_mi: puantaj?.durumu_bildirdi_mi ?? null
      });
      setSuccessMessage("Olay kararı kaydedildi.");
      setGerekce("");
      await load();
    } catch (error) {
      setErrorMessage(getApiErrorMessage(error, "Olay kararı kaydedilemedi."));
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <div className="puantaj-detail-card" data-testid="puantaj-olay-karar-panel">
      <h3>Puantaj Olay Kararı</h3>
      <p className="form-hint">
        Ham dakika canonical puantaj kaydından okunur. Tolerans yalnız geç kalmada ve en fazla{" "}
        {LATE_TOLERANCE_MAX_MINUTE} dakika için geçerlidir; erken çıkışta tolerans yoktur.
      </p>

      {isLoading ? <p>Kararlar yükleniyor...</p> : null}
      {errorMessage ? (
        <p className="workspace-error" role="alert" data-testid="puantaj-olay-karar-error">
          {errorMessage}
        </p>
      ) : null}
      {successMessage ? (
        <p className="workspace-success" data-testid="puantaj-olay-karar-success">
          {successMessage}
        </p>
      ) : null}

      {events.map((event) => {
        const current = items.find((row) => row.olay_turu === event.olayTuru) ?? null;
        const actions = availableActions(event.olayTuru, event.rawDakika);
        return (
          <div
            key={event.olayTuru}
            className="puantaj-olay-karar-event"
            data-testid={`puantaj-olay-karar-${event.olayTuru.toLowerCase()}`}
          >
            <div className="form-field-grid">
              <div className="form-field">
                <span className="form-label">Personel</span>
                <div className="form-input puantaj-readonly-value">{personelId}</div>
              </div>
              <div className="form-field">
                <span className="form-label">Tarih</span>
                <div className="form-input puantaj-readonly-value">{tarih}</div>
              </div>
              <div className="form-field">
                <span className="form-label">Olay</span>
                <div className="form-input puantaj-readonly-value">{event.label}</div>
              </div>
              <div className="form-field">
                <span className="form-label">RAW dakika</span>
                <div className="form-input puantaj-readonly-value" data-testid="puantaj-olay-karar-raw">
                  {event.rawDakika}
                </div>
              </div>
              <div className="form-field">
                <span className="form-label">Önceden bildirdi mi</span>
                <div className="form-input puantaj-readonly-value">
                  {puantaj?.durumu_bildirdi_mi ? "Evet" : "Hayır"}
                </div>
              </div>
              <div className="form-field">
                <span className="form-label">Mevcut karar</span>
                <div className="form-input puantaj-readonly-value" data-testid="puantaj-olay-karar-current">
                  {formatKarar(current?.karar)}
                </div>
              </div>
              <div className="form-field">
                <span className="form-label">Karar veren</span>
                <div className="form-input puantaj-readonly-value">
                  {current?.karar_veren_user_id ?? "—"}
                </div>
              </div>
              <div className="form-field">
                <span className="form-label">Karar zamanı</span>
                <div className="form-input puantaj-readonly-value">{current?.karar_at ?? "—"}</div>
              </div>
              <div className="form-field">
                <span className="form-label">Gerekçe</span>
                <div className="form-input puantaj-readonly-value">{current?.gerekce ?? "—"}</div>
              </div>
            </div>

            {canDecide ? (
              <div className="form-actions-row" data-testid={`puantaj-olay-karar-actions-${event.olayTuru}`}>
                {actions.map((action) => (
                  <button
                    key={action}
                    type="button"
                    className="universal-btn-aux"
                    disabled={isSaving}
                    data-testid={`puantaj-olay-karar-action-${action}`}
                    onClick={() => void submitKarar(event.olayTuru, event.rawDakika, action)}
                  >
                    {formatKarar(action)}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        );
      })}

      {canDecide ? (
        <FormField
          as="textarea"
          label="Karar gerekçesi"
          name="puantaj-olay-karar-gerekce"
          value={gerekce}
          onChange={setGerekce}
          rows={2}
          placeholder="İsteğe bağlı gerekçe"
        />
      ) : null}
    </div>
  );
}

/** Pure helpers exported for targeted source/unit tests. */
export const puantajOlayKararUiHelpers = {
  LATE_TOLERANCE_MAX_MINUTE,
  availableActions,
  formatKarar
};
