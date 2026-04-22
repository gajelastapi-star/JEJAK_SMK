<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $username = normalize_username((string) ($payload['username'] ?? ''));
    $password = trim((string) ($payload['password'] ?? ''));

    if ($username === '' || $password === '') {
        json_response(['success' => false, 'message' => 'Username dan password wajib diisi.'], 422);
    }

    if ($username === normalize_username(ADMIN_USERNAME) && $password === ADMIN_PASSWORD) {
        json_response([
            'success' => true,
            'user' => [
                'role' => 'guru',
                'id' => 'GURU-01',
                'username' => ADMIN_USERNAME,
                'name' => 'PENGAWAS ADMIN',
                'class' => 'STAF PENGAJAR',
                'jurusan' => 'ADMIN',
                'absenNo' => '-',
                'avatar' => 'char-live-admin',
                'photo' => '',
                'lastAttendancePhoto' => '',
                'bio' => 'DASHBOARD MONITORING SMKN 2 SRAGEN',
            ],
        ]);
    }

    $pdo = get_pdo();
    $student = fetch_student_by_username($pdo, $username);

    if (!$student || $student['password'] !== $password) {
        json_response(['success' => false, 'message' => 'Username atau password tidak valid.'], 401);
    }

    $session = lock_student_session($pdo, $student['student_id']);
    $student['active_session_token'] = $session['token'];

    json_response([
        'success' => true,
        'user' => format_student_user($student),
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}
