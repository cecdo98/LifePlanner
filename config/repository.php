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

    // --- Savings Goals ---

    function get_savings_goals($conn, $userId) {
        $stmt = $conn->prepare("SELECT * FROM savings_goals WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function save_savings_goal($conn, $userId, $name, $target, $current, $deadline, $description) {
        $stmt = $conn->prepare("INSERT INTO savings_goals (user_id, name, target_amount, current_amount, deadline, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("isddss", $userId, $name, $target, $current, $deadline, $description);
        return $stmt->execute();
    }

    function update_savings_goal($conn, $userId, $id, $name, $target, $current, $deadline, $description) {
        $stmt = $conn->prepare("UPDATE savings_goals SET name=?, target_amount=?, current_amount=?, deadline=?, description=? WHERE id=? AND user_id=?");
        $stmt->bind_param("sddssi", $name, $target, $current, $deadline, $description, $id, $userId);
        return $stmt->execute();
    }

    function delete_savings_goal($conn, $userId, $id) {
        $stmt = $conn->prepare("DELETE FROM savings_goals WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $userId);
        return $stmt->execute();
    }

    // --- Recurring Expenses ---

    function get_recurring_expenses($conn, $userId) {
        $stmt = $conn->prepare("
            SELECT r.*, c.name AS category_name
            FROM recurring_expenses r
            LEFT JOIN categories c ON c.id = r.category_id
            WHERE r.user_id = ?
            ORDER BY r.description ASC
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function apply_recurring_for_month($conn, $userId, $year, $month) {
        $recurring = get_recurring_expenses($conn, $userId);
        $applied = 0;
        foreach ($recurring as $r) {
            if (!$r['active']) continue;
            $rid = (int)$r['id'];
            $check = $conn->prepare("SELECT 1 FROM recurring_applied WHERE recurring_id=? AND user_id=? AND year=? AND month=?");
            $check->bind_param("iiii", $rid, $userId, $year, $month);
            $check->execute();
            if ($check->get_result()->fetch_assoc()) continue;

            $day = min((int)$r['day_of_month'], cal_days_in_month(CAL_GREGORIAN, $month, $year));
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            $catId = $r['category_id'];
            $amount = (float)$r['amount'];
            $desc = $r['description'];
            $detail = $r['detail'];
            $nif = (int)$r['nif'];

            $ins = $conn->prepare("INSERT INTO transactions (user_id, category_id, amount, date, description, detail, nif) VALUES (?,?,?,?,?,?,?)");
            $ins->bind_param("iidsssi", $userId, $catId, $amount, $date, $desc, $detail, $nif);
            $ins->execute();

            $mark = $conn->prepare("INSERT OR IGNORE INTO recurring_applied (recurring_id, user_id, year, month) VALUES (?,?,?,?)");
            $mark->bind_param("iiii", $rid, $userId, $year, $month);
            $mark->execute();
            $applied++;
        }
        return $applied;
    }

    // --- Tags ---

    function get_user_tags($conn, $userId) {
        $stmt = $conn->prepare("SELECT id, name FROM tags WHERE user_id = ? ORDER BY name ASC");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function get_transaction_tags($conn, $transactionId) {
        $stmt = $conn->prepare("SELECT t.id, t.name FROM tags t JOIN transaction_tags tt ON tt.tag_id = t.id WHERE tt.transaction_id = ?");
        $stmt->bind_param("i", $transactionId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function sync_transaction_tags($conn, $transactionId, $tagIds) {
        $del = $conn->prepare("DELETE FROM transaction_tags WHERE transaction_id = ?");
        $del->bind_param("i", $transactionId);
        $del->execute();
        foreach ($tagIds as $tid) {
            $tid = (int)$tid;
            if ($tid <= 0) continue;
            $ins = $conn->prepare("INSERT OR IGNORE INTO transaction_tags (transaction_id, tag_id) VALUES (?,?)");
            $ins->bind_param("ii", $transactionId, $tid);
            $ins->execute();
        }
    }
?>
