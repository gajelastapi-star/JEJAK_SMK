<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

try {
    reset_database();
    header('Content-Type: text/plain; charset=utf-8');
    echo "Database MySQL berhasil dibuat.\n";
    echo "Host     : " . DB_HOST . ':' . DB_PORT . "\n";
    echo "Database : " . DB_NAME . "\n";
    echo "User     : " . DB_USER . "\n";
    echo "Excel    : " . EXCEL_PATH . "\n\n";
    echo "Selanjutnya buka aplikasi dari browser melalui Apache/XAMPP.\n";
    echo "Contoh lokal : http://localhost/absensi-smk/\n";
    echo "Contoh HP    : http://IP-PC-ANDA/absensi-smk/\n";
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Setup gagal: ' . $e->getMessage();
}
