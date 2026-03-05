-- Create service user for public WEB registrations.
-- IMPORTANT:
-- - created_by / updated_by must reference an existing active app_user.
-- - In this environment, admin user id = 2.
-- - This script is idempotent (won't duplicate if username exists).

SET @ADMIN_ID := 2;

INSERT INTO app_user
  (username, password_hash, full_name, email, last_login_at,
   active_from, inactive_at,
   created_at, updated_at,
   created_by, updated_by)
SELECT
  'svc_web_registration',
  'DISABLED',
  'Servicio - Registro Web',
  'no-reply@clubpuertoazul.com',
  NULL,
  NOW(),
  NULL,
  NOW(),
  NOW(),
  @ADMIN_ID,
  @ADMIN_ID
WHERE NOT EXISTS (
  SELECT 1 FROM app_user WHERE username = 'svc_web_registration'
);

SELECT id, username
FROM app_user
WHERE username = 'svc_web_registration';
