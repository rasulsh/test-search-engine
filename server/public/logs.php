<?php

/**
 * GET /logs.php. Moved into the admin panel in M39 (admin.php?p=logs), behind the panel
 * login instead of a token. Old links and bookmarks are redirected, keeping view and page.
 */

declare(strict_types=1);

$query = ['p' => 'logs'];
foreach (['view', 'page'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) {
        $query[$key] = $_GET[$key];
    }
}
http_response_code(302);
header('Location: admin.php?' . http_build_query($query));
header('Cache-Control: no-store');
