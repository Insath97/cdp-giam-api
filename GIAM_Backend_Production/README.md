# GIAM API

Production-oriented Laravel API for Global Identity and Access Management, SSO and project-scoped RBAC.

## Core model
- GIAM has a single authentication surface.
- `user_type` is `admin` or `staff`.
- GIAM-internal roles/permissions are global (`application_id = NULL`).
- Project roles, modules, permission groups and permissions are owned by the respective project and are read-only from GIAM.
- The same role/group/permission name may safely exist in multiple projects because assignments are project-scoped by ID.
- Users can be assigned to multiple projects with optional modules, roles, permission groups and direct permissions.
- Project user synchronization is per-user/per-project and queued with retries.
- Project payloads are filtered by `user_field_access_rules` before transmission.
- GIAM SSO uses short-lived, one-time handoff tokens for project launch while the GIAM session remains active.
- Audit logs capture authenticated actor, action, module, request URL/method, IP, user agent and sanitized payload.

## Authentication
The API sets `auth_token` as an HttpOnly cookie. The frontend must use Axios `withCredentials=true` and must not access the cookie from JavaScript.

Endpoints include:
- `POST /api/v1/login`
- `POST /api/v1/refresh`
- `GET /api/v1/me`
- `POST /api/v1/logout`
- `POST /api/v1/sso/generate-token`
- `POST /api/v1/sso/exchange-token`

## Project catalog
Projects push their catalog to:
`POST /api/v1/sync/roles-permissions`

GIAM exposes the normalized project catalog to authorized users through:
`GET /api/v1/applications/{id}/catalog`

## Queue
Run a worker in every environment where asynchronous project synchronization is required:
```bash
php artisan queue:work --tries=5
```

## Environment
Copy `.env.example` to `.env`. Generate an application key and JWT secret; use production database, mail, queue, CORS and cookie settings in production.

Never commit `.env` or real project API credentials.

## Deployment checklist
1. `php artisan migrate --force`
2. Configure `QUEUE_CONNECTION` and run queue workers.
3. Set `APP_ENV=production` and `APP_DEBUG=false`.
4. Set explicit `CORS_ALLOWED_ORIGINS`.
5. Use HTTPS and `SESSION_SECURE_COOKIE=true`.
6. Rotate any development credentials before production.
7. Configure project sync credentials using encrypted-at-rest storage.
8. Monitor `user_project_sync_logs` and application logs.
