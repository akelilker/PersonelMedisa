import { readFileSync, readdirSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const root = process.cwd();

const CPANEL_FTP_WORKFLOWS = [
  '.github/workflows/deploy-cpanel.yml',
  '.github/workflows/apply-cpanel-migrations.yml',
  '.github/workflows/ops-migration-worker-diagnostics.yml',
  '.github/workflows/ops-organization-inventory.yml',
  '.github/workflows/apply-kalici-sil-099.yml',
];

const CPANEL_FTP_SCRIPTS = ['scripts/deploy/cpanel-ftp-readback-lib.sh'];

function read(path: string): string {
  return readFileSync(resolve(root, path), 'utf8');
}

function productionFtpOwners(): string[] {
  return [...CPANEL_FTP_WORKFLOWS, ...CPANEL_FTP_SCRIPTS].map((path) => read(path));
}

describe('MG-CPANEL-PLAIN-FTP-TRANSPORT-001 production FTP owners', () => {
  it('uses passive plain FTP only with SSL disabled in every canonical owner', () => {
    for (const workflowPath of CPANEL_FTP_WORKFLOWS.filter(
      (path) => path !== '.github/workflows/deploy-cpanel.yml',
    )) {
      const owner = read(workflowPath);
      expect(owner).toContain('set ftp:passive-mode on;');
      expect(owner).toContain('set ftp:ssl-allow false;');
      expect(owner).toContain('set ftp:ssl-force false;');
      expect(owner).toContain('set ftp:ssl-protect-data false;');
      expect(owner).toContain('ftp://${FTP_SERVER}');
      expect(owner).toContain('Deploy transport mode: plain-ftp');
    }

    const readbackLib = read('scripts/deploy/cpanel-ftp-readback-lib.sh');
    expect(readbackLib).toContain('Deploy transport mode: plain-ftp');
    expect(read('.github/workflows/deploy-cpanel.yml')).toContain('cpanel-ftp-readback-lib.sh');
  });

  it('removes explicit FTPS attempts and FTPS-first fallback from production owners', () => {
    const forbidden = [
      'explicit-ftps',
      'Explicit FTPS',
      'use_ftps',
      'run_ftp_mode(',
      'FTPS_RESULT',
      'FTPS_ERROR',
      'plain FTP fallback',
      'FTPS basarisiz',
    ];
    for (const owner of productionFtpOwners()) {
      for (const needle of forbidden) {
        expect(owner, needle).not.toContain(needle);
      }
    }
  });

  it('keeps deploy readback-before-upload and marker-after-verify ordering', () => {
    const deploy = read('.github/workflows/deploy-cpanel.yml');
    const readbackIdx = deploy.indexOf('FTP read-back capability + previous SHA probe starting');
    const refuseIdx = deploy.indexOf('REFUSING_BULK_UPLOAD=YES');
    const bulkIdx = deploy.indexOf('upload_verify_finalize');

    expect(readbackIdx).toBeGreaterThanOrEqual(0);
    expect(refuseIdx).toBeGreaterThan(readbackIdx);
    expect(bulkIdx).toBeGreaterThan(refuseIdx);
    expect(deploy).toContain('verify_payload_before_sha');
    expect(deploy).toMatch(
      /upload_verify_finalize\(\)[\s\S]*run_cpanel_ftp[\s\S]*verify_payload_before_sha[\s\S]*finalize_deploy_sha/,
    );
    expect(deploy).toContain('cpanel-ftp-readback-lib.sh');
  });

  it('pins the readback lib to a single plain FTP diagnosis path', () => {
    const lib = read('scripts/deploy/cpanel-ftp-readback-lib.sh');
    expect(lib).toContain('run_cpanel_ftp_diagnosed');
    expect(lib).toContain('PLAIN_FTP_RESULT=');
    expect(lib).toContain('PLAIN_FTP_ERROR_DETAIL=');
    expect(lib).not.toMatch(/FTPS|explicit-ftps|use_ftps/);
  });

  it('scans all workflow files so FTPS cannot re-enter through a new owner', () => {
    const workflowDir = resolve(root, '.github/workflows');
    const allWorkflows = readdirSync(workflowDir)
      .filter((name) => name.endsWith('.yml') || name.endsWith('.yaml'))
      .map((name) => read(join('.github/workflows', name)));

    for (const workflow of allWorkflows) {
      if (!workflow.includes('ftp://${FTP_SERVER}')) {
        continue;
      }
      expect(workflow).not.toContain('explicit-ftps');
      expect(workflow).not.toContain('use_ftps');
    }
  });
});
