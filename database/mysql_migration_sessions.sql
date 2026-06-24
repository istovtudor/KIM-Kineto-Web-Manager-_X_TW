-- Migrare KIM: abonamente cu sedinte limitate
-- Ruleaza in phpMyAdmin pe baza kim_db (tab SQL)
-- Nu sterge date existente — doar adauga coloane

ALTER TABLE `subscription_types`
    ADD COLUMN IF NOT EXISTS `sessions_included` INT UNSIGNED NOT NULL DEFAULT 4
    AFTER `duration_days`;

ALTER TABLE `user_subscriptions`
    ADD COLUMN IF NOT EXISTS `sessions_remaining` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `status`;

-- Actualizeaza tipurile existente (daca exista deja)
UPDATE `subscription_types` SET `sessions_included` = 8  WHERE `name` = 'Basic';
UPDATE `subscription_types` SET `sessions_included` = 30 WHERE `name` = 'Premium';
UPDATE `subscription_types` SET `sessions_included` = 10 WHERE `name` = 'Kineto Pack';

-- Sincronizeaza sedintele ramase pentru abonamente active existente
UPDATE `user_subscriptions` us
JOIN `subscription_types` st ON st.id = us.subscription_type_id
SET us.sessions_remaining = st.sessions_included
WHERE us.status = 'active' AND us.sessions_remaining = 0;
