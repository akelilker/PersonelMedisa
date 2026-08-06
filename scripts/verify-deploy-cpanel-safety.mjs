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

/** @returns {string|null} */
function extractRewritePattern(ruleLine) {
  const match = ruleLine.match(/RewriteRule\s+(\S+)\s+/);
  return match?.[1] ?? null;
}

/**
 * Simulate Apache RewriteRule path match with optional NC flag.
 * @param {string} pattern
 * @param {string} path
 * @param {{ nc?: boolean }} [options]
 */
function apacheRewriteMatches(pattern, path, options = {}) {
  const flags = options.nc === false ? '' : 'i';
  return new RegExp(pattern, flags).test(path);
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
check(/\bNC\b/.test(configLocalDenyRule), 'api/config.local.php deny rule must be case-insensitive [NC]');
check(!/\bR=\d+\b/.test(configLocalDenyRule), 'api/config.local.php deny rule must not redirect sensitive path');

const configDenyPattern = extractRewritePattern(configLocalDenyRule);
check(Boolean(configDenyPattern), 'api/config.local.php deny RewriteRule pattern could not be parsed');

const denyPaths = [
  'config.local.php',
  'config.local.php.s86bak.20260722165032',
  'config.local.php.bak',
  'config.local.php.backup',
  'config.local.php.old',
  'config.local.php.orig',
  'config.local.php.save',
  'config.local.php.tmp',
  'config.local.php~',
  'CONFIG.LOCAL.PHP.BAK',
  'config.local.php/foo',
  'config.local.php/anything',
];
const allowPaths = [
  'config.php',
  'configuration.local.php',
  'public/index.php',
  'health',
  'auth/login',
  'src/Router.php',
];

if (configDenyPattern) {
  for (const path of denyPaths) {
    check(
      apacheRewriteMatches(configDenyPattern, path, { nc: true }),
      `config deny pattern must DENY path: ${path}`,
    );
  }
  for (const path of allowPaths) {
    check(
      !apacheRewriteMatches(configDenyPattern, path, { nc: true }),
      `config deny pattern must ALLOW path: ${path}`,
    );
  }
}

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

const broadPhpDenyRules = htaccessLines.filter(
  (line) =>
    /RewriteRule\s+\^/.test(line) &&
    /\\\.php/.test(line) &&
    !/RewriteRule\s+\^config\\\.local\\\.php/.test(line) &&
    /\[\s*F\b/.test(line),
);
check(broadPhpDenyRules.length === 0, 'broad .php deny rule must not block normal PHP/API paths');
check(
  /RewriteRule\s+\^\s+public\/index\.php\s+\[L,\s*QSA\]/.test(apiHtaccess),
  'api/public/index.php router fallback must remain intact',
);

check(
  !/(?:put|mirror)\b[^\n]*config\.local\.php/.test(workflow),
  'deploy payload must not upload config.local.php or config.local.php.*',
);
check(
  !/mirror\s+-R\s+--verbose\s+api\/config\.local\.php/.test(workflow),
  'deploy must not mirror api/config.local.php',
);
check(
  /put\s+-O\s+api\s+api\/\.htaccess/.test(workflow) &&
    /mirror\s+-R\s+--verbose\s+api\/public\s+api\/public/.test(workflow) &&
    /mirror\s+-R\s+--verbose\s+api\/src\s+api\/src/.test(workflow) &&
    !/put\s+-O\s+api\s+api\/config\.local\.php/.test(workflow),
  'deploy payload must stay limited to .htaccess + public + src (no config.local.php~ / backups)',
);

const unsafeSecretEchoes = lines.filter(
  (line) =>
    /\becho\b[^\n]*\$\{?(FTP_SERVER|FTP_USERNAME|FTP_PASSWORD|SMOKE_AUTH_USERNAME|SMOKE_AUTH_PASSWORD)\}?/.test(
      line,
    ),
);
check(unsafeSecretEchoes.length === 0, 'deploy and smoke secret values must not be echoed');
check(/cancel-in-progress:\s*false/.test(workflow), 'active production deploy must not be cancelled by a newer run');
check(/timeout-minutes:\s*30/.test(workflow), 'deploy job must keep the bounded 30 minute timeout');
check(
  /SMOKE_AUTH_USERNAME:\s*\$\{\{ secrets\.SMOKE_AUTH_USERNAME \}\}/.test(workflow) &&
    /SMOKE_AUTH_PASSWORD:\s*\$\{\{ secrets\.SMOKE_AUTH_PASSWORD \}\}/.test(workflow),
  'deploy workflow must use dedicated authenticated smoke secrets',
);
check(/run:\s*npm run smoke:live/.test(workflow), 'deploy workflow must run post-deploy smoke');

if (failures.length > 0) {
  for (const failure of failures) {
    console.error(`verify-deploy-cpanel-safety: FAIL: ${failure}`);
  }
  process.exit(1);
}

console.log('verify-deploy-cpanel-safety: OK');
