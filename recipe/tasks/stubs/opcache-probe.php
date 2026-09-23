<?php

// Uploaded to {release_path}/public under a random filename by
// statik:reload-phpfpm; removed in the task's finally block.
// `file` is the resolved path of the copy that executed, so the task can tell
// whether the request was served from the new release or the previous one.
header('Content-Type: application/json');
$status = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
echo json_encode([
    'start_time' => is_array($status) ? ($status['opcache_statistics']['start_time'] ?? 0) : 0,
    'now' => time(),
    'file' => __FILE__,
]);
