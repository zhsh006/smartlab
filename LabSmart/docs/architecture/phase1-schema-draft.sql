-- Phase 1 SmartLab extension tables
-- These tables are designed to sit on top of eLabFTW core tables.

CREATE TABLE smartlab_projects (
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
);

CREATE TABLE smartlab_project_members (
  project_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role VARCHAR(64) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (project_id, user_id),
  CONSTRAINT fk_smartlab_project_members_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_smartlab_project_members_user FOREIGN KEY (user_id) REFERENCES users(userid) ON DELETE CASCADE
);

CREATE TABLE smartlab_project_entities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  entity_type VARCHAR(32) NOT NULL,
  entity_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_smartlab_project_entities (project_id, entity_type, entity_id),
  CONSTRAINT fk_smartlab_project_entities_project FOREIGN KEY (project_id) REFERENCES smartlab_projects(id) ON DELETE CASCADE
);

CREATE TABLE smartlab_samples (
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
);

CREATE TABLE smartlab_sample_events (
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
);

CREATE TABLE smartlab_review_flows (
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
);

CREATE TABLE smartlab_review_tasks (
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
);
