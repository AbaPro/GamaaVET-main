-- Snapshot the actual packaging reconciliation when its workflow step completes.
-- Existing completed orders and historical stock are deliberately not backfilled.
CREATE TABLE IF NOT EXISTS manufacturing_packaging_material_tracking (
    sourcing_component_id int NOT NULL PRIMARY KEY,
    planned_quantity decimal(14,4) NOT NULL,
    issued_quantity decimal(14,4) NOT NULL,
    used_quantity decimal(14,4) NOT NULL,
    returned_quantity decimal(14,4) NOT NULL,
    waste_quantity decimal(14,4) NOT NULL,
    issued_notes text NULL,
    used_notes text NULL,
    returned_notes text NULL,
    waste_notes text NULL,
    posted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sourcing_component_id) REFERENCES manufacturing_sourcing_components(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
