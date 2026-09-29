-- Contact Manager API schema migration.
-- This script preserves all existing Users and Contacts records.

CREATE DATABASE IF NOT EXISTS `ContactsAppDB`
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE `ContactsAppDB`;

DELIMITER //

DROP PROCEDURE IF EXISTS AddColumnIfMissing//
CREATE PROCEDURE AddColumnIfMissing(
    IN table_name_value VARCHAR(64),
    IN column_name_value VARCHAR(64),
    IN column_definition_value VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND COLUMN_NAME = column_name_value
    ) THEN
        SET @statement = CONCAT(
            'ALTER TABLE `', table_name_value, '` ADD COLUMN `',
            column_name_value, '` ', column_definition_value
        );
        PREPARE migration_statement FROM @statement;
        EXECUTE migration_statement;
        DEALLOCATE PREPARE migration_statement;
    END IF;
END//

DROP PROCEDURE IF EXISTS AddIndexIfMissing//
CREATE PROCEDURE AddIndexIfMissing(
    IN table_name_value VARCHAR(64),
    IN index_name_value VARCHAR(64),
    IN index_columns_value VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = table_name_value
          AND INDEX_NAME = index_name_value
    ) THEN
        SET @statement = CONCAT(
            'ALTER TABLE `', table_name_value, '` ADD INDEX `',
            index_name_value, '` (', index_columns_value, ')'
        );
        PREPARE migration_statement FROM @statement;
        EXECUTE migration_statement;
        DEALLOCATE PREPARE migration_statement;
    END IF;
END//

DELIMITER ;

CALL AddColumnIfMissing('Users', 'IsAdmin', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `Password`');
CALL AddColumnIfMissing('Users', 'IsActive', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `IsAdmin`');

CALL AddIndexIfMissing('Contacts', 'idx_contacts_user_name', '`UserID`, `LastName`, `FirstName`');
CALL AddIndexIfMissing('Contacts', 'idx_contacts_email', '`Email`');
CALL AddIndexIfMissing('Users', 'idx_users_name', '`LastName`, `FirstName`');

DROP PROCEDURE IF EXISTS AddColumnIfMissing;
DROP PROCEDURE IF EXISTS AddIndexIfMissing;

-- Ensure an existing installation has an initial administrator.
-- Change this assignment after migration if a different account should be used.
UPDATE Users
SET IsAdmin = 1
WHERE ID = (SELECT first_id FROM (SELECT MIN(ID) AS first_id FROM Users) AS existing_users)
  AND NOT EXISTS (
      SELECT 1
      FROM (SELECT IsAdmin FROM Users WHERE IsAdmin = 1 LIMIT 1) AS current_admin
  );

-- Existing legacy plaintext passwords are upgraded to password_hash() values
-- automatically on their next successful authenticated API request. All newly
-- registered and administratively changed passwords are hashed immediately.
