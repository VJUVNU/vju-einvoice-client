<?php

return [

    /*
    | Gốc của dịch vụ, KHÔNG kèm /api/v1 (vd. https://einvoice.vju.ac.vn). Đường dẫn đem ký luôn là
    | /api/v1/... — phần đường dẫn con nằm trong URL này (nếu có) không vào chuỗi ký.
    */
    'url' => env('EINVOICE_URL'),

    // Mã app (client_apps.code) và khoá API do quản trị dịch vụ cấp.
    'key' => env('EINVOICE_KEY'),
    'secret' => env('EINVOICE_SECRET'),

    // Đơn vị phát hành mặc định (issuers.code) khi DocumentRequest không ghi issuer.
    'default_issuer' => env('EINVOICE_ISSUER'),

    'timeout' => (int) env('EINVOICE_TIMEOUT', 15),
    'connect_timeout' => (int) env('EINVOICE_CONNECT_TIMEOUT', 5),

    // Chỉ GET được thử lại, và chỉ khi lỗi kết nối. Mỗi lần thử ký lại với nonce mới.
    'retries' => (int) env('EINVOICE_RETRIES', 2),
    'retry_sleep_ms' => (int) env('EINVOICE_RETRY_SLEEP_MS', 300),

    // Cửa sổ lệch giờ cho phép khi kiểm chữ ký webhook (giây). Hợp đồng: ±300.
    'signature_window' => (int) env('EINVOICE_SIGNATURE_WINDOW', 300),

    'webhook' => [
        'enabled' => (bool) env('EINVOICE_WEBHOOK_ENABLED', true),
        'path' => env('EINVOICE_WEBHOOK_PATH', 'einvoice/webhook'),

        // X-Vju-Key mà dịch vụ gửi kèm webhook. Hợp đồng chốt là "einvoice".
        'key' => 'einvoice',

        // client_apps.webhook_secret. Lúc xoay khoá: ghi nhiều khoá, ngăn bằng dấu phẩy.
        'secret' => env('EINVOICE_WEBHOOK_SECRET'),

        // Middleware thêm cho route webhook. Không gắn nhóm "web" — không có CSRF/phiên.
        'middleware' => [],
    ],

    'pull' => [
        'limit' => (int) env('EINVOICE_PULL_LIMIT', 100),
        'max_pages' => (int) env('EINVOICE_PULL_MAX_PAGES', 20),

        // true thì gói tự đăng ký lịch einvoice:pull; mặc định false — app tự khai trong routes/console.php.
        'schedule' => (bool) env('EINVOICE_PULL_SCHEDULE', false),
        'cron' => env('EINVOICE_PULL_CRON', '*/5 * * * *'),
    ],

    // Kết nối CSDL cho bảng chống trùng/con trỏ/nonce. Để null (kết nối mặc định) để listener ghi cùng
    // một transaction với dòng chống trùng.
    'connection' => env('EINVOICE_DB_CONNECTION'),

    // false nếu app đã publish migration và muốn tự quản lý.
    'run_migrations' => true,
];
