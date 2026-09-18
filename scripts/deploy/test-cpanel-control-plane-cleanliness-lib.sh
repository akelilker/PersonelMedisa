#!/usr/bin/env bash
# Runtime harness for the control-plane cleanliness contract.
#
# Covers the production false failure directly: a pre-existing historical
# request.failed.* file must not fail a run whose own request completed, while
# this run's own pending/processing/failed states must still block.
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
    echo "${name}=FAIL expected=[${expected}] actual=[${actual}]"
    FAIL=$((FAIL + 1))
  fi
}

# shellcheck source=scripts/deploy/cpanel-control-plane-cleanliness-lib.sh
source "./scripts/deploy/cpanel-control-plane-cleanliness-lib.sh"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

REQUEST_ID="pflcpre-35389100733-1"
COMPLETED="request.completed.${REQUEST_ID}.json"
PENDING="request.pending.${REQUEST_ID}.json"
FAILED="request.failed.${REQUEST_ID}.json"

# A long-lived control plane: archived successes AND failures from earlier
# operations, all present before this run writes its request.
HISTORICAL=(
  "request.completed.aa11bb22cc33dd44ee55ff66.json"
  "request.completed.1122334455667788990aabbc.json"
  "request.failed.99887766554433221100ffeedd.json"
  "request.failed.aabbccddeeff001122334455.json"
)

write_listing() {
  local file="$1"
  shift
  : > "$file"
  local entry
  for entry in "$@"; do
    printf '%s\r\n' "$entry" >> "$file"
  done
}

run_case() {
  local name="$1"
  local expected_rc="$2"
  local expected_reason="$3"
  local before_listing="$4"
  local after_listing="$5"

  local output
  local rc=0
  output="$(assert_control_plane_clean_for_request "$before_listing" "$after_listing" "$REQUEST_ID")" || rc=$?

  assert_eq "${name}_RC" "$expected_rc" "$rc"
  assert_eq "${name}_REASON" "$expected_reason" "$output"
}

# 1) The production false failure: historical failed requests exist, this run's
#    request completed cleanly. The previous global rule reported
#    FAILED_REQUEST_PRESENT here.
write_listing "$WORK/c1.before" "${HISTORICAL[@]}"
write_listing "$WORK/c1.after" "${HISTORICAL[@]}" "$COMPLETED"
run_case "HISTORICAL_FAILED_CURRENT_COMPLETED" 0 "" "$WORK/c1.before" "$WORK/c1.after"

# 2) This run's own request failed -> BLOCKED, with history unchanged.
write_listing "$WORK/c2.before" "${HISTORICAL[@]}"
write_listing "$WORK/c2.after" "${HISTORICAL[@]}" "$FAILED"
run_case "CURRENT_FAILED" 1 "CONTROL_PLANE_FAILED_REQUEST_PRESENT" "$WORK/c2.before" "$WORK/c2.after"

# 3) This run's own request was never claimed -> BLOCKED.
write_listing "$WORK/c3.before" "${HISTORICAL[@]}"
write_listing "$WORK/c3.after" "${HISTORICAL[@]}" "$PENDING"
run_case "CURRENT_PENDING" 1 "CONTROL_PLANE_LEFTOVER_PENDING_REQUEST" "$WORK/c3.before" "$WORK/c3.after"

# 4) The worker claimed the request and died mid-flight. The worker names this
#    file with its own claim token, never with the request id, so only the delta
#    can see it.
write_listing "$WORK/c4.before" "${HISTORICAL[@]}"
write_listing "$WORK/c4.after" "${HISTORICAL[@]}" "request.processing.0011aabb22cc33dd44ee55ff.json"
run_case "CURRENT_PROCESSING_LEFTOVER" 1 "CONTROL_PLANE_LEFTOVER_PROCESSING_REQUEST" "$WORK/c4.before" "$WORK/c4.after"

# 5) The request left no trace at all -> BLOCKED.
write_listing "$WORK/c5.before" "${HISTORICAL[@]}"
write_listing "$WORK/c5.after" "${HISTORICAL[@]}"
run_case "CURRENT_NOT_ARCHIVED" 1 "CONTROL_PLANE_REQUEST_NOT_ARCHIVED" "$WORK/c5.before" "$WORK/c5.after"

# 6) This run added something unexpected -> BLOCKED.
write_listing "$WORK/c6.before" "${HISTORICAL[@]}"
write_listing "$WORK/c6.after" "${HISTORICAL[@]}" "$COMPLETED" "status.json.keep"
run_case "UNEXPECTED_DELTA" 1 "CONTROL_PLANE_UNEXPECTED_DELTA" "$WORK/c6.before" "$WORK/c6.after"

# 7) A fresh control plane: empty before-listing, clean completion -> PASS.
write_listing "$WORK/c7.before"
write_listing "$WORK/c7.after" "$COMPLETED"
run_case "EMPTY_BEFORE_COMPLETED" 0 "" "$WORK/c7.before" "$WORK/c7.after"

# 8) A request id this run did not write must not satisfy the archive check.
write_listing "$WORK/c8.before" "${HISTORICAL[@]}"
write_listing "$WORK/c8.after" "${HISTORICAL[@]}" "request.completed.pflcpre-99999999999-1.json"
run_case "FOREIGN_COMPLETION_ONLY" 1 "CONTROL_PLANE_UNEXPECTED_DELTA" "$WORK/c8.before" "$WORK/c8.after"

# Negative control: the fixture for case 1 really does contain a historical
# failed request, which is exactly what used to trigger the false failure.
if grep -q 'request\.failed\.' "$WORK/c1.after"; then
  echo "FALSE_FAILURE_FIXTURE_HAS_HISTORICAL_FAILED=PASS"
  PASS=$((PASS + 1))
else
  echo "FALSE_FAILURE_FIXTURE_HAS_HISTORICAL_FAILED=FAIL"
  FAIL=$((FAIL + 1))
fi

echo "HARNESS_PASS=${PASS}"
echo "HARNESS_FAIL=${FAIL}"
if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
