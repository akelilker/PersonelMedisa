import {
  mkdtempSync,
  mkdirSync,
  readFileSync,
  rmSync,
  writeFileSync,
} from 'node:fs';
import { spawnSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import {
  classifyLftpReadLog,
  collectApiChangesFromDiffLines,
  createDeployPlan,
  formatDeploySummary,
  isApiOwnedPath,
  isFatalFtpReadCapabilityFailure,
  isLftpSafePath,
  isSafeExactDeletePath,
  isSafeLftpLocalDir,
  normalizeRelativePath,
  parseNameStatusLine,
  planApiTransfers,
  previousShaReadFromClass,
  renderBrokenQuotedLftpGitPath,
  renderBrokenQuotedLftpLocalDir,
  renderFinalizeShaFtpCommands,
  renderFullMirrorFtpCommands,
  renderIncrementalFtpCommands,
  renderLftpGetCommand,
  renderLftpGitPath,
  renderLocalLftpPreflightCommands,
  renderLftpLocalDir,
  resolveVerifiedLocalDeployDirs,
  runLocalLftpPreflight,
  sanitizeLftpErrorDetail,
  validateLftpGetSyntaxForms,
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
    expect(cmds.indexOf('put index.html')).toBeGreaterThan(entryIdx);
    expect(cmds.indexOf('--exclude index.html')).toBeGreaterThan(assetIdx);
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
    expect(payload.indexOf('put index.html')).toBeGreaterThan(
      payload.indexOf('PHP API full mirror'),
    );
    expect(finalize).toContain('put -O api /tmp/.deploy-sha');
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
    expect(cmds).toContain('put -O api/src api/src/Router.php');
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
    const deleteIdx = cmds.indexOf('rm api/src/Obsolete.php');
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
      incCmds.indexOf('put -O api/src api/src/Router.php'),
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
    expect(fullCmds.indexOf('put index.html')).toBeGreaterThan(
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
    expect(finalize.match(/put -O api \/tmp\/\.deploy-sha/g)).toHaveLength(1);
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

  it('S) generated LOCAL dist lcd works with GitHub runner-style absolute path', () => {
    const dist =
      '/home/runner/work/PersonelMedisa/PersonelMedisa/dist';
    const rendered = renderLftpLocalDir(dist);
    expect(rendered).toBe(dist);
    expect(rendered.startsWith('"')).toBe(false);
    expect(isSafeLftpLocalDir(dist)).toBe(true);

    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
    });
    const cmds = renderFullMirrorFtpCommands(plan, {
      localDistDir: dist,
      localRepoDir: '/home/runner/work/PersonelMedisa/PersonelMedisa',
      remoteTargetDir: '.',
      deployShaLocalPath: '/home/runner/work/_temp/.deploy-sha',
    });
    expect(cmds).toContain(`lcd ${dist};`);
    expect(cmds).not.toContain(`lcd "${dist}"`);
    expect(renderBrokenQuotedLftpLocalDir(dist)).toBe(`"${dist}"`);
  });

  it('T) generated LOCAL repo lcd works', () => {
    const repo = '/home/runner/work/PersonelMedisa/PersonelMedisa';
    expect(renderLftpLocalDir(repo)).toBe(repo);
    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
    });
    const cmds = renderFullMirrorFtpCommands(plan, {
      localDistDir: `${repo}/dist`,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/tmp/.deploy-sha',
    });
    expect(cmds).toContain(`lcd ${repo};`);
    expect(cmds).not.toContain(`lcd "${repo}"`);
  });

  it('U) real temp dist + index.html local lftp preflight succeeds (or skips without lftp)', () => {
    const dist = makeDist();
    const repo = makeRepoSkeleton();
    const verified = resolveVerifiedLocalDeployDirs({ distDir: dist, repoDir: repo });
    const script = renderLocalLftpPreflightCommands(verified);
    expect(script).toContain(`lcd ${renderLftpLocalDir(verified.distAbs)};`);
    expect(script).not.toMatch(/lcd\s+"/);

    const result = runLocalLftpPreflight(verified);
    if (result.skipped) {
      expect(result.script).toContain('lcd ');
      expect(result.script).not.toMatch(/lcd\s+"/);
      return;
    }
    expect(result.ok).toBe(true);
    expect(result.code).toBe(0);

    const broken = runLocalLftpPreflight({
      ...verified,
      useBrokenQuotedRender: true,
    });
    if (!broken.skipped) {
      expect(broken.ok).toBe(false);
      expect(broken.script).toMatch(/lcd\s+"/);
      expect(`${broken.stderr}${broken.stdout}`).toMatch(/No such file or directory|"\//);
    }
  });

  it('V) missing local dist fails before FTP/network attempt', () => {
    const repo = makeRepoSkeleton();
    const missing = join(repo, 'no-such-dist-dir');
    expect(() =>
      resolveVerifiedLocalDeployDirs({ distDir: missing, repoDir: repo }),
    ).toThrow(/LOCAL_DIST_MISSING/);

    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const preflightIdx = source.indexOf('Local lftp directory preflight');
    const uploadIdx = source.indexOf('Upload dist and PHP API to cPanel personelmedisa FTP root');
    expect(preflightIdx).toBeGreaterThanOrEqual(0);
    expect(uploadIdx).toBeGreaterThan(preflightIdx);
    expect(source).toContain('test -d "$DIST_ABS"');
    expect(source).toContain('test -f "$DIST_ABS/index.html"');
    expect(source).toContain('LOCAL_LFTP_PREFLIGHT=PASS');
  });

  it('W) PREVIOUS_SHA read failure → FULL_MIRROR_FALLBACK', () => {
    const missing = createDeployPlan({
      previousSha: 'NONE',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      previousShaReadStatus: 'NOT_FOUND',
    });
    expect(missing.mode).toBe('FULL_MIRROR_FALLBACK');
    expect(missing.fallbackReason).toBe('PREVIOUS_SHA_MISSING');

    const transport = createDeployPlan({
      previousSha: 'NONE',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      previousShaReadStatus: 'TRANSPORT_FAILED',
    });
    expect(transport.mode).toBe('FULL_MIRROR_FALLBACK');
    expect(transport.fallbackReason).toBe('PREVIOUS_SHA_TRANSPORT_FAILED');
    expect(transport.fallbackReason).not.toBe('PREVIOUS_SHA_MISSING');

    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    expect(source).toContain('PREVIOUS_SHA_READ');
    expect(source).toContain('TRANSPORT_FAILED');
    expect(source).toContain('NOT_FOUND');
    expect(source).toContain('INVALID_CONTENT');
    expect(source).toContain('PREVIOUS_SHA_VALUE');
    expect(source).toContain('FTP_READBACK_PREFLIGHT');
    expect(source).toContain('REFUSING_BULK_UPLOAD=YES');
  });

  it('X) full mirror fallback uses valid local lcd syntax', () => {
    const dist =
      '/home/runner/work/PersonelMedisa/PersonelMedisa/dist';
    const repo = '/home/runner/work/PersonelMedisa/PersonelMedisa';
    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
    });
    expect(plan.mode).toBe('FULL_MIRROR_FALLBACK');
    const cmds = renderFullMirrorFtpCommands(plan, {
      localDistDir: dist,
      localRepoDir: repo,
      remoteTargetDir: '.',
      deployShaLocalPath: '/home/runner/work/_temp/cpanel-deploy-plan/.deploy-sha',
    });
    expect(cmds).toContain(`lcd ${dist};`);
    expect(cmds).toContain(`lcd ${repo};`);
    expect(cmds).toContain('cd .;');
    expect(cmds).not.toContain(`lcd "${dist}"`);
    expect(cmds).not.toContain('cd ".";');
    expect(cmds).toContain('PHP API full mirror upload');
    assertPayloadHasNoDeploySha(cmds);
  });

  it('Y) no .deploy-sha finalize on local-preflight failure', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const preflightBlock = source.slice(
      source.indexOf('Local lftp directory preflight'),
      source.indexOf('Upload dist and PHP API to cPanel personelmedisa FTP root'),
    );
    expect(preflightBlock).toContain('lftp -e');
    expect(preflightBlock).toContain('lcd ${DIST_LCD}');
    expect(preflightBlock).not.toContain('ftp-finalize-sha');
    expect(preflightBlock).not.toContain('.deploy-sha');
    expect(preflightBlock).not.toContain('FTP_PASSWORD');
    expect(source.indexOf('finalize_deploy_sha')).toBeGreaterThan(
      source.indexOf('LOCAL_LFTP_PREFLIGHT=PASS'),
    );
  });

  it('Z1) canonical lftp GET remote→local syntax executes correctly', () => {
    const local =
      '/home/runner/work/_temp/cpanel-deploy-plan/previous.deploy-sha';
    const cmd = renderLftpGetCommand({
      remotePath: 'api/.deploy-sha',
      localPath: local,
    });
    expect(cmd).toBe(`get -o ${local} api/.deploy-sha`);
    expect(cmd).not.toContain('"');
    expect(renderBrokenQuotedLftpGitPath('api/.deploy-sha')).toBe('"api/.deploy-sha"');

    // Optional live parser check via lftp file: backend when available.
    const fixtureRoot = mkdtempSync(join(tmpdir(), 'medisa-lftp-get-'));
    temporaryDirectories.push(fixtureRoot);
    mkdirSync(join(fixtureRoot, 'api'), { recursive: true });
    writeFileSync(join(fixtureRoot, 'api', '.deploy-sha'), 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa\n');
    const outFile = join(fixtureRoot, 'out.sha');
    const result = runLocalLftpPreflight({
      distAbs: fixtureRoot,
      repoAbs: fixtureRoot,
    });
    // Reuse spawn path: if lftp missing, skip live get; syntax contract still asserted above.
    if (!result.skipped) {
      const getCmd = renderLftpGetCommand({
        remotePath: 'api/.deploy-sha',
        localPath: outFile.replace(/\\/g, '/'),
      });
      const live = spawnSync(
        'lftp',
        ['file:' + fixtureRoot.replace(/\\/g, '/'), '-e', `set cmd:fail-exit true; ${getCmd}; bye`],
        { encoding: 'utf8' },
      );
      if (live.error && (live.error as NodeJS.ErrnoException).code === 'ENOENT') {
        return;
      }
      expect(live.status).toBe(0);
      expect(readFileSync(outFile, 'utf8').trim()).toBe(
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      );
    }
  });

  it('Z2) valid remote deploy SHA → PREVIOUS_SHA_READ=SUCCESS', () => {
    expect(previousShaReadFromClass('SUCCESS')).toBe('SUCCESS');
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      previousShaReadStatus: 'SUCCESS',
      fetchOk: true,
      ancestryOk: true,
      diffLines: [],
    });
    expect(plan.mode).toBe('INCREMENTAL');
  });

  it('Z3) actual 550/not-found → NOT_FOUND', () => {
    const klass = classifyLftpReadLog(
      'get: Access failed: 550 Failed to open file. (api/.deploy-sha)\n',
      { exitCode: 1 },
    );
    expect(klass).toBe('REMOTE_NOT_FOUND');
    expect(previousShaReadFromClass(klass)).toBe('NOT_FOUND');
    expect(isFatalFtpReadCapabilityFailure(klass)).toBe(false);
  });

  it('Z4) local output/path error → correct failure classification', () => {
    const klass = classifyLftpReadLog(
      'get: /home/runner/work/_temp/plan/"previous.deploy-sha": No such file or directory\n',
      {
        exitCode: 1,
        localPath: '/home/runner/work/_temp/plan/previous.deploy-sha',
      },
    );
    expect(klass).toBe('LOCAL_OUTPUT_ERROR');
    expect(previousShaReadFromClass(klass)).toBe('TRANSPORT_FAILED');
    expect(isFatalFtpReadCapabilityFailure(klass)).toBe(true);
  });

  it('Z5) transport failure does not masquerade as PREVIOUS_SHA_MISSING', () => {
    const plan = createDeployPlan({
      previousSha: null,
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      previousShaReadStatus: 'TRANSPORT_FAILED',
    });
    expect(plan.fallbackReason).toBe('PREVIOUS_SHA_TRANSPORT_FAILED');
    expect(plan.fallbackReason).not.toBe('PREVIOUS_SHA_MISSING');
  });

  it('Z6) transport/read capability failure stops before bulk mirror', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const probeIdx = source.indexOf('FTP read-back capability + previous SHA probe starting');
    const refuseIdx = source.indexOf('REFUSING_BULK_UPLOAD=YES');
    const bulkIdx = source.indexOf('Deploy payload transfer path');
    expect(probeIdx).toBeGreaterThanOrEqual(0);
    expect(refuseIdx).toBeGreaterThan(probeIdx);
    expect(bulkIdx).toBeGreaterThan(refuseIdx);
    expect(source).toContain('FTP_READBACK_PREFLIGHT=PASS');
    expect(isFatalFtpReadCapabilityFailure('FTP_CONNECTION_ERROR')).toBe(true);
    expect(isFatalFtpReadCapabilityFailure('REMOTE_NOT_FOUND')).toBe(false);
  });

  it('Z7) remote bundle read uses same canonical GET helper', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    expect(source).toContain('lftp_get_remote_to_local');
    expect(source).toContain('renderLftpGetCommand');
    const verifyFn = source.slice(
      source.indexOf('verify_payload_before_sha()'),
      source.indexOf('finalize_deploy_sha()'),
    );
    expect(verifyFn).toContain(
      'lftp_get_remote_to_local "api/runtime-build/canonical-migrations.php"',
    );
    expect(verifyFn).not.toMatch(/get -o \$\{remote_bundle_path\}/);
  });

  it('Z8) final SHA read-back uses same canonical GET helper', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const finalizeFn = source.slice(
      source.indexOf('finalize_deploy_sha()'),
      source.indexOf('upload_verify_finalize()'),
    );
    expect(finalizeFn).toContain('lftp_get_remote_to_local "api/.deploy-sha"');
    expect(finalizeFn).toContain('FINAL_SHA_GET=SUCCESS');
  });

  it('Z9) read-back failure leaves deploy SHA marker unfinalized', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const refuseBlock = source.slice(
      source.indexOf('PREVIOUS_SHA_READ" == "TRANSPORT_FAILED"'),
      source.indexOf('FTP_READBACK_PREFLIGHT=PASS'),
    );
    expect(refuseBlock).toContain('REFUSING_BULK_UPLOAD=YES');
    expect(refuseBlock).toContain('exit 1');
    expect(refuseBlock).not.toContain('finalize_deploy_sha');
    expect(refuseBlock).not.toContain('ftp-finalize-sha');
    // Finalize remains after verify only.
    expect(source.indexOf('finalize_deploy_sha')).toBeGreaterThan(
      source.indexOf('verify_payload_before_sha'),
    );
  });

  it('Z10) valid previous SHA + ancestor → INCREMENTAL mode', () => {
    const plan = createDeployPlan({
      previousSha: 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      currentSha: 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
      repoRoot: makeRepoSkeleton(),
      distDir: makeDist(),
      previousShaReadStatus: 'SUCCESS',
      fetchOk: true,
      ancestryOk: true,
      diffLines: ['M\tapi/src/Router.php'],
    });
    expect(plan.mode).toBe('INCREMENTAL');
    expect(plan.apiUploads).toEqual(['api/src/Router.php']);
    expect(renderLftpGitPath('index.html')).toBe('index.html');
    expect(renderLftpGitPath('index.html')).not.toContain('"');
  });

  it('AA1-AA8) read-back lib preserves errexit and classifies failures', () => {
    const script = resolve(
      process.cwd(),
      'scripts/deploy/test-cpanel-ftp-readback-errexit.sh',
    );
    const bashCandidates = [
      'C:/Program Files/Git/bin/bash.exe',
      '/usr/bin/bash',
      'bash',
    ];
    let result: ReturnType<typeof spawnSync> | null = null;
    let output = '';
    for (const bin of bashCandidates) {
      const attempt = spawnSync(bin, [script], {
        encoding: 'utf8',
        cwd: process.cwd(),
        env: process.env,
      });
      if (attempt.error && (attempt.error as NodeJS.ErrnoException).code === 'ENOENT') {
        continue;
      }
      output = `${attempt.stdout ?? ''}\n${attempt.stderr ?? ''}`;
      if (attempt.status === 0 && output.includes('HARNESS_FAIL=0')) {
        result = attempt;
        break;
      }
      // Keep last attempt for assertion diagnostics if none succeed.
      result = attempt;
    }
    expect(result).not.toBeNull();
    expect(output).toContain('AA1_ERREXIT_PRESERVED=PASS');
    expect(output).toContain('AA2_RC_CAPTURED=PASS');
    expect(output).toContain('AA3_CLASS=PASS');
    expect(output).toContain('AA3_CAPABILITY_PASS=PASS');
    expect(output).toContain('AA4_SYNTAX=PASS');
    expect(output).toContain('AA5_TRANSPORT_FAILED=PASS');
    expect(output).toContain('AA5_REFUSE=PASS');
    expect(output).toContain('AA8_NO_SECRET=PASS');
    expect(output).toMatch(/HARNESS_FAIL=0/);
    expect(result?.status).toBe(0);

    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const lib = readFileSync(
      resolve(process.cwd(), 'scripts/deploy/cpanel-ftp-readback-lib.sh'),
      'utf8',
    );
    const libCode = lib
      .split(/\r?\n/)
      .filter((line) => !/^\s*#/.test(line))
      .join('\n');
    expect(libCode).not.toMatch(/(^|\n)\s*set \+e/);
    expect(libCode).not.toMatch(/(^|\n)\s*set -[^\n]*e/);
    expect(source).toContain('cpanel-ftp-readback-lib.sh');
    expect(lib).toContain('FTPS_ERROR_DETAIL');
    expect(lib).toContain('PLAIN_FTP_ERROR_DETAIL');
    // AA6: refuse gate remains before bulk transfer path
    expect(source.indexOf('REFUSING_BULK_UPLOAD=YES')).toBeLessThan(
      source.indexOf('Deploy payload transfer path'),
    );
  });

  it('AA6) failure path does not reach bulk mirror (workflow gate)', () => {
    const source = readFileSync(
      resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml'),
      'utf8',
    );
    const refuseIdx = source.indexOf('REFUSING_BULK_UPLOAD=YES');
    const exitIdx = source.indexOf('exit 1', refuseIdx);
    const bulkIdx = source.indexOf('Deploy payload transfer path');
    expect(refuseIdx).toBeGreaterThanOrEqual(0);
    expect(exitIdx).toBeGreaterThan(refuseIdx);
    expect(bulkIdx).toBeGreaterThan(exitIdx);
  });

  it('AA7) valid GET success returns downloaded SHA (lftp file: when available)', () => {
    const probe = validateLftpGetSyntaxForms();
    expect(probe.canonical.startsWith('get -o ')).toBe(true);
    expect(probe.remoteFirst.startsWith('get api/.deploy-sha -o ')).toBe(true);
    if (probe.skipped) {
      expect(probe.preferred).toBeNull();
      return;
    }
    expect(probe.canonicalOk).toBe(true);
    expect(probe.preferred).toBe('canonical');
  });

  it('AA8) sanitizeLftpErrorDetail never leaks credentials', () => {
    const detail = sanitizeLftpErrorDetail(
      [
        'Deploy transport mode: explicit-ftps',
        'lftp -u secretuser,super-secret-password ftp://ftp.example.com',
        'get: Access failed: 550 Failed to open file. (api/.deploy-sha)',
      ].join('\n'),
    );
    expect(detail).toContain('550');
    expect(detail).not.toContain('super-secret-password');
    expect(detail).not.toContain('secretuser');
    expect(classifyLftpReadLog('Unknown command `getx\'\nUsage: get [OPTS] files\n', { exitCode: 1 })).toBe(
      'LFTP_SYNTAX_ERROR',
    );
    expect(isFatalFtpReadCapabilityFailure('LFTP_SYNTAX_ERROR')).toBe(true);
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
    expect(source).toContain('Local lftp directory preflight');
    expect(planner).toContain('renderLftpLocalDir');
    expect(planner).not.toMatch(
      /export function renderFullMirrorPayloadCommands[\s\S]*lftpQuote\(ctx\.localDistDir/,
    );
  });
});
