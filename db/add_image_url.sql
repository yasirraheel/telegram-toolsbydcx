-- Issue 52: product and category images.
-- Run this once in phpMyAdmin (SSH is down) so the live DB gets the new
-- image_url columns. Safe to run twice: each statement adds the column only
-- if it is missing (MySQL 8 / MariaDB 10.5+ support ADD COLUMN IF NOT EXISTS;
-- on older servers run the two ALTERs without the IF NOT EXISTS clause).
ALTER TABLE categories ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NOT NULL DEFAULT '' AFTER emoji;
ALTER TABLE products ADD COLUMN IF NOT EXISTS image_url VARCHAR(500) NOT NULL DEFAULT '' AFTER description;
