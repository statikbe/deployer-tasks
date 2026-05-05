<?php

// Uploaded to {release_path}/public under a random filename by
// statik:reload-phpfpm; removed in the task's finally block.
header('Content-Type: application/json');
$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
echo json_encode([
    'start_time' => is_array($status) ? ($status['opcache_statistics']['start_time'] ?? 0) : 0,
    'now' => time(),
]);
