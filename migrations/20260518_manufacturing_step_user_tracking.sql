-- Track who started, completed, and last updated each manufacturing step.
ALTER TABLE manufacturing_order_steps
  ADD COLUMN started_by INT DEFAULT NULL AFTER completed_at,
  ADD COLUMN completed_by INT DEFAULT NULL AFTER started_by,
  ADD COLUMN last_updated_by INT DEFAULT NULL AFTER completed_by,
  ADD KEY idx_manufacturing_steps_started_by (started_by),
  ADD KEY idx_manufacturing_steps_completed_by (completed_by),
  ADD KEY idx_manufacturing_steps_last_updated_by (last_updated_by),
  ADD CONSTRAINT fk_manufacturing_steps_started_by FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_manufacturing_steps_completed_by FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_manufacturing_steps_last_updated_by FOREIGN KEY (last_updated_by) REFERENCES users(id) ON DELETE SET NULL;
