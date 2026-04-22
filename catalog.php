<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    $pdo = get_pdo();
    $rows = $pdo->query('SELECT jurusan, class_name FROM students ORDER BY jurusan, class_name')->fetchAll();
    $catalog = ['jurusan' => [], 'classesByJurusan' => []];

    foreach ($rows as $row) {
        $jurusan = trim((string) $row['jurusan']);
        $className = trim((string) $row['class_name']);
        if ($jurusan === '' || $className === '') {
            continue;
        }
        if (!in_array($jurusan, $catalog['jurusan'], true)) {
            $catalog['jurusan'][] = $jurusan;
        }
        $catalog['classesByJurusan'][$jurusan] ??= [];
        if (!in_array($className, $catalog['classesByJurusan'][$jurusan], true)) {
            $catalog['classesByJurusan'][$jurusan][] = $className;
        }
    }

    sort($catalog['jurusan']);
    foreach ($catalog['classesByJurusan'] as &$classes) {
        sort($classes);
    }

    json_response(['success' => true, 'catalog' => $catalog]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 500);
}

