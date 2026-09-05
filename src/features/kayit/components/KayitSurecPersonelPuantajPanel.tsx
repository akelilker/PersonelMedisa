import { type ReactNode } from "react";
import { useNavigate } from "react-router-dom";
import { useRoleAccess } from "../../../hooks/use-role-access";
import { dispatchOpenBugunPersonelDurumu } from "../../../lib/bildirim/bugun-personel-durumu-events";
import { canCorrectScopedGunlukBildirim } from "../../../lib/bildirim/gunluk-bildirim-correct-scoped";
import type { Personel } from "../../../types/personel";
import {
  DEVAMSIZLIK_SUB_CARDS,
  PUANTAJ_SUBDOMAIN_CARDS,
  type DevamsizlikSubId,
  type PuantajSubdomainId
} from "../kayit-surec-constants";
import { buildKayitSurecRouteState } from "../kayit-surec-navigation";

type KayitSurecPersonelPuantajPanelProps = {
  personel: Personel;
  activeSubdomain: PuantajSubdomainId | null;
  hakDuzeltmeOpen: boolean;
  canManageYillikIzinHak: boolean;
  isPassive: boolean;
  onSelectDevamsizlikSub: (id: DevamsizlikSubId) => void;
  onOpenHakDuzeltme: () => void;
  children?: ReactNode;
};

function isDevamsizlikSubId(value: PuantajSubdomainId): value is DevamsizlikSubId {
  return DEVAMSIZLIK_SUB_CARDS.some((card) => card.id === value);
}

export function KayitSurecPersonelPuantajPanel({
  personel,
  activeSubdomain,
  hakDuzeltmeOpen,
  canManageYillikIzinHak,
  isPassive,
  onSelectDevamsizlikSub,
  onOpenHakDuzeltme,
  children
}: KayitSurecPersonelPuantajPanelProps) {
  const navigate = useNavigate();
  const { hasPermission } = useRoleAccess();
  const canViewBugun = hasPermission("bugun_personel_durumu.view");
  const canCorrectScoped = canCorrectScopedGunlukBildirim(hasPermission);
  const canUpdatePuantaj = hasPermission("puantaj.update");

  if (isPassive) {
    return (
      <div className="surec-person-placeholder" data-testid="kayit-surec-puantaj-panel">
        <strong>Puantaj</strong>
        <p>Bu personel pasif; puantaj ve izin/devamsızlık kaydı eklenmez. Geçmiş kayıtlar salt okunur kalır.</p>
      </div>
    );
  }

  function handleSubdomainClick(id: PuantajSubdomainId) {
    const card = PUANTAJ_SUBDOMAIN_CARDS.find((item) => item.id === id);
    if (!card) {
      return;
    }

    if (card.kind === "bugun-modal") {
      dispatchOpenBugunPersonelDurumu();
      return;
    }

    if (card.kind === "route") {
      navigate("/bildirimler", {
        state: buildKayitSurecRouteState(personel.id, "puantaj", { openCreateModal: true })
      });
      return;
    }

    if (card.kind === "puantaj-route") {
      // IK without puantaj.update: daily correction owner is Bugünkü Personel Durumu.
      if (!canUpdatePuantaj && canViewBugun) {
        dispatchOpenBugunPersonelDurumu();
        return;
      }
      navigate(`/puantaj?personel_id=${encodeURIComponent(String(personel.id))}`, {
        state: buildKayitSurecRouteState(personel.id, "puantaj")
      });
      return;
    }

    if (isDevamsizlikSubId(id)) {
      onSelectDevamsizlikSub(id);
    }
  }

  const showInlineWorkspace = Boolean(activeSubdomain && isDevamsizlikSubId(activeSubdomain)) || hakDuzeltmeOpen;

  return (
    <div className="surec-shell-panel" data-testid="kayit-surec-puantaj-panel">
      <p className="workspace-empty-hint" data-testid="kayit-surec-puantaj-owner-hint">
        <strong>Puantaj</strong> — İzin / Rapor / İş Kazası / İzinsiz = özlük süreç kaydı. Geç / Erken /
        Görevde = Bugünkü Personel Durumu (saatli günlük durum).
      </p>

      {canViewBugun ? (
        <div className="workspace-empty-hint" data-testid="kayit-surec-puantaj-open-items">
          <strong>Kontrol Gerekenler</strong>
          <p>
            Henüz değerlendirilmedi, geç geldi, gelmedi ve eksik günlük kayıtlar Bugünkü Personel Durumu
            üzerinden okunur
            {canCorrectScoped ? "; günlük düzeltme de orada yapılır" : ""}. İzin / rapor / izinsiz için
            aşağıdaki özlük kayıtlarını kullan. Puantaj satırı düzenleme yetkisi yoksa satır ekranı yerine
            Bugün açılır.
          </p>
          <button
            type="button"
            className="universal-btn-aux"
            data-testid="kayit-surec-puantaj-open-bugun"
            onClick={() => dispatchOpenBugunPersonelDurumu()}
          >
            Bugünkü Personel Durumu
          </button>
        </div>
      ) : null}

      <div className="surec-devamsizlik-tiles" role="group" aria-label="Puantaj alt işlemleri">
        {PUANTAJ_SUBDOMAIN_CARDS.map((card) => {
          const isActive =
            !hakDuzeltmeOpen &&
            card.kind === "inline-devamsizlik" &&
            activeSubdomain === card.id;
          const routesToBugun =
            card.kind === "bugun-modal" || (card.kind === "puantaj-route" && !canUpdatePuantaj && canViewBugun);

          return (
            <button
              key={card.id}
              type="button"
              className={`surec-devamsizlik-tile${isActive ? " is-active" : ""}`}
              data-testid={`kayit-surec-puantaj-sub-${card.id}`}
              onClick={() => handleSubdomainClick(card.id)}
            >
              <span className="surec-devamsizlik-tile-title">{card.title}</span>
              <span className="surec-devamsizlik-tile-desc">
                {card.kind === "puantaj-route" && !canUpdatePuantaj && canViewBugun
                  ? "Günlük durum kontrolü — Bugünkü Personel Durumu"
                  : card.description}
              </span>
              <span className="surec-devamsizlik-tile-status">
                {routesToBugun || card.kind === "route"
                  ? "Aç"
                  : isActive
                    ? "Seçildi"
                    : "Seç"}
              </span>
            </button>
          );
        })}
        {canManageYillikIzinHak ? (
          <button
            type="button"
            className={`surec-devamsizlik-tile${hakDuzeltmeOpen ? " is-active" : ""}`}
            data-testid="yillik-izin-hak-duzeltme-tile"
            onClick={onOpenHakDuzeltme}
          >
            <span className="surec-devamsizlik-tile-title">İzin Hak Düzeltmesi</span>
            <span className="surec-devamsizlik-tile-desc">
              Devir / ek hak / idari düzeltme (süreç kaydı değil)
            </span>
            <span className="surec-devamsizlik-tile-status">{hakDuzeltmeOpen ? "Seçildi" : "Seç"}</span>
          </button>
        ) : null}
      </div>

      {showInlineWorkspace ? children : null}
    </div>
  );
}
