SET NAMES utf8mb4;
SET time_zone = '+09:00';

CREATE TABLE employees (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_code VARCHAR(30) NOT NULL,
    name VARCHAR(100) NOT NULL,
    kana VARCHAR(100) NULL,
    department_name VARCHAR(100) NULL,
    employment_type VARCHAR(30) NOT NULL,
    status VARCHAR(20) NOT NULL,
    joined_on DATE NOT NULL,
    retired_on DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_employees_employee_code (employee_code),
    KEY idx_employees_department_name (department_name),
    KEY idx_employees_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_auth (
    employee_id BIGINT UNSIGNED NOT NULL,
    login_id VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    password_updated_at DATETIME NULL,
    last_login_at DATETIME NULL,
    mobile_push_token VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (employee_id),
    UNIQUE KEY uq_employee_auth_login_id (login_id),
    CONSTRAINT fk_employee_auth_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    card_uid VARCHAR(64) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_employee_cards_employee_id (employee_id),
    KEY idx_employee_cards_card_uid (card_uid),
    KEY idx_employee_cards_is_active (is_active),
    CONSTRAINT fk_employee_cards_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    device_code VARCHAR(50) NOT NULL,
    name VARCHAR(100) NOT NULL,
    location_name VARCHAR(100) NULL,
    os_user VARCHAR(100) NULL,
    app_version VARCHAR(30) NULL,
    device_secret_hash VARCHAR(255) NULL,
    last_seen_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_devices_device_code (device_code),
    KEY idx_attendance_devices_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NULL,
    device_id BIGINT UNSIGNED NOT NULL,
    card_uid VARCHAR(64) NOT NULL,
    occurred_at DATETIME NOT NULL,
    event_type VARCHAR(20) NULL,
    source_type VARCHAR(20) NOT NULL,
    receive_status VARCHAR(20) NOT NULL,
    rejection_reason VARCHAR(100) NULL,
    offline_saved TINYINT(1) NOT NULL DEFAULT 0,
    dedupe_key VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_events_dedupe_key (dedupe_key),
    KEY idx_attendance_events_employee_occurred_at (employee_id, occurred_at),
    KEY idx_attendance_events_device_occurred_at (device_id, occurred_at),
    KEY idx_attendance_events_receive_status (receive_status),
    KEY idx_attendance_events_card_uid (card_uid),
    CONSTRAINT fk_attendance_events_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_attendance_events_device_id
        FOREIGN KEY (device_id) REFERENCES attendance_devices (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_daily (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    target_date DATE NOT NULL,
    clock_in_at DATETIME NULL,
    clock_out_at DATETIME NULL,
    work_minutes INT NULL,
    late_flag TINYINT(1) NOT NULL DEFAULT 0,
    early_leave_flag TINYINT(1) NOT NULL DEFAULT 0,
    absence_flag TINYINT(1) NOT NULL DEFAULT 0,
    special_leave_flag TINYINT(1) NOT NULL DEFAULT 0,
    paid_leave_unit DECIMAL(4,2) NULL,
    remark VARCHAR(255) NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_daily_employee_date (employee_id, target_date),
    KEY idx_attendance_daily_target_date (target_date),
    CONSTRAINT fk_attendance_daily_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_types (
    code VARCHAR(20) NOT NULL,
    name VARCHAR(50) NOT NULL,
    requires_balance TINYINT(1) NOT NULL,
    allows_half_day TINYINT(1) NOT NULL,
    sort_order INT NOT NULL,
    PRIMARY KEY (code),
    UNIQUE KEY uq_leave_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    leave_type_code VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    day_unit VARCHAR(10) NOT NULL,
    half_day_type VARCHAR(10) NULL,
    quantity_days DECIMAL(4,2) NOT NULL,
    reason TEXT NULL,
    status VARCHAR(20) NOT NULL,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    decision_comment TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_leave_requests_employee_created_at (employee_id, created_at),
    KEY idx_leave_requests_status_start_date (status, start_date),
    KEY idx_leave_requests_leave_type_code (leave_type_code),
    CONSTRAINT fk_leave_requests_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_leave_requests_leave_type_code
        FOREIGN KEY (leave_type_code) REFERENCES leave_types (code),
    CONSTRAINT fk_leave_requests_approved_by
        FOREIGN KEY (approved_by) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_request_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    leave_request_id BIGINT UNSIGNED NOT NULL,
    action_type VARCHAR(20) NOT NULL,
    action_by BIGINT UNSIGNED NOT NULL,
    comment TEXT NULL,
    acted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_leave_request_actions_request_acted_at (leave_request_id, acted_at),
    CONSTRAINT fk_leave_request_actions_request_id
        FOREIGN KEY (leave_request_id) REFERENCES leave_requests (id),
    CONSTRAINT fk_leave_request_actions_action_by
        FOREIGN KEY (action_by) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE paid_leave_grants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    granted_on DATE NOT NULL,
    granted_days DECIMAL(4,2) NOT NULL,
    used_days DECIMAL(4,2) NOT NULL DEFAULT 0,
    expires_on DATE NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_paid_leave_grants_employee_granted_on (employee_id, granted_on),
    KEY idx_paid_leave_grants_expires_on (expires_on),
    CONSTRAINT fk_paid_leave_grants_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_statements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    target_year_month CHAR(7) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    original_file_name VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    content_type VARCHAR(100) NULL,
    published_at DATETIME NULL,
    uploaded_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_statements_employee_month (employee_id, target_year_month),
    KEY idx_payroll_statements_published_at (published_at),
    CONSTRAINT fk_payroll_statements_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_payroll_statements_uploaded_by
        FOREIGN KEY (uploaded_by) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_statement_views (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payroll_statement_id BIGINT UNSIGNED NOT NULL,
    employee_id BIGINT UNSIGNED NOT NULL,
    viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_payroll_statement_views_statement_viewed_at (payroll_statement_id, viewed_at),
    KEY idx_payroll_statement_views_employee_viewed_at (employee_id, viewed_at),
    CONSTRAINT fk_payroll_statement_views_statement_id
        FOREIGN KEY (payroll_statement_id) REFERENCES payroll_statements (id),
    CONSTRAINT fk_payroll_statement_views_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id BIGINT UNSIGNED NOT NULL,
    notification_type VARCHAR(30) NOT NULL,
    title VARCHAR(100) NOT NULL,
    body TEXT NOT NULL,
    related_type VARCHAR(30) NULL,
    related_id BIGINT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_notifications_employee_is_read_sent_at (employee_id, is_read, sent_at),
    CONSTRAINT fk_notifications_employee_id
        FOREIGN KEY (employee_id) REFERENCES employees (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_type VARCHAR(20) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(50) NOT NULL,
    target_id VARCHAR(100) NULL,
    detail_json JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NULL,
    PRIMARY KEY (id),
    KEY idx_audit_logs_occurred_at (occurred_at),
    KEY idx_audit_logs_actor (actor_type, actor_id),
    KEY idx_audit_logs_target (target_type, target_id),
    KEY idx_audit_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO leave_types (code, name, requires_balance, allows_half_day, sort_order)
VALUES
    ('PAID', '有給休暇', 1, 1, 1),
    ('ABSENCE', '欠勤', 0, 0, 2),
    ('SPECIAL', '特別休暇', 0, 1, 3);

INSERT INTO employees (id, employee_code, name, kana, department_name, employment_type, status, joined_on, retired_on)
VALUES
    (1, 'E0001', '山田 太郎', 'ヤマダ タロウ', '総務部', 'FULL_TIME', 'ACTIVE', '2024-04-01', NULL),
    (2, 'E0002', '佐藤 花子', 'サトウ ハナコ', '介護部', 'PART_TIME', 'ACTIVE', '2024-04-01', NULL),
    (100, 'A0001', '管理者', 'カンリシャ', '管理部', 'ADMIN', 'ACTIVE', '2024-04-01', NULL);

INSERT INTO employee_auth (employee_id, login_id, password_hash)
VALUES
    (1, 'staff001', '$2y$10$replace_with_bcrypt_hash'),
    (100, 'admin001', '$2y$10$replace_with_bcrypt_hash');

INSERT INTO attendance_devices (id, device_code, name, location_name, app_version, is_active)
VALUES
    (1, 'PC-ENTRANCE-01', '玄関端末', '玄関', '1.0.0', 1);

INSERT INTO paid_leave_grants (employee_id, granted_on, granted_days, used_days, expires_on, note)
VALUES
    (1, '2025-04-01', 10.00, 1.50, '2027-03-31', '初期付与'),
    (2, '2025-04-01', 10.00, 0.00, '2027-03-31', '初期付与');
