# Highlands Exam Portal

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
