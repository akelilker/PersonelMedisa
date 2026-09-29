-- 094: Day-key identity for NO_EVENT_DAY attendance anomalies and corrections.
-- A business date with zero QR events has no source_event_id. Existing event-keyed
-- rows stay event-keyed: their dedupe expression is unchanged when anomaly_source_event_id
-- is present. NULL source stays non-unique for unrelated inbox kinds.
-- No QR insert. No puantaj mutation. No seed data.
-- APPLY is a separate gate. This file is not executed by the application.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE qr_attendance_correction_requests
  DROP INDEX uq_qacr_pending_event,
  DROP COLUMN pending_event_guard;

ALTER TABLE qr_attendance_correction_requests
  MODIFY source_event_id INT UNSIGNED NULL,
  MODIFY original_occurred_at_utc DATETIME(6) NULL,
  ADD COLUMN anomaly_type VARCHAR(32) NULL AFTER event_type;

ALTER TABLE qr_attendance_correction_requests
  ADD COLUMN pending_event_guard INT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN status = 'BEKLIYOR' THEN source_event_id ELSE NULL END
    ) STORED,
  ADD UNIQUE KEY uq_qacr_pending_event (pending_event_guard),
  ADD COLUMN pending_day_guard VARCHAR(96)
    GENERATED ALWAYS AS (
      CASE
        WHEN status = 'BEKLIYOR'
          AND source_event_id IS NULL
          AND anomaly_type IS NOT NULL
          AND anomaly_type <> ''
          THEN CONCAT(personel_id, '#', DATE_FORMAT(business_date, '%Y-%m-%d'), '#', anomaly_type)
        ELSE NULL
      END
    ) STORED,
  ADD UNIQUE KEY uq_qacr_pending_day (pending_day_guard);

ALTER TABLE personel_inbox_notifications
  DROP INDEX uq_pin_anomaly_dedupe;

ALTER TABLE personel_inbox_notifications
  DROP COLUMN anomaly_dedupe_key,
  ADD COLUMN anomaly_type VARCHAR(32) NULL AFTER anomaly_audience,
  ADD COLUMN anomaly_business_date DATE NULL AFTER anomaly_type,
  ADD COLUMN anomaly_dedupe_key VARCHAR(160)
    GENERATED ALWAYS AS (
      CASE
        WHEN anomaly_audience IS NULL OR anomaly_audience = '' THEN NULL
        WHEN anomaly_source_event_id IS NOT NULL
          THEN CONCAT(kind, '#', anomaly_source_event_id, '#', anomaly_audience)
        WHEN anomaly_type IS NOT NULL
          AND anomaly_type <> ''
          AND anomaly_business_date IS NOT NULL
          AND personel_id IS NOT NULL
          THEN CONCAT(
            kind, '#', anomaly_type, '#', personel_id, '#',
            DATE_FORMAT(anomaly_business_date, '%Y-%m-%d'), '#', anomaly_audience
          )
        ELSE NULL
      END
    ) STORED,
  ADD UNIQUE KEY uq_pin_anomaly_dedupe (anomaly_dedupe_key);
