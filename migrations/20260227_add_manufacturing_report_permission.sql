-- Add permission for viewing full manufacturing report

INSERT INTO permissions (`key`, `name`, `module`, `description`) VALUES
('manufacturing.view_report', 'View Full Manufacturing Report', 'manufacturing', 'Permission to view the full PDF report for a completed manufacturing order');
