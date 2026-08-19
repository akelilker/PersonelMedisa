import { useMemo } from "react";
import type { UserRole } from "../../../types/auth";
import { buildKullaniciRoleSummary } from "../../../lib/yonetim/kullanici-role-summary";

type KullaniciRoleSummaryPanelProps = {
  role: UserRole;
};

export function KullaniciRoleSummaryPanel(props: KullaniciRoleSummaryPanelProps) {
  const groups = useMemo(() => buildKullaniciRoleSummary(props.role), [props.role]);

  if (groups.length === 0) {
    return (
      <div className="yonetim-workspace-panel" data-testid="yonetim-kullanici-role-summary">
        <p className="yonetim-hint">Seçili rol için özet yetki bulunamadı.</p>
      </div>
    );
  }

  return (
    <div className="yonetim-workspace-panel" data-testid="yonetim-kullanici-role-summary">
      <p className="yonetim-workspace-panel-title">Rol yetki özeti</p>
      <p className="yonetim-hint">Tam yetki matrisi kod owner&apos;ında kalır; burada yalnızca seçili rolün ana erişim alanları gösterilir.</p>
      <div className="yonetim-role-summary-grid">
        {groups.map((group) => (
          <section key={group.title} className="yonetim-role-summary-group" aria-label={group.title}>
            <h4>{group.title}</h4>
            <ul>
              {group.items.map((item) => (
                <li key={item}>{item}</li>
              ))}
            </ul>
          </section>
        ))}
      </div>
    </div>
  );
}
