# ExamSubs

![ScreenshotOfApp](https://github.com/user-attachments/assets/e9ccb11a-2b5e-4c53-ace7-a801fcfe383f)

ExamSubs is a lightweight PHP/MySQL web portal for managing exam submissions for an education institution. Students can upload required files through a simple form, while staff can define exams, collect submissions, and review uploaded materials from a dedicated dashboard. The system keeps uploads organised by exam and submission to make retrieval and auditing straightforward.

## Setup

1. Create a MySQL database (default name `exam_portal`).
2. Import the schema.sql file included in this repo into the database.
3. Put your mySQL database credentials in `config.php`.
4. Ensure the web server user can write to `uploads/`.
5. Visit:
   - Student view: `/index.php`
   - Staff view: `/staff/index.php`

## Notes

- File uploads are stored under `uploads/exam_{id}/submission_{id}`, though are of course downloadable from the Administration Portal.
- Configure PHP upload limits (`upload_max_filesize`, `post_max_size`) if needed to increase maximum file size that can be uploaded.

## Staff Authentication (Microsoft Entra)

This app is setup to use Microsoft Entra authentication to access the Exam Administration (Staff) section. You will need to create an 'App Registration' within the Microsoft Entra portal.

### App Registration Settings

- Single-tenant
- Web platform
- Redirect URI will be set to your site URL + `/auth/callback.php`) e.g. https://your-domain.example.com/auth/callback.php

### The following API Permissions will be required:

`openid`, `profile`, `email`

Using the 'Enterprise Applications' section of Microsoft Entra, i highly recommend you restrict usage of this application to only trusted staff members by enabling the 'Assignment required?' option in the Properties of the app in Entra, and then using the 'Users and Groups' tab to assign trusted users.

### Once you have setup an app registration, configure the the `entra` section in `config.php`:

- `tenant_id` - Your Microsoft Tenant ID
- `client_id` - Your Application (Client) ID in your registered app on Entra
- `client_secret` - The Client Secret you setup in the API Permissions of the App on Entra
- `redirect_uri` (set to your site URL + `/auth/callback.php`) e.g. https://your-domain.example.com/auth/callback.php


