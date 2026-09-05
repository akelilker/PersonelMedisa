import { FormEvent, useCallback, useEffect, useMemo, useState } from "react";
import { AppModal } from "../../../components/modal/AppModal";
import {
  createBildirim,
  fetchBildirimDetail,
  fetchBugunPersonelDurumu,
  updateBildirim
} from "../../../api/bildirimler.api";
import { getApiErrorMessage } from "../../../api/api-client";
import { useRoleAccess } from "../../../hooks/use-role-access";
import {
  BUGUN_STATUS_KEYS,
  BUGUN_STATUS_LABEL,
  filterPersonsByStatus,
  formatCompletionGlyph,
  type BugunStatusKey
} from "../../../lib/bildirim/bugun-personel-durumu";
import {
  PAYROLL_LOCK_EDIT_MESSAGE,
  SCOPED_CORRECTABLE_TURLER,
  SCOPED_CORRECTABLE_TUR_LABEL,
  canCorrectScopedGunlukBildirim,
  defaultTurFromPerson,
  evidenceLabel,
  formatAuditClock,
  formatAuditTransition,
  turNeedsAltTur,
  turNeedsTimeFields,
  turRequiresAciklama,
  type ScopedCorrectableTur
} from "../../../lib/bildirim/gunluk-bildirim-correct-scoped";
import { istanbulBusinessDate } from "../../self-service/birim-amiri-operational";
import type {
  BugunPersonelDurumu,
  BugunPersonelDurumuBranch,
  BugunPersonelDurumuPerson,
  BugunPersonelDurumuUnit,
  GunlukBildirimDuzeltmeAudit
} from "../../../types/bildirim";

type NavLevel =
  | { kind: "branches" }
  | { kind: "units"; branch: BugunPersonelDurumuBranch }
  | { kind: "unit_roster"; branch: BugunPersonelDurumuBranch; unit: BugunPersonelDurumuUnit }
  | { kind: "status"; branch: BugunPersonelDurumuBranch; unit: BugunPersonelDurumuUnit; statusKey: BugunStatusKey }
  | {
      kind: "person";
      branch: BugunPersonelDurumuBranch;
      unit: BugunPersonelDurumuUnit;
      statusKey: BugunStatusKey | null;
      person: BugunPersonelDurumuPerson;
    };

type EditFormState = {
  bildirimTuru: ScopedCorrectableTur;
  dakika: string;
  baslangicSaati: string;
  bitisSaati: string;
  altTur: string;
  aciklama: string;
  correctionReason: string;
};

type BugunPersonelDurumuModalProps = {
  open: boolean;
  onClose: () => void;
};

type NavAnchor = {
  subeId: number;
  birimId: number | null;
  statusKey: BugunStatusKey | null;
  personelId: number | null;
  view: "units" | "unit_roster" | "status" | "person";
};

function countToneClass(key: BugunStatusKey, value: number): string {
  if (value <= 0) return "";
  if (key === "gelmedi" || key === "gec_geldi") return " is-attention";
  if (key === "izinli" || key === "raporlu" || key === "gorevde" || key === "henuz_degerlendirilmedi") {
    return " is-planned";
  }
  return "";
}

function emptyEditForm(person: BugunPersonelDurumuPerson): EditFormState {
  return {
    bildirimTuru: defaultTurFromPerson(person),
    dakika: person.gec_kalma_dakika != null ? String(person.gec_kalma_dakika) : person.erken_cikis_dakika != null ? String(person.erken_cikis_dakika) : "",
    baslangicSaati: person.giris_saati ?? "",
    bitisSaati: person.cikis_saati ?? "",
    altTur: person.alt_tur ?? "",
    aciklama: person.aciklama ?? "",
    correctionReason: ""
  };
}

function findBranch(payload: BugunPersonelDurumu, subeId: number): BugunPersonelDurumuBranch | null {
  return payload.branches.find((b) => b.sube_id === subeId) ?? null;
}

function findUnit(branch: BugunPersonelDurumuBranch, birimId: number | null): BugunPersonelDurumuUnit | null {
  return (
    branch.units.find((u) => (u.birim_id ?? null) === birimId) ??
    null
  );
}

function restoreNav(payload: BugunPersonelDurumu, anchor: NavAnchor | null): NavLevel {
  if (!anchor) {
    return { kind: "branches" };
  }
  const branch = findBranch(payload, anchor.subeId);
  if (!branch) {
    return { kind: "branches" };
  }
  if (anchor.view === "units") {
    return { kind: "units", branch };
  }
  const unit = findUnit(branch, anchor.birimId);
  if (!unit) {
    return { kind: "units", branch };
  }
  if (anchor.view === "unit_roster") {
    return { kind: "unit_roster", branch, unit };
  }
  if (anchor.view === "status" && anchor.statusKey) {
    return { kind: "status", branch, unit, statusKey: anchor.statusKey };
  }
  if (anchor.view === "person" && anchor.personelId != null) {
    const person =
      unit.personeller.find((p) => p.personel_id === anchor.personelId) ?? null;
    if (!person) {
      if (anchor.statusKey) {
        return { kind: "status", branch, unit, statusKey: anchor.statusKey };
      }
      return { kind: "unit_roster", branch, unit };
    }
    return {
      kind: "person",
      branch,
      unit,
      statusKey: anchor.statusKey,
      person
    };
  }
  return { kind: "units", branch };
}

function navToAnchor(nav: NavLevel): NavAnchor | null {
  if (nav.kind === "branches") {
    return null;
  }
  if (nav.kind === "units") {
    return { subeId: nav.branch.sube_id, birimId: null, statusKey: null, personelId: null, view: "units" };
  }
  if (nav.kind === "unit_roster") {
    return {
      subeId: nav.branch.sube_id,
      birimId: nav.unit.birim_id,
      statusKey: null,
      personelId: null,
      view: "unit_roster"
    };
  }
  if (nav.kind === "status") {
    return {
      subeId: nav.branch.sube_id,
      birimId: nav.unit.birim_id,
      statusKey: nav.statusKey,
      personelId: null,
      view: "status"
    };
  }
  return {
    subeId: nav.branch.sube_id,
    birimId: nav.unit.birim_id,
    statusKey: nav.statusKey,
    personelId: nav.person.personel_id,
    view: "person"
  };
}

export function BugunPersonelDurumuModal({ open, onClose }: BugunPersonelDurumuModalProps) {
  const { hasPermission } = useRoleAccess();
  const canCorrect = canCorrectScopedGunlukBildirim(hasPermission);

  const [payload, setPayload] = useState<BugunPersonelDurumu | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [nav, setNav] = useState<NavLevel>({ kind: "branches" });
  const [editing, setEditing] = useState(false);
  const [editForm, setEditForm] = useState<EditFormState | null>(null);
  const [editError, setEditError] = useState<string | null>(null);
  const [editSubmitting, setEditSubmitting] = useState(false);
  const [history, setHistory] = useState<GunlukBildirimDuzeltmeAudit[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  const load = useCallback(async (preserve: NavAnchor | null = null) => {
    setLoading(true);
    setError(null);
    try {
      const data = await fetchBugunPersonelDurumu({ tarih: istanbulBusinessDate() });
      setPayload(data);
      setNav(restoreNav(data, preserve));
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
    setEditing(false);
    setEditForm(null);
    setEditError(null);
    setHistory([]);
    void load(null);
  }, [open, load]);

  useEffect(() => {
    if (!open || nav.kind !== "person") {
      setHistory([]);
      return;
    }
    const bildirimId = nav.person.bildirim_id;
    if (bildirimId == null || bildirimId <= 0) {
      setHistory([]);
      return;
    }
    let cancelled = false;
    setHistoryLoading(true);
    void fetchBildirimDetail(bildirimId)
      .then((detail) => {
        if (cancelled) return;
        setHistory(Array.isArray(detail.duzeltme_gecmisi) ? detail.duzeltme_gecmisi : []);
      })
      .catch(() => {
        if (!cancelled) setHistory([]);
      })
      .finally(() => {
        if (!cancelled) setHistoryLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [open, nav]);

  const title = useMemo(() => {
    if (nav.kind === "person") {
      return nav.person.ad_soyad;
    }
    if (nav.kind === "status") {
      return BUGUN_STATUS_LABEL[nav.statusKey];
    }
    if (nav.kind === "unit_roster") {
      return nav.unit.birim_adi;
    }
    if (nav.kind === "units") {
      return nav.branch.sube_adi;
    }
    return "Bugünkü Personel Durumu";
  }, [nav]);

  const crumb = useMemo(() => {
    if (nav.kind === "person") {
      const statusPart = nav.statusKey ? ` · ${BUGUN_STATUS_LABEL[nav.statusKey]}` : "";
      return `${nav.branch.sube_adi} · ${nav.unit.birim_adi}${statusPart}`;
    }
    if (nav.kind === "status") {
      return `${nav.branch.sube_adi} · ${nav.unit.birim_adi}`;
    }
    if (nav.kind === "unit_roster") {
      return nav.branch.sube_adi;
    }
    if (nav.kind === "units") {
      return "Bugünkü Personel Durumu";
    }
    return null;
  }, [nav]);

  function goHome() {
    setEditing(false);
    setEditForm(null);
    setNav({ kind: "branches" });
  }

  function goBack() {
    setEditing(false);
    setEditForm(null);
    setEditError(null);
    if (nav.kind === "person") {
      if (nav.statusKey) {
        setNav({ kind: "status", branch: nav.branch, unit: nav.unit, statusKey: nav.statusKey });
        return;
      }
      setNav({ kind: "unit_roster", branch: nav.branch, unit: nav.unit });
      return;
    }
    if (nav.kind === "status" || nav.kind === "unit_roster") {
      setNav({ kind: "units", branch: nav.branch });
      return;
    }
    if (nav.kind === "units") {
      setNav({ kind: "branches" });
      return;
    }
    onClose();
  }

  function openPerson(
    branch: BugunPersonelDurumuBranch,
    unit: BugunPersonelDurumuUnit,
    person: BugunPersonelDurumuPerson,
    statusKey: BugunStatusKey | null
  ) {
    setEditing(false);
    setEditForm(null);
    setEditError(null);
    setNav({ kind: "person", branch, unit, person, statusKey });
  }

  function startEdit(person: BugunPersonelDurumuPerson) {
    setEditForm(emptyEditForm(person));
    setEditError(null);
    setEditing(true);
  }

  async function handleEditSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editForm || nav.kind !== "person" || editSubmitting) {
      return;
    }
    const tur = editForm.bildirimTuru;
    const reason = editForm.correctionReason.trim();
    if (!reason) {
      setEditError("Düzeltme nedeni zorunludur.");
      return;
    }
    if (turRequiresAciklama(tur) && !editForm.aciklama.trim()) {
      setEditError("Diğer türü için açıklama zorunludur.");
      return;
    }

    const bodyBase = {
      bildirim_turu: tur,
      aciklama: editForm.aciklama.trim() || null,
      alt_tur: turNeedsAltTur(tur) ? editForm.altTur.trim() || null : null,
      baslangic_saati: turNeedsTimeFields(tur) ? editForm.baslangicSaati.trim() || null : null,
      bitis_saati: turNeedsTimeFields(tur) ? editForm.bitisSaati.trim() || null : null,
      dakika:
        turNeedsTimeFields(tur) && editForm.dakika.trim() !== ""
          ? Number.parseInt(editForm.dakika.trim(), 10)
          : null,
      correction_reason: reason
    };
    if (bodyBase.dakika != null && Number.isNaN(bodyBase.dakika)) {
      setEditError("Dakika geçerli bir sayı olmalıdır.");
      return;
    }

    setEditSubmitting(true);
    setEditError(null);
    const anchor = navToAnchor(nav);
    try {
      const person = nav.person;
      if (person.bildirim_id != null && person.bildirim_id > 0) {
        await updateBildirim(person.bildirim_id, bodyBase);
      } else {
        await createBildirim({
          tarih: payload?.tarih ?? istanbulBusinessDate(),
          personel_id: person.personel_id,
          ...bodyBase,
          aciklama: bodyBase.aciklama ?? undefined
        });
      }
      setEditing(false);
      setEditForm(null);
      await load(anchor);
    } catch (err) {
      const code =
        err && typeof err === "object" && "code" in err ? String((err as { code?: string }).code) : "";
      const message = getApiErrorMessage(err, "Durum kaydedilemedi.");
      if (code === "PERIOD_LOCKED" || message.toLowerCase().includes("donem") || message.toLowerCase().includes("dönem")) {
        setEditError(PAYROLL_LOCK_EDIT_MESSAGE);
      } else {
        setEditError(message);
      }
    } finally {
      setEditSubmitting(false);
    }
  }

  if (!open) {
    return null;
  }

  const statusPersons: BugunPersonelDurumuPerson[] =
    nav.kind === "status" ? filterPersonsByStatus(nav.unit.personeller, nav.statusKey) : [];

  const periodWritable =
    nav.kind === "person" || nav.kind === "status" || nav.kind === "unit_roster" || nav.kind === "units"
      ? nav.branch.period_writable !== false
      : true;

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
            <button type="button" className="btn btn-secondary" onClick={() => void load(navToAnchor(nav))}>
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
                <button
                  type="button"
                  className="bugun-personel-unit-open"
                  data-testid={`bugun-unit-open-${unit.birim_id ?? "none"}`}
                  onClick={() => setNav({ kind: "unit_roster", branch: nav.branch, unit })}
                >
                  <div className="bugun-personel-unit-head">
                    <strong>{unit.birim_adi}</strong>
                    <span>Toplam {unit.counts.toplam}</span>
                  </div>
                  {unit.bolum_adi ? <p className="bugun-personel-unit-bolum">{unit.bolum_adi}</p> : null}
                </button>
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
                {unit.bildirim.tamamlandi_mi && (unit.bildirim.eksik_giris ?? 0) > 0 ? (
                  <p
                    className="gunluk-eksik-giris-warning"
                    data-testid={`bugun-eksik-giris-${unit.birim_id ?? "none"}`}
                    role="status"
                  >
                    {unit.bildirim.eksik_giris} Personel Henüz Giriş Yapmadı
                  </p>
                ) : null}
              </div>
            ))}
          </div>
        ) : null}

        {!loading && !error && nav.kind === "unit_roster" ? (
          <div className="bugun-personel-person-list" role="list" data-testid="bugun-unit-roster">
            {nav.unit.personeller.length === 0 ? (
              <p className="bugun-personel-state">Bu birimde personel yok.</p>
            ) : (
              nav.unit.personeller.map((person) => (
                <button
                  key={person.personel_id}
                  type="button"
                  className="bugun-personel-person-row bugun-personel-person-row--btn"
                  role="listitem"
                  data-testid={`bugun-person-${person.personel_id}`}
                  onClick={() => openPerson(nav.branch, nav.unit, person, null)}
                >
                  <strong>{person.ad_soyad}</strong>
                  <span>{person.durum_label}</span>
                  <span className="bugun-personel-evidence">{evidenceLabel(person.evidence)}</span>
                </button>
              ))
            )}
          </div>
        ) : null}

        {!loading && !error && nav.kind === "status" ? (
          <div className="bugun-personel-person-list" role="list">
            {statusPersons.length === 0 ? (
              <p className="bugun-personel-state">Bu durumda personel yok.</p>
            ) : (
              statusPersons.map((person) => (
                <button
                  key={person.personel_id}
                  type="button"
                  className="bugun-personel-person-row bugun-personel-person-row--btn"
                  role="listitem"
                  data-testid={`bugun-person-${person.personel_id}`}
                  onClick={() => openPerson(nav.branch, nav.unit, person, nav.statusKey)}
                >
                  <strong>{person.ad_soyad}</strong>
                  <span>{person.detail_line}</span>
                </button>
              ))
            )}
          </div>
        ) : null}

        {!loading && !error && nav.kind === "person" ? (
          <div className="bugun-personel-person-detail" data-testid="bugun-person-detail">
            <div className="bugun-personel-person-detail-head">
              <strong>{nav.person.ad_soyad}</strong>
              <span>{nav.person.durum_label}</span>
            </div>
            <p className="bugun-personel-person-detail-line">{nav.person.detail_line}</p>
            <p className="bugun-personel-evidence">{evidenceLabel(nav.person.evidence)}</p>

            {canCorrect ? (
              <div className="bugun-personel-edit-actions">
                {!periodWritable ? (
                  <p className="bugun-personel-lock-note" data-testid="bugun-period-lock-note">
                    {PAYROLL_LOCK_EDIT_MESSAGE}
                  </p>
                ) : null}
                <button
                  type="button"
                  className="btn btn-secondary"
                  data-testid="bugun-person-edit"
                  disabled={!periodWritable || editing}
                  onClick={() => startEdit(nav.person)}
                >
                  Durumu Düzenle
                </button>
              </div>
            ) : null}

            {editing && editForm ? (
              <form
                className="bugun-personel-edit-form"
                data-testid="bugun-person-edit-form"
                onSubmit={(event) => void handleEditSubmit(event)}
              >
                <label className="bugun-personel-field">
                  <span>Durum</span>
                  <select
                    value={editForm.bildirimTuru}
                    onChange={(e) =>
                      setEditForm((prev) =>
                        prev
                          ? { ...prev, bildirimTuru: e.target.value as ScopedCorrectableTur }
                          : prev
                      )
                    }
                    data-testid="bugun-edit-tur"
                  >
                    {SCOPED_CORRECTABLE_TURLER.map((tur) => (
                      <option key={tur} value={tur}>
                        {SCOPED_CORRECTABLE_TUR_LABEL[tur]}
                      </option>
                    ))}
                  </select>
                </label>

                {turNeedsTimeFields(editForm.bildirimTuru) ? (
                  <>
                    <label className="bugun-personel-field">
                      <span>Dakika</span>
                      <input
                        type="number"
                        min={0}
                        value={editForm.dakika}
                        onChange={(e) =>
                          setEditForm((prev) => (prev ? { ...prev, dakika: e.target.value } : prev))
                        }
                        data-testid="bugun-edit-dakika"
                      />
                    </label>
                    <label className="bugun-personel-field">
                      <span>Başlangıç saati</span>
                      <input
                        type="time"
                        value={editForm.baslangicSaati}
                        onChange={(e) =>
                          setEditForm((prev) =>
                            prev ? { ...prev, baslangicSaati: e.target.value } : prev
                          )
                        }
                        data-testid="bugun-edit-baslangic"
                      />
                    </label>
                    <label className="bugun-personel-field">
                      <span>Bitiş saati</span>
                      <input
                        type="time"
                        value={editForm.bitisSaati}
                        onChange={(e) =>
                          setEditForm((prev) => (prev ? { ...prev, bitisSaati: e.target.value } : prev))
                        }
                        data-testid="bugun-edit-bitis"
                      />
                    </label>
                  </>
                ) : null}

                {turNeedsAltTur(editForm.bildirimTuru) ? (
                  <label className="bugun-personel-field">
                    <span>Alt tür</span>
                    <input
                      type="text"
                      value={editForm.altTur}
                      onChange={(e) =>
                        setEditForm((prev) => (prev ? { ...prev, altTur: e.target.value } : prev))
                      }
                      data-testid="bugun-edit-alt-tur"
                    />
                  </label>
                ) : null}

                <label className="bugun-personel-field">
                  <span>Açıklama{turRequiresAciklama(editForm.bildirimTuru) ? " *" : ""}</span>
                  <textarea
                    rows={2}
                    value={editForm.aciklama}
                    onChange={(e) =>
                      setEditForm((prev) => (prev ? { ...prev, aciklama: e.target.value } : prev))
                    }
                    data-testid="bugun-edit-aciklama"
                  />
                </label>

                <label className="bugun-personel-field">
                  <span>Düzeltme nedeni *</span>
                  <textarea
                    rows={2}
                    value={editForm.correctionReason}
                    onChange={(e) =>
                      setEditForm((prev) =>
                        prev ? { ...prev, correctionReason: e.target.value } : prev
                      )
                    }
                    data-testid="bugun-edit-reason"
                    required
                  />
                </label>

                {editError ? <p className="bugun-personel-form-error">{editError}</p> : null}

                <div className="bugun-personel-edit-footer">
                  <button
                    type="submit"
                    className="btn btn-primary"
                    disabled={editSubmitting}
                    data-testid="bugun-edit-save"
                  >
                    {editSubmitting ? "Kaydediliyor…" : "Kaydet"}
                  </button>
                  <button
                    type="button"
                    className="btn btn-secondary"
                    disabled={editSubmitting}
                    onClick={() => {
                      setEditing(false);
                      setEditForm(null);
                      setEditError(null);
                    }}
                  >
                    Vazgeç
                  </button>
                </div>
              </form>
            ) : null}

            <div className="bugun-personel-history" data-testid="bugun-duzeltme-gecmisi">
              <h3>Düzeltme Geçmişi</h3>
              {historyLoading ? <p className="bugun-personel-state">Yükleniyor…</p> : null}
              {!historyLoading && history.length === 0 ? (
                <p className="bugun-personel-history-empty">Kayıt yok.</p>
              ) : null}
              {!historyLoading && history.length > 0 ? (
                <ul className="bugun-personel-history-list">
                  {history.map((row) => (
                    <li key={row.id}>
                      <div>
                        {formatAuditClock(row.created_at)} · {formatAuditTransition(row)}
                      </div>
                      <div className="bugun-personel-history-actor">
                        {row.actor_ad_soyad?.trim() || `Kullanıcı #${row.actor_user_id}`}
                      </div>
                      {row.correction_reason ? (
                        <div className="bugun-personel-history-reason">“{row.correction_reason}”</div>
                      ) : null}
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>
          </div>
        ) : null}
      </div>
    </AppModal>
  );
}
