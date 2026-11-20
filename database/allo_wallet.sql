-- Import this file in phpMyAdmin. It does not delete existing wallet records.
CREATE DATABASE IF NOT EXISTS allo_wallet
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE allo_wallet;

-- One row stores the settings for this simple, shared wallet.
CREATE TABLE IF NOT EXISTS wallet_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    goal_name VARCHAR(50) NOT NULL DEFAULT 'Emergency fund',
    goal_target INT UNSIGNED NOT NULL DEFAULT 500000,
    legacy_imported TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- Keep each savings target separate while retaining the original settings.
CREATE TABLE IF NOT EXISTS goals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    target INT UNSIGNED NOT NULL,
    created_at DATETIME(3) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

-- Store when the next allowance is expected, if the owner sets a date.
ALTER TABLE wallet_settings
    ADD COLUMN IF NOT EXISTS next_allowance_date DATE NULL AFTER legacy_imported;

-- This local app has one owner. Passwords contain a hash, never plain text.
CREATE TABLE IF NOT EXISTS wallet_owner (
    id TINYINT UNSIGNED PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

-- Older owner accounts keep their data; recovery can be set up from localhost during a password reset.
ALTER TABLE wallet_owner
    ADD COLUMN IF NOT EXISTS security_question VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS security_answer_hash VARCHAR(255) NULL;

-- Failed sign-in and recovery attempts share a short IP-based cooldown.
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX idx_login_attempts_ip_time (ip, attempted_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO wallet_settings (id) VALUES (1);

-- Store money as whole centavos: 8500 means PHP 85.00.
CREATE TABLE IF NOT EXISTS transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('income', 'expense', 'saving') NOT NULL,
    amount INT UNSIGNED NOT NULL,
    description VARCHAR(60) NOT NULL,
    category VARCHAR(30) NOT NULL,
    created_at DATETIME(3) NOT NULL
) ENGINE=InnoDB;

-- Savings belong to a goal; withdrawals return goal money to the wallet.
ALTER TABLE transactions
    ADD COLUMN IF NOT EXISTS goal_id INT UNSIGNED NULL AFTER category,
    MODIFY COLUMN type ENUM('income', 'expense', 'saving', 'withdrawal') NOT NULL;

-- Each expense category can have one optional monthly limit.
CREATE TABLE IF NOT EXISTS category_budgets (
    category VARCHAR(30) PRIMARY KEY,
    monthly_limit INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

-- Public portal accounts have student or employee access; the original owner remains the administrator.
CREATE TABLE IF NOT EXISTS portal_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('student', 'employee') NOT NULL,
    status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
    auth_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_portal_accounts_email (email),
    INDEX idx_portal_accounts_role_status (role, status)
) ENGINE=InnoDB;

ALTER TABLE portal_accounts
    ADD COLUMN IF NOT EXISTS auth_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;

-- Allowance and expense records are owned by one student and retain who created them.
CREATE TABLE IF NOT EXISTS allowance_entries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    entry_type ENUM('allowance', 'expense') NOT NULL,
    amount INT UNSIGNED NOT NULL,
    description VARCHAR(100) NOT NULL,
    created_by_role ENUM('admin', 'employee', 'student') NOT NULL,
    created_by_account_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_allowance_entries_student
        FOREIGN KEY (student_id) REFERENCES portal_accounts(id),
    INDEX idx_allowance_entries_student_date (student_id, created_at),
    INDEX idx_allowance_entries_creator (created_by_role, created_by_account_id)
) ENGINE=InnoDB;
