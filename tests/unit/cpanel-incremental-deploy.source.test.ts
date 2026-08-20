import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import {
  collectApiChangesFromDiffLines,
  createDeployPlan,
  formatDeploySummary,
  isApiOwnedPath,
  isLftpSafePath,
  isSafeExactDeletePath,
  normalizeRelativePath,
  parseNameStatusLine,
  planApiTransfers,
  renderFinalizeShaFtpCommands,
  renderFullMirrorFtpCommands,
  renderIncrementalFtpCommands,
} from '../../scripts/deploy/plan-cpanel-incremental.mjs';

const temporaryDirectories: string[] = [];

function makeDist(entrypoints: string[] = ['index.html']) {
  const directory = mkdtempSync(join(tmpdir(), 'medisa-inc-deploy-'));
  temporaryDirectories.push(directory);
  mkdirSync(join(directory, 'assets'), { recursive: true });
  writeFileSync(join(directory, 'assets', 'index-aaa.js'), 'console.log(1)', 'utf8');
  writeFileSync(join(directory, 'assets', 'index-aaa.css'), 'body{}', 'utf8');
  writeFileSync(join(directory, '.htaccess'), 'RewriteEngine On\n', 'utf8');
  for (const name of entrypoints) {
    writeFileSync(join(directory, name), '<!doctype html><div id="root"></div>', 'utf8');
  }
  return directory;
}

function makeRepoSkeleton() {
  const directory = mkdtempSync(join(tmpdir(), 'medisa-inc-repo-'));
  temporaryDirectories.push(directory);
  mkdirSync(join(directory, 'api/src'), { recursive: true });
  mkdirSync(join(directory, 'api/bin'), { recursive: true });
  mkdirSync(join(directory, 'api/public'), { recursive: true });
  mkdirSync(join(directory, 'api/migrations'), { recursive: true });
  mkdirSync(join(directory, 'api/runtime-build'), { recursive: true });
  mkdirSync(join(directory, 'api/runtime'), { recursive: true });
  writeFileSync(join(directory, 'api/.htaccess'), 'deny\n', 'utf8');
  writeFileSync(join(directory, 'api/src/Router.php'), '<?php\n', 'utf8');
  writeFileSync(join(directory, 'api/src/A.php'), '<?php\n', 'utf8');
  writeFileSync(join(directory, 'api/src/B.php'), '<?php\n', 'utf8');
  writeFileSync(join(directory, 'api/bin/migrate.php'), '<?php\n', 'utf8');
  writeFileSync(join(directory, 'api/public/index.php'), '<?php\n', 'utf8');
  writeFileSync(
    join(directory, 'api/migrations/070_offline_mutation_idempotency.sql'),
    '-- tip\n',
    'utf8',
  );
  writeFileSync(
    join(directory, 'api/runtime-build/canonical-migrations.php'),
    "<?php return ['version' => '000'];\n",
    'utf8',
  );
  writeFileSync(join(directory, 'api/runtime/.htaccess'), 'Require all denied\n', 'utf8');
  return directory;
}

function assertPayloadHasNoDeploySha(commands: string) {
  expect(commands).not.toMatch(/put\s+-O\s+"?api"?\s+[^\n]*\.deploy-sha/);
  expect(commands).not.toContain('deploy-sha finalization');
}

afterEach(() => {
  while (temporaryDirectories.length > 0) {
    const directory = temporaryDirectories.pop();
    if (directory) {
      rmSync(directory, { recursive: true, force: true });
    }
  }
});

describe('cPanel incremental deploy planner', () => {
  it('A) only one changed PHP file enters the API upload plan', () => {
    const repo = makeRepoSkeleton();
    const dist = makeDist();
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['M\tapi/src/Router.php', 'M\tsrc/App.tsx'],
    });

    expect(plan.mode).toBe('INCREMENTAL');
    expect(plan.apiUploads).toEqual(['api/src/Router.php']);
    expect(plan.apiDeletes).toEqual([]);
    expect(plan.fallbackReason).toBeNull();
  });

  it('B) no API changes skips re-upload of hundreds of api/src files', () => {
    const repo = makeRepoSkeleton();
    const dist = makeDist();
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['M\tsrc/features/users/UsersPage.tsx'],
    });

    expect(plan.mode).toBe('INCREMENTAL');
    expect(plan.apiUploads).toEqual([]);
    expect(plan.unchangedApiFilesSkipped).toBeGreaterThan(0);
    expect(plan.apiUploads).not.toContain('api/src/A.php');
    expect(plan.apiUploads).not.toContain('api/src/B.php');
  });

  it('C) deleted API file plans exact allowed remote delete only', () => {
    const transfers = planApiTransfers([
      { status: 'D', path: 'api/src/Obsolete.php' },
      { status: 'M', path: 'README.md' },
    ]);
    expect(transfers.deletes).toEqual(['api/src/Obsolete.php']);
    expect(isSafeExactDeletePath('api/src/Obsolete.php')).toBe(true);
  });

  it('D) rename plans old delete + new upload', () => {
    const parsed = parseNameStatusLine('R100\tapi/src/Old.php\tapi/src/New.php');
    expect(parsed).toEqual({
      status: 'R',
      path: 'api/src/New.php',
      fromPath: 'api/src/Old.php',
    });
    const transfers = planApiTransfers([
      { status: 'R', path: 'api/src/New.php', fromPath: 'api/src/Old.php' },
    ]);
    expect(transfers.deletes).toEqual(['api/src/Old.php']);
    expect(transfers.uploads).toEqual(['api/src/New.php']);
  });

  it('E) path traversal / unsafe delete is denied', () => {
    expect(normalizeRelativePath('../api/src/x.php')).toBeNull();
    expect(isApiOwnedPath('api/runtime/migration-control/status.json')).toBe(false);
    expect(isSafeExactDeletePath('api/runtime/migration-control/status.json')).toBe(false);
    expect(isSafeExactDeletePath('api/config.local.php')).toBe(false);
    expect(isSafeExactDeletePath('/etc/passwd')).toBe(false);

    const collected = collectApiChangesFromDiffLines([
      'D\tapi/runtime/secrets.json',
    ]);
    expect(collected.error).toBe('UNSAFE_DELETE_PATH');
  });

  it('F) missing previous SHA forces FULL_MIRROR_FALLBACK', () => {
    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
    });
    expect(plan.mode).toBe('FULL_MIRROR_FALLBACK');
    expect(plan.fallbackReason).toBe('PREVIOUS_SHA_MISSING');
  });

  it('G) previous SHA not ancestor forces FULL_MIRROR_FALLBACK', () => {
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      fetchOk: true,
      ancestryOk: false,
      diffLines: [],
    });
    expect(plan.mode).toBe('FULL_MIRROR_FALLBACK');
    expect(plan.fallbackReason).toBe('PREVIOUS_SHA_NOT_ANCESTOR');
  });

  it('H) migration add keeps always-upload canonical bundle path', () => {
    const repo = makeRepoSkeleton();
    writeFileSync(
      join(repo, 'api/migrations/071_example.sql'),
      '-- new\n',
      'utf8',
    );
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: makeDist(),
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['A\tapi/migrations/071_example.sql'],
    });
    expect(plan.mode).toBe('INCREMENTAL');
    expect(plan.apiUploads).toContain('api/migrations/071_example.sql');
    expect(plan.alwaysUploads).toContain('api/runtime-build/canonical-migrations.php');
  });

  it('I) frontend hashed assets are listed before entrypoints in publish order', () => {
    const dist = makeDist(['index.html']);
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: dist,
      fetchOk: true,
      ancestryOk: true,
      diffLines: [],
    });
    const cmds = renderIncrementalFtpCommands(plan, {
      localDistDir: dist,
      localRepoDir: makeRepoSkeleton(),
      remoteTargetDir: '.',
      deployShaLocalPath: join(dist, '.deploy-sha'),
    });
    const assetIdx = cmds.indexOf('SPA assets+supporting');
    const entryIdx = cmds.indexOf('SPA entrypoint upload');
    expect(assetIdx).toBeGreaterThanOrEqual(0);
    expect(entryIdx).toBeGreaterThan(assetIdx);
    expect(cmds.indexOf('put "index.html"')).toBeGreaterThan(entryIdx);
    expect(cmds.indexOf('--exclude "index.html"')).toBeGreaterThan(assetIdx);
    assertPayloadHasNoDeploySha(cmds);
  });

  it('J) payload transfer never writes deploy-sha; finalize is separate and last', () => {
    const dist = makeDist(['index.html']);
    const repo = makeRepoSkeleton();
    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
    });
    const payload = renderFullMirrorFtpCommands(plan, {
      localDistDir: dist,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    const finalize = renderFinalizeShaFtpCommands({
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    assertPayloadHasNoDeploySha(payload);
    expect(payload.indexOf('put "index.html"')).toBeGreaterThan(
      payload.indexOf('PHP API full mirror'),
    );
    expect(finalize).toContain('put -O api "/tmp/.deploy-sha"');
    expect(finalize).toContain('deploy-sha finalization');
  });

  it('K) incremental payload never writes deploy-sha (API puts stay in transfer phase)', () => {
    const dist = makeDist();
    const repo = makeRepoSkeleton();
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['M\tapi/src/Router.php'],
    });
    const cmds = renderIncrementalFtpCommands(plan, {
      localDistDir: dist,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    expect(cmds).toContain('put -O "api/src" "api/src/Router.php"');
    assertPayloadHasNoDeploySha(cmds);
  });

  it('L) exact owned deletes run fail-closed (not under fail-exit false)', () => {
    const dist = makeDist();
    const repo = makeRepoSkeleton();
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['D\tapi/src/Obsolete.php'],
    });
    expect(plan.mode).toBe('INCREMENTAL');
    expect(plan.apiDeletes).toEqual(['api/src/Obsolete.php']);
    const cmds = renderIncrementalFtpCommands(plan, {
      localDistDir: dist,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    const deleteIdx = cmds.indexOf('rm "api/src/Obsolete.php"');
    const failOpenIdx = cmds.indexOf('set cmd:fail-exit false');
    const legacyCleanupIdx = cmds.indexOf('glob -a rm api/public/_migration_*.php');
    expect(deleteIdx).toBeGreaterThanOrEqual(0);
    expect(failOpenIdx).toBeGreaterThan(deleteIdx);
    expect(legacyCleanupIdx).toBeGreaterThan(failOpenIdx);
    expect(cmds.indexOf('API exact deletes')).toBeGreaterThan(
      cmds.indexOf('PHP API incremental upload'),
    );
  });

  it('M) entrypoint publish is strictly after API operations', () => {
    const dist = makeDist(['index.html']);
    const repo = makeRepoSkeleton();
    const incremental = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['M\tapi/src/Router.php'],
    });
    const incCmds = renderIncrementalFtpCommands(incremental, {
      localDistDir: dist,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    expect(incCmds.indexOf('SPA entrypoint upload')).toBeGreaterThan(
      incCmds.indexOf('put -O "api/src" "api/src/Router.php"'),
    );
    expect(incCmds.indexOf('SPA entrypoint upload')).toBeGreaterThan(
      incCmds.indexOf('PHP API incremental upload'),
    );

    const full = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: repo,
      distDir: dist,
    });
    const fullCmds = renderFullMirrorFtpCommands(full, {
      localDistDir: dist,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    expect(fullCmds.indexOf('SPA entrypoint upload')).toBeGreaterThan(
      fullCmds.indexOf('PHP API full mirror'),
    );
    expect(fullCmds.indexOf('put "index.html"')).toBeGreaterThan(
      fullCmds.indexOf('mirror -R --verbose api/src api/src'),
    );
  });

  it('N) payload verify happens before .deploy-sha finalization in workflow', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const verifyIdx = source.indexOf('verify_payload_before_sha');
    const finalizeIdx = source.indexOf('finalize_deploy_sha');
    const callVerify = source.indexOf('verify_payload_before_sha\n');
    const callFinalize = source.indexOf('finalize_deploy_sha\n');
    expect(verifyIdx).toBeGreaterThanOrEqual(0);
    expect(finalizeIdx).toBeGreaterThan(verifyIdx);
    expect(callVerify).toBeGreaterThanOrEqual(0);
    expect(callFinalize).toBeGreaterThan(callVerify);
    expect(source).toContain('ftp-finalize-sha.commands');
    expect(source).toContain('Payload transfer NEVER writes api/.deploy-sha');
  });

  it('O) incremental failure leaves marker unchanged until fallback finalize', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    expect(source).toContain('INCREMENTAL transfer/verify/finalize failed');
    expect(source).toContain(
      'production marker must remain the previous SHA',
    );
    expect(source).toMatch(
      /if ! upload_verify_finalize "INCREMENTAL"[\s\S]*upload_verify_finalize "FULL_MIRROR_FALLBACK"/,
    );
    // Payload commands themselves never finalize the marker.
    const planner = readFileSync(
      resolve(process.cwd(), 'scripts/deploy/plan-cpanel-incremental.mjs'),
      'utf8',
    );
    const payloadFn = planner.slice(
      planner.indexOf('export function renderIncrementalPayloadCommands'),
      planner.indexOf('export function renderFinalizeShaFtpCommands'),
    );
    expect(payloadFn).not.toMatch(/put\s+-O\s+api[^\n]*deploy-sha/);
  });

  it('P) fallback failure also keeps marker unchanged (finalize only after verify)', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    // Single shared path: transfer → verify_payload_before_sha → finalize_deploy_sha
    const fnStart = source.indexOf('upload_verify_finalize()');
    const fnBody = source.slice(fnStart, source.indexOf('if [[ "$DEPLOY_MODE"'));
    expect(fnBody).toContain('run_cpanel_ftp "$commands"');
    expect(fnBody.indexOf('verify_payload_before_sha')).toBeLessThan(
      fnBody.indexOf('finalize_deploy_sha'),
    );
    expect(fnBody).not.toContain('get -o ${remote_sha_path} api/.deploy-sha');
  });

  it('Q) successful path finalizes marker exactly once after payload verify', () => {
    const finalize = renderFinalizeShaFtpCommands({
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    expect(finalize.match(/put -O api "\/tmp\/\.deploy-sha"/g)).toHaveLength(1);
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    expect(source.match(/run_cpanel_ftp "\$FINALIZE_SHA_COMMANDS"/g)).toHaveLength(1);
    expect(source).toContain('test "$(tr -d \'\\r\\n\' < "${remote_sha_path}")" = "${DEPLOY_SHA}"');
  });

  it('R) unsafe lftp command-separator path denies incremental / forces fallback', () => {
    expect(isLftpSafePath('api/src/x;rm-something.php')).toBe(false);
    expect(isLftpSafePath('api/src/Router.php')).toBe(true);
    expect(isLftpSafePath('api/src/bad path.php')).toBe(false);
    expect(isLftpSafePath('api/src/bad`tick`.php')).toBe(false);

    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['M\tapi/src/x;rm-something.php'],
    });
    expect(plan.mode).toBe('FULL_MIRROR_FALLBACK');
    expect(plan.fallbackReason).toBe('UNSAFE_LFTP_PATH');

    const cmds = renderIncrementalFtpCommands(plan, {
      localDistDir: makeDist(),
      localRepoDir: makeRepoSkeleton(),
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    expect(cmds).not.toContain('x;rm-something.php');
    expect(cmds).toContain('PHP API full mirror upload');
  });

  it('emits secret-free deploy summary lines', () => {
    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
    });
    const summary = formatDeploySummary(plan);
    expect(summary).toContain('DEPLOY_MODE=FULL_MIRROR_FALLBACK');
    expect(summary).toContain('FALLBACK_REASON=PREVIOUS_SHA_MISSING');
    expect(summary).not.toMatch(/FTP_|PASSWORD|TOKEN|SECRET/i);
  });
});

describe('cPanel incremental deploy workflow wiring', () => {
  const source = readFileSync(
    resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
    'utf8',
  );
  const planner = readFileSync(
    resolve(process.cwd(), 'scripts/deploy/plan-cpanel-incremental.mjs'),
    'utf8',
  );

  it('keeps dual-mode planner + full mirror fallback contracts', () => {
    expect(source).toContain('plan-cpanel-incremental.mjs');
    expect(source).toContain('DEPLOY_MODE');
    expect(source).toContain('FULL_MIRROR_FALLBACK');
    expect(source).toContain('INCREMENTAL');
    expect(source).toContain('api/.deploy-sha');
    expect(source).toContain('canonical-migrations.php');
    expect(source).toContain('npm run smoke:live');
    expect(planner).toContain('mirror -R --verbose api/src api/src');
    expect(planner).toContain('mirror -R --verbose api/bin api/bin');
    expect(planner).toContain('mirror -R --verbose api/migrations api/migrations');
    expect(source).not.toMatch(/--only-newer\b/);
    expect(planner).not.toMatch(/--only-newer\b/);
    expect(source).not.toMatch(/\becho\b[^\n]*\$\{?FTP_PASSWORD\}?/);
  });

  it('publishes frontend entrypoints after assets and finalizes deploy-sha last', () => {
    expect(planner).toContain('SPA assets+supporting');
    expect(planner).toContain('SPA entrypoint');
    expect(planner).toContain('renderFinalizeShaFtpCommands');
    expect(source).toContain('.deploy-sha');
    expect(source).toContain('ftp-finalize-sha.commands');
  });
});
