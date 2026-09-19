#!/usr/bin/env bash
# Runtime harness for the control-plane cleanliness contract.
#
# Covers the production false failures directly:
#   * a pre-existing historical request.failed.* file must not fail a run whose own
#     request completed, while this run's own pending/processing/failed states must
#     still block;
#   * the first-ever APPLY's own two control-plane outputs are accepted only when the
#     caller declares them as an EXACT allowlist, and an allowlist entry never excuses
#     this run's own request state, a wildcard or an unrelated new file.
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
  shift 5

  local output
  local rc=0
  # No extra argument means the preflight contract: the archived request and
  # nothing else. Any extra arguments are the caller's exact allowlist.
  if (( $# > 0 )); then
    output="$(assert_control_plane_clean_for_request "$before_listing" "$after_listing" "$REQUEST_ID" "$@")" || rc=$?
  else
    output="$(assert_control_plane_clean_for_request "$before_listing" "$after_listing" "$REQUEST_ID")" || rc=$?
  fi

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

# ---------------------------------------------------------------------------
# First-ever APPLY: it legitimately persists its own two control-plane outputs
# beside this run's archived request. The caller declares them as an EXACT
# allowlist; the default (preflight) contract stays untouched.
# ---------------------------------------------------------------------------
ALLOW_APPLY=(
  "personel-first-login-apply.json"
  "personel-first-login-apply-preimage.json"
)

# 9) The production false negative: this run's completed request plus its own two
#    outputs, with the exact allowlist declared -> PASS.
write_listing "$WORK/c9.before" "${HISTORICAL[@]}"
write_listing "$WORK/c9.after" "${HISTORICAL[@]}" "$COMPLETED" "${ALLOW_APPLY[@]}"
run_case "APPLY_OWN_OUTPUTS_ALLOWLISTED" 0 "" "$WORK/c9.before" "$WORK/c9.after" "${ALLOW_APPLY[@]}"

# 10) The same delta with NO allowlist -> still BLOCKED. The default contract did
#     not get weaker for every other caller.
run_case "APPLY_OWN_OUTPUTS_NO_ALLOWLIST" 1 "CONTROL_PLANE_UNEXPECTED_DELTA" "$WORK/c9.before" "$WORK/c9.after"

# 11) An allowlisted output plus one unrelated new file -> BLOCKED. The allowlist
#     never becomes "ignore everything this operation writes".
write_listing "$WORK/c11.before" "${HISTORICAL[@]}"
write_listing "$WORK/c11.after" "${HISTORICAL[@]}" "$COMPLETED" "${ALLOW_APPLY[@]}" "random.json"
run_case "ALLOWLIST_PLUS_UNKNOWN_DELTA" 1 "CONTROL_PLANE_UNEXPECTED_DELTA" "$WORK/c11.before" "$WORK/c11.after" "${ALLOW_APPLY[@]}"

# 12) This run's own pending request -> BLOCKED even with an allowlist present.
#     A pending/failed/processing request can never be excused by an allowlist.
write_listing "$WORK/c12.before" "${HISTORICAL[@]}"
write_listing "$WORK/c12.after" "${HISTORICAL[@]}" "$PENDING" "${ALLOW_APPLY[@]}"
run_case "CURRENT_PENDING_WITH_ALLOWLIST" 1 "CONTROL_PLANE_LEFTOVER_PENDING_REQUEST" \
  "$WORK/c12.before" "$WORK/c12.after" "${ALLOW_APPLY[@]}"

# 13) This run's own failed request -> BLOCKED even with an allowlist present.
write_listing "$WORK/c13.before" "${HISTORICAL[@]}"
write_listing "$WORK/c13.after" "${HISTORICAL[@]}" "$FAILED" "${ALLOW_APPLY[@]}"
run_case "CURRENT_FAILED_WITH_ALLOWLIST" 1 "CONTROL_PLANE_FAILED_REQUEST_PRESENT" \
  "$WORK/c13.before" "$WORK/c13.after" "${ALLOW_APPLY[@]}"

# 14) A mid-flight worker leak -> BLOCKED even with an allowlist present.
write_listing "$WORK/c14.before" "${HISTORICAL[@]}"
write_listing "$WORK/c14.after" "${HISTORICAL[@]}" "request.processing.0011aabb22cc33dd44ee55ff.json" "${ALLOW_APPLY[@]}"
run_case "CURRENT_PROCESSING_LEAK_WITH_ALLOWLIST" 1 "CONTROL_PLANE_LEFTOVER_PROCESSING_REQUEST" \
  "$WORK/c14.before" "$WORK/c14.after" "${ALLOW_APPLY[@]}"

# 15) Preflight caller: no allowlist argument at all, clean completion -> PASS.
write_listing "$WORK/c15.before" "${HISTORICAL[@]}"
write_listing "$WORK/c15.after" "${HISTORICAL[@]}" "$COMPLETED"
run_case "PREFLIGHT_CALLER_NO_EXTRAS" 0 "" "$WORK/c15.before" "$WORK/c15.after"

# 16) A wildcard in the allowlist is refused instead of silently widening.
run_case "ALLOWLIST_GLOB_REFUSED" 2 "CONTROL_PLANE_ALLOWLIST_ENTRY_INVALID" \
  "$WORK/c15.before" "$WORK/c15.after" "personel-first-login-*.json"

# 17) A request.* entry in the allowlist is refused: request state is never
#     allowlistable.
run_case "ALLOWLIST_REQUEST_ENTRY_REFUSED" 2 "CONTROL_PLANE_ALLOWLIST_ENTRY_INVALID" \
  "$WORK/c15.before" "$WORK/c15.after" "$PENDING"

# 18) A path-shaped allowlist entry is refused too.
run_case "ALLOWLIST_PATH_REFUSED" 2 "CONTROL_PLANE_ALLOWLIST_ENTRY_INVALID" \
  "$WORK/c15.before" "$WORK/c15.after" "../migration-control/personel-first-login-apply.json"

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
