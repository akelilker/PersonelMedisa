#!/usr/bin/env bash
# Fail-closed cleanliness contract for a control-plane request this run created.
#
# The control plane is shared and append-only: it keeps the archived
# request.completed.* / request.failed.* files of every earlier operation. A run
# may therefore only judge ITS OWN request. A global grep for request.failed.*
# re-judges history that this run did not create, which turned a PASS payload
# into FAILED_REQUEST_PRESENT.
#
# The only honest correlation is the before/after delta plus this run's exact
# request id, because the control plane carries no "which run owns this file"
# marker other than the request id inside the file name.
#
# Contract for the request `pflcpre-<run>-<attempt>`:
#   request.pending.<id>.json    -> BLOCKED (the worker never claimed it)
#   request.processing.<claim>   -> BLOCKED (the worker died mid-request; the
#                                   worker names this file with its own claim
#                                   token, so it is only visible as a new entry)
#   request.failed.<id>.json     -> BLOCKED (this request failed)
#   request.completed.<id>.json  -> required (this request finished)
#   any other new entry          -> BLOCKED (this run left something unexpected)
#
# Pre-existing files, failed or completed, are present in both listings and are
# never re-judged. Nothing is deleted.

# Normalize an `lftp cls -1` listing: drop CR and blank lines, take basenames,
# dedupe and sort so the delta is stable and empty lines never become grep
# patterns (an empty pattern would match every line).
normalize_control_plane_listing() {
  local line

  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    line="${line#"${line%%[![:space:]]*}"}"
    line="${line%"${line##*[![:space:]]}"}"
    [[ -z "$line" ]] && continue
    printf '%s\n' "${line##*/}"
  done | LC_ALL=C sort -u
}

# Prints the reason code and returns non-zero when the control plane is not clean
# for this request. Prints nothing and returns 0 when it is clean. Returns 2 for
# an unusable invocation, which the caller must treat as a block as well.
assert_control_plane_clean_for_request() {
  local before_listing="${1:-}"
  local after_listing="${2:-}"
  local request_id="${3:-}"

  if [[ ! "$request_id" =~ ^[A-Za-z0-9._-]{1,128}$ ]]; then
    echo "CONTROL_PLANE_REQUEST_ID_INVALID"
    return 2
  fi
  if [[ ! -f "$before_listing" ]]; then
    echo "CONTROL_PLANE_BEFORE_LISTING_MISSING"
    return 2
  fi
  if [[ ! -f "$after_listing" ]]; then
    echo "CONTROL_PLANE_AFTER_LISTING_MISSING"
    return 2
  fi

  local work
  work="$(mktemp -d)"
  normalize_control_plane_listing < "$before_listing" > "$work/before.txt"
  normalize_control_plane_listing < "$after_listing" > "$work/after.txt"

  local completed="request.completed.${request_id}.json"
  local pending="request.pending.${request_id}.json"
  local failed="request.failed.${request_id}.json"

  # This request's own terminal states, independent of the delta.
  if grep -qxF "$pending" "$work/after.txt"; then
    echo "CONTROL_PLANE_LEFTOVER_PENDING_REQUEST"
    rm -rf "$work"
    return 1
  fi
  if grep -qxF "$failed" "$work/after.txt"; then
    echo "CONTROL_PLANE_FAILED_REQUEST_PRESENT"
    rm -rf "$work"
    return 1
  fi

  # Entries this run added. A pre-existing failed/completed request is in both
  # listings and can never appear here.
  if [[ -s "$work/before.txt" ]]; then
    grep -vxF -f "$work/before.txt" "$work/after.txt" > "$work/delta.txt" || true
  else
    cp "$work/after.txt" "$work/delta.txt"
  fi

  local entry
  while IFS= read -r entry; do
    [[ -z "$entry" ]] && continue
    [[ "$entry" == "$completed" ]] && continue
    case "$entry" in
      request.processing.*)
        echo "CONTROL_PLANE_LEFTOVER_PROCESSING_REQUEST"
        rm -rf "$work"
        return 1
        ;;
      request.pending.*)
        echo "CONTROL_PLANE_LEFTOVER_PENDING_REQUEST"
        rm -rf "$work"
        return 1
        ;;
      request.failed.*)
        echo "CONTROL_PLANE_FAILED_REQUEST_PRESENT"
        rm -rf "$work"
        return 1
        ;;
      *)
        echo "CONTROL_PLANE_UNEXPECTED_DELTA"
        rm -rf "$work"
        return 1
        ;;
    esac
  done < "$work/delta.txt"

  if ! grep -qxF "$completed" "$work/after.txt"; then
    echo "CONTROL_PLANE_REQUEST_NOT_ARCHIVED"
    rm -rf "$work"
    return 1
  fi

  rm -rf "$work"
  return 0
}
