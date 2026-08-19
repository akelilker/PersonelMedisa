import { useCallback, useEffect, useState } from "react";
import { isApiRequestError } from "../../../api/api-client";
import {
  bindYonetimActorIdentity,
  createYonetimActorIdentity,
  fetchYonetimActorIdentity,
  verifyYonetimActorIdentity
} from "../../../api/yonetim.api";
import type { YonetimActorIdentityRead } from "../../../types/yonetim";

const ACTOR_STATUS_LABELS: Record<string, string> = {
  PENDING: "Beklemede",
  VERIFIED: "Doğrulandı",
  REVOKED: "İptal edildi"
};

type KullaniciActorIdentityPanelProps = {
  userId: number;
  canManage: boolean;
  formatSubeScope: (subeIds: number[]) => string;
};

export function KullaniciActorIdentityPanel(props: KullaniciActorIdentityPanelProps) {
  const [snapshot, setSnapshot] = useState<YonetimActorIdentityRead | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isWorking, setIsWorking] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const loadSnapshot = useCallback(async () => {
    setIsLoading(true);
    setErrorMessage(null);
    try {
      const read = await fetchYonetimActorIdentity(props.userId);
      setSnapshot(read);
    } catch (error) {
      if (
        isApiRequestError(error) &&
        (error.status === 404 || error.code === "ACTOR_IDENTITY_NOT_FOUND")
      ) {
        setSnapshot(null);
      } else {
        setErrorMessage(error instanceof Error ? error.message : "Actor kimliği okunamadı.");
      }
    } finally {
      setIsLoading(false);
    }
  }, [props.userId]);

  useEffect(() => {
    void loadSnapshot();
  }, [loadSnapshot]);

  async function runAction(action: () => Promise<YonetimActorIdentityRead>) {
    if (!props.canManage || isWorking) {
      return;
    }

    setIsWorking(true);
    setErrorMessage(null);
    try {
      const read = await action();
      setSnapshot(read);
    } catch (error) {
      setErrorMessage(error instanceof Error ? error.message : "Actor kimliği işlemi başarısız.");
    } finally {
      setIsWorking(false);
    }
  }

  const actorId = snapshot?.actor_identity_id ?? null;
  const actorStatus = snapshot?.actor_status ?? null;
  const readinessLabel =
    snapshot?.ready === true
      ? "SGK dual-control hazır"
      : snapshot?.readiness_code
        ? `Hazır değil (${snapshot.readiness_code})`
        : "Hazır değil";

  return (
    <div className="yonetim-workspace-panel" data-testid="yonetim-kullanici-actor-identity">
      <p className="yonetim-workspace-panel-title">Formal SGK actor kimliği</p>
      <p className="yonetim-hint">
        Personel bağlantısından ayrıdır. Create / verify / bind işlemleri mevcut backend owner&apos;ı üzerinden çalışır.
      </p>

      {isLoading ? <p className="yonetim-hint">Actor durumu yükleniyor…</p> : null}
      {!isLoading && errorMessage ? <p className="yonetim-inline-error">{errorMessage}</p> : null}

      {!isLoading && !errorMessage ? (
        <dl className="yonetim-actor-readback">
          <div>
            <dt>Actor ID</dt>
            <dd>{actorId ?? "—"}</dd>
          </div>
          <div>
            <dt>Durum</dt>
            <dd>{actorStatus ? (ACTOR_STATUS_LABELS[actorStatus] ?? actorStatus) : "—"}</dd>
          </div>
          <div>
            <dt>Readiness</dt>
            <dd>{actorId ? readinessLabel : "—"}</dd>
          </div>
          <div>
            <dt>Actor şube kapsamı</dt>
            <dd>{snapshot?.branch_scope?.length ? props.formatSubeScope(snapshot.branch_scope) : "—"}</dd>
          </div>
          <div>
            <dt>Bağlı personel (actor)</dt>
            <dd>{snapshot?.personel_id ?? "—"}</dd>
          </div>
        </dl>
      ) : null}

      {props.canManage ? (
        <div className="yonetim-actor-actions">
          <button
            type="button"
            className="universal-btn-secondary"
            data-testid="yonetim-actor-create"
            disabled={isWorking || isLoading || actorId != null}
            onClick={() => void runAction(() => createYonetimActorIdentity(props.userId))}
          >
            Actor kimliği oluştur
          </button>
          <button
            type="button"
            className="universal-btn-secondary"
            data-testid="yonetim-actor-verify"
            disabled={isWorking || isLoading || actorId == null || actorStatus === "VERIFIED"}
            onClick={() => void runAction(() => verifyYonetimActorIdentity(actorId!))}
          >
            Doğrula
          </button>
          <button
            type="button"
            className="universal-btn-secondary"
            data-testid="yonetim-actor-bind"
            disabled={isWorking || isLoading || actorId == null || (snapshot?.user_id === props.userId && actorId != null)}
            onClick={() => void runAction(() => bindYonetimActorIdentity(props.userId, actorId!))}
          >
            Kullanıcıya bağla
          </button>
        </div>
      ) : (
        <p className="yonetim-hint">Actor işlemleri yalnızca yönetim yetkisi olan kullanıcılar tarafından yapılabilir.</p>
      )}
    </div>
  );
}
