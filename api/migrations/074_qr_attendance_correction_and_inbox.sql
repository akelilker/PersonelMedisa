-- 074: QR attendance correction requests + personel inbox notifications
-- Additive / forward-only. No seed data. No truncate/delete/update of existing rows.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS qr_attendance_correction_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  personel_id INT UNSIGNED NOT NULL,
  requester_user_id INT UNSIGNED NOT NULL,
  source_event_id INT UNSIGNED NOT NULL,
  event_type ENUM('GIRIS', 'CIKIS') NOT NULL,
  business_date DATE NOT NULL,
  original_occurred_at_utc DATETIME(6) NOT NULL,
  requested_local_time CHAR(5) NOT NULL,
  requested_occurred_at_utc DATETIME(6) NOT NULL,
  explanation VARCHAR(500) NULL,
  status ENUM('BEKLIYOR', 'ONAYLANDI', 'UYGUN_GORULMEDI') NOT NULL DEFAULT 'BEKLIYOR',
  resolved_approver_user_id INT UNSIGNED NULL,
  assigned_approver_user_id INT UNSIGNED NOT NULL,
  assigned_approver_role VARCHAR(64) NOT NULL,
  decision_at_utc DATETIME(6) NULL,
  decision_note VARCHAR(500) NULL,
  effective_applied_at_utc DATETIME(6) NULL,
  effective_local_time CHAR(5) NULL,
  gunluk_puantaj_id INT UNSIGNED NULL,
  reminder_due_at_utc DATETIME(6) NOT NULL,
  reminder_sent_at_utc DATETIME(6) NULL,
  pending_event_guard INT UNSIGNED
    GENERATED ALWAYS AS (CASE WHEN status = 'BEKLIYOR' THEN source_event_id ELSE NULL END) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_qacr_pending_event (pending_event_guard),
  KEY idx_qacr_personel_date (personel_id, business_date),
  KEY idx_qacr_approver_status (assigned_approver_user_id, status),
  KEY idx_qacr_reminder (status, reminder_due_at_utc, reminder_sent_at_utc),
  CONSTRAINT fk_qacr_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qacr_requester FOREIGN KEY (requester_user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qacr_source_event FOREIGN KEY (source_event_id) REFERENCES qr_attendance_events (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qacr_assigned FOREIGN KEY (assigned_approver_user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_qacr_resolved FOREIGN KEY (resolved_approver_user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT chk_qacr_requested_time CHECK (requested_local_time REGEXP '^[0-2][0-9]:[0-5][0-9]$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pending_event_guard enforces at most one BEKLIYOR row per source QR event.
-- Historical ONAYLANDI / UYGUN_GORULMEDI rows may repeat after resolution.

CREATE TABLE IF NOT EXISTS personel_inbox_notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  recipient_user_id INT UNSIGNED NOT NULL,
  personel_id INT UNSIGNED NULL,
  kind VARCHAR(64) NOT NULL,
  title VARCHAR(200) NOT NULL,
  body VARCHAR(1000) NOT NULL,
  payload_json JSON NULL,
  related_correction_id INT UNSIGNED NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
  popup_required TINYINT(1) NOT NULL DEFAULT 1,
  popup_consumed_at_utc DATETIME(6) NULL,
  reminder_of_notification_id INT UNSIGNED NULL,
  created_at_utc DATETIME(6) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pin_recipient_created (recipient_user_id, created_at_utc),
  KEY idx_pin_popup (recipient_user_id, popup_required, popup_consumed_at_utc),
  KEY idx_pin_correction (related_correction_id),
  CONSTRAINT fk_pin_recipient FOREIGN KEY (recipient_user_id) REFERENCES users (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pin_personel FOREIGN KEY (personel_id) REFERENCES personeller (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pin_correction FOREIGN KEY (related_correction_id)
    REFERENCES qr_attendance_correction_requests (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT,
  CONSTRAINT fk_pin_reminder_of FOREIGN KEY (reminder_of_notification_id)
    REFERENCES personel_inbox_notifications (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
