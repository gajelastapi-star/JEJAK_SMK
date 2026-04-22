<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function normalize_username(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    return preg_replace('/\s+/u', ' ', $value) ?? $value;
}

function extract_jurusan(string $className): string
{
    $parts = preg_split('/\s+/', trim($className)) ?: [];
    if (count($parts) >= 3) {
        array_shift($parts);
        array_pop($parts);
        return implode(' ', $parts);
    }
    return trim($className);
}

function mysql_dsn(?string $database = null): string
{
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    if ($database !== null && $database !== '') {
        $dsn .= ';dbname=' . $database;
    }
    return $dsn;
}

function get_server_pdo(): PDO
{
    $pdo = new PDO(mysql_dsn(), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function get_pdo(): PDO
{
    ensure_database_exists();
    $pdo = new PDO(mysql_dsn(DB_NAME), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

function ensure_database_exists(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $serverPdo = get_server_pdo();
    $serverPdo->exec(
        'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );

    $pdo = new PDO(mysql_dsn(DB_NAME), DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    if ($schema === false) {
        throw new RuntimeException('Schema database tidak ditemukan.');
    }
    $pdo->exec($schema);
    ensure_schema_columns($pdo);

    $tableExists = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn() > 0;
    if (!$tableExists || database_needs_student_reimport($pdo)) {
        import_excel_to_database($pdo, EXCEL_PATH);
    }

    $initialized = true;
}

function database_needs_student_reimport(PDO $pdo): bool
{
    try {
        $sample = $pdo->query('SELECT username_display, username_normalized, name FROM students ORDER BY id ASC LIMIT 5')->fetchAll();
        if ($sample === []) {
            return true;
        }

        foreach ($sample as $row) {
            $display = trim((string) ($row['username_display'] ?? ''));
            $normalized = trim((string) ($row['username_normalized'] ?? ''));
            $name = trim((string) ($row['name'] ?? ''));

            if ($display === '' || $name === '') {
                return true;
            }

            // Re-import if old records still use synthetic usernames like "smk12075"
            // or any username that no longer mirrors the student's name.
            if ($display !== $name || $normalized !== normalize_username($name)) {
                return true;
            }
        }
    } catch (Throwable $e) {
        return true;
    }

    return false;
}

function ensure_schema_columns(PDO $pdo): void
{
    $columns = $pdo->query('SHOW COLUMNS FROM students')->fetchAll();
    $existing = array_map(static fn(array $col): string => $col['Field'], $columns);

    if (!in_array('active_session_token', $existing, true)) {
        $pdo->exec('ALTER TABLE students ADD COLUMN active_session_token VARCHAR(128) NULL AFTER bio');
    }
    if (!in_array('active_session_at', $existing, true)) {
        $pdo->exec('ALTER TABLE students ADD COLUMN active_session_at DATETIME NULL AFTER active_session_token');
    }
}

function reset_database(): void
{
    $serverPdo = get_server_pdo();
    $serverPdo->exec('DROP DATABASE IF EXISTS `' . DB_NAME . '`');
    ensure_database_exists();
}

function import_excel_to_database(PDO $pdo, string $excelPath): void
{
    if (!is_file($excelPath)) {
        throw new RuntimeException('File Excel tidak ditemukan.');
    }

    $zip = new ZipArchive();
    if ($zip->open($excelPath) !== true) {
        throw new RuntimeException('File Excel gagal dibuka.');
    }

    $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sharedStringsXml === false || $sheetXml === false) {
        throw new RuntimeException('Struktur Excel tidak sesuai.');
    }

    $sharedStrings = parse_shared_strings($sharedStringsXml);
    $rows = parse_sheet_rows($sheetXml, $sharedStrings);

    $pdo->beginTransaction();
    $pdo->exec('TRUNCATE TABLE students');
    $stmt = $pdo->prepare(
        'INSERT INTO students (
            username_normalized, username_display, password, name, class_name, jurusan, absen_no, student_id, bio
        ) VALUES (
            :username_normalized, :username_display, :password, :name, :class_name, :jurusan, :absen_no, :student_id, :bio
        )'
    );

    foreach ($rows as $row) {
        if (($row['B'] ?? '') === 'Password' || ($row['C'] ?? '') === 'Nama') {
            continue;
        }
        if (!isset($row['B'], $row['C'])) {
            continue;
        }

        $password = preg_replace('/\D+/', '', (string) $row['B']);
        $name = trim((string) $row['C']);
        $className = trim((string) ($row['E'] ?? ''));
        $absenNo = preg_replace('/\D+/', '', (string) ($row['F'] ?? ''));
        if ($password === '' || $name === '') {
            continue;
        }

        $stmt->execute([
            ':username_normalized' => normalize_username($name),
            ':username_display' => $name,
            ':password' => $password,
            ':name' => $name,
            ':class_name' => $className,
            ':jurusan' => extract_jurusan($className),
            ':absen_no' => $absenNo,
            ':student_id' => $password,
            ':bio' => 'PESERTA UTS SMKN 2 SRAGEN',
        ]);
    }

    $pdo->commit();
}

function parse_shared_strings(string $xml): array
{
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }

    $doc->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $items = [];
    foreach ($doc->xpath('//x:si') ?: [] as $si) {
        $text = '';
        foreach ($si->xpath('.//x:t') ?: [] as $t) {
            $text .= (string) $t;
        }
        $items[] = trim($text);
    }
    return $items;
}

function parse_sheet_rows(string $xml, array $sharedStrings): array
{
    $doc = simplexml_load_string($xml);
    if ($doc === false) {
        return [];
    }

    $doc->registerXPathNamespace('x', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rows = [];

    foreach ($doc->xpath('//x:sheetData/x:row') ?: [] as $row) {
        $rowData = [];
        foreach ($row->xpath('./x:c') ?: [] as $cell) {
            $ref = (string) $cell['r'];
            $column = preg_replace('/\d+/', '', $ref) ?: '';
            $type = (string) $cell['t'];
            $value = '';

            if ($type === 's') {
                $index = (int) ($cell->v ?? 0);
                $value = $sharedStrings[$index] ?? '';
            } else {
                $value = trim((string) ($cell->v ?? ''));
            }

            if ($column !== '') {
                $rowData[$column] = $value;
            }
        }
        if ($rowData !== []) {
            $rows[] = $rowData;
        }
    }

    return $rows;
}

function fetch_student_by_username(PDO $pdo, string $username): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM students WHERE username_normalized = :username LIMIT 1');
    $stmt->execute([':username' => normalize_username($username)]);
    $student = $stmt->fetch();
    return $student ?: null;
}

function format_student_user(array $student): array
{
    return [
        'role' => 'siswa',
        'id' => $student['student_id'],
        'username' => $student['username_display'],
        'name' => $student['name'],
        'class' => $student['class_name'],
        'jurusan' => $student['jurusan'],
        'absenNo' => $student['absen_no'],
        'avatar' => 'char-live-1',
        'photo' => '',
        'lastAttendancePhoto' => '',
        'bio' => $student['bio'],
        'sessionToken' => $student['active_session_token'] ?? '',
    ];
}

function generate_session_token(): string
{
    return bin2hex(random_bytes(32));
}

function lock_student_session(PDO $pdo, int|string $studentId): array
{
    $stmt = $pdo->prepare('SELECT id, active_session_token, active_session_at FROM students WHERE student_id = :student_id LIMIT 1');
    $stmt->execute([':student_id' => (string) $studentId]);
    $student = $stmt->fetch();
    if (!$student) {
        throw new RuntimeException('Data siswa tidak ditemukan.');
    }

    if (!empty($student['active_session_token'])) {
        throw new RuntimeException('Akun ini sedang dipakai di perangkat lain. Silakan logout dulu dari perangkat sebelumnya.');
    }

    $token = generate_session_token();
    $update = $pdo->prepare('UPDATE students SET active_session_token = :token, active_session_at = NOW() WHERE id = :id');
    $update->execute([
        ':token' => $token,
        ':id' => $student['id'],
    ]);

    return ['token' => $token];
}

function release_student_session(PDO $pdo, string $studentId, string $token = ''): void
{
    if ($studentId === '') {
        return;
    }

    if ($token !== '') {
        $stmt = $pdo->prepare('UPDATE students SET active_session_token = NULL, active_session_at = NULL WHERE student_id = :student_id AND active_session_token = :token');
        $stmt->execute([
            ':student_id' => $studentId,
            ':token' => $token,
        ]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE students SET active_session_token = NULL, active_session_at = NULL WHERE student_id = :student_id');
    $stmt->execute([':student_id' => $studentId]);
}

function validate_student_session(PDO $pdo, string $studentId, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM students WHERE student_id = :student_id AND active_session_token = :token LIMIT 1');
    $stmt->execute([
        ':student_id' => $studentId,
        ':token' => $token,
    ]);
    $student = $stmt->fetch();
    return $student ?: null;
}
