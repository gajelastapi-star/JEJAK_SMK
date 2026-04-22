<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    $studentId = trim((string) ($payload['studentId'] ?? ''));
    $token = trim((string) ($payload['sessionToken'] ?? ''));
    $role = trim((string) ($payload['role'] ?? ''));

    if ($role === 'guru') {
        json_response(['success' => true]);
    }

    $pdo = get_pdo();
    release_student_session($pdo, $studentId, $token);
    json_response(['success' => true]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}

