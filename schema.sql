CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username_normalized VARCHAR(191) NOT NULL UNIQUE,
    username_display VARCHAR(191) NOT NULL,
    password VARCHAR(50) NOT NULL,
    name VARCHAR(191) NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    jurusan VARCHAR(100) NOT NULL,
    absen_no VARCHAR(20) NOT NULL,
    student_id VARCHAR(50) NOT NULL,
    bio VARCHAR(191) NOT NULL DEFAULT 'PESERTA UTS SMKN 2 SRAGEN',
    active_session_token VARCHAR(128) NULL,
    active_session_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE INDEX idx_students_jurusan ON students (jurusan);
CREATE INDEX idx_students_class_name ON students (class_name);
