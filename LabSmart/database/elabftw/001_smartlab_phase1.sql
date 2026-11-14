CREATE TABLE IF NOT EXISTS smartlab_projects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  status TINYINT UNSIGNED NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_projects_team_code (team_id, code),
  CONSTRAINT fk_smartlab_projects_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_projects_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_project_members (
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_smartlab_project_members_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_project_members_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_project_entities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_project_entities (project_id, entity_type, entity_id),
  CONSTRAINT fk_smartlab_project_entities_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_samples (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  source_item_id INT UNSIGNED NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  sample_type VARCHAR(128) NOT NULL,
  status VARCHAR(64) NOT NULL DEFAULT 'active',
  quantity DECIMAL(12,4) NULL,
  unit VARCHAR(32) NULL,
  metadata JSON NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_samples_team_code (team_id, code),
  CONSTRAINT fk_smartlab_samples_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_samples_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_samples_source_item FOREIGN KEY (source_item_id) REFERENCES items(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_samples_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_sample_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sample_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  experiment_id INT UNSIGNED NULL,
  actor_userid INT UNSIGNED NULL,
  quantity_delta DECIMAL(12,4) NULL,
  note TEXT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smartlab_sample_events_sample_time (sample_id, occurred_at),
  CONSTRAINT fk_smartlab_sample_events_sample FOREIGN KEY (sample_id) REFERENCES smartlab_samples(id) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_sample_events_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_sample_events_actor FOREIGN KEY (actor_userid) REFERENCES users(userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_review_flows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'submitted',
  requester_userid INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smartlab_review_flows_entity (entity_type, entity_id),
  CONSTRAINT fk_smartlab_review_flows_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_review_flows_requester FOREIGN KEY (requester_userid) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_review_tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  flow_id INT UNSIGNED NOT NULL,
  assignee_userid INT UNSIGNED NOT NULL,
  decision VARCHAR(32) NULL,
  comment TEXT NULL,
  decided_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smartlab_review_tasks_flow (flow_id),
  CONSTRAINT fk_smartlab_review_tasks_flow FOREIGN KEY (flow_id) REFERENCES smartlab_review_flows(id) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_review_tasks_assignee FOREIGN KEY (assignee_userid) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_materials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  source_item_id INT UNSIGNED NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(128) NULL,
  unit VARCHAR(32) NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_materials_team_code (team_id, code),
  UNIQUE KEY uq_smartlab_materials_team_source_item (team_id, source_item_id),
  CONSTRAINT fk_smartlab_materials_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_materials_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_materials_source_item FOREIGN KEY (source_item_id) REFERENCES items(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_materials_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_material_lots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  material_id INT UNSIGNED NOT NULL,
  lot_no VARCHAR(128) NOT NULL,
  expires_at DATE NULL,
  quantity DECIMAL(12,4) NULL,
  location_label VARCHAR(255) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_material_lots (material_id, lot_no),
  KEY idx_smartlab_material_lots_expires_at (expires_at),
  CONSTRAINT fk_smartlab_material_lots_material FOREIGN KEY (material_id) REFERENCES smartlab_materials(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_inventory_transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  lot_id INT UNSIGNED NOT NULL,
  experiment_id INT UNSIGNED NULL,
  actor_userid INT UNSIGNED NULL,
  txn_type VARCHAR(32) NOT NULL,
  quantity DECIMAL(12,4) NOT NULL,
  note TEXT NULL,
  occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smartlab_inventory_transactions_lot_time (lot_id, occurred_at),
  CONSTRAINT fk_smartlab_inventory_transactions_lot FOREIGN KEY (lot_id) REFERENCES smartlab_material_lots(id) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_inventory_transactions_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE SET NULL,
  CONSTRAINT fk_smartlab_inventory_transactions_actor FOREIGN KEY (actor_userid) REFERENCES users(userid) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_primers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  target_gene VARCHAR(255) NOT NULL,
  reference_gene VARCHAR(255) NULL,
  amplicon_bp VARCHAR(32) NULL,
  annealing_temperature VARCHAR(32) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_primers_team_code (team_id, code),
  CONSTRAINT fk_smartlab_primers_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_primers_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_antibodies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  application_scope VARCHAR(64) NOT NULL,
  host_species VARCHAR(128) NULL,
  dilution_hint VARCHAR(64) NULL,
  target_name VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_antibodies_team_code (team_id, code),
  KEY idx_smartlab_antibodies_scope (team_id, application_scope),
  CONSTRAINT fk_smartlab_antibodies_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_antibodies_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_reagents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  team_id INT UNSIGNED NOT NULL,
  code VARCHAR(64) NOT NULL,
  name VARCHAR(255) NOT NULL,
  reagent_scope VARCHAR(64) NOT NULL,
  vendor VARCHAR(255) NULL,
  model_hint VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  note TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_reagents_team_code (team_id, code),
  KEY idx_smartlab_reagents_scope (team_id, reagent_scope),
  CONSTRAINT fk_smartlab_reagents_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_reagents_created_by FOREIGN KEY (created_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_assay_packs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  experiment_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  pack_key VARCHAR(64) NOT NULL,
  pack_mode VARCHAR(32) NOT NULL DEFAULT 'quick',
  payload JSON NOT NULL,
  qc_status VARCHAR(32) NOT NULL DEFAULT 'draft',
  qc_summary JSON NULL,
  auto_summary TEXT NULL,
  created_by INT UNSIGNED NOT NULL,
  updated_by INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_assay_packs_experiment (experiment_id),
  KEY idx_smartlab_assay_packs_team_pack (team_id, pack_key),
  CONSTRAINT fk_smartlab_assay_packs_experiment FOREIGN KEY (experiment_id) REFERENCES experiments(id) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_assay_packs_team FOREIGN KEY (team_id) REFERENCES teams(id),
  CONSTRAINT fk_smartlab_assay_packs_created_by FOREIGN KEY (created_by) REFERENCES users(userid),
  CONSTRAINT fk_smartlab_assay_packs_updated_by FOREIGN KEY (updated_by) REFERENCES users(userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS smartlab_assay_pack_results (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  assay_pack_id INT UNSIGNED NOT NULL,
  row_index INT UNSIGNED NOT NULL,
  sample_label VARCHAR(255) NOT NULL,
  group_label VARCHAR(255) NOT NULL DEFAULT '',
  target_gene VARCHAR(255) NOT NULL,
  ct_value DECIMAL(10,4) NULL,
  reference_ct DECIMAL(10,4) NULL,
  delta_ct DECIMAL(10,4) NULL,
  delta_delta_ct DECIMAL(10,4) NULL,
  fold_change DECIMAL(14,6) NULL,
  replicate_count TINYINT UNSIGNED NULL,
  is_outlier TINYINT UNSIGNED NOT NULL DEFAULT 0,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_smartlab_assay_pack_results_pack_row (assay_pack_id, row_index),
  CONSTRAINT fk_smartlab_assay_pack_results_pack FOREIGN KEY (assay_pack_id) REFERENCES smartlab_assay_packs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_0900_ai_ci;
