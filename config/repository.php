<?php
    function get_categories($conn) {
        $stmt = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function build_nav_links($categories) {
        $links = [["../dashboard/dashboard.php", "Inicio"], ["../movements/movements.php", "Movimentos"]];
        foreach ($categories as $category) {
            $links[] = ["../options/option.php?cat=" . $category['id'], $category['name']];
        }
        return $links;
    }

    function category_exists($conn, $categoryId) {
        $stmt = $conn->prepare("SELECT id FROM categories WHERE id = ?");
        $stmt->bind_param("i", $categoryId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    function get_monthly_budget_rows($conn, $userId, $year, $month) {
        $stmt = $conn->prepare("
            SELECT c.id, c.name, COALESCE(cb.budget, 0) AS budget
            FROM categories c
            LEFT JOIN category_budgets cb
                ON cb.category_id = c.id
                AND cb.user_id = ?
                AND cb.year = ?
                AND cb.month = ?
            ORDER BY c.name ASC
        ");
        $stmt->bind_param("iii", $userId, $year, $month);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function save_monthly_budget($conn, $userId, $categoryId, $year, $month, $budget) {
        $stmt = $conn->prepare("
            INSERT INTO category_budgets (user_id, category_id, year, month, budget)
            VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(user_id, category_id, year, month) DO UPDATE SET
                budget = excluded.budget
        ");
        $stmt->bind_param("iiiid", $userId, $categoryId, $year, $month, $budget);
        return $stmt->execute();
    }
?>
