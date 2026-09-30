<?php
// school_owner/debtors.php
// Redirects to merged fees.php "Owing" tab.
// Preserves old filter names and CSV export.
// Original content moved into fees.php on 2026-09-19.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

// Build new query string
$qs = $_GET;
$qs['tab'] = 'owing';

// Map old query params → new merged params
$mapping = [
    'class_id'    => 'filter_class_id',
    'min_balance' => 'filter_min_balance',
    'term'        => 'filter_term',
    'session'     => 'filter_session',
];

foreach ($mapping as $old => $new) {
    if (isset($qs[$old])) {
        $qs[$new] = $qs[$old];
        unset($qs[$old]);
    }
}

// CSV export passthrough
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $qs['export'] = 'debtors';
    unset($qs['tab']);
}

header('Location: fees.php?' . http_build_query($qs), true, 302);
exit;