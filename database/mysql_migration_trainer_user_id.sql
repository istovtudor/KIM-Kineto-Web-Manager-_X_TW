-- Migrare: trainer_id = users.id (nu trainers.id)
-- RECOMANDAT: ruleaza scriptul PHP (detecteaza automat numele FK):
--   php database/migrate_trainer_user_id.php
-- sau in browser: http://localhost/ProiectWEB/database/migrate_trainer_user_id.php
--
-- Daca rulezi manual in phpMyAdmin, PASUL 0: afla numele FK real:
-- SELECT CONSTRAINT_NAME, REFERENCED_TABLE_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = 'kim_db'
--   AND TABLE_NAME = 'sessions'
--   AND COLUMN_NAME = 'trainer_id'
--   AND REFERENCED_TABLE_NAME IS NOT NULL;
-- (inlocuieste 'kim_db' cu numele bazei tale)
-- Apoi inlocuieste fk_sessions_trainer cu numele gasit (ex: sessions_ibfk_1)

SET FOREIGN_KEY_CHECKS = 0;

UPDATE `sessions` s
INNER JOIN `trainers` t ON t.id = s.trainer_id
SET s.trainer_id = t.user_id
WHERE t.user_id IS NOT NULL;

UPDATE `trainer_intervals` ti
INNER JOIN `trainers` t ON t.id = ti.trainer_id
SET ti.trainer_id = t.user_id
WHERE t.user_id IS NOT NULL;

-- Daca primesti eroare #1091 (FK inexistent), FK-ul are alt nume sau migrarea e deja facuta.
-- Verifica cu SELECT de mai sus sau foloseste migrate_trainer_user_id.php

-- ALTER TABLE `sessions` DROP FOREIGN KEY `fk_sessions_trainer`;
-- ALTER TABLE `sessions` DROP FOREIGN KEY `sessions_ibfk_1`;

ALTER TABLE `sessions`
    ADD CONSTRAINT `fk_sessions_trainer_user`
        FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`);

-- ALTER TABLE `trainer_intervals` DROP FOREIGN KEY `fk_trainer_intervals_trainer`;

ALTER TABLE `trainer_intervals`
    ADD CONSTRAINT `fk_trainer_intervals_user`
        FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;
