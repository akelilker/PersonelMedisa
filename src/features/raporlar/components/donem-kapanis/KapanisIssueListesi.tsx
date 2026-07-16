import { Link } from "react-router-dom";
import type { DonemKapanisIssue, DonemKapanisSeverity } from "../../../../api/donem-kapanis.api";
import {
  formatSeverityLabel,
  mapActionRouteToAppPath,
  severityClassName,
  SEVERITY_ICONS
} from "../../../../lib/donem-kapanis/display";
import { useRoleAccess } from "../../../../hooks/use-role-access";
import type { AppPermission } from "../../../../lib/authorization/role-permissions";

type KapanisIssueListesiProps = {
  blockers: DonemKapanisIssue[];
  warnings: DonemKapanisIssue[];
  infos: DonemKapanisIssue[];
  onShowItems: (issue: DonemKapanisIssue) => void;
};

function IssueRow({
  issue,
  onShowItems
}: {
  issue: DonemKapanisIssue;
  onShowItems: (issue: DonemKapanisIssue) => void;
}) {
  const { hasPermission } = useRoleAccess();
  const canNavigate =
    issue.action_permission && hasPermission(issue.action_permission as AppPermission);
  const route = mapActionRouteToAppPath(issue.action_route);

  return (
    <article
      className="kapanis-issue-row"
      data-testid={`donem-kapanis-issue-${issue.code}`}
      data-severity={issue.severity}
    >
      <div className="kapanis-issue-head">
        <span className={severityClassName(issue.severity)} data-testid={`donem-kapanis-severity-${issue.code}`}>
          <span aria-hidden="true">{SEVERITY_ICONS[issue.severity as DonemKapanisSeverity]}</span>
          {formatSeverityLabel(issue.severity as DonemKapanisSeverity)}
        </span>
        <strong>{issue.title}</strong>
        <span className="kapanis-issue-count">{issue.count}</span>
      </div>
      <p className="kapanis-issue-message">{issue.message}</p>
      <div className="kapanis-issue-actions">
        {issue.count > 0 ? (
          <button
            type="button"
            className="state-action-btn"
            data-testid={`donem-kapanis-issue-detail-${issue.code}`}
            onClick={() => onShowItems(issue)}
          >
            Detay listesi
          </button>
        ) : null}
        {canNavigate && issue.action_route ? (
          <Link to={route} data-testid={`donem-kapanis-issue-link-${issue.code}`}>
            {issue.domain === "etki_adayi" ? "Puantaj etki adayları" : "İlgili ekrana git"}
          </Link>
        ) : null}
      </div>
    </article>
  );
}

function IssueSection({
  title,
  issues,
  onShowItems,
  testId
}: {
  title: string;
  issues: DonemKapanisIssue[];
  onShowItems: (issue: DonemKapanisIssue) => void;
  testId: string;
}) {
  if (issues.length === 0) {
    return null;
  }

  return (
    <section className="kapanis-issue-section" data-testid={testId}>
      <h3>{title}</h3>
      <div className="kapanis-issue-list">
        {issues.map((issue) => (
          <IssueRow key={issue.code} issue={issue} onShowItems={onShowItems} />
        ))}
      </div>
    </section>
  );
}

export function KapanisIssueListesi({ blockers, warnings, infos, onShowItems }: KapanisIssueListesiProps) {
  const hasAny = blockers.length + warnings.length + infos.length > 0;

  if (!hasAny) {
    return (
      <p className="yonetim-hint" data-testid="donem-kapanis-issue-empty">
        Bu dönem için listelenecek açık iş bulunmuyor.
      </p>
    );
  }

  return (
    <div className="kapanis-issue-panel" data-testid="donem-kapanis-issue-listesi">
      <IssueSection title="Engelleyiciler" issues={blockers} onShowItems={onShowItems} testId="donem-kapanis-blockers" />
      <IssueSection title="Uyarılar" issues={warnings} onShowItems={onShowItems} testId="donem-kapanis-warnings" />
      <IssueSection title="Bilgilendirme" issues={infos} onShowItems={onShowItems} testId="donem-kapanis-infos" />
    </div>
  );
}
