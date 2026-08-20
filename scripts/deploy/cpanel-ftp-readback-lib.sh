#!/usr/bin/env bash
# Privacy-safe cPanel FTP read-back helpers.
# Sourced by .github/workflows/deploy-cpanel.yml after defining:
#   deploy_with_ftp_mode
#   classify_lftp_read_log
#   sanitize_lftp_error_detail
#
# CRITICAL: these functions must NEVER call `set -e` / `set +e`.
# Doing so leaks errexit into the caller and aborts before rc capture.

cpanel_ftp_errexit_enabled() {
  case $- in
    *e*) return 0 ;;
    *) return 1 ;;
  esac
}

# Runs FTPS then plain FTP without mutating caller errexit.
# Sets globals:
#   LAST_FTP_LOG LAST_FTPS_LOG LAST_PLAIN_LOG
#   LAST_FTPS_RESULT LAST_PLAIN_RESULT LAST_READ_CLASS
#   LAST_FTPS_DETAIL LAST_PLAIN_DETAIL
run_cpanel_ftp_diagnosed() {
  local ftp_commands="$1"
  local local_out_hint="${2:-}"
  local ftps_log plain_log
  local ftps_rc=1
  local plain_rc=1

  ftps_log="$(mktemp)"
  plain_log="$(mktemp)"
  LAST_FTPS_LOG="$ftps_log"
  LAST_PLAIN_LOG="$plain_log"
  LAST_FTP_LOG="$plain_log"
  LAST_FTPS_RESULT="NOT_RUN"
  LAST_PLAIN_RESULT="NOT_RUN"
  LAST_FTPS_DETAIL=""
  LAST_PLAIN_DETAIL=""
  LAST_READ_CLASS="LFTP_COMMAND_ERROR"

  echo "Explicit FTPS port 21 deneniyor..."
  if deploy_with_ftp_mode "explicit-ftps" "true" "$ftp_commands" >"$ftps_log" 2>&1; then
    ftps_rc=0
  else
    ftps_rc=$?
  fi

  if [[ "$ftps_rc" -eq 0 ]]; then
    LAST_FTPS_RESULT="SUCCESS"
    LAST_PLAIN_RESULT="SKIPPED"
    LAST_READ_CLASS="SUCCESS"
    LAST_FTPS_DETAIL=""
    LAST_PLAIN_DETAIL=""
    LAST_FTP_LOG="$ftps_log"
    echo "FTPS_RESULT=SUCCESS"
    echo "PLAIN_FTP_RESULT=SKIPPED"
    return 0
  fi

  LAST_FTPS_RESULT="$(classify_lftp_read_log "$ftps_log" "$local_out_hint" "$ftps_rc")"
  LAST_FTPS_DETAIL="$(sanitize_lftp_error_detail "$ftps_log")"
  echo "FTPS_RESULT=${LAST_FTPS_RESULT}"
  echo "FTPS_ERROR_CLASS=${LAST_FTPS_RESULT}"
  echo "FTPS_ERROR_DETAIL=${LAST_FTPS_DETAIL}"
  echo "Explicit FTPS basarisiz oldu, plain FTP fallback deneniyor..."

  if deploy_with_ftp_mode "plain-ftp" "false" "$ftp_commands" >"$plain_log" 2>&1; then
    plain_rc=0
  else
    plain_rc=$?
  fi

  if [[ "$plain_rc" -eq 0 ]]; then
    LAST_PLAIN_RESULT="SUCCESS"
    LAST_READ_CLASS="SUCCESS"
    LAST_PLAIN_DETAIL=""
    LAST_FTP_LOG="$plain_log"
    echo "PLAIN_FTP_RESULT=SUCCESS"
    return 0
  fi

  LAST_PLAIN_RESULT="$(classify_lftp_read_log "$plain_log" "$local_out_hint" "$plain_rc")"
  LAST_PLAIN_DETAIL="$(sanitize_lftp_error_detail "$plain_log")"
  LAST_READ_CLASS="$LAST_PLAIN_RESULT"
  LAST_FTP_LOG="$plain_log"
  echo "PLAIN_FTP_RESULT=${LAST_PLAIN_RESULT}"
  echo "PLAIN_FTP_ERROR_CLASS=${LAST_PLAIN_RESULT}"
  echo "PLAIN_FTP_ERROR_DETAIL=${LAST_PLAIN_DETAIL}"
  return 1
}
