#!/usr/bin/env bash
# Fail-closed helper for creating a known cPanel FTP directory.
#
# The caller must define run_cpanel_ftp. An existing directory is accepted by
# probing it first. A racing `550 ... File exists` response is accepted only
# when a second probe proves that the exact directory now exists.

ensure_cpanel_remote_directory() {
  local remote_directory="${1:-}"
  local create_log
  local create_rc=1

  if [[ -z "$remote_directory" || "$remote_directory" == /* || "$remote_directory" == *".."* || ! "$remote_directory" =~ ^[A-Za-z0-9._/-]+$ ]]; then
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=INVALID_PATH"
    return 2
  fi

  if run_cpanel_ftp "cls -d ${remote_directory};" >/dev/null 2>&1; then
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=EXISTS"
    return 0
  fi

  create_log="$(mktemp)"
  if run_cpanel_ftp "mkdir -p ${remote_directory};" >"$create_log" 2>&1; then
    rm -f "$create_log"
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=CREATED"
    return 0
  else
    create_rc=$?
  fi

  if grep -Fq "550 Can't create directory: File exists" "$create_log" \
    && run_cpanel_ftp "cls -d ${remote_directory};" >/dev/null 2>&1; then
    rm -f "$create_log"
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=EXISTS_AFTER_RACE"
    return 0
  fi

  rm -f "$create_log"
  echo "REMOTE_DIRECTORY_ENSURE_RESULT=FAILED"
  return "$create_rc"
}
