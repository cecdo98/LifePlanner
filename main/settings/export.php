<?php
    include_once "../../config/bootstrap.php";
    include_once "../../config/bd.php";
    include_once "../../config/security.php";

    require_login("../../index.php");

    $user_id = $_SESSION['user_id'];
    $filterYear = isset($_GET['year']) && $_GET['year'] !== '' ? validate_year($_GET['year']) : null;
    $filterMonth = isset($_GET['month']) && $_GET['month'] !== '' ? validate_month($_GET['month']) : null;
    $filterCategory = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? max(1, (int)$_GET['category_id']) : null;

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
    if ($filterCategory) {
        $stmt = $conn->prepare("SELECT id, name FROM categories WHERE id = ? ORDER BY id ASC");
        $stmt->bind_param("i", $filterCategory);
    } else {
        $stmt = $conn->prepare("SELECT id, name FROM categories ORDER BY id ASC");
    }
    $stmt->execute();
    $categories = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $categories[] = $row;
    }

    // --- TRANSACTIONS ---
    $txWhere = "WHERE user_id = ?";
    if ($filterYear) {
        $txWhere .= " AND YEAR(date) = " . (int)$filterYear;
    }
    if ($filterMonth) {
        $txWhere .= " AND MONTH(date) = " . (int)$filterMonth;
    }
    if ($filterCategory) {
        $txWhere .= " AND category_id = " . (int)$filterCategory;
    }

    $stmt = $conn->prepare("
        SELECT id, user_id, category_id, amount, date, description, detail, nif
        FROM transactions
        $txWhere
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
    $summaryWhere = "WHERE user_id = ?";
    if ($filterYear) {
        $summaryWhere .= " AND year = " . (int)$filterYear;
    }
    if ($filterMonth) {
        $summaryWhere .= " AND month = " . (int)$filterMonth;
    }

    $stmt = $conn->prepare("
        SELECT id, user_id, year, month, total_spent, salary, final_balance
        FROM monthly_summary
        $summaryWhere
        ORDER BY year ASC, month ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $monthly_summary = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $monthly_summary[] = $row;
    }

    // --- CATEGORY BUDGETS ---
    $budgetWhere = "WHERE user_id = ?";
    if ($filterYear) {
        $budgetWhere .= " AND year = " . (int)$filterYear;
    }
    if ($filterMonth) {
        $budgetWhere .= " AND month = " . (int)$filterMonth;
    }
    if ($filterCategory) {
        $budgetWhere .= " AND category_id = " . (int)$filterCategory;
    }

    $stmt = $conn->prepare("
        SELECT id, user_id, category_id, year, month, budget
        FROM category_budgets
        $budgetWhere
        ORDER BY year ASC, month ASC, category_id ASC
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $category_budgets = [];
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $category_budgets[] = $row;
    }

    $export = [
        "export_date"     => date('Y-m-d H:i:s'),
        "schema_version"  => "1.0",
        "user_id"         => $user_id,
        "filters"         => [
            "year" => $filterYear,
            "month" => $filterMonth,
            "category_id" => $filterCategory,
        ],
        "users"           => $users,
        "categories"      => $categories,
        "transactions"    => $transactions,
        "monthly_summary" => $monthly_summary,
        "category_budgets" => $category_budgets,
    ];

    $filenameParts = ["lifeplanner_backup"];
    if ($filterYear) $filenameParts[] = (string)$filterYear;
    if ($filterMonth) $filenameParts[] = str_pad((string)$filterMonth, 2, '0', STR_PAD_LEFT);
    if ($filterCategory) $filenameParts[] = "cat" . $filterCategory;
    $filenameParts[] = date('Ymd_His');
    $filename = implode("_", $filenameParts) . ".json";

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');

    echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit();
