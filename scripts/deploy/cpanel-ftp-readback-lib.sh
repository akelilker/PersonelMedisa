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

# Runs passive plain FTP only without mutating caller errexit.
# Sets globals:
#   LAST_FTP_LOG
#   LAST_FTP_RESULT LAST_READ_CLASS LAST_FTP_DETAIL
run_cpanel_ftp_diagnosed() {
  local ftp_commands="$1"
  local local_out_hint="${2:-}"
  local ftp_log
  local ftp_rc=1

  ftp_log="$(mktemp)"
  LAST_FTP_LOG="$ftp_log"
  LAST_FTP_RESULT="NOT_RUN"
  LAST_FTP_DETAIL=""
  LAST_READ_CLASS="LFTP_COMMAND_ERROR"

  echo "Deploy transport mode: plain-ftp"
  if deploy_with_ftp_mode "$ftp_commands" >"$ftp_log" 2>&1; then
    ftp_rc=0
  else
    ftp_rc=$?
  fi

  if [[ "$ftp_rc" -eq 0 ]]; then
    LAST_FTP_RESULT="SUCCESS"
    LAST_READ_CLASS="SUCCESS"
    LAST_FTP_DETAIL=""
    echo "PLAIN_FTP_RESULT=SUCCESS"
    return 0
  fi

  LAST_FTP_RESULT="$(classify_lftp_read_log "$ftp_log" "$local_out_hint" "$ftp_rc")"
  LAST_FTP_DETAIL="$(sanitize_lftp_error_detail "$ftp_log")"
  LAST_READ_CLASS="$LAST_FTP_RESULT"
  echo "PLAIN_FTP_RESULT=${LAST_FTP_RESULT}"
  echo "PLAIN_FTP_ERROR_CLASS=${LAST_FTP_RESULT}"
  echo "PLAIN_FTP_ERROR_DETAIL=${LAST_FTP_DETAIL}"
  return 1
}
