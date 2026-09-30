<?php require 'lib.php';
header('Content-Type: application/json');
$email = trim($_GET['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['ok' => false, 'reason' => 'Invalid email address.']); exit; }
if (rate_limited()) { http_response_code(429); echo json_encode(['ok' => false, 'reason' => 'Too many requests. Please wait a moment.', 'rate' => true]); exit; }
echo json_encode(load_inbox($email));
