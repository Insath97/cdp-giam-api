# GIAM Production Implementation Notes

## Tech-lead requirements implemented

- One GIAM login for Admin/HR/Staff.
- `user_type` is `admin` or `staff`.
- GIAM-internal RBAC uses global roles (`application_id IS NULL`).
- Staff users have no GIAM admin sidebar and see only assigned projects.
- HR is a role in the same GIAM application, not a separate portal.
- User creation captures employee/basic information and project access in one wizard flow.
- Multiple projects can be assigned to one user.
- Project modules are optional; only projects that have modules expose module selection.
- Project roles, permission groups and permissions are scoped to the selected project ID.
- Identical names across projects remain independent records.
- Project RBAC is read-only from GIAM and is populated by project catalog synchronization.
- GIAM-internal roles/permission groups/permissions remain manageable by authorized GIAM administrators.
- Project user synchronization is asynchronous, per user/per project, with retry/backoff.
- Project payloads are filtered through per-user/per-project field-access rules.
- SSO uses a short-lived one-time handoff token; the GIAM HttpOnly session remains alive.
- Axios uses `withCredentials` and automatic session refresh; frontend JavaScript never reads the JWT cookie.
- Activity logs record actor, action, module, URL, method, IP, user agent and sanitized payload.
- Access reports include project, role, user name/email, permission groups and effective permissions and support PDF/CSV export.

## Database additions

- `role_permission_group`
- `model_has_permission_groups`
- `application_user_module`
- unique user/project field-access rule
- project-scoped RBAC indexes

## Required deployment commands

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:work --tries=5
```

For the frontend:

```bash
npm ci
npm run build
npm run start
```

Set `NEXT_PUBLIC_API_URL` to the HTTPS GIAM API URL.

## Security

Never commit `.env`, `.env.local`, JWT secrets, project API keys, database passwords, or generated build/dependency directories.
Use HTTPS in production and set `SESSION_SECURE_COOKIE=true`.
