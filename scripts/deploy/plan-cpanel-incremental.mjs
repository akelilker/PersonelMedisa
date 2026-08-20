/**
 * Safe cPanel incremental deploy planner.
 *
 * Source of truth for API delta: git name-status between previous and current SHA.
 * Frontend: build artifact (dist/) with assets-before-entrypoint publish order.
 * Never uses FTP mtime or "only newer" flags as the decision mechanism.
 *
 * CLI:
 *   node scripts/deploy/plan-cpanel-incremental.mjs \
 *     --previous-sha=<40hex|NONE> \
 *     --current-sha=<40hex> \
 *     --repo-root=. \
 *     --dist-dir=dist \
 *     --out-dir=/tmp/deploy-plan \
 *     [--deploy-sha-local-path=/tmp/.deploy-sha]
 */

import { execFileSync } from 'node:child_process';
import {
  existsSync,
  mkdirSync,
  readdirSync,
  statSync,
  writeFileSync,
} from 'node:fs';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

export const SHA_RE = /^[0-9a-f]{40}$/i;

/** Paths incremental deploy may upload or exact-delete. */
export const API_OWNED_PREFIXES = Object.freeze([
  'api/public/',
  'api/src/',
  'api/bin/',
  'api/migrations/',
]);

export const API_OWNED_FILES = Object.freeze(['api/.htaccess']);

/** Always uploaded (generated / protect), never deleted by incremental. */
export const ALWAYS_UPLOAD_PATHS = Object.freeze([
  'api/runtime/.htaccess',
  'api/runtime-build/canonical-migrations.php',
]);

/** Never delete / never touch via incremental delete. */
export const PROTECTED_PATH_PREFIXES = Object.freeze([
  'api/runtime/',
  'api/config.local.php',
]);

export const FRONTEND_ENTRYPOINTS = Object.freeze([
  'index.html',
  'sw.js',
  'service-worker.js',
  'serviceWorker.js',
]);

/**
 * @typedef {'INCREMENTAL' | 'FULL_MIRROR_FALLBACK'} DeployMode
 * @typedef {'FULL_ORDERED' | 'FULL_MIRROR'} FrontendDeployMode
 *
 * @typedef {{
 *   status: 'A' | 'M' | 'D' | 'R',
 *   path: string,
 *   fromPath?: string,
 * }} ApiChange
 *
 * @typedef {{
 *   mode: DeployMode,
 *   frontendMode: FrontendDeployMode,
 *   previousSha: string | null,
 *   currentSha: string,
 *   fallbackReason: string | null,
 *   apiChanges: ApiChange[],
 *   apiUploads: string[],
 *   apiDeletes: string[],
 *   unchangedApiFilesSkipped: number,
 *   frontendAssets: string[],
 *   frontendEntrypoints: string[],
 *   frontendSupporting: string[],
 *   alwaysUploads: string[],
 * }} DeployPlan
 */

export function isValidSha(value) {
  return typeof value === 'string' && SHA_RE.test(value);
}

export function normalizeRelativePath(raw) {
  if (typeof raw !== 'string' || raw.trim() === '') {
    return null;
  }
  let path = raw.trim().replace(/\\/g, '/');
  if (path.startsWith('./')) {
    path = path.slice(2);
  }
  if (path.startsWith('/') || /^[A-Za-z]:\//.test(path)) {
    return null;
  }
  if (path.includes('\0') || path.split('/').some((part) => part === '..')) {
    return null;
  }
  if (path.includes('//')) {
    return null;
  }
  return path;
}

export function isProtectedPath(path) {
  const normalized = normalizeRelativePath(path);
  if (!normalized) {
    return true;
  }
  if (normalized === 'api/config.local.php' || normalized.startsWith('api/config.local.php')) {
    return true;
  }
  if (normalized.startsWith('api/runtime/') && normalized !== 'api/runtime/.htaccess') {
    return true;
  }
  return false;
}

export function isApiOwnedPath(path) {
  const normalized = normalizeRelativePath(path);
  if (!normalized || isProtectedPath(normalized)) {
    return false;
  }
  if (API_OWNED_FILES.includes(normalized)) {
    return true;
  }
  return API_OWNED_PREFIXES.some((prefix) => normalized.startsWith(prefix));
}

export function isSafeExactDeletePath(path) {
  const normalized = normalizeRelativePath(path);
  if (!normalized || !isApiOwnedPath(normalized)) {
    return false;
  }
  if (normalized.endsWith('/')) {
    return false;
  }
  // Never delete entire trees; only exact owned files.
  if (ALWAYS_UPLOAD_PATHS.includes(normalized)) {
    return false;
  }
  if (normalized.startsWith('api/runtime/')) {
    return false;
  }
  return true;
}

/**
 * Strict allowlist for paths embedded into lftp -e scripts.
 * Rejects whitespace, quotes, and shell/lftp command separators.
 * @param {string} path
 */
export function isLftpSafePath(path) {
  const normalized = normalizeRelativePath(path);
  if (!normalized) {
    return false;
  }
  // Keep incremental command generation boring and unambiguous.
  return /^[A-Za-z0-9._/-]+$/.test(normalized);
}

/**
 * @param {string} line
 * @returns {ApiChange | { error: string }}
 */
export function parseNameStatusLine(line) {
  const trimmed = line.trimEnd();
  if (!trimmed) {
    return { error: 'EMPTY_DIFF_LINE' };
  }
  const parts = trimmed.split('\t');
  const statusToken = parts[0] ?? '';
  const status = statusToken[0];

  if (status === 'R' || status === 'C') {
    if (parts.length < 3) {
      return { error: 'MALFORMED_RENAME_LINE' };
    }
    const fromPath = normalizeRelativePath(parts[1]);
    const toPath = normalizeRelativePath(parts[2]);
    if (!fromPath || !toPath) {
      return { error: 'UNSAFE_RENAME_PATH' };
    }
    return { status: 'R', path: toPath, fromPath };
  }

  if (parts.length < 2) {
    return { error: 'MALFORMED_DIFF_LINE' };
  }
  const path = normalizeRelativePath(parts[1]);
  if (!path) {
    return { error: 'UNSAFE_DIFF_PATH' };
  }
  if (status === 'A') {
    return { status: 'A', path };
  }
  if (status === 'M' || status === 'T') {
    return { status: 'M', path };
  }
  if (status === 'D') {
    return { status: 'D', path };
  }
  return { error: `UNSUPPORTED_DIFF_STATUS_${statusToken}` };
}

/**
 * @param {string[]} lines
 * @returns {{ changes: ApiChange[], error: string | null }}
 */
export function collectApiChangesFromDiffLines(lines) {
  /** @type {ApiChange[]} */
  const changes = [];
  for (const line of lines) {
    if (!line.trim()) {
      continue;
    }
    const parsed = parseNameStatusLine(line);
    if ('error' in parsed) {
      return { changes: [], error: parsed.error };
    }

    if (parsed.status === 'R') {
      const fromOwned = isApiOwnedPath(parsed.fromPath ?? '');
      const toOwned = isApiOwnedPath(parsed.path);
      if (!fromOwned && !toOwned) {
        continue;
      }
      if (fromOwned !== toOwned) {
        return { changes: [], error: 'RENAME_CROSSES_OWNERSHIP_BOUNDARY' };
      }
      if (!isSafeExactDeletePath(parsed.fromPath ?? '')) {
        return { changes: [], error: 'UNSAFE_RENAME_DELETE_PATH' };
      }
      if (!isApiOwnedPath(parsed.path)) {
        return { changes: [], error: 'UNSAFE_RENAME_UPLOAD_PATH' };
      }
      changes.push(parsed);
      continue;
    }

    if (parsed.status === 'D') {
      if (!isSafeExactDeletePath(parsed.path)) {
        if (parsed.path.startsWith('api/')) {
          return { changes: [], error: 'UNSAFE_DELETE_PATH' };
        }
        continue;
      }
      changes.push(parsed);
      continue;
    }

    if (!isApiOwnedPath(parsed.path)) {
      // Non-API path changes (frontend source, docs, workflows) do not block incremental API.
      continue;
    }
    changes.push(parsed);
  }
  return { changes, error: null };
}

/**
 * @param {ApiChange[]} changes
 */
export function planApiTransfers(changes) {
  const uploads = new Set();
  const deletes = new Set();

  for (const change of changes) {
    if (change.status === 'A' || change.status === 'M') {
      uploads.add(change.path);
    } else if (change.status === 'D') {
      deletes.add(change.path);
    } else if (change.status === 'R') {
      if (change.fromPath) {
        deletes.add(change.fromPath);
      }
      uploads.add(change.path);
    }
  }

  // If a path is both deleted and uploaded (shouldn't happen), prefer upload.
  for (const path of uploads) {
    deletes.delete(path);
  }

  return {
    uploads: [...uploads].sort(),
    deletes: [...deletes].sort(),
  };
}

/**
 * @param {string} distDir
 */
export function classifyFrontendArtifacts(distDir) {
  const root = resolve(distDir);
  if (!existsSync(root)) {
    throw new Error(`DIST_MISSING:${distDir}`);
  }

  /** @type {string[]} */
  const assets = [];
  /** @type {string[]} */
  const entrypoints = [];
  /** @type {string[]} */
  const supporting = [];

  /**
   * @param {string} absDir
   * @param {string} relDir
   */
  function walk(absDir, relDir) {
    for (const name of readdirSync(absDir)) {
      const abs = join(absDir, name);
      const rel = relDir ? `${relDir}/${name}` : name;
      const st = statSync(abs);
      if (st.isDirectory()) {
        walk(abs, rel);
        continue;
      }
      if (FRONTEND_ENTRYPOINTS.includes(rel) || FRONTEND_ENTRYPOINTS.includes(name)) {
        entrypoints.push(rel.replace(/\\/g, '/'));
      } else if (rel.replace(/\\/g, '/').startsWith('assets/')) {
        assets.push(rel.replace(/\\/g, '/'));
      } else {
        supporting.push(rel.replace(/\\/g, '/'));
      }
    }
  }

  walk(root, '');
  return {
    assets: assets.sort(),
    entrypoints: entrypoints.sort(),
    supporting: supporting.sort(),
  };
}

/**
 * @param {{ repoRoot: string, prefixes?: string[] }} args
 */
export function countOwnedApiFiles(args) {
  const prefixes = args.prefixes ?? [...API_OWNED_PREFIXES, ...API_OWNED_FILES];
  let count = 0;
  for (const item of prefixes) {
    const abs = resolve(args.repoRoot, item);
    if (!existsSync(abs)) {
      continue;
    }
    const st = statSync(abs);
    if (st.isFile()) {
      count += 1;
      continue;
    }
    const walk = (dir) => {
      for (const name of readdirSync(dir)) {
        const child = join(dir, name);
        const childSt = statSync(child);
        if (childSt.isDirectory()) {
          walk(child);
        } else {
          count += 1;
        }
      }
    };
    walk(abs);
  }
  return count;
}

/**
 * @param {{
 *   previousSha: string | null | undefined,
 *   currentSha: string,
 *   repoRoot: string,
 *   distDir: string,
 *   diffLines?: string[] | null,
 *   ancestryOk?: boolean | null,
 *   fetchOk?: boolean | null,
 * }} input
 * @returns {DeployPlan}
 */
export function createDeployPlan(input) {
  const currentSha = (input.currentSha ?? '').trim().toLowerCase();
  const previousRaw = input.previousSha == null ? '' : String(input.previousSha).trim();
  const previousSha =
    previousRaw && previousRaw !== 'NONE' ? previousRaw.toLowerCase() : null;

  const frontend = classifyFrontendArtifacts(input.distDir);
  const ownedCount = countOwnedApiFiles({ repoRoot: input.repoRoot });
  const alwaysUploads = ALWAYS_UPLOAD_PATHS.filter((path) =>
    existsSync(resolve(input.repoRoot, path)),
  );

  /** @type {DeployPlan} */
  const base = {
    mode: 'FULL_MIRROR_FALLBACK',
    frontendMode: 'FULL_ORDERED',
    previousSha,
    currentSha,
    fallbackReason: null,
    apiChanges: [],
    apiUploads: [],
    apiDeletes: [],
    unchangedApiFilesSkipped: 0,
    frontendAssets: frontend.assets,
    frontendEntrypoints: frontend.entrypoints,
    frontendSupporting: frontend.supporting,
    alwaysUploads,
  };

  if (!isValidSha(currentSha)) {
    return { ...base, fallbackReason: 'CURRENT_SHA_INVALID' };
  }
  if (!previousSha) {
    return { ...base, fallbackReason: 'PREVIOUS_SHA_MISSING' };
  }
  if (!isValidSha(previousSha)) {
    return { ...base, fallbackReason: 'PREVIOUS_SHA_INVALID' };
  }
  if (previousSha === currentSha) {
    // Same SHA redeploy: still safe to skip bulk API re-upload; only always-upload + FE.
    return {
      ...base,
      mode: 'INCREMENTAL',
      fallbackReason: null,
      apiUploads: [],
      apiDeletes: [],
      unchangedApiFilesSkipped: ownedCount,
    };
  }
  if (input.fetchOk === false) {
    return { ...base, fallbackReason: 'PREVIOUS_SHA_FETCH_FAILED' };
  }
  if (input.ancestryOk === false) {
    return { ...base, fallbackReason: 'PREVIOUS_SHA_NOT_ANCESTOR' };
  }

  const lines = input.diffLines;
  if (!Array.isArray(lines)) {
    return { ...base, fallbackReason: 'DIFF_UNAVAILABLE' };
  }

  const collected = collectApiChangesFromDiffLines(lines);
  if (collected.error) {
    return { ...base, fallbackReason: collected.error };
  }

  const transfers = planApiTransfers(collected.changes);
  for (const path of transfers.deletes) {
    if (!isSafeExactDeletePath(path)) {
      return { ...base, fallbackReason: 'UNSAFE_DELETE_PATH' };
    }
  }
  for (const path of transfers.uploads) {
    if (!isApiOwnedPath(path)) {
      return { ...base, fallbackReason: 'UNSAFE_UPLOAD_PATH' };
    }
  }

  const commandPaths = [...transfers.uploads, ...transfers.deletes, ...alwaysUploads];
  for (const path of commandPaths) {
    if (!isLftpSafePath(path)) {
      return { ...base, fallbackReason: 'UNSAFE_LFTP_PATH' };
    }
  }

  const touched = new Set([...transfers.uploads, ...transfers.deletes]);
  const skipped = Math.max(0, ownedCount - touched.size);

  return {
    ...base,
    mode: 'INCREMENTAL',
    fallbackReason: null,
    apiChanges: collected.changes,
    apiUploads: transfers.uploads,
    apiDeletes: transfers.deletes,
    unchangedApiFilesSkipped: skipped,
  };
}

/**
 * Quote a path for lftp -e scripts. Caller must pass isLftpSafePath-approved paths
 * for incremental-owned git paths; local absolute dirs are quoted literally.
 * @param {string} path
 */
export function lftpQuote(path) {
  const normalized = String(path).replace(/\\/g, '/');
  if (normalized.includes('"') || normalized.includes('\n') || normalized.includes('\r')) {
    throw new Error(`UNSAFE_LFTP_QUOTE:${path}`);
  }
  return `"${normalized}"`;
}

function renderCommonPreamble(dist, remote) {
  return `
              set cmd:fail-exit true;
              set cmd:verbose on;
              set net:max-retries 2;
              set net:timeout 20;
              set net:reconnect-interval-base 5;
              set net:reconnect-interval-max 10;
              set xfer:clobber true;
              set ssl:verify-certificate no;
              set ssl:check-hostname no;
              set ftp:passive-mode on;
              lcd ${dist};
              !echo 'FTP root hedefleniyor';
              cd ${remote};
`.trim();
}

/**
 * Payload transfer only — never writes api/.deploy-sha.
 * Order: FE assets → API → exact deletes → FE entrypoints → optional legacy cleanup.
 *
 * @param {DeployPlan} plan
 * @param {{
 *   localDistDir: string,
 *   localRepoDir: string,
 *   remoteTargetDir: string,
 *   deployShaLocalPath: string,
 * }} ctx
 */
export function renderFullMirrorPayloadCommands(plan, ctx) {
  const dist = lftpQuote(ctx.localDistDir.replace(/\/$/, ''));
  const repo = lftpQuote(ctx.localRepoDir);
  const remote = lftpQuote(ctx.remoteTargetDir);

  const entryExcludes = plan.frontendEntrypoints
    .map((name) => `--exclude ${lftpQuote(name)}`)
    .join(' ');

  const entryPuts = plan.frontendEntrypoints
    .map((name) => `put ${lftpQuote(name)};`)
    .join('\n              ');

  return `
              ${renderCommonPreamble(dist, remote)}
              !echo 'SPA assets+supporting (entrypoint haric) upload basliyor';
              mirror -R --verbose --exclude api/ --exclude-glob '.git*' --exclude-glob '.cpanel*' ${entryExcludes} . .;
              !echo 'PHP API full mirror upload basliyor';
              lcd ${repo};
              mkdir -p api;
              put -O api api/.htaccess;
              mirror -R --verbose api/public api/public;
              mirror -R --verbose api/src api/src;
              mirror -R --verbose api/bin api/bin;
              mirror -R --verbose api/migrations api/migrations;
              mirror -R --verbose api/runtime-build api/runtime-build;
              mkdir -p api/runtime;
              put -O api/runtime api/runtime/.htaccess;
              !echo 'SPA entrypoint upload basliyor';
              lcd ${dist};
              ${entryPuts || '!echo no-entrypoint;'}
              set cmd:fail-exit false;
              glob -a rm api/public/_migration_*.php;
              set cmd:fail-exit true;
              !echo 'payload transfer tamamlandi';
`.trim();
}

/**
 * @deprecated Use renderFullMirrorPayloadCommands — kept name for source contracts.
 */
export function renderFullMirrorFtpCommands(plan, ctx) {
  return renderFullMirrorPayloadCommands(plan, ctx);
}

/**
 * Payload transfer only — never writes api/.deploy-sha.
 *
 * @param {DeployPlan} plan
 * @param {{
 *   localDistDir: string,
 *   localRepoDir: string,
 *   remoteTargetDir: string,
 *   deployShaLocalPath: string,
 * }} ctx
 */
export function renderIncrementalPayloadCommands(plan, ctx) {
  if (plan.mode !== 'INCREMENTAL') {
    return renderFullMirrorPayloadCommands(plan, ctx);
  }

  const dist = lftpQuote(ctx.localDistDir.replace(/\/$/, ''));
  const repo = lftpQuote(ctx.localRepoDir);
  const remote = lftpQuote(ctx.remoteTargetDir);

  const entryExcludes = plan.frontendEntrypoints
    .map((name) => `--exclude ${lftpQuote(name)}`)
    .join(' ');
  const entryPuts = plan.frontendEntrypoints
    .map((name) => `put ${lftpQuote(name)};`)
    .join('\n              ');

  const mkdirCmds = new Set();
  for (const path of [...plan.apiUploads, ...plan.alwaysUploads]) {
    if (!isLftpSafePath(path)) {
      throw new Error(`UNSAFE_LFTP_PATH:${path}`);
    }
    const dir = dirname(path).replace(/\\/g, '/');
    if (dir && dir !== '.') {
      mkdirCmds.add(`mkdir -p ${lftpQuote(dir)};`);
    }
  }

  const uploadCmds = [];
  for (const path of plan.apiUploads) {
    const dir = dirname(path).replace(/\\/g, '/');
    uploadCmds.push(`put -O ${lftpQuote(dir)} ${lftpQuote(path)};`);
  }
  for (const path of plan.alwaysUploads) {
    if (!isLftpSafePath(path)) {
      throw new Error(`UNSAFE_LFTP_PATH:${path}`);
    }
    const dir = dirname(path).replace(/\\/g, '/');
    uploadCmds.push(`put -O ${lftpQuote(dir)} ${lftpQuote(path)};`);
  }

  const deleteCmds = [];
  for (const path of plan.apiDeletes) {
    if (!isLftpSafePath(path) || !isSafeExactDeletePath(path)) {
      throw new Error(`UNSAFE_LFTP_DELETE:${path}`);
    }
    // Exact owned deletes are fail-closed (cmd:fail-exit remains true).
    deleteCmds.push(`rm ${lftpQuote(path)};`);
  }

  return `
              ${renderCommonPreamble(dist, remote)}
              !echo 'SPA assets+supporting (entrypoint haric) upload basliyor';
              mirror -R --verbose --exclude api/ --exclude-glob '.git*' --exclude-glob '.cpanel*' ${entryExcludes} . .;
              !echo 'PHP API incremental upload basliyor';
              lcd ${repo};
              mkdir -p api;
              ${[...mkdirCmds].join('\n              ')}
              ${uploadCmds.join('\n              ') || '!echo no-api-uploads;'}
              !echo 'API exact deletes basliyor';
              ${deleteCmds.join('\n              ') || '!echo no-api-deletes;'}
              !echo 'SPA entrypoint upload basliyor';
              lcd ${dist};
              ${entryPuts || '!echo no-entrypoint;'}
              set cmd:fail-exit false;
              glob -a rm api/public/_migration_*.php;
              set cmd:fail-exit true;
              !echo 'payload transfer tamamlandi';
`.trim();
}

/**
 * @deprecated Use renderIncrementalPayloadCommands.
 */
export function renderIncrementalFtpCommands(plan, ctx) {
  return renderIncrementalPayloadCommands(plan, ctx);
}

/**
 * Separate finalization: write api/.deploy-sha only after payload verify PASS.
 * @param {{
 *   remoteTargetDir: string,
 *   deployShaLocalPath: string,
 * }} ctx
 */
export function renderFinalizeShaFtpCommands(ctx) {
  const remote = lftpQuote(ctx.remoteTargetDir);
  const shaLocal = lftpQuote(ctx.deployShaLocalPath);
  return `
              set cmd:fail-exit true;
              set net:max-retries 2;
              set net:timeout 20;
              set xfer:clobber true;
              set ssl:verify-certificate no;
              set ssl:check-hostname no;
              set ftp:passive-mode on;
              cd ${remote};
              !echo 'deploy-sha finalization basliyor';
              put -O api ${shaLocal};
              !echo 'deploy-sha finalization tamamlandi';
`.trim();
}

/**
 * @param {DeployPlan} plan
 */
export function formatDeploySummary(plan) {
  const lines = [
    `DEPLOY_MODE=${plan.mode}`,
    `PREVIOUS_DEPLOY_SHA=${plan.previousSha ?? 'NONE'}`,
    `CURRENT_DEPLOY_SHA=${plan.currentSha}`,
    `API_CHANGED_FILES=${plan.apiChanges.length}`,
    `API_UPLOADED_FILES=${plan.apiUploads.length}`,
    `API_DELETED_FILES=${plan.apiDeletes.length}`,
    `UNCHANGED_API_FILES_SKIPPED=${plan.unchangedApiFilesSkipped}`,
    `FRONTEND_DEPLOY_MODE=${plan.frontendMode}`,
    `FRONTEND_FILES_UPLOADED=${
      plan.frontendAssets.length +
      plan.frontendEntrypoints.length +
      plan.frontendSupporting.length
    }`,
    `FRONTEND_ASSETS=${plan.frontendAssets.length}`,
    `FRONTEND_ENTRYPOINTS=${plan.frontendEntrypoints.length}`,
    `FALLBACK_REASON=${plan.fallbackReason ?? 'NONE'}`,
  ];
  return `${lines.join('\n')}\n`;
}

function runGit(repoRoot, args) {
  return execFileSync('git', args, {
    cwd: repoRoot,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  });
}

function parseArgs(argv) {
  /** @type {Record<string, string>} */
  const out = {};
  for (const arg of argv) {
    if (!arg.startsWith('--')) {
      continue;
    }
    const eq = arg.indexOf('=');
    if (eq === -1) {
      out[arg.slice(2)] = 'true';
    } else {
      out[arg.slice(2, eq)] = arg.slice(eq + 1);
    }
  }
  return out;
}

function main() {
  const args = parseArgs(process.argv.slice(2));
  const repoRoot = resolve(args['repo-root'] ?? process.cwd());
  const distDir = resolve(repoRoot, args['dist-dir'] ?? 'dist');
  const outDir = resolve(args['out-dir'] ?? join(repoRoot, '.deploy-plan'));
  const currentSha = (args['current-sha'] ?? '').trim().toLowerCase();
  const previousArg = (args['previous-sha'] ?? 'NONE').trim();
  const previousSha =
    !previousArg || previousArg.toUpperCase() === 'NONE' ? null : previousArg.toLowerCase();
  const deployShaLocalPath = resolve(
    args['deploy-sha-local-path'] ?? join(outDir, '.deploy-sha'),
  );

  mkdirSync(outDir, { recursive: true });

  let fetchOk = null;
  let ancestryOk = null;
  /** @type {string[] | null} */
  let diffLines = null;

  if (previousSha && isValidSha(previousSha) && isValidSha(currentSha)) {
    try {
      runGit(repoRoot, ['cat-file', '-e', `${previousSha}^{commit}`]);
      fetchOk = true;
    } catch {
      try {
        runGit(repoRoot, ['fetch', '--no-tags', '--depth=1', 'origin', previousSha]);
        runGit(repoRoot, ['cat-file', '-e', `${previousSha}^{commit}`]);
        fetchOk = true;
      } catch {
        fetchOk = false;
      }
    }

    if (fetchOk) {
      try {
        runGit(repoRoot, ['merge-base', '--is-ancestor', previousSha, currentSha]);
        ancestryOk = true;
      } catch {
        ancestryOk = false;
      }
    }

    if (fetchOk && ancestryOk) {
      try {
        const diff = runGit(repoRoot, [
          'diff',
          '--name-status',
          '--find-renames',
          `${previousSha}..${currentSha}`,
        ]);
        diffLines = diff.split(/\r?\n/);
      } catch {
        diffLines = null;
      }
    }
  }

  const plan = createDeployPlan({
    previousSha,
    currentSha,
    repoRoot,
    distDir,
    diffLines,
    ancestryOk,
    fetchOk,
  });

  writeFileSync(join(outDir, 'plan.json'), `${JSON.stringify(plan, null, 2)}\n`, 'utf8');
  writeFileSync(join(outDir, 'summary.env'), formatDeploySummary(plan), 'utf8');
  writeFileSync(deployShaLocalPath, `${currentSha}\n`, 'utf8');

  const ctx = {
    localDistDir: resolve(repoRoot, args['dist-dir'] ?? 'dist'),
    localRepoDir: repoRoot,
    remoteTargetDir: args['remote-target-dir'] ?? '.',
    deployShaLocalPath,
  };

  writeFileSync(
    join(outDir, 'ftp-full.commands'),
    `${renderFullMirrorPayloadCommands(plan, ctx)}\n`,
    'utf8',
  );
  writeFileSync(
    join(outDir, 'ftp-active.commands'),
    `${
      plan.mode === 'INCREMENTAL'
        ? renderIncrementalPayloadCommands(plan, ctx)
        : renderFullMirrorPayloadCommands(plan, ctx)
    }\n`,
    'utf8',
  );
  writeFileSync(
    join(outDir, 'ftp-finalize-sha.commands'),
    `${renderFinalizeShaFtpCommands(ctx)}\n`,
    'utf8',
  );

  process.stdout.write(formatDeploySummary(plan));
}

const isDirect =
  process.argv[1] &&
  resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url));

if (isDirect) {
  try {
    main();
  } catch (error) {
    const message = error instanceof Error ? error.message : String(error);
    console.error(`plan-cpanel-incremental: FAIL: ${message}`);
    process.exit(1);
  }
}
