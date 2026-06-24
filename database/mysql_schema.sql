-- =============================================================================
-- KIM - Kineto Web Manager
-- Schema MySQL / MariaDB (compatibil phpMyAdmin)
-- Convertit din schema SQLite originala
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Optional: creati baza in phpMyAdmin sau decomentati liniile de mai jos:
-- CREATE DATABASE IF NOT EXISTS `kim_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE `kim_db`;

DROP TABLE IF EXISTS `user_subscription_types`;
DROP TABLE IF EXISTS `email_logs`;
DROP TABLE IF EXISTS `trainer_intervals`;
DROP TABLE IF EXISTS `user_subscriptions`;
DROP TABLE IF EXISTS `subscription_types`;
DROP TABLE IF EXISTS `session_bookings`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `equipment`;
DROP TABLE IF EXISTS `trainers`;
DROP TABLE IF EXISTS `rooms`;
DROP TABLE IF EXISTS `user_activity`;
DROP TABLE IF EXISTS `user_profiles`;
DROP TABLE IF EXISTS `users`;

-- -----------------------------------------------------------------------------
-- users
-- -----------------------------------------------------------------------------
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(255) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('member', 'trainer', 'admin') NOT NULL DEFAULT 'member',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- user_profiles
-- -----------------------------------------------------------------------------
CREATE TABLE `user_profiles` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `bio` TEXT DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_profiles_user_id` (`user_id`),
    CONSTRAINT `fk_user_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- user_activity
-- -----------------------------------------------------------------------------
CREATE TABLE `user_activity` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_activity_user_id` (`user_id`),
    CONSTRAINT `fk_user_activity_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- rooms
-- -----------------------------------------------------------------------------
CREATE TABLE `rooms` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `capacity` INT UNSIGNED NOT NULL DEFAULT 10,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- trainers
-- -----------------------------------------------------------------------------
CREATE TABLE `trainers` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `full_name` VARCHAR(255) NOT NULL,
    `specialty` VARCHAR(255) DEFAULT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_trainers_user_id` (`user_id`),
    CONSTRAINT `fk_trainers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- equipment
-- -----------------------------------------------------------------------------
CREATE TABLE `equipment` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `room_id` INT UNSIGNED DEFAULT NULL,
    `status` ENUM('available', 'maintenance', 'retired') NOT NULL DEFAULT 'available',
    PRIMARY KEY (`id`),
    KEY `idx_equipment_room_id` (`room_id`),
    CONSTRAINT `fk_equipment_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- sessions
-- -----------------------------------------------------------------------------
CREATE TABLE `sessions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `type` ENUM('fitness', 'forta', 'kineto') NOT NULL,
    `trainer_id` INT UNSIGNED NOT NULL,
    `room_id` INT UNSIGNED NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `max_participants` INT UNSIGNED NOT NULL DEFAULT 10,
    `status` ENUM('scheduled', 'cancelled', 'completed') NOT NULL DEFAULT 'scheduled',
    `created_by` INT UNSIGNED DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_sessions_trainer_id` (`trainer_id`),
    KEY `idx_sessions_room_id` (`room_id`),
    KEY `idx_sessions_start_time` (`start_time`),
    CONSTRAINT `fk_sessions_trainer_user` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_sessions_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`),
    CONSTRAINT `fk_sessions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- session_bookings
-- -----------------------------------------------------------------------------
CREATE TABLE `session_bookings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `booked_by_name` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    `booked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_session_bookings_session_user` (`session_id`, `user_id`),
    KEY `idx_session_bookings_user_id` (`user_id`),
    CONSTRAINT `fk_session_bookings_session` FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_session_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- subscription_types
-- -----------------------------------------------------------------------------
CREATE TABLE `subscription_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `price` DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
    `sessions_included` INT UNSIGNED NOT NULL DEFAULT 4,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- user_subscriptions
-- -----------------------------------------------------------------------------
CREATE TABLE `user_subscriptions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `subscription_type_id` INT UNSIGNED NOT NULL,
    `status` ENUM('active', 'suspended', 'expired') NOT NULL DEFAULT 'active',
    `sessions_remaining` INT UNSIGNED NOT NULL DEFAULT 0,
    `sessions_remaining_fitness` INT UNSIGNED NOT NULL DEFAULT 0,
    `sessions_remaining_kineto` INT UNSIGNED NOT NULL DEFAULT 0,
    `start_date` DATE NOT NULL,
    `end_date` DATE NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_user_subscriptions_user_id` (`user_id`),
    KEY `idx_user_subscriptions_type_id` (`subscription_type_id`),
    CONSTRAINT `fk_user_subscriptions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_user_subscriptions_type` FOREIGN KEY (`subscription_type_id`) REFERENCES `subscription_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- user_subscription_types (tipuri incluse per abonament activ)
-- -----------------------------------------------------------------------------
CREATE TABLE `user_subscription_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_subscription_id` INT UNSIGNED NOT NULL,
    `type` ENUM('fitness', 'forta', 'kineto') NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ust_subscription` (`user_subscription_id`),
    KEY `idx_ust_type` (`type`),
    CONSTRAINT `fk_ust_subscription` FOREIGN KEY (`user_subscription_id`)
        REFERENCES `user_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- email_logs
-- -----------------------------------------------------------------------------
CREATE TABLE `email_logs` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body` TEXT NOT NULL,
    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_email_logs_user_id` (`user_id`),
    CONSTRAINT `fk_email_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -----------------------------------------------------------------------------
-- trainer_intervals
-- -----------------------------------------------------------------------------
CREATE TABLE `trainer_intervals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `trainer_id` INT UNSIGNED NOT NULL,
    `day_of_week` TINYINT UNSIGNED NOT NULL COMMENT '0=Duminica, 1=Luni, ... 6=Sambata',
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `type` ENUM('fitness', 'forta', 'kineto') NOT NULL,
    `capacity` INT UNSIGNED NOT NULL DEFAULT 10,
    PRIMARY KEY (`id`),
    KEY `idx_trainer_intervals_trainer` (`trainer_id`),
    KEY `idx_trainer_intervals_day` (`day_of_week`),
    CONSTRAINT `fk_trainer_intervals_user` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
