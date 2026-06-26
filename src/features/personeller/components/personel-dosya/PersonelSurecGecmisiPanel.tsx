import { useMemo } from "react";
import type { Personel } from "../../../../types/personel";
import type { Surec } from "../../../../types/surec";
import type { Zimmet } from "../../../../types/zimmet";
import { buildPersonelTimeline } from "./personel-timeline-utils";

export function PersonelSurecGecmisiPanel({
  personel,
  canAccessSurecler,
  canCreateSurec,
  isLoading,
  errorMessage,
  surecler,
  zimmetler,
  onOpenCreateModal
}: {
  personel: Personel;
  canAccessSurecler: boolean;
  canCreateSurec: boolean;
  isLoading: boolean;
  errorMessage: string | null;
  surecler: Surec[];
  zimmetler: Zimmet[];
  onOpenCreateModal: () => void;
}) {
  const timeline = useMemo(
    () => buildPersonelTimeline(personel, surecler, zimmetler),
    [personel, surecler, zimmetler]
  );

  if (!canAccessSurecler) {
    return (
      <div className="personel-kart-placeholder">
        <h3>Süreç Geçmişi</h3>
        <p>Bu dosya yalnızca süreç görüntüleme yetkisi olan kullanıcılar için açılır.</p>
      </div>
    );
  }

  return (
    <div className="personel-surec-history">
      <div className="personel-surec-history-head">
        <div>
          <h3>Süreç Geçmişi</h3>
          <p>Süreç kayıtları ve zimmet hareketleri tek akışta, en yeniden eskiye sıralanır.</p>
        </div>
        {canCreateSurec ? (
          <button type="button" className="universal-btn-aux" onClick={onOpenCreateModal}>
            Süreç Ekle
          </button>
        ) : null}
      </div>

      {isLoading ? <p className="personel-kart-placeholder-note">Süreç geçmişi yükleniyor...</p> : null}
      {!isLoading && errorMessage ? <p className="personel-create-error">{errorMessage}</p> : null}
      {!isLoading && !errorMessage && timeline.length === 0 ? (
        <div className="personel-kart-placeholder">
          <h3>Kayıt Bulunamadı</h3>
          <p>Bu personel için henüz süreç veya zimmet satırı oluşmamış.</p>
        </div>
      ) : null}

      {!isLoading && !errorMessage && timeline.length > 0 ? (
        <ol className="personel-timeline" data-testid="personel-surec-timeline">
          {timeline.map((event) => (
            <li
              key={event.id}
              className={`personel-timeline-item${event.tone === "danger" ? " is-danger" : ""}`}
            >
              <div className="personel-timeline-marker" aria-hidden="true" />
              <div className="personel-timeline-body">
                <div className="personel-timeline-head">
                  <strong>{event.baslik}</strong>
                  {event.etiket ? <span className="personel-surec-state">{event.etiket}</span> : null}
                </div>
                <div className="personel-timeline-meta">
                  <span>{event.tarih ?? "Tarih belirtilmedi"}</span>
                  {event.zamanIkincil ? (
                    <>
                      <span className="personel-timeline-meta-dot" aria-hidden="true">
                        ·
                      </span>
                      <span className="personel-timeline-meta-secondary">{event.zamanIkincil}</span>
                    </>
                  ) : null}
                  <span className="personel-timeline-meta-dot" aria-hidden="true">
                    ·
                  </span>
                  <span>{event.kaynak}</span>
                </div>
                <p className="personel-timeline-summary">{event.ozet}</p>
                {event.aciklama ? (
                  <p className="personel-timeline-note">{event.aciklama}</p>
                ) : null}
              </div>
            </li>
          ))}
        </ol>
      ) : null}
    </div>
  );
}
