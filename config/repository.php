<?php
    // Fonte única dos nomes dos meses em português — evita divergências como
    // "Marco" vs "Março" que apareciam repetidas em cada página.
    function month_names($full = true) {
        return $full
            ? [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
               7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro']
            : [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
               7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
    }

    function get_categories($conn) {
        $stmt = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function build_nav_links($categories) {
        $links = [
            ["../dashboard/dashboard.php", "Inicio"],
            ["../movements/movements.php", "Movimentos"],
            ["../report/report.php", "Relatório"],
        ];
        foreach ($categories as $category) {
            $links[] = ["../options/option.php?cat=" . $category['id'], $category['name']];
        }
        return $links;
    }

    // Renderiza a barra de navegação de forma consistente em todas as páginas:
    // os 3 links fixos + um dropdown "Categorias" para o resto. $activeMatch é o
    // sufixo do URL atual (ex.: "movements.php" ou "cat=5") usado para marcar o
    // link ativo; passar null quando a página não corresponde a nenhum link fixo
    // (ex.: dashboard). Usa "termina com" em vez de "contém" para "cat=5" não
    // corresponder por engano a "cat=51".
    function render_nav($navLinks, $activeMatch = null) {
        $fixedLinks = array_slice($navLinks, 0, 3);
        $catLinks   = array_slice($navLinks, 3);
        $isActive   = fn($href) => $activeMatch && str_ends_with($href, $activeMatch);
        $catActive  = false;
        foreach ($catLinks as [$href, $label]) {
            if ($isActive($href)) { $catActive = true; break; }
        }
        ob_start();
        ?>
        <ul class="nav-links" id="nav-links">
          <?php foreach ($fixedLinks as [$href, $label]): ?>
          <li><a href="<?= e($href) ?>"<?= $isActive($href) ? ' class="active"' : '' ?>><?= e($label) ?></a></li>
          <?php endforeach; ?>
          <?php if (!empty($catLinks)): ?>
          <li class="nav-dropdown">
            <button class="nav-dropdown-btn<?= $catActive ? ' has-active' : '' ?>" onclick="toggleNavDropdown(this)">Categorias ▾</button>
            <ul class="nav-dropdown-menu">
              <?php foreach ($catLinks as [$href, $label]): ?>
              <li><a href="<?= e($href) ?>"<?= $isActive($href) ? ' class="active"' : '' ?>><?= e($label) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </li>
          <?php endif; ?>
        </ul>
        <?php
        return ob_get_clean();
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
        $conn->begin_transaction();
        try {
            foreach ($recurring as $r) {
                if (!$r['active']) continue;
                $rid = (int)$r['id'];
                $check = $conn->prepare("SELECT 1 FROM recurring_applied WHERE recurring_id=? AND user_id=? AND year=? AND month=?");
                $check->bind_param("iiii", $rid, $userId, $year, $month);
                $check->execute();
                if ($check->get_result()->fetch_assoc()) continue;

                $day = min((int)$r['day_of_month'], (int)date('t', mktime(0, 0, 0, $month, 1, $year)));
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
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            return 0;
        }
        return $applied;
    }

    function get_pending_recurring($conn, $userId, $year, $month) {
        $recurring = get_recurring_expenses($conn, $userId);
        $active = array_values(array_filter($recurring, fn($r) => $r['active']));
        if (empty($active)) return [];

        $ids = array_column($active, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("SELECT recurring_id FROM recurring_applied WHERE user_id=? AND year=? AND month=? AND recurring_id IN ($placeholders)");
        $params = array_merge([$userId, $year, $month], $ids);
        $types = 'iii' . str_repeat('i', count($ids));
        $refs = [];
        foreach ($params as $k => $v) $refs[$k] = &$params[$k];
        $stmt->bind_param($types, ...$refs);
        $stmt->execute();
        $appliedIds = array_map('intval', array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'recurring_id'));

        return array_values(array_filter($active, fn($r) => !in_array((int)$r['id'], $appliedIds)));
    }

    function get_tags_for_transactions($conn, $ids) {
        if (empty($ids)) return [];
        $ids = array_values(array_map('intval', $ids));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $conn->prepare("
            SELECT tt.transaction_id, t.id, t.name
            FROM transaction_tags tt
            JOIN tags t ON t.id = tt.tag_id
            WHERE tt.transaction_id IN ($placeholders)
            ORDER BY t.name ASC
        ");
        $types = str_repeat('i', count($ids));
        $refs = [];
        foreach ($ids as $k => $v) $refs[$k] = &$ids[$k];
        $stmt->bind_param($types, ...$refs);
        $stmt->execute();
        $map = [];
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $map[(int)$row['transaction_id']][] = ['id' => (int)$row['id'], 'name' => $row['name']];
        }
        return $map;
    }

    // --- Income Entries ---

    function get_income_entries($conn, $userId, $year, $month) {
        $stmt = $conn->prepare("SELECT * FROM income_entries WHERE user_id=? AND year=? AND month=? ORDER BY date ASC, id ASC");
        $stmt->bind_param("iii", $userId, $year, $month);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    function add_income_entry($conn, $userId, $year, $month, $amount, $description, $date) {
        $stmt = $conn->prepare("INSERT INTO income_entries (user_id, year, month, amount, description, date) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iiidss", $userId, $year, $month, $amount, $description, $date);
        return $stmt->execute();
    }

    function delete_income_entry($conn, $userId, $id) {
        $stmt = $conn->prepare("DELETE FROM income_entries WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $userId);
        return $stmt->execute();
    }

    function get_total_income($conn, $userId, $year, $month) {
        $stmt = $conn->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM income_entries WHERE user_id=? AND year=? AND month=?");
        $stmt->bind_param("iii", $userId, $year, $month);
        $stmt->execute();
        return (float)$stmt->get_result()->fetch_assoc()['total'];
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
