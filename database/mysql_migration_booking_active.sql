-- Migrare: status rezervari active + sedinte separate fitness/kineto
-- Ruleaza in phpMyAdmin pe baza kim_db

-- 1. Status rezervare: active | cancelled (inlocuieste confirmed)
UPDATE `session_bookings` SET `status` = 'active' WHERE `status` = 'confirmed';

ALTER TABLE `session_bookings`
    MODIFY COLUMN `status` ENUM('active', 'cancelled') NOT NULL DEFAULT 'active';

-- 2. Sedinte ramase separate per categorie
ALTER TABLE `user_subscriptions`
    ADD COLUMN `sessions_remaining_fitness` INT UNSIGNED NOT NULL DEFAULT 0
        AFTER `sessions_remaining`,
    ADD COLUMN `sessions_remaining_kineto` INT UNSIGNED NOT NULL DEFAULT 0
        AFTER `sessions_remaining_fitness`;

-- Basic / Premium: fitness+forta
UPDATE `user_subscriptions` us
JOIN `subscription_types` st ON st.id = us.subscription_type_id
SET us.sessions_remaining_fitness = us.sessions_remaining
WHERE us.status = 'active'
  AND (LOWER(st.name) LIKE '%basic%' OR LOWER(st.name) LIKE '%premium%')
  AND us.sessions_remaining_fitness = 0;

-- Kineto / Premium: kineto
UPDATE `user_subscriptions` us
JOIN `subscription_types` st ON st.id = us.subscription_type_id
SET us.sessions_remaining_kineto = us.sessions_remaining
WHERE us.status = 'active'
  AND (LOWER(st.name) LIKE '%kineto%' OR LOWER(st.name) LIKE '%premium%')
  AND us.sessions_remaining_kineto = 0;
