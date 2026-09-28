-- 093: Race-safe idempotency for one attendance-anomaly notification per source QR event and audience.
-- personel_inbox_notifications had no unique key on (kind, source event, audience).
-- A read-then-insert check loses to two CLI ticks in the same minute.
-- Generated column follows the 074 pending_event_guard pattern. NULL stays non-unique so
-- existing inbox kinds are unchanged. No new table. No QR/puantaj mutation.
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE personel_inbox_notifications
  ADD COLUMN anomaly_source_event_id INT UNSIGNED NULL AFTER payload_json,
  ADD COLUMN anomaly_audience VARCHAR(16) NULL AFTER anomaly_source_event_id,
  ADD COLUMN anomaly_dedupe_key VARCHAR(160)
    GENERATED ALWAYS AS (
      CASE
        WHEN anomaly_source_event_id IS NULL OR anomaly_audience IS NULL OR anomaly_audience = '' THEN NULL
        ELSE CONCAT(kind, '#', anomaly_source_event_id, '#', anomaly_audience)
      END
    ) STORED,
  ADD UNIQUE KEY uq_pin_anomaly_dedupe (anomaly_dedupe_key);
