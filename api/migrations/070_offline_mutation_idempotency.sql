-- 070: Generic offline-mutation idempotency ledger (actor-scoped).
-- Claim lives in the same DB transaction as the business mutation (no durable orphan CLAIMED).
-- Raw request/response bodies are NOT stored; only payload_hash + result locator.

CREATE TABLE IF NOT EXISTS offline_mutation_idempotency (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id INT UNSIGNED NOT NULL,
  operation_scope VARCHAR(96) NOT NULL,
  idempotency_key VARCHAR(128) NOT NULL,
  payload_hash CHAR(64) NOT NULL,
  state ENUM('CLAIMED', 'COMPLETED', 'FAILED') NOT NULL,
  result_entity_type VARCHAR(32) NULL,
  result_entity_id BIGINT UNSIGNED NULL,
  result_entity_ref VARCHAR(96) NULL,
  http_status SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  error_code VARCHAR(80) NULL,
  created_at DATETIME(3) NOT NULL,
  completed_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_omi_actor_scope_key (actor_user_id, operation_scope, idempotency_key),
  KEY idx_omi_actor_created (actor_user_id, created_at),
  KEY idx_omi_state_created (state, created_at),
  CONSTRAINT chk_omi_payload_hash CHECK (payload_hash REGEXP '^[0-9a-f]{64}$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
