# ExamSubs

ExamSubs is a lightweight web portal for managing exam submissions. Students can upload required files through a simple intake flow, while staff can define exams, collect submissions, and review uploaded materials from a dedicated dashboard. The system keeps uploads organized per exam and submission to make retrieval and auditing straightforward.

## Setup

1. Create a MySQL database (default name `exam_portal`).
2. Import the schema:

```sql
source schema.sql;
```

3. Update database credentials in `config.php`.
4. Ensure the web server user can write to `uploads/`.
5. Visit:
   - Student view: `/index.php`
   - Staff view: `/staff/index.php`

## Notes

- File uploads are stored under `uploads/exam_{id}/submission_{id}`.
- Configure PHP upload limits (`upload_max_filesize`, `post_max_size`) if needed.

## Staff Authentication (Microsoft Entra)

### Required Config

Update the `entra` section in `config.php`:

- `tenant_id`
- `client_id`
- `client_secret`
- `redirect_uri` (set to your site URL + `/auth/callback.php`)

Example redirect URI:

```
https://your-domain.example.com/auth/callback.php
```

### App Registration Settings

- Single-tenant
- Web platform
- Redirect URI as above
- API permissions: `openid`, `profile`, `email`

### Notes

- All `/staff/*` pages require authentication.
- Logout clears the local session.
