"""Existing FTP control-plane trust; no application password or general mutation API."""
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import time
from datetime import datetime, timezone


def require(condition, code):
    if not condition:
        raise RuntimeError(code)


def preflight_drift_items(report, request_id, sha):
    """Bounded drift tokens of one correlated FAILED preflight report.

    A failed preflight reports every approved-preimage mismatch in one list. The
    report is trusted only when it describes this exact request, and the list only
    when it is a bounded list of bounded single tokens; anything else fails closed
    instead of publishing raw report content (snapshot, preimage values, personnel or
    user text, SQL).
    """
    require(report.get('request_id') == request_id and report.get('deployed_sha') == sha
            and report.get('mode') == 'FINAL_CLOSE_PREFLIGHT',
            'FINAL_CLOSE_PREIMAGE_REPORT_INVALID')
    count, drifts = report.get('preimage_drift_count'), report.get('preimage_drifts')
    require(report.get('result') == 'FAIL' and report.get('preflight_checksum') is None
            and report.get('production_mutation_count') == 0
            and isinstance(count, int) and not isinstance(count, bool)
            and isinstance(drifts, list) and 0 < count <= 100 and count == len(drifts)
            and all(isinstance(item, str) and re.fullmatch('[A-Z0-9_]{1,100}', item) for item in drifts),
            'FINAL_CLOSE_PREIMAGE_DRIFT_INVALID')
    return drifts


def main():
    env = os.environ
    mode, sha = env['MODE'], env['DEPLOYED_SHA']
    require(env.get('GITHUB_REPOSITORY') == 'akelilker/PersonelMedisa'
            and env.get('REPOSITORY_PRIVATE') == 'true'
            and env.get('GITHUB_REF') == 'refs/heads/main'
            and env.get('GITHUB_SHA') == sha, 'FINAL_CLOSE_PRIVATE_MAIN_REQUIRED')
    require(mode in ('FINAL_CLOSE_PREFLIGHT', 'FINAL_CLOSE_APPLY')
            and env['CONFIRMATION'] == mode and re.fullmatch('[a-f0-9]{40}', sha),
            'FINAL_CLOSE_AUTHORIZATION_INVALID')
    checksum = env.get('PREFLIGHT_CHECKSUM', '')
    require((mode == 'FINAL_CLOSE_PREFLIGHT' and not checksum)
            or (mode == 'FINAL_CLOSE_APPLY' and re.fullmatch('[a-f0-9]{64}', checksum)),
            'FINAL_CLOSE_PREFLIGHT_REQUIRED')
    require(env.get('FTP_PORT') == '21', 'FINAL_CLOSE_FTP_PORT_INVALID')
    # Escape lftp language, not shell language. Secrets stay in stdin, never argv/logs.
    def quote(value):
        require(not any(c in value for c in '\r\n\x00'), 'FINAL_CLOSE_FTP_VALUE_INVALID')
        return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$').replace('`', '\\`') + '"'

    def ftp(commands):
        script = '\n'.join([
            'set cmd:fail-exit true', 'set net:max-retries 2', 'set net:timeout 20',
            'set ftp:passive-mode on', 'set ftp:ssl-allow false', 'set xfer:clobber true',
            'open -p 21 ' + quote('ftp://' + env['FTP_SERVER']),
            'user ' + quote(env['FTP_USERNAME']) + ' ' + quote(env['FTP_PASSWORD']),
            *commands, 'bye'])
        result = subprocess.run(['lftp'], input=script, text=True, capture_output=True, timeout=90)
        require(result.returncode == 0, 'FINAL_CLOSE_CONTROL_TRANSPORT_FAILED')

    with tempfile.TemporaryDirectory(prefix='final-close-') as directory:
        work = Path(directory)
        def get(remote, name):
            local = work / name
            ftp(['get ' + quote(remote) + ' -o ' + quote(str(local))])
            return local.read_bytes()

        require(get('api/.deploy-sha', 'sha').decode().strip() == sha, 'FINAL_CLOSE_DEPLOY_SHA_MISMATCH')
        # Same SHA marker alone cannot attest to a complete/unaltered worker upload.
        paths = ['api/bin/cpanel-migration-cron.php', 'api/bin/final-close-owner.php',
                 'api/src/Controllers/PersonellerController.php', 'api/src/Controllers/YonetimController.php',
                 'api/src/Database/MigrationBackupService.php']
        paths += [str(p).replace('\\', '/') for p in Path('api/src/Services/Operations').glob('FinalClose*.php')]
        for index, path in enumerate(paths):
            require(hashlib.sha256(get(path, 'owner-' + str(index))).digest()
                    == hashlib.sha256(Path(path).read_bytes()).digest(), 'FINAL_CLOSE_OWNER_PARITY_MISMATCH')
        heartbeat = json.loads(get('api/runtime/migration-control/worker-heartbeat.json', 'heartbeat'))
        require(heartbeat.get('deployed_sha') == sha, 'FINAL_CLOSE_HEARTBEAT_SHA_MISMATCH')
        at = datetime.fromisoformat(heartbeat['updated_at'].replace('Z', '+00:00')).timestamp()
        require(-60 <= time.time() - at <= 1800, 'FINAL_CLOSE_HEARTBEAT_STALE')
        listing = work / 'listing'
        ftp(['cls -1 api/runtime/migration-control > ' + quote(str(listing))])
        require(not re.search(r'request\.(pending|processing)\.', listing.read_text()), 'FINAL_CLOSE_WORKER_BUSY')
        request_id = 'final-close-' + env['GITHUB_RUN_ID'] + '-' + env['GITHUB_RUN_ATTEMPT']
        require(re.fullmatch('[A-Za-z0-9._-]{1,100}', request_id), 'FINAL_CLOSE_REQUEST_ID_INVALID')
        request = {'request_id': request_id, 'deployed_sha': sha, 'mode': mode,
                   'confirmation': mode, 'repository': 'akelilker/PersonelMedisa',
                   'repository_private': True, 'package_id': 'PERSONELMEDISA_FINAL_CLOSE_20260909',
                   'preflight_checksum': checksum or None,
                   'requested_at': datetime.now(timezone.utc).isoformat().replace('+00:00', 'Z')}
        local = work / 'request.json'
        local.write_text(json.dumps(request), encoding='utf-8')
        base = 'api/runtime/migration-control/'
        temporary = base + 'request.uploading.' + request_id + '.json'
        pending = base + 'request.pending.' + request_id + '.json'
        ftp(['put ' + quote(str(local)) + ' -o ' + quote(temporary),
             'mv ' + quote(temporary) + ' ' + quote(pending)])
        deadline = time.time() + 1800
        while time.time() < deadline:
            try:
                status = json.loads(get(base + 'status.json', 'status'))
            except (RuntimeError, json.JSONDecodeError):
                time.sleep(10)
                continue
            if status.get('request_id') == request_id and status.get('state') in ('SUCCEEDED', 'FAILED'):
                if status.get('state') == 'FAILED':
                    # Surface the worker's bounded reason/stage so a failure is
                    # attributable. detail is printed only when it is a clean code;
                    # anything else (PDO/secrets/personnel text) is never logged.
                    for key in ('reason', 'stage', 'detail'):
                        value = status.get(key)
                        if isinstance(value, str) and re.fullmatch('[A-Z0-9_]+', value):
                            print('FINAL_CLOSE_WORKER_' + key.upper() + '=' + value)
                    if mode == 'FINAL_CLOSE_PREFLIGHT':
                        # One failed preflight must not report only the first drift:
                        # the correlated report is read read-only and only its bounded
                        # tokens are logged, so a whole run is attributable at once.
                        try:
                            drift_report = json.loads(get(base + 'final-close-report.json', 'report'))
                        except json.JSONDecodeError:
                            raise RuntimeError('FINAL_CLOSE_PREIMAGE_REPORT_INVALID')
                        drifts = preflight_drift_items(drift_report, request_id, sha)
                        print('FINAL_CLOSE_PREIMAGE_DRIFT_COUNT=' + str(len(drifts)))
                        for item in drifts:
                            print('FINAL_CLOSE_PREIMAGE_DRIFT_ITEM=' + item)
                    raise RuntimeError('FINAL_CLOSE_WORKER_FAILED')
                report = json.loads(get(base + 'final-close-report.json', 'report'))
                require(report.get('request_id') == request_id and report.get('deployed_sha') == sha
                        and report.get('mode') == mode, 'FINAL_CLOSE_REPORT_CORRELATION_FAILED')
                print(json.dumps(report))  # Redacted metadata only; preimages remain outside webroot.
                return
            time.sleep(10)
        raise RuntimeError('FINAL_CLOSE_TIMEOUT_RECONCILE_REQUEST_BEFORE_RETRY')


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        code = str(error)
        print(code if re.fullmatch('FINAL_CLOSE_[A-Z_]+', code) else 'FINAL_CLOSE_CONTROL_FAILED')
        raise SystemExit(1)
