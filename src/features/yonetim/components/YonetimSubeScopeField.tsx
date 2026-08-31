import type { UserRole } from "../../../types/auth";
import {
  BIRIM_ASSIGNMENT_ROLES,
  BOLUM_ASSIGNMENT_ROLES,
  GLOBAL_SCOPE_ROLES,
  ORGANIZATION_GLOBAL_READ_ROLES,
  SUBE_ASSIGNMENT_ROLES,
  WRITE_COMPANY_SCOPED_ROLES
} from "../../../types/auth";
import type { YonetimSirket, YonetimSube } from "../../../types/yonetim";

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
  sirketler?: YonetimSirket[];
  selectedSirketIds?: number[];
  onToggleSirket?: (sirketId: number) => void;
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

function isOrganizationGlobalRead(role: UserRole): boolean {
  return (ORGANIZATION_GLOBAL_READ_ROLES as readonly string[]).includes(role);
}

function needsWriteCompany(role: UserRole): boolean {
  return (WRITE_COMPANY_SCOPED_ROLES as readonly string[]).includes(role);
}

/** Role-specific organizational assignment controls for Kullanıcı Yönetimi. */
export function YonetimOrgScopeFields(props: YonetimOrgScopeFieldsProps) {
  const showSube = needsSube(props.role) && !needsBolum(props.role) && !needsBirim(props.role);
  // Global may optionally keep branch assignment UI.
  const showSubeOptional = isGlobal(props.role);
  const showBolum = needsBolum(props.role);
  const showBirim = needsBirim(props.role);

  const showOrganizationGlobalRead = isOrganizationGlobalRead(props.role);
  const showWriteCompany = needsWriteCompany(props.role);
  const sirketler = props.sirketler ?? [];
  const selectedSirketIds = props.selectedSirketIds ?? [];

  return (
    <>
      {showOrganizationGlobalRead && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-ik-global-scope-note">
          <p className="yonetim-checkbox-title">Organizasyon erişimi</p>
          <p className="yonetim-hint">
            {showWriteCompany
              ? "Bu rol tüm şirket ve şubeleri görüntüler. Şube seçimi gerekmez; sonradan açılan şubeler de otomatik görünür."
              : "Tüm şirket ve şubelerde İK erişimi. Şube seçimi gerekmez; sonradan açılan şubeler de otomatik kapsama girer."}
          </p>
        </div>
      )}

      {showWriteCompany && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-sirket-write-scope-field">
          <p className="yonetim-checkbox-title">İşlem yapabileceği şirketler</p>
          <p className="yonetim-hint">
            En az bir şirket seçilmelidir. Seçilmeyen şirketlerin kayıtları görüntülenir ancak
            değiştirilemez; bu işlemi İK sorumlusu kendi hesabıyla gerçekleştirir.
          </p>
          <div className="yonetim-selection-grid">
            {sirketler.map((sirket) => (
              <button
                key={sirket.id}
                type="button"
                className={`yonetim-selection-pill${selectedSirketIds.includes(sirket.id) ? " is-selected" : ""}`}
                aria-pressed={selectedSirketIds.includes(sirket.id)}
                onClick={() => props.onToggleSirket?.(sirket.id)}
              >
                <strong>{sirket.ad}</strong>
                <span>{sirket.kod}</span>
              </button>
            ))}
          </div>
        </div>
      )}

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
                <strong>{sube.tam_ad}</strong>
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
          <p className="yonetim-hint">Birim Yöneticisi için en az bir birim zorunludur.</p>
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
