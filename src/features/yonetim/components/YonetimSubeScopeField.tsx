import type { UserRole } from "../../../types/auth";
import {
  BIRIM_ASSIGNMENT_ROLES,
  BOLUM_ASSIGNMENT_ROLES,
  GLOBAL_SCOPE_ROLES,
  ORGANIZATION_GLOBAL_READ_ROLES,
  SGK_SCOPE_ELIGIBLE_ROLES,
  WRITE_COMPANY_SCOPED_ROLES
} from "../../../types/auth";
import type { YonetimOrgRelation, YonetimSirket, YonetimSube } from "../../../types/yonetim";

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
  sgkIsverenler?: YonetimOrgRelation[];
  selectedSgkIsverenIds?: number[];
  onToggleSgkIsveren?: (sgkIsverenId: number) => void;
};

function isGlobal(role: UserRole): boolean {
  return (GLOBAL_SCOPE_ROLES as readonly string[]).includes(role);
}

function needsRequiredSube(role: UserRole): boolean {
  return role === "SUBE_YONETICISI";
}

function needsOptionalSube(role: UserRole): boolean {
  return role === "MUHASEBE" || isGlobal(role);
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

/** Company visibility grant (live branch resolution), not İK write-company. */
function needsCompanyVisibility(role: UserRole): boolean {
  return role === "MUHASEBE";
}

function needsSgkScope(role: UserRole): boolean {
  return (SGK_SCOPE_ELIGIBLE_ROLES as readonly string[]).includes(role);
}

/** Role-specific organizational assignment controls for Kullanıcı Yönetimi. */
export function YonetimOrgScopeFields(props: YonetimOrgScopeFieldsProps) {
  const showRequiredSube = needsRequiredSube(props.role) && !needsBolum(props.role) && !needsBirim(props.role);
  const showOptionalSube = needsOptionalSube(props.role);
  const showBolum = needsBolum(props.role);
  const showBirim = needsBirim(props.role);

  const showOrganizationGlobalRead = isOrganizationGlobalRead(props.role);
  const showWriteCompany = needsWriteCompany(props.role);
  const showCompanyVisibility = needsCompanyVisibility(props.role);
  const showSgk = needsSgkScope(props.role);
  const sirketler = props.sirketler ?? [];
  const selectedSirketIds = props.selectedSirketIds ?? [];
  const sgkIsverenler = props.sgkIsverenler ?? [];
  const selectedSgkIsverenIds = props.selectedSgkIsverenIds ?? [];

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

      {showCompanyVisibility && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-sirket-visibility-scope-field">
          <p className="yonetim-checkbox-title">Şirket kapsamı</p>
          <p className="yonetim-hint">
            Seçilen şirketin mevcut ve sonradan açılan şubeleri otomatik kapsama girer; her yeni şube
            için ayrı şube ataması gerekmez. Şube ve/veya SGK kapsamı ile birlikte kullanılabilir.
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

      {showSgk && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-sgk-scope-field">
          <p className="yonetim-checkbox-title">SGK / bordro işveren kapsamı</p>
          <p className="yonetim-hint">
            Personelin SGK işveren birimine göre bordro/SGK erişimi sağlar. Fiziksel şube yetkisi
            üretmez; şube seçiminin yerine geçmez.
          </p>
          <div className="yonetim-selection-grid">
            {sgkIsverenler.length === 0 ? (
              <p className="yonetim-hint">Tanımlı SGK işvereni bulunamadı.</p>
            ) : (
              sgkIsverenler.map((sgk) => (
                <button
                  key={sgk.id}
                  type="button"
                  className={`yonetim-selection-pill${selectedSgkIsverenIds.includes(sgk.id) ? " is-selected" : ""}`}
                  aria-pressed={selectedSgkIsverenIds.includes(sgk.id)}
                  onClick={() => props.onToggleSgkIsveren?.(sgk.id)}
                >
                  <strong>{sgk.ad}</strong>
                  <span>{sgk.kod ?? "SGK"}</span>
                </button>
              ))
            )}
          </div>
        </div>
      )}

      {(showRequiredSube || showOptionalSube) && (
        <div className="yonetim-checkbox-section" data-testid="yonetim-sube-scope-field">
          <p className="yonetim-checkbox-title">Şube Yetkisi</p>
          <p className="yonetim-hint">
            {showOptionalSube && props.role === "MUHASEBE"
              ? "İsteğe bağlı. Şirket veya SGK kapsamı yoksa en az bir şube zorunludur."
              : showOptionalSube
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
