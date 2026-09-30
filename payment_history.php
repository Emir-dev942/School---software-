<?php
// school_owner/payment_history.php
// Redirects to merged fees.php "Payments" tab.
// Preserves old filter names and CSV export.
// Original content moved into fees.php on 2026-09-19.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';

requireRole(['Owner', 'Principal', 'Accountant']);

// Build new query string
$qs = $_GET;
$qs['tab'] = 'payments';

// Map old query params → new merged params (prefixed with p_)
$mapping = [
    'search'    => 'p_search',
    'date_from' => 'p_date_from',
    'date_to'   => 'p_date_to',
    'year'      => 'p_year',
    'class_id'  => 'p_class_id',
    'status'    => 'p_status',
];

foreach ($mapping as $old => $new) {
    if (isset($qs[$old]) && $old !== $new) {
        $qs[$new] = $qs[$old];
        unset($qs[$old]);
    }
}

// CSV export passthrough
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $qs['export'] = 'payments';
    unset($qs['tab']);
}

header('Location: fees.php?' . http_build_query($qs), true, 302);
exit;