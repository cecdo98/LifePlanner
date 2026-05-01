<?php
$pdo = new PDO('sqlite:config/financas.sqlite');
$stmt = $pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
