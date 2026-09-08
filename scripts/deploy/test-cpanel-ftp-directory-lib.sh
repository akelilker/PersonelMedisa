#!/usr/bin/env bash
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

# shellcheck source=scripts/deploy/cpanel-ftp-directory-lib.sh
source "./scripts/deploy/cpanel-ftp-directory-lib.sh"

run_case() {
  local name="$1"
  local scenario="$2"
  local expected_rc="$3"
  local expected_mkdir_calls="$4"
  local remote_directory="${5:-api/runtime/migration-control}"
  local call_count=0
  local mkdir_calls=0

  run_cpanel_ftp() {
    local command="$1"
    call_count=$((call_count + 1))
    if [[ "$command" == *"mkdir -p "* ]]; then
      mkdir_calls=$((mkdir_calls + 1))
    fi

    case "$scenario:$call_count" in
      existing:1) return 0 ;;
      missing:1|nested_parents:1) echo "cls: Access failed: 550 No such file"; return 1 ;;
      missing:2|nested_parents:2) return 0 ;;
      missing:3|nested_parents:3) return 0 ;;
      race:1) echo "cls: Access failed: 550 No such file"; return 1 ;;
      race:2) echo "mkdir: Access failed: 550 Can't create directory: File exists"; return 1 ;;
      race:3) return 0 ;;
      permission:1) echo "cls: Access failed: 550 Permission denied"; return 1 ;;
      permission:2) echo "mkdir: Access failed: 550 Permission denied"; return 1 ;;
      auth:1) echo "Login failed: 530 Authentication failed"; return 1 ;;
      auth:2) echo "Login failed: 530 Authentication failed"; return 1 ;;
      wrong_root:1) echo "cls: Access failed: 550 No such file"; return 1 ;;
      wrong_root:2) echo "mkdir: Access failed: 550 No such file"; return 1 ;;
      file_collision:1) echo "cd: Access failed: 550 Not a directory"; return 1 ;;
      file_collision:2) echo "mkdir: Access failed: 550 Can't create directory: File exists"; return 1 ;;
      file_collision:3) echo "cd: Access failed: 550 Not a directory"; return 1 ;;
      unreadable:1) echo "cls: Access failed: 550 Permission denied"; return 1 ;;
      unreadable:2) echo "mkdir: Access failed: 550 Can't create directory: File exists"; return 1 ;;
      unreadable:3) echo "cls: Access failed: 550 Permission denied"; return 1 ;;
      other:1) echo "421 Service not available"; return 1 ;;
      other:2) echo "421 Service not available"; return 1 ;;
      *) echo "Unexpected mock call: ${scenario}:${call_count}"; return 99 ;;
    esac
  }

  local rc=0
  if ensure_cpanel_remote_directory_at_root "." "$remote_directory" >/dev/null; then
    rc=0
  else
    rc=$?
  fi

  assert_eq "${name}_RC" "$expected_rc" "$rc"
  assert_eq "${name}_MKDIR_CALLS" "$expected_mkdir_calls" "$mkdir_calls"
}

run_case "MISSING_DIRECTORY" "missing" 0 1
run_case "EXISTING_DIRECTORY" "existing" 0 0
run_case "NESTED_EXISTING_PARENTS" "nested_parents" 0 1
run_case "EXACT_FILE_EXISTS_RACE" "race" 0 1
run_case "PERMISSION_DENIED_550" "permission" 1 1
run_case "AUTH_530" "auth" 1 1
run_case "WRONG_REMOTE_ROOT" "wrong_root" 1 1
run_case "OTHER_FTP_ERROR" "other" 1 1
run_case "REMOTE_FILE_NOT_DIRECTORY" "file_collision" 1 1 "api"
run_case "UNREADABLE_EXISTING_DIRECTORY" "unreadable" 1 1 "api"
run_case "INCREMENTAL_EXISTING_API" "existing" 0 0 "api"
run_case "FULL_MIRROR_EXISTING_API" "existing" 0 0 "api"

echo "HARNESS_PASS=${PASS}"
echo "HARNESS_FAIL=${FAIL}"
if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi
