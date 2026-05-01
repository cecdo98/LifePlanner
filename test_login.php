<?php
require 'config/bd.php';
$stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
if (!$stmt) {
    echo "ERROR: " . $conn->error;
} else {
    echo "OK";
}
