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

## Updating Existing Databases

If you already created tables before the completed-exam feature, run:

```sql
ALTER TABLE exams
    ADD COLUMN is_completed TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN completed_at DATETIME NULL;
```

If you already created tables before the naming-template feature, run:

```sql
ALTER TABLE exams
    ADD COLUMN file_name_template VARCHAR(255) NULL,
    ADD COLUMN folder_name_template VARCHAR(255) NULL;
```

## Notes

- File uploads are stored under `uploads/exam_{id}/submission_{id}`.
- Configure PHP upload limits (`upload_max_filesize`, `post_max_size`) if needed.
