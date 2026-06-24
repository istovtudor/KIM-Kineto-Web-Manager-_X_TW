

ALTER TABLE `subscription_types`
    ADD COLUMN `sessions_included` INT UNSIGNED NOT NULL DEFAULT 4
    AFTER `duration_days`;

ALTER TABLE `user_subscriptions`
    ADD COLUMN `sessions_remaining` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `status`;

UPDATE `subscription_types` SET `sessions_included` = 8  WHERE `name` = 'Basic';
UPDATE `subscription_types` SET `sessions_included` = 30 WHERE `name` = 'Premium';
UPDATE `subscription_types` SET `sessions_included` = 10 WHERE `name` = 'Kineto Pack';

UPDATE `user_subscriptions` us
JOIN `subscription_types` st ON st.id = us.subscription_type_id
SET us.sessions_remaining = st.sessions_included
WHERE us.status = 'active' AND us.sessions_remaining = 0;
