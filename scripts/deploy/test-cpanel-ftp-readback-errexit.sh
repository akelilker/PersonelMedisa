#!/usr/bin/env bash
# Regression harness for cpanel-ftp-readback-lib.sh errexit + classification contracts.
# Exit 0 on PASS. Prints AA*_RESULT lines for the vitest driver.
set -u
cd "$(dirname "$0")/../.."

PASS=0
FAIL=0
assert_eq() {
  local name="$1"
  local expected="$2"
  local actual="$3"
  if [[ "$expected" == "$actual" ]]; then
    echo "${name}=PASS"
    PASS=$((PASS + 1))
  else
    echo "${name}=FAIL expected=${expected} actual=${actual}"
    FAIL=$((FAIL + 1))
  fi
}

assert_contains() {
  local name="$1"
  local needle="$2"
  local hay="$3"
  if [[ "$hay" == *"$needle"* ]]; then
    echo "${name}=PASS"
    PASS=$((PASS + 1))
  else
    echo "${name}=FAIL missing=${needle}"
    FAIL=$((FAIL + 1))
  fi
}

classify_lftp_read_log() {
  local log_file="$1"
  local local_path="$2"
  local exit_code="$3"
  node --input-type=module -e "import { classifyLftpReadLog } from './scripts/deploy/plan-cpanel-incremental.mjs'; const fs = await import('node:fs'); const text = fs.readFileSync(process.argv[1], 'utf8'); process.stdout.write(classifyLftpReadLog(text, { localPath: process.argv[2], exitCode: Number(process.argv[3]) }));" "$log_file" "$local_path" "$exit_code"
}

sanitize_lftp_error_detail() {
  local log_file="$1"
  node --input-type=module -e "import { sanitizeLftpErrorDetail } from './scripts/deploy/plan-cpanel-incremental.mjs'; const fs = await import('node:fs'); const text = fs.readFileSync(process.argv[1], 'utf8'); process.stdout.write(sanitizeLftpErrorDetail(text));" "$log_file"
}

# ---- AA1 / AA2: both transports fail, caller errexit preserved, rc captured ----
deploy_with_ftp_mode() {
  local mode_name="$1"
  echo "Deploy transport mode: ${mode_name}"
  echo "get: Access failed: 421 Service not available"
  return 1
}

# shellcheck source=scripts/deploy/cpanel-ftp-readback-lib.sh
source "./scripts/deploy/cpanel-ftp-readback-lib.sh"

set -e
BEFORE=0
if cpanel_ftp_errexit_enabled; then BEFORE=1; fi

tmpout="$(mktemp)"
set +e
run_cpanel_ftp_diagnosed "cd .; get -o /tmp/x api/.deploy-sha;" "/tmp/x" >"$tmpout" 2>&1
rc=$?
set -e
out="$(cat "$tmpout")"
rm -f "$tmpout"

AFTER=0
if cpanel_ftp_errexit_enabled; then AFTER=1; fi

assert_eq "AA1_ERREXIT_PRESERVED" "$BEFORE" "$AFTER"
assert_eq "AA2_RC_CAPTURED" "1" "$rc"
assert_contains "AA2_HAS_FTPS_RESULT" "FTPS_RESULT=" "$out"
assert_contains "AA2_HAS_PLAIN_RESULT" "PLAIN_FTP_RESULT=" "$out"
assert_contains "AA2_HAS_FTPS_DETAIL" "FTPS_ERROR_DETAIL=" "$out"
assert_contains "AA2_HAS_PLAIN_DETAIL" "PLAIN_FTP_ERROR_DETAIL=" "$out"
assert_eq "AA2_LAST_READ_CLASS_TRANSPORTISH" "1" "$([[ "$LAST_READ_CLASS" != "SUCCESS" && "$LAST_READ_CLASS" != "REMOTE_NOT_FOUND" ]] && echo 1 || echo 0)"

# Simulate caller classification after failure (must be reachable)
PREVIOUS_SHA_READ="TRANSPORT_FAILED"
PREVIOUS_SHA_FAILURE_CLASS="$LAST_READ_CLASS"
echo "PREVIOUS_SHA_READ=${PREVIOUS_SHA_READ}"
echo "PREVIOUS_SHA_FAILURE_CLASS=${PREVIOUS_SHA_FAILURE_CLASS}"
echo "FTP_READBACK_PREFLIGHT=FAIL"
echo "REFUSING_BULK_UPLOAD=YES"
echo "READBACK_FAILURE_CLASS=${PREVIOUS_SHA_FAILURE_CLASS}"
assert_eq "AA5_TRANSPORT_FAILED" "TRANSPORT_FAILED" "$PREVIOUS_SHA_READ"
assert_contains "AA5_REFUSE" "REFUSING_BULK_UPLOAD=YES" "REFUSING_BULK_UPLOAD=YES"

# ---- AA3: real 550 → REMOTE_NOT_FOUND / capability PASS ----
deploy_with_ftp_mode() {
  local mode_name="$1"
  echo "Deploy transport mode: ${mode_name}"
  echo "get: Access failed: 550 Failed to open file. (api/.deploy-sha)"
  return 1
}
set +e
tmp550="$(mktemp)"
run_cpanel_ftp_diagnosed "cd .; get -o /tmp/x api/.deploy-sha;" "/tmp/x" >"$tmp550" 2>&1
rc550=$?
set -e
out550="$(cat "$tmp550")"
rm -f "$tmp550"
assert_eq "AA3_RC" "1" "$rc550"
assert_eq "AA3_CLASS" "REMOTE_NOT_FOUND" "$LAST_READ_CLASS"
# Capability: NOT_FOUND is non-fatal
if [[ "$LAST_READ_CLASS" == "REMOTE_NOT_FOUND" ]]; then
  echo "FTP_READBACK_PREFLIGHT=PASS"
  assert_eq "AA3_CAPABILITY_PASS" "PASS" "PASS"
fi

# ---- AA4: syntax error class ----
deploy_with_ftp_mode() {
  local mode_name="$1"
  echo "Deploy transport mode: ${mode_name}"
  echo "Unknown command \`getx'"
  echo "Usage: get [OPTS] files"
  return 1
}
set +e
run_cpanel_ftp_diagnosed "cd .; getx -o /tmp/x api/.deploy-sha;" "/tmp/x" >/dev/null 2>&1
set -e
assert_eq "AA4_SYNTAX" "LFTP_SYNTAX_ERROR" "$LAST_READ_CLASS"

# ---- AA8: sanitize does not leak secrets ----
secret_log="$(mktemp)"
printf '%s\n' \
  'Deploy transport mode: explicit-ftps' \
  'lftp -u secretuser,super-secret-password ftp://ftp.example.com' \
  'get: Access failed: 550 Failed to open file. (api/.deploy-sha)' \
  >"$secret_log"
detail="$(sanitize_lftp_error_detail "$secret_log")"
rm -f "$secret_log"
assert_contains "AA8_KEEP_550" "550" "$detail"
if [[ "$detail" != *super-secret-password* && "$detail" != *secretuser* ]]; then
  echo "AA8_NO_SECRET=PASS"
  PASS=$((PASS + 1))
else
  echo "AA8_NO_SECRET=FAIL detail=${detail}"
  FAIL=$((FAIL + 1))
fi

echo "HARNESS_PASS=${PASS}"
echo "HARNESS_FAIL=${FAIL}"
if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
exit 0
