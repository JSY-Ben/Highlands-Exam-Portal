CREATE TABLE exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_code VARCHAR(100) NOT NULL,
    title VARCHAR(255) NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NOT NULL,
    buffer_pre_minutes INT NOT NULL DEFAULT 0,
    buffer_post_minutes INT NOT NULL DEFAULT 0,
    is_completed TINYINT(1) NOT NULL DEFAULT 0,
    completed_at DATETIME NULL,
    file_name_template VARCHAR(255) NULL,
    folder_name_template VARCHAR(255) NULL,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE exam_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_exam_documents_exam
        FOREIGN KEY (exam_id)
        REFERENCES exams (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_name VARCHAR(255) NOT NULL,
    candidate_number VARCHAR(100) NOT NULL,
    submitted_at DATETIME NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    CONSTRAINT fk_submissions_exam
        FOREIGN KEY (exam_id)
        REFERENCES exams (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE submission_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    exam_document_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    file_size INT NOT NULL,
    CONSTRAINT fk_submission_files_submission
        FOREIGN KEY (submission_id)
        REFERENCES submissions (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_submission_files_exam_document
        FOREIGN KEY (exam_document_id)
        REFERENCES exam_documents (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
