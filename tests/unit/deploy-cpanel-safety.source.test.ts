import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const workflowPath = resolve(process.cwd(), '.github/workflows/deploy-cpanel.yml');
const workflow = readFileSync(workflowPath, 'utf8');
const lines = workflow.split(/\r?\n/);
const staticMirror = lines.find(
  (line) => /\bmirror\s+-R\b/.test(line) && /\s\.\s+\.;\s*$/.test(line),
) ?? '';

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
  });

  it('does not echo FTP secret values', () => {
    const unsafeSecretEchoes = lines.filter(
      (line) => /\becho\b/.test(line) && /\$\{?(FTP_SERVER|FTP_USERNAME|FTP_PASSWORD)\}?/.test(line),
    );
    expect(unsafeSecretEchoes).toEqual([]);
  });
});
