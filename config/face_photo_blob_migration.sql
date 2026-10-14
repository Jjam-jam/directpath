USE directpath_db;

-- Store reference photos in MySQL so they travel with a database backup.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS reference_photo_data LONGBLOB NULL,
  ADD COLUMN IF NOT EXISTS reference_photo_mime VARCHAR(50) NULL;
