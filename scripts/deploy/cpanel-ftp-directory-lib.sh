#!/usr/bin/env bash
# Fail-closed helper for creating a known cPanel FTP directory.
#
# The caller must define run_cpanel_ftp. An existing directory is accepted by
# entering and listing it first. A racing `550 ... File exists` response is
# accepted only when a second probe proves that the exact path is a usable
# directory.

probe_cpanel_remote_directory_at_root() {
  local remote_root="$1"
  local remote_directory="$2"

  run_cpanel_ftp "cd ${remote_root}; cd ${remote_directory}; cls -la;"
}

ensure_cpanel_remote_directory_at_root() {
  local remote_root="${1:-}"
  local remote_directory="${2:-}"
  local create_log
  local create_rc=1

  if [[ -z "$remote_root" || "$remote_root" == /* || "$remote_root" == *".."* || ! "$remote_root" =~ ^[A-Za-z0-9._/-]+$ ]]; then
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=INVALID_ROOT"
    return 2
  fi

  if [[ -z "$remote_directory" || "$remote_directory" == /* || "$remote_directory" == *".."* || ! "$remote_directory" =~ ^[A-Za-z0-9._/-]+$ ]]; then
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=INVALID_PATH"
    return 2
  fi

  if probe_cpanel_remote_directory_at_root "$remote_root" "$remote_directory" >/dev/null 2>&1; then
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=EXISTS"
    return 0
  fi

  create_log="$(mktemp)"
  if run_cpanel_ftp "cd ${remote_root}; mkdir -p ${remote_directory};" >"$create_log" 2>&1; then
    if probe_cpanel_remote_directory_at_root "$remote_root" "$remote_directory" >/dev/null 2>&1; then
      rm -f "$create_log"
      echo "REMOTE_DIRECTORY_ENSURE_RESULT=CREATED"
      return 0
    fi
  else
    create_rc=$?
  fi

  if grep -Fq "550 Can't create directory: File exists" "$create_log" \
    && probe_cpanel_remote_directory_at_root "$remote_root" "$remote_directory" >/dev/null 2>&1; then
    rm -f "$create_log"
    echo "REMOTE_DIRECTORY_ENSURE_RESULT=EXISTS_AFTER_RACE"
    return 0
  fi

  rm -f "$create_log"
  echo "REMOTE_DIRECTORY_ENSURE_RESULT=FAILED"
  return "$create_rc"
}

ensure_cpanel_remote_directory() {
  local remote_directory="${1:-}"

  ensure_cpanel_remote_directory_at_root "." "$remote_directory"
}
