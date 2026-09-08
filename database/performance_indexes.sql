-- Performance Optimization Indexes for NIS Posting Management System
-- Safe to execute on live databases (using CREATE INDEX IF NOT EXISTS)

-- 1. Personnel Indexes (supports both tbl_emppersonal and personnel table naming)
SET @exist_tbl := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tbl_emppersonal');
SET @exist_p := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'personnel');

-- Index on tbl_emppersonal if present
ALTER TABLE tbl_emppersonal ADD INDEX idx_emp_service_number (serviceNumber), ADD INDEX idx_emp_rank (currentRank), ADD INDEX idx_emp_state (state_of_origin), ADD INDEX idx_emp_gender (gender);
-- Ignore error if table tbl_emppersonal does not exist or index exists

-- Index on personnel if present
ALTER TABLE personnel ADD INDEX idx_p_service_number (service_number), ADD INDEX idx_p_surname (surname), ADD INDEX idx_p_rank (rank), ADD INDEX idx_p_gender (gender);

-- 2. Postings Table Indexes
ALTER TABLE postings 
    ADD INDEX idx_postings_personnel_id (personnel_id),
    ADD INDEX idx_postings_service_number (service_number),
    ADD INDEX idx_postings_formation_id (formation_id),
    ADD INDEX idx_postings_status (posting_status),
    ADD INDEX idx_postings_date (date_of_posting);

-- 3. Formations Table Indexes
ALTER TABLE formations 
    ADD INDEX idx_formations_zone (zone),
    ADD INDEX idx_formations_type (formation_type);

-- 4. User Zones Access Control Index
ALTER TABLE user_zones 
    ADD INDEX idx_user_zones_composite (user_id, zone_id);

-- 5. Notifications Index
ALTER TABLE posting_notifications 
    ADD INDEX idx_notifications_read_created (is_read, created_at);

-- 6. Activity Logs Index
ALTER TABLE activity_logs 
    ADD INDEX idx_logs_user_date (user_id, created_at);
