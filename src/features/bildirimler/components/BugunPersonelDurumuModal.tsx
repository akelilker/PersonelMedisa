import { useCallback, useEffect, useMemo, useState } from "react";
import { AppModal } from "../../../components/modal/AppModal";
import { fetchBugunPersonelDurumu } from "../../../api/bildirimler.api";
import {
  BUGUN_STATUS_KEYS,
  BUGUN_STATUS_LABEL,
  filterPersonsByStatus,
  formatCompletionGlyph,
  type BugunStatusKey
} from "../../../lib/bildirim/bugun-personel-durumu";
import { istanbulBusinessDate } from "../../self-service/birim-amiri-operational";
import type {
  BugunPersonelDurumu,
  BugunPersonelDurumuBranch,
  BugunPersonelDurumuPerson,
  BugunPersonelDurumuUnit
} from "../../../types/bildirim";

type NavLevel =
  | { kind: "branches" }
  | { kind: "units"; branch: BugunPersonelDurumuBranch }
  | { kind: "status"; branch: BugunPersonelDurumuBranch; unit: BugunPersonelDurumuUnit; statusKey: BugunStatusKey };

type BugunPersonelDurumuModalProps = {
  open: boolean;
  onClose: () => void;
};

function countToneClass(key: BugunStatusKey, value: number): string {
  if (value <= 0) return "";
  if (key === "gelmedi" || key === "gec_geldi") return " is-attention";
  if (key === "izinli" || key === "raporlu" || key === "gorevde" || key === "henuz_degerlendirilmedi") {
    return " is-planned";
  }
  return "";
}

export function BugunPersonelDurumuModal({ open, onClose }: BugunPersonelDurumuModalProps) {
  const [payload, setPayload] = useState<BugunPersonelDurumu | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [nav, setNav] = useState<NavLevel>({ kind: "branches" });

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await fetchBugunPersonelDurumu({ tarih: istanbulBusinessDate() });
      setPayload(data);
      setNav({ kind: "branches" });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Bugünkü personel durumu yüklenemedi.");
      setPayload(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    if (!open) {
      return;
    }
    void load();
  }, [open, load]);

  const title = useMemo(() => {
    if (nav.kind === "status") {
      return BUGUN_STATUS_LABEL[nav.statusKey];
    }
    if (nav.kind === "units") {
      return nav.branch.sube_adi;
    }
    return "Bugünkü Personel Durumu";
  }, [nav]);

  const crumb = useMemo(() => {
    if (nav.kind === "status") {
      return `${nav.branch.sube_adi} · ${nav.unit.birim_adi}`;
    }
    if (nav.kind === "units") {
      return "Bugünkü Personel Durumu";
    }
    return null;
  }, [nav]);

  function goHome() {
    setNav({ kind: "branches" });
  }

  function goBack() {
    if (nav.kind === "status") {
      setNav({ kind: "units", branch: nav.branch });
      return;
    }
    if (nav.kind === "units") {
      setNav({ kind: "branches" });
      return;
    }
    onClose();
  }

  if (!open) {
    return null;
  }

  const statusPersons: BugunPersonelDurumuPerson[] =
    nav.kind === "status" ? filterPersonsByStatus(nav.unit.personeller, nav.statusKey) : [];

  return (
    <AppModal
      title={title}
      titleVariant="premium"
      titleTestId="bugun-personel-durumu-title"
      onClose={onClose}
      onBack={nav.kind === "branches" ? undefined : goBack}
      backLabel={nav.kind === "branches" ? undefined : "Geri"}
      backTestId="bugun-personel-durumu-back"
      headerStart={
        <button
          type="button"
          className="modal-home-btn"
          onClick={() => {
            goHome();
            onClose();
          }}
          aria-label="Ana sayfaya dön"
          data-testid="bugun-personel-durumu-home"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
            <path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" />
          </svg>
        </button>
      }
      className="modal-container--bugun-personel"
      bodyClassName="modal-body--bugun-personel"
    >
      <div className="bugun-personel-panel" data-testid="bugun-personel-durumu-panel">
        {crumb ? <p className="bugun-personel-crumb">{crumb}</p> : null}
        {loading ? <p className="bugun-personel-state">Yükleniyor…</p> : null}
        {error ? (
          <div className="bugun-personel-state bugun-personel-state--error">
            <p>{error}</p>
            <button type="button" className="btn btn-secondary" onClick={() => void load()}>
              Yenile
            </button>
          </div>
        ) : null}

        {!loading && !error && payload && nav.kind === "branches" ? (
          <div className="bugun-personel-branch-list" role="list">
            {payload.branches.length === 0 ? (
              <p className="bugun-personel-state">Bugün için kapsamda personel yok.</p>
            ) : (
              payload.branches.map((branch) => (
                <button
                  key={branch.sube_id}
                  type="button"
                  className="bugun-personel-branch-card"
                  role="listitem"
                  data-testid={`bugun-branch-${branch.sube_id}`}
                  onClick={() => setNav({ kind: "units", branch })}
                >
                  <div className="bugun-personel-branch-head">
                    <strong>{branch.sube_adi}</strong>
                    <span>Toplam {branch.counts.toplam}</span>
                  </div>
                  <div className="bugun-personel-stat-grid">
                    {BUGUN_STATUS_KEYS.map((key) => (
                      <span key={key} className={`bugun-personel-stat${countToneClass(key, branch.counts[key])}`}>
                        {branch.counts[key]} {BUGUN_STATUS_LABEL[key]}
                      </span>
                    ))}
                  </div>
                  <div className="bugun-personel-branch-foot">
                    Birim Bildirimi: {branch.birim_bildirim.tamamlanan} / {branch.birim_bildirim.toplam}{" "}
                    Tamamlandı
                  </div>
                </button>
              ))
            )}
          </div>
        ) : null}

        {!loading && !error && nav.kind === "units" ? (
          <div className="bugun-personel-unit-list" role="list">
            {nav.branch.units.map((unit) => (
              <div
                key={`${unit.birim_id ?? "none"}-${unit.birim_adi}`}
                className="bugun-personel-unit-card"
                role="listitem"
                data-testid={`bugun-unit-${unit.birim_id ?? "none"}`}
              >
                <div className="bugun-personel-unit-head">
                  <strong>{unit.birim_adi}</strong>
                  <span>Toplam {unit.counts.toplam}</span>
                </div>
                {unit.bolum_adi ? <p className="bugun-personel-unit-bolum">{unit.bolum_adi}</p> : null}
                <div className="bugun-personel-stat-grid">
                  {BUGUN_STATUS_KEYS.map((key) => {
                    const value = unit.counts[key];
                    return (
                      <button
                        key={key}
                        type="button"
                        className={`bugun-personel-stat-btn${countToneClass(key, value)}`}
                        disabled={value <= 0}
                        data-testid={`bugun-status-${key}`}
                        onClick={() =>
                          setNav({
                            kind: "status",
                            branch: nav.branch,
                            unit,
                            statusKey: key
                          })
                        }
                      >
                        {value} {BUGUN_STATUS_LABEL[key]}
                      </button>
                    );
                  })}
                </div>
                <div
                  className={`bugun-personel-completion bugun-personel-completion--${unit.bildirim.status.toLowerCase()}`}
                >
                  <span aria-hidden="true">{formatCompletionGlyph(unit.bildirim.status)}</span>
                  <span>{unit.bildirim.status_label}</span>
                </div>
              </div>
            ))}
          </div>
        ) : null}

        {!loading && !error && nav.kind === "status" ? (
          <div className="bugun-personel-person-list" role="list">
            {statusPersons.length === 0 ? (
              <p className="bugun-personel-state">Bu durumda personel yok.</p>
            ) : (
              statusPersons.map((person) => (
                <div
                  key={person.personel_id}
                  className="bugun-personel-person-row"
                  role="listitem"
                  data-testid={`bugun-person-${person.personel_id}`}
                >
                  <strong>{person.ad_soyad}</strong>
                  <span>{person.detail_line}</span>
                </div>
              ))
            )}
          </div>
        ) : null}
      </div>
    </AppModal>
  );
}
