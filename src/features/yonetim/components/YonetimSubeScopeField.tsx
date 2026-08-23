import type { UserRole } from "../../../types/auth";
import {
  BIRIM_ASSIGNMENT_ROLES,
  BOLUM_ASSIGNMENT_ROLES,
  GLOBAL_SCOPE_ROLES,
  SUBE_ASSIGNMENT_ROLES
} from "../../../types/auth";
import type { YonetimSube } from "../../../types/yonetim";

type OrgOption = { id: number; ad: string; parentLabel?: string };

type YonetimOrgScopeFieldsProps = {
  role: UserRole;
  subeler: YonetimSube[];
  bolumler: OrgOption[];
  birimler: OrgOption[];
  selectedSubeIds: number[];
  selectedBolumIds: number[];
  selectedBirimIds: number[];
  onToggleSube: (subeId: number) => void;
  onToggleBolum: (bolumId: number) => void;
  onToggleBirim: (birimId: number) => void;
};

function isGlobal(role: UserRole): boolean {
  return (GLOBAL_SCOPE_ROLES as readonly string[]).includes(role);
}

function needsSube(role: UserRole): boolean {
  return (SUBE_ASSIGNMENT_ROLES as readonly string[]).includes(role) || isGlobal(role);
}

function needsBolum(role: UserRole): boolean {
  return (BOLUM_ASSIGNMENT_ROLES as readonly string[]).includes(role);
}

function needsBirim(role: UserRole): boolean {
  return (BIRIM_ASSIGNMENT_ROLES as readonly string[]).includes(role);
}

/** Role-specific organizational assignment controls for Kullanıcı Yönetimi. */
export function YonetimOrgScopeFields(props: YonetimOrgScopeFieldsProps) {
  const showSube = needsSube(props.role) && !needsBolum(props.role) && !needsBirim(props.role);
  // Global may optionally keep branch assignment UI.
  const showSubeOptional = isGlobal(props.role);
  const showBolum = needsBolum(props.role);
  const showBirim = needsBirim(props.role);

  return (
    <>
      {(showSube || showSubeOptional) && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-sube-scope-field">
          <p className="yonetim-checkbox-title">Şube Yetkisi</p>
          <p className="yonetim-hint">
            {showSubeOptional
              ? "Boş bırakılırsa tüm şubeler (global kapsam)."
              : "Bu rol için en az bir şube zorunludur. Boş = erişim yok."}
          </p>
          <div className="yonetim-selection-grid">
            {props.subeler.map((sube) => (
              <button
                key={sube.id}
                type="button"
                className={`yonetim-selection-pill${props.selectedSubeIds.includes(sube.id) ? " is-selected" : ""}`}
                aria-pressed={props.selectedSubeIds.includes(sube.id)}
                onClick={() => props.onToggleSube(sube.id)}
              >
                <strong>{sube.ad}</strong>
                <span>{sube.departman_adlari.join(", ") || "Departman tanımlı değil"}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {showBolum && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-bolum-scope-field">
          <p className="yonetim-checkbox-title">Bölüm Yetkisi</p>
          <p className="yonetim-hint">BOLUM_YONETICISI için en az bir bölüm zorunludur.</p>
          <div className="yonetim-selection-grid">
            {props.bolumler.map((bolum) => (
              <button
                key={bolum.id}
                type="button"
                className={`yonetim-selection-pill${props.selectedBolumIds.includes(bolum.id) ? " is-selected" : ""}`}
                aria-pressed={props.selectedBolumIds.includes(bolum.id)}
                onClick={() => props.onToggleBolum(bolum.id)}
              >
                <strong>{bolum.ad}</strong>
                <span>{bolum.parentLabel || "Bölüm"}</span>
              </button>
            ))}
          </div>
        </div>
      )}

      {showBirim && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-birim-scope-field">
          <p className="yonetim-checkbox-title">Birim Yetkisi</p>
          <p className="yonetim-hint">BIRIM_AMIRI için en az bir birim zorunludur.</p>
          <div className="yonetim-selection-grid">
            {props.birimler.map((birim) => (
              <button
                key={birim.id}
                type="button"
                className={`yonetim-selection-pill${props.selectedBirimIds.includes(birim.id) ? " is-selected" : ""}`}
                aria-pressed={props.selectedBirimIds.includes(birim.id)}
                onClick={() => props.onToggleBirim(birim.id)}
              >
                <strong>{birim.ad}</strong>
                <span>{birim.parentLabel || "Birim"}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </>
  );
}

/** @deprecated Prefer YonetimOrgScopeFields */
export function YonetimSubeScopeField(props: {
  subeler: YonetimSube[];
  selectedSubeIds: number[];
  onToggleSube: (subeId: number) => void;
}) {
  return (
    <YonetimOrgScopeFields
      role="SUBE_YONETICISI"
      subeler={props.subeler}
      bolumler={[]}
      birimler={[]}
      selectedSubeIds={props.selectedSubeIds}
      selectedBolumIds={[]}
      selectedBirimIds={[]}
      onToggleSube={props.onToggleSube}
      onToggleBolum={() => undefined}
      onToggleBirim={() => undefined}
    />
  );
}
