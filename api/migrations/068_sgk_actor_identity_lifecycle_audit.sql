-- S98: Canonical formal SGK actor identity lifecycle audit.
-- Identity state remains owned by actor_identities; this table records
-- attributable create/verify/bind transitions.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE IF NOT EXISTS actor_identity_audits (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_identity_id INT UNSIGNED NOT NULL,
  target_user_id INT UNSIGNED NULL,
  action VARCHAR(32) NOT NULL,
  changed_by_user_id INT UNSIGNED NOT NULL,
  details_json JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_actor_identity_audits_identity (actor_identity_id, created_at),
  KEY idx_actor_identity_audits_target_user (target_user_id, created_at),
  KEY idx_actor_identity_audits_changed_by (changed_by_user_id, created_at),
  CONSTRAINT fk_actor_identity_audits_identity
    FOREIGN KEY (actor_identity_id) REFERENCES actor_identities (id),
  CONSTRAINT fk_actor_identity_audits_target_user
    FOREIGN KEY (target_user_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_actor_identity_audits_changed_by
    FOREIGN KEY (changed_by_user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
