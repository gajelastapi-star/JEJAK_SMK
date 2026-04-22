<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $studentId = trim((string) ($_GET['studentId'] ?? ''));
    $token = trim((string) ($_GET['sessionToken'] ?? ''));
    $role = trim((string) ($_GET['role'] ?? ''));

    if ($role === 'guru') {
        json_response(['success' => true]);
    }

    if ($studentId === '' || $token === '') {
        json_response(['success' => false, 'message' => 'Sesi tidak lengkap.'], 401);
    }

    $pdo = get_pdo();
    $student = validate_student_session($pdo, $studentId, $token);
    if (!$student) {
      json_response(['success' => false, 'message' => 'Sesi sudah tidak aktif.'], 401);
    }

    json_response([
        'success' => true,
        'user' => format_student_user($student),
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
