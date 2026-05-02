<?php
    session_start();
    include_once "../../config/bd.php";

    if (!isset($_SESSION['user_id'])) {
        header("Location: ../../index.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];

    // --- USERS (apenas o utilizador atual, sem password_hash) ---
    $stmt = $conn->prepare("SELECT id, username FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $users = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $users[] = $row;
    }

    // --- CATEGORIES ---
    $stmt = $conn->prepare("SELECT id, name FROM categories ORDER BY id ASC");
    $stmt->execute();
    $categories = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }

    // --- TRANSACTIONS ---
    $stmt = $conn->prepare("
        SELECT id, user_id, category_id, amount, date, description, detail, nif
        FROM transactions
        WHERE user_id = ?
        ORDER BY date ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $transactions = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $transactions[] = $row;
    }

    // --- MONTHLY SUMMARY ---
    $stmt = $conn->prepare("
        SELECT id, user_id, year, month, total_spent, salary, final_balance
        FROM monthly_summary
        WHERE user_id = ?
        ORDER BY year ASC, month ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $monthly_summary = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $monthly_summary[] = $row;
    }

    $export = [
        "export_date"     => date('Y-m-d H:i:s'),
        "schema_version"  => "1.0",
        "user_id"         => $user_id,
        "users"           => $users,
        "categories"      => $categories,
        "transactions"    => $transactions,
        "monthly_summary" => $monthly_summary,
    ];

    $filename = "lifeplanner_backup_" . date('Ymd_His') . ".json";

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
