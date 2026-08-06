import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const workflowPath = resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml');
const apiHtaccessPath = resolve(process.cwd(), 'api/.htaccess');
const workflow = readFileSync(workflowPath, 'utf8');
const apiHtaccess = readFileSync(apiHtaccessPath, 'utf8');
const lines = workflow.split(/\r?\n/);
const htaccessLines = apiHtaccess.split(/\r?\n/);
const staticMirror = lines.find(
  (line) => /\bmirror\s+-R\b/.test(line) && /\s\.\s+\.;\s*$/.test(line),
) ?? '';

function extractRewritePattern(ruleLine: string): string | null {
  const match = ruleLine.match(/RewriteRule\s+(\S+)\s+/);
  return match?.[1] ?? null;
}

function apacheRewriteMatches(pattern: string, path: string, options: { nc?: boolean } = {}): boolean {
  const flags = options.nc === false ? '' : 'i';
  return new RegExp(pattern, flags).test(path);
}

describe('cPanel deploy server-state safety contract', () => {
  it('keeps the dedicated FTP account root target explicit', () => {
    expect(workflow).toMatch(/REMOTE_TARGET_DIR:\s*\./);
    expect(staticMirror).not.toBe('');
  });

  it('does not delete remote-only files from the FTP root', () => {
    expect(staticMirror).not.toMatch(/--delete\b/);
    const unsafeRootMirrors = lines.filter(
      (line) => /\bmirror\s+-R\b/.test(line) && /--delete\b/.test(line) && /\s\.\s+\.;\s*$/.test(line),
    );
    expect(unsafeRootMirrors).toEqual([]);
  });

  it('preserves the separately uploaded API directory', () => {
    expect(staticMirror).toMatch(/--exclude\s+api\//);
  });

  it('preserves cPanel and Git server-state metadata', () => {
    expect(staticMirror).toMatch(/--exclude-glob\s+['"]\.git\*['"]/);
    expect(staticMirror).toMatch(/--exclude-glob\s+['"]\.cpanel\*['"]/);
  });

  it('keeps the narrow PHP API upload contract', () => {
    expect(workflow).toMatch(/test\s+!\s+-f\s+api\/config\.local\.php/);
    expect(workflow).toMatch(/put\s+-O\s+api\s+api\/\.htaccess/);
    expect(workflow).toMatch(/mirror\s+-R\s+--verbose\s+api\/public\s+api\/public/);
    expect(workflow).toMatch(/mirror\s+-R\s+--verbose\s+api\/src\s+api\/src/);
    expect(workflow).not.toMatch(/(?:put|mirror)\b[^\n]*config\.local\.php/);
  });

  it('does not echo deploy or smoke secret values', () => {
    const unsafeSecretEchoes = lines.filter(
      (line) =>
        /\becho\b[^\n]*\$\{?(FTP_SERVER|FTP_USERNAME|FTP_PASSWORD|SMOKE_AUTH_USERNAME|SMOKE_AUTH_PASSWORD)\}?/.test(
          line,
        ),
    );
    expect(unsafeSecretEchoes).toEqual([]);
  });

  it('does not cancel an active production mirror and verifies the deployed app', () => {
    expect(workflow).toMatch(/cancel-in-progress:\s*false/);
    expect(workflow).toMatch(/timeout-minutes:\s*30/);
    expect(workflow).toContain('SMOKE_AUTH_USERNAME: ${{ secrets.SMOKE_AUTH_USERNAME }}');
    expect(workflow).toContain('SMOKE_AUTH_PASSWORD: ${{ secrets.SMOKE_AUTH_PASSWORD }}');
    expect(workflow).toContain('run: npm run smoke:live');
  });
});

describe('api/.htaccess config.local.php web deny contract', () => {
  const configLocalDenyRule =
    htaccessLines.find((line) => /RewriteRule\s+\^config\\\.local\\\.php/.test(line)) ?? '';
  const configDenyPattern = extractRewritePattern(configLocalDenyRule);

  it('denies direct web access to config.local.php with explicit RewriteRule', () => {
    expect(apiHtaccess).toBeTruthy();
    expect(configLocalDenyRule).not.toBe('');
    expect(configLocalDenyRule).toMatch(/\[.*\bF\b.*\bL\b/);
    expect(configLocalDenyRule).toMatch(/\[.*\bNC\b/);
    expect(configLocalDenyRule).not.toMatch(/\bR=\d+\b/);
    expect(configDenyPattern).toBeTruthy();
  });

  it('places the config deny rule before the router fallback', () => {
    const configLocalDenyIndex = htaccessLines.findIndex((line) =>
      /RewriteRule\s+\^config\\\.local\\\.php/.test(line),
    );
    const routerFallbackIndex = htaccessLines.findIndex((line) =>
      /RewriteCond\s+%\{REQUEST_FILENAME\}\s+!-f/.test(line),
    );

    expect(configLocalDenyIndex).toBeGreaterThanOrEqual(0);
    expect(routerFallbackIndex).toBeGreaterThanOrEqual(0);
    expect(configLocalDenyIndex).toBeLessThan(routerFallbackIndex);
  });

  it('does not add a broad PHP deny that blocks api/public/index.php', () => {
    const broadPhpDenyRules = htaccessLines.filter(
      (line) =>
        /RewriteRule\s+\^/.test(line) &&
        /\\\.php/.test(line) &&
        !/RewriteRule\s+\^config\\\.local\\\.php/.test(line) &&
        /\[\s*F\b/.test(line),
    );

    expect(broadPhpDenyRules).toEqual([]);
    expect(apiHtaccess).toMatch(/RewriteRule\s+\^\s+public\/index\.php/);
  });

  it('matches deny/allow path semantics for config backup derivatives', () => {
    expect(configDenyPattern).toBeTruthy();
    const pattern = configDenyPattern as string;

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
    ] as const;

    const allowPaths = [
      'config.php',
      'configuration.local.php',
      'public/index.php',
      'health',
      'auth/login',
    ] as const;

    for (const path of denyPaths) {
      expect(apacheRewriteMatches(pattern, path, { nc: true }), `expected DENY for ${path}`).toBe(true);
    }
    for (const path of allowPaths) {
      expect(apacheRewriteMatches(pattern, path, { nc: true }), `expected ALLOW for ${path}`).toBe(false);
    }
  });

  it('keeps deploy config protection and uploads api/.htaccess', () => {
    expect(workflow).toMatch(/test\s+!\s+-f\s+api\/config\.local\.php/);
    expect(workflow).toMatch(/put\s+-O\s+api\s+api\/\.htaccess/);
    expect(workflow).not.toMatch(/mirror\s+-R\s+--verbose\s+api\/config\.local\.php/);
  });
});
