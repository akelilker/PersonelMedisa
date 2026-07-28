import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const scriptDir = dirname(fileURLToPath(import.meta.url));
const repoRoot = resolve(scriptDir, '..');
const workflowPath = resolve(repoRoot, '.github/workflows/deploy-cpanel.yml');
const apiHtaccessPath = resolve(repoRoot, 'api/.htaccess');
const workflow = readFileSync(workflowPath, 'utf8');
const apiHtaccess = readFileSync(apiHtaccessPath, 'utf8');
const lines = workflow.split(/\r?\n/);
const htaccessLines = apiHtaccess.split(/\r?\n/);
const staticMirror = lines.find(
  (line) => /\bmirror\s+-R\b/.test(line) && /\s\.\s+\.;\s*$/.test(line),
) ?? '';

const failures = [];

function check(condition, message) {
  if (!condition) failures.push(message);
}

check(/REMOTE_TARGET_DIR:\s*\./.test(workflow), 'REMOTE_TARGET_DIR must remain FTP root (.)');
check(Boolean(staticMirror), 'static root mirror command was not found');
check(!/--delete\b/.test(staticMirror), 'static root mirror must not use --delete');
check(
  !lines.some((line) => /\bmirror\s+-R\b/.test(line) && /--delete\b/.test(line) && /\s\.\s+\.;\s*$/.test(line)),
  'global root mirror --delete contract must not exist',
);
check(/--exclude\s+api\//.test(staticMirror), 'static mirror must exclude api/');
check(/--exclude-glob\s+['"]\.git\*['"]/.test(staticMirror), 'static mirror must exclude .git*');
check(/--exclude-glob\s+['"]\.cpanel\*['"]/.test(staticMirror), 'static mirror must exclude .cpanel*');
check(/put\s+-O\s+api\s+api\/\.htaccess/.test(workflow), 'api/.htaccess upload contract is missing');
check(/mirror\s+-R\s+--verbose\s+api\/public\s+api\/public/.test(workflow), 'api/public upload contract is missing');
check(/mirror\s+-R\s+--verbose\s+api\/src\s+api\/src/.test(workflow), 'api/src upload contract is missing');
check(/test\s+!\s+-f\s+api\/config\.local\.php/.test(workflow), 'api/config.local.php deploy guard is missing');

const configLocalDenyRule =
  htaccessLines.find((line) => /RewriteRule\s+\^config\\\.local\\\.php/.test(line)) ?? '';
check(configLocalDenyRule !== '', 'api/config.local.php deny rule is missing');
check(
  /\[F,\s*L(?:,\s*NC)?\]/.test(configLocalDenyRule),
  'api/config.local.php deny rule must use [F,L] or [F,L,NC] security semantics',
);
check(!/\bR=\d+\b/.test(configLocalDenyRule), 'api/config.local.php deny rule must not redirect sensitive path');

const configLocalDenyIndex = htaccessLines.findIndex((line) =>
  /RewriteRule\s+\^config\\\.local\\\.php/.test(line),
);
const routerFallbackIndex = htaccessLines.findIndex((line) =>
  /RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-f/.test(line),
);
check(
  configLocalDenyIndex !== -1 && routerFallbackIndex !== -1 && configLocalDenyIndex < routerFallbackIndex,
  'api/config.local.php deny rule must precede router fallback',
);

const unsafeSecretEchoes = lines.filter(
  (line) => /\becho\b[^\n]*\$\{?(FTP_SERVER|FTP_USERNAME|FTP_PASSWORD)\}?/.test(line),
);
check(unsafeSecretEchoes.length === 0, 'FTP secret values must not be echoed');

if (failures.length > 0) {
  for (const failure of failures) {
    console.error(`verify-deploy-cpanel-safety: FAIL: ${failure}`);
  }
  process.exit(1);
}

console.log('verify-deploy-cpanel-safety: OK');
