<?php

/**
 * GET /analytics.php. Moved into the admin panel in M39 (admin.php?p=analytics): the browser
 * page and its JSON need the panel session now, so the old token login is gone. Old links and
 * bookmarks are redirected, keeping the window / compare / format they carried.
 */

declare(strict_types=1);

$query = ['p' => 'analytics'];
foreach (['window', 'compare', 'format'] as $key) {
    if (isset($_GET[$key]) && is_string($_GET[$key])) {
        $query[$key] = $_GET[$key];
    }
}
http_response_code(302);
header('Location: admin.php?' . http_build_query($query));
header('Cache-Control: no-store');
