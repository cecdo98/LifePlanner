<?php
$pdo = new PDO('sqlite:config/financas.sqlite');
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
