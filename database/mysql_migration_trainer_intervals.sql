-- Migrare: intervale orare antrenori
-- Ruleaza in phpMyAdmin pe baza kim_db

CREATE TABLE IF NOT EXISTS `trainer_intervals` (
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
    CONSTRAINT `fk_trainer_intervals_trainer` FOREIGN KEY (`trainer_id`) REFERENCES `trainers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
