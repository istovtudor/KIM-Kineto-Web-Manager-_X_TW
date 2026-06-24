-- Migrare: abonamente multiple + booked_by_name
-- Ruleaza in phpMyAdmin pe baza kim_db

ALTER TABLE `session_bookings`
    ADD COLUMN `booked_by_name` VARCHAR(100) NULL
    AFTER `user_id`;

CREATE TABLE IF NOT EXISTS `user_subscription_types` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_subscription_id` INT UNSIGNED NOT NULL,
    `type` ENUM('fitness', 'forta', 'kineto') NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ust_subscription` (`user_subscription_id`),
    KEY `idx_ust_type` (`type`),
    CONSTRAINT `fk_ust_subscription` FOREIGN KEY (`user_subscription_id`)
        REFERENCES `user_subscriptions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Populeaza tipurile pentru abonamente active existente (rulare unica)
INSERT INTO `user_subscription_types` (`user_subscription_id`, `type`)
SELECT us.id, 'fitness' FROM user_subscriptions us
JOIN subscription_types st ON st.id = us.subscription_type_id
WHERE us.status = 'active' AND LOWER(st.name) LIKE '%basic%'
AND NOT EXISTS (SELECT 1 FROM user_subscription_types x WHERE x.user_subscription_id = us.id AND x.type = 'fitness');

INSERT INTO `user_subscription_types` (`user_subscription_id`, `type`)
SELECT us.id, 'forta' FROM user_subscriptions us
JOIN subscription_types st ON st.id = us.subscription_type_id
WHERE us.status = 'active' AND (LOWER(st.name) LIKE '%basic%' OR LOWER(st.name) LIKE '%premium%')
AND NOT EXISTS (SELECT 1 FROM user_subscription_types x WHERE x.user_subscription_id = us.id AND x.type = 'forta');

INSERT INTO `user_subscription_types` (`user_subscription_id`, `type`)
SELECT us.id, 'fitness' FROM user_subscriptions us
JOIN subscription_types st ON st.id = us.subscription_type_id
WHERE us.status = 'active' AND LOWER(st.name) LIKE '%premium%'
AND NOT EXISTS (SELECT 1 FROM user_subscription_types x WHERE x.user_subscription_id = us.id AND x.type = 'fitness');

INSERT INTO `user_subscription_types` (`user_subscription_id`, `type`)
SELECT us.id, 'kineto' FROM user_subscriptions us
JOIN subscription_types st ON st.id = us.subscription_type_id
WHERE us.status = 'active' AND LOWER(st.name) LIKE '%kineto%'
AND NOT EXISTS (SELECT 1 FROM user_subscription_types x WHERE x.user_subscription_id = us.id AND x.type = 'kineto');

INSERT INTO `user_subscription_types` (`user_subscription_id`, `type`)
SELECT us.id, 'kineto' FROM user_subscriptions us
JOIN subscription_types st ON st.id = us.subscription_type_id
WHERE us.status = 'active' AND LOWER(st.name) LIKE '%premium%'
AND NOT EXISTS (SELECT 1 FROM user_subscription_types x WHERE x.user_subscription_id = us.id AND x.type = 'kineto');

UPDATE session_bookings b
JOIN user_profiles p ON p.user_id = b.user_id
SET b.booked_by_name = p.full_name
WHERE b.booked_by_name IS NULL OR b.booked_by_name = '';
