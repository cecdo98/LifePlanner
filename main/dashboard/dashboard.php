<?php
    session_start();
    include_once "../../config/bd.php";
    include_once "../../config/security.php";
    include_once "../../config/repository.php";

    require_login("../../index.php");

    $user_id = $_SESSION['user_id'];
    $year    = validate_year($_GET['year'] ?? null);
    $month   = validate_month($_GET['month'] ?? null);
    $nif     = validate_nif_filter($_GET['nif'] ?? 'all');
    $success = flash_message($_GET['msg'] ?? '');

    // POST: aplicar recorrentes / gerir rendimentos
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token();
        $pAction = $_POST['action'] ?? '';
        $pYear   = validate_year($_POST['year'] ?? null);
        $pMonth  = validate_month($_POST['month'] ?? null);

        if ($pAction === 'apply_recurring') {
            apply_recurring_for_month($conn, $user_id, $pYear, $pMonth);
            header("Location: dashboard.php?year=$pYear&month=$pMonth&msg=recurring_applied");
            exit();
        }
        if ($pAction === 'add_income') {
            $iAmt  = filter_var($_POST['income_amount'] ?? 0, FILTER_VALIDATE_FLOAT);
            $iDesc = trim($_POST['income_desc'] ?? '');
            $iDate = $_POST['income_date'] ?? '';
            if ($iAmt !== false && $iAmt > 0 && $iDesc !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $iDate)) {
                add_income_entry($conn, $user_id, $pYear, $pMonth, $iAmt, $iDesc, $iDate);
            }
            header("Location: dashboard.php?year=$pYear&month=$pMonth&msg=income_saved");
            exit();
        }
        if ($pAction === 'delete_income') {
            $iId = intval($_POST['income_id'] ?? 0);
            if ($iId > 0) delete_income_entry($conn, $user_id, $iId);
            header("Location: dashboard.php?year=$pYear&month=$pMonth&msg=income_deleted");
            exit();
        }
    }

    $meses = [
        1=>"Jan",2=>"Fev",3=>"Mar",4=>"Abr",5=>"Mai",6=>"Jun",
        7=>"Jul",8=>"Ago",9=>"Set",10=>"Out",11=>"Nov",12=>"Dez"
    ];
    $mesesFull = [
        1=>"Janeiro",2=>"Fevereiro",3=>"Marco",4=>"Abril",5=>"Maio",6=>"Junho",
        7=>"Julho",8=>"Agosto",9=>"Setembro",10=>"Outubro",11=>"Novembro",12=>"Dezembro"
    ];

    // Ordenado
    $stmtSalary = $conn->prepare("SELECT salary FROM monthly_summary WHERE user_id = ? AND year = ? AND month = ?");
    $stmtSalary->bind_param("iii", $user_id, $year, $month);
    $stmtSalary->execute();
    $resSalary = $stmtSalary->get_result()->fetch_assoc();
    $salary = $resSalary ? (float)$resSalary['salary'] : 0.00;

    // Auto-aplicar recorrentes na primeira visita do mês actual
    $today    = new DateTime();
    $curYear  = (int)$today->format('Y');
    $curMonth = (int)$today->format('n');
    if ($year === $curYear && $month === $curMonth) {
        $autoChk = $conn->prepare("SELECT 1 FROM recurring_auto_applied WHERE user_id=? AND year=? AND month=?");
        $autoChk->bind_param("iii", $user_id, $year, $month);
        $autoChk->execute();
        if (!$autoChk->get_result()->fetch_assoc()) {
            $autoN = apply_recurring_for_month($conn, $user_id, $year, $month);
            $markAuto = $conn->prepare("INSERT OR IGNORE INTO recurring_auto_applied (user_id, year, month) VALUES (?,?,?)");
            $markAuto->bind_param("iii", $user_id, $year, $month);
            $markAuto->execute();
            if ($autoN > 0 && !$success) {
                $success = "$autoN despesa" . ($autoN !== 1 ? 's' : '') . " recorrente" . ($autoN !== 1 ? 's' : '') . " aplicada" . ($autoN !== 1 ? 's' : '') . " automaticamente em " . $mesesFull[$month] . ".";
            }
        }
    }

    // Rendimentos extra do mês
    $incomeEntries = get_income_entries($conn, $user_id, $year, $month);
    $otherIncome   = (float)array_sum(array_column($incomeEntries, 'amount'));
    $totalIncome   = $salary + $otherIncome;

    // Total gasto (com ou sem filtro NIF)
    if ($nif === 'all') {
        $stmtTotal = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
        $stmtTotal->bind_param("iii", $user_id, $year, $month);
    } else {
        $stmtTotal = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ? AND nif = ?");
        $stmtTotal->bind_param("iiii", $user_id, $year, $month, $nif);
    }
    $stmtTotal->execute();
    $totalGasto = (float)($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
    $restante = $totalIncome - $totalGasto;
    $percentSpent = $totalIncome > 0 ? min(999, round(($totalGasto / $totalIncome) * 100, 1)) : 0;

    // Total gasto sem filtro NIF (para sync)
    $stmtTotalAll = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
    $stmtTotalAll->bind_param("iii", $user_id, $year, $month);
    $stmtTotalAll->execute();
    $totalGastoAll = (float)($stmtTotalAll->get_result()->fetch_assoc()['total'] ?? 0);
    $restanteAll = $totalIncome - $totalGastoAll;

    // Mes anterior
    $prevMonth = $month === 1 ? 12 : $month - 1;
    $prevYear  = $month === 1 ? $year - 1 : $year;
    if ($nif === 'all') {
        $stmtPrev = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
        $stmtPrev->bind_param("iii", $user_id, $prevYear, $prevMonth);
    } else {
        $stmtPrev = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ? AND nif = ?");
        $stmtPrev->bind_param("iiii", $user_id, $prevYear, $prevMonth, $nif);
    }
    $stmtPrev->execute();
    $totalAnterior = (float)($stmtPrev->get_result()->fetch_assoc()['total'] ?? 0);
    $diffAnterior = $totalGasto - $totalAnterior;
    $diffAnteriorPct = $totalAnterior > 0 ? round(($diffAnterior / $totalAnterior) * 100, 1) : null;

    // Previsao de gastos para o mes atual
    $forecast = null;
    $daysElapsed = null;
    $daysInMonth = null;
    if ($year === $curYear && $month === $curMonth && $totalGasto > 0) {
        $daysElapsed = (int)$today->format('j');
        $daysInMonth = (int)$today->format('t');
        if ($daysElapsed > 0) {
            $forecast = round(($totalGasto / $daysElapsed) * $daysInMonth, 2);
        }
    }

    // Sync monthly_summary
    $stmtSync = $conn->prepare("
        INSERT INTO monthly_summary (user_id, year, month, total_spent, salary, final_balance)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(user_id, year, month) DO UPDATE SET
            total_spent = excluded.total_spent,
            salary = excluded.salary,
            final_balance = excluded.final_balance
    ");
    $stmtSync->bind_param("iiiddd", $user_id, $year, $month, $totalGastoAll, $salary, $restanteAll);
    $stmtSync->execute();

    // Categorias + orçamentos
    $sqlCategory = "
        SELECT c.id, c.name AS categoria, COALESCE(SUM(t.amount), 0) AS total,
               COALESCE(cb.budget, 0) AS budget
        FROM categories c
        LEFT JOIN transactions t
            ON t.category_id = c.id
            AND YEAR(t.date) = ?
            AND MONTH(t.date) = ?
            AND t.user_id = ?
            " . ($nif === 'all' ? "" : "AND t.nif = ?") . "
        LEFT JOIN category_budgets cb
            ON cb.category_id = c.id
            AND cb.user_id = ?
            AND cb.year = ?
            AND cb.month = ?
        GROUP BY c.id, c.name, cb.budget
        ORDER BY c.name
    ";
    $stmt = $conn->prepare($sqlCategory);
    if ($nif === 'all') {
        $stmt->bind_param("iiiiii", $year, $month, $user_id, $user_id, $year, $month);
    } else {
        $stmt->bind_param("iiiiiii", $year, $month, $user_id, $nif, $user_id, $year, $month);
    }
    $stmt->execute();
    $result = $stmt->get_result();

    $categoryRows = [];
    $dataPoints = [];
    $overBudgetCount = 0;
    while ($row = $result->fetch_assoc()) {
        $row['total']  = (float)$row['total'];
        $row['budget'] = (float)$row['budget'];
        $categoryRows[] = $row;
        if ($row['total'] > 0) {
            $dataPoints[] = ["label" => $row['categoria'], "y" => $row['total']];
        }
        if ($row['budget'] > 0 && $row['total'] > $row['budget']) {
            $overBudgetCount++;
        }
    }

    // Top categorias (ordenadas por gasto)
    $topCategories = $categoryRows;
    usort($topCategories, fn($a, $b) => $b['total'] <=> $a['total']);
    $topCategories = array_filter($topCategories, fn($r) => $r['total'] > 0);
    $topCategories = array_slice(array_values($topCategories), 0, 6);
    $maxTop = count($topCategories) > 0 ? $topCategories[0]['total'] : 1;

    // Dados anuais
    $stmtAnual = $conn->prepare("SELECT month, salary, final_balance, total_spent FROM monthly_summary WHERE user_id = ? AND year = ? ORDER BY month ASC");
    $stmtAnual->bind_param("ii", $user_id, $year);
    $stmtAnual->execute();
    $resAnual = $stmtAnual->get_result();

    $salaryAnual  = array_fill(1, 12, null);
    $balanceAnual = array_fill(1, 12, null);
    $spentAnual   = array_fill(1, 12, null);
    while ($row = $resAnual->fetch_assoc()) {
        $m = (int)$row['month'];
        $salaryAnual[$m]  = (float)$row['salary'];
        $balanceAnual[$m] = (float)$row['final_balance'];
        $spentAnual[$m]   = (float)$row['total_spent'];
    }

    // Ano anterior para comparacao
    $yearPrev = $year - 1;
    $stmtAnualPrev = $conn->prepare("SELECT month, total_spent FROM monthly_summary WHERE user_id = ? AND year = ? ORDER BY month ASC");
    $stmtAnualPrev->bind_param("ii", $user_id, $yearPrev);
    $stmtAnualPrev->execute();
    $resAnualPrev = $stmtAnualPrev->get_result();
    $spentAnualPrev = array_fill(1, 12, null);
    while ($row = $resAnualPrev->fetch_assoc()) {
        $spentAnualPrev[(int)$row['month']] = (float)$row['total_spent'];
    }

    // Breakdown semanal do mes selecionado
    $stmtWeekly = $conn->prepare("
        SELECT
            CASE
                WHEN CAST(strftime('%d', date) AS INTEGER) <= 7 THEN 1
                WHEN CAST(strftime('%d', date) AS INTEGER) <= 14 THEN 2
                WHEN CAST(strftime('%d', date) AS INTEGER) <= 21 THEN 3
                ELSE 4
            END AS wk,
            SUM(amount) AS total
        FROM transactions
        WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?
        GROUP BY wk ORDER BY wk
    ");
    $stmtWeekly->bind_param("iii", $user_id, $year, $month);
    $stmtWeekly->execute();
    $weeklyMap = [];
    $resWeekly = $stmtWeekly->get_result();
    while ($r = $resWeekly->fetch_assoc()) {
        $weeklyMap[(int)$r['wk']] = (float)$r['total'];
    }
    $weeklyData = [
        $weeklyMap[1] ?? 0,
        $weeklyMap[2] ?? 0,
        $weeklyMap[3] ?? 0,
        $weeklyMap[4] ?? 0,
    ];

    // Metas de poupança
    $savingsGoals = get_savings_goals($conn, $user_id);

    // Verificar recorrentes pendentes para este mes (1 query em vez de N)
    $pendingRecurring = get_pending_recurring($conn, $user_id, $year, $month);

    // JS data
    $jsLabels   = [];
    $jsSalary   = [];
    $jsBalance  = [];
    $jsSpent    = [];
    $jsSpentPrev = [];
    for ($m = 1; $m <= 12; $m++) {
        $jsLabels[]    = $meses[$m];
        $jsSalary[]    = $salaryAnual[$m]    !== null ? $salaryAnual[$m]    : 'null';
        $jsBalance[]   = $balanceAnual[$m]   !== null ? $balanceAnual[$m]   : 'null';
        $jsSpent[]     = $spentAnual[$m]     !== null ? $spentAnual[$m]     : 'null';
        $jsSpentPrev[] = $spentAnualPrev[$m] !== null ? $spentAnualPrev[$m] : 'null';
    }

    $all_categories = get_categories($conn);
    $navLinks = build_nav_links($all_categories);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — LifePlanner</title>
    <link rel="stylesheet" href="./stylesDashboard.css">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
    <script src="../../assets/global.js"></script>
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links" id="nav-links">
    <?php foreach (array_slice($navLinks, 0, 3) as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
    <?php $catLinks = array_slice($navLinks, 3); if (!empty($catLinks)): ?>
    <li class="nav-dropdown">
      <button class="nav-dropdown-btn" onclick="toggleNavDropdown(this)">Categorias ▾</button>
      <ul class="nav-dropdown-menu">
        <?php foreach ($catLinks as [$href, $label]): ?>
        <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </li>
    <?php endif; ?>
  </ul>
  <div class="nav-controls">
    <ul class="nav-right">
      <li><a href="../settings/settings.php">Definições</a></li>
      <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
    </ul>
    <button id="theme-toggle" class="nav-icon-btn" title="Mudar tema"></button>
    <button id="nav-hamburger" class="nav-icon-btn" title="Menu" aria-expanded="false">
      <span class="bar"></span><span class="bar"></span><span class="bar"></span>
    </button>
  </div>
</nav>

<div class="page">
  <div class="page-header">
    <h1 class="page-title">Dashboard <span><?= e($mesesFull[$month] . ' ' . $year) ?></span></h1>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>

  <?php if ($overBudgetCount > 0): ?>
  <div class="alert alert-warning">
    ⚠ <?= $overBudgetCount ?> categoria<?= $overBudgetCount > 1 ? 's' : '' ?> ultrapassou o orçamento este mês.
  </div>
  <?php endif; ?>

  <?php if (!empty($pendingRecurring)): ?>
  <div class="alert alert-warning">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;">
      <div>
        <strong><?= count($pendingRecurring) ?> despesa<?= count($pendingRecurring) !== 1 ? 's' : '' ?> recorrente<?= count($pendingRecurring) !== 1 ? 's' : '' ?> por aplicar em <?= e($mesesFull[$month]) ?>:</strong>
        <ul style="margin:5px 0 0 16px;padding:0;font-size:0.87rem;">
          <?php foreach ($pendingRecurring as $pr): ?>
          <li><?= e($pr['description']) ?> — <?= number_format((float)$pr['amount'], 2, ',', '.') ?> €</li>
          <?php endforeach; ?>
        </ul>
      </div>
      <form method="post" action="dashboard.php" style="flex-shrink:0;margin-top:2px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="apply_recurring">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <button type="submit" class="btn btn-sm" style="white-space:nowrap">Aplicar agora</button>
      </form>
    </div>
  </div>
  <?php endif; ?>

  <!-- Filtros -->
  <form action="" method="get">
    <div class="filter-bar">
      <div class="filter-group">
        <label>Ano</label>
        <select name="year">
          <?php for ($i = 2026; $i <= 2070; $i++): ?>
          <option value="<?= $i ?>" <?= $i == $year ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Mês</label>
        <select name="month">
          <?php foreach ($mesesFull as $num => $nome): ?>
          <option value="<?= $num ?>" <?= $num == $month ? 'selected' : '' ?>><?= e($nome) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>NIF</label>
        <div class="radio-group">
          <label><input type="radio" name="nif" value="all" <?= $nif == 'all' ? 'checked' : '' ?>> Todos</label>
          <label><input type="radio" name="nif" value="0"   <?= $nif == '0'   ? 'checked' : '' ?>> Não</label>
          <label><input type="radio" name="nif" value="1"   <?= $nif == '1'   ? 'checked' : '' ?>> Sim</label>
        </div>
      </div>
      <button type="submit" class="btn">Filtrar</button>
    </div>
  </form>

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-card red">
      <div class="kpi-label">Total Gasto</div>
      <div class="kpi-value"><?= number_format($totalGasto, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">Total Rendimentos</div>
      <div class="kpi-value"><?= number_format($totalIncome, 2, ',', '.') ?> €</div>
      <?php if ($otherIncome > 0): ?>
      <div class="kpi-note">Ordenado <?= number_format($salary, 2, ',', '.') ?> € + outros <?= number_format($otherIncome, 2, ',', '.') ?> €</div>
      <?php endif; ?>
    </div>
    <div class="kpi-card <?= $restante >= 0 ? 'green' : 'red' ?>">
      <div class="kpi-label">Saldo Restante</div>
      <div class="kpi-value"><?= number_format($restante, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">% Gasto</div>
      <div class="kpi-value"><?= number_format($percentSpent, 1, ',', '.') ?>%</div>
      <div class="kpi-note">
        <?php if ($diffAnteriorPct === null): ?>
          Sem comparação anterior
        <?php else: ?>
          <?= $diffAnterior >= 0 ? '+' : '' ?><?= number_format($diffAnteriorPct, 1, ',', '.') ?>% vs mês anterior
        <?php endif; ?>
      </div>
    </div>
    <?php if ($forecast !== null): ?>
    <div class="kpi-card orange">
      <div class="kpi-label">Previsão Fim do Mês</div>
      <div class="kpi-value"><?= number_format($forecast, 2, ',', '.') ?> €</div>
      <div class="kpi-note">Dia <?= $daysElapsed ?> de <?= $daysInMonth ?></div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Grelha principal -->
  <div class="main-grid">

    <!-- Tabela de categorias + gráfico semanal -->
    <div>
      <div class="card" style="margin-bottom:14px;">
        <div class="card-title">Despesas por Categoria — <?= e($mesesFull[$month]) ?></div>
        <table class="data-table">
          <thead>
            <tr>
              <th>Categoria</th>
              <th style="text-align:right">Gasto</th>
              <th style="text-align:right">Orçamento</th>
              <th style="text-align:right">Estado</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($categoryRows as $row):
              $amount = (float)$row['total'];
              $budget = (float)$row['budget'];
              $budgetPct = $budget > 0 ? min(999, round(($amount / $budget) * 100, 1)) : null;
            ?>
            <tr>
              <td class="cat-name"><?= e($row['categoria']) ?></td>
              <td style="text-align:right" class="<?= $amount > 0 ? 'amount-pos' : 'amount-zero' ?>">
                <?= number_format($amount, 2, ',', '.') ?> €
              </td>
              <td style="text-align:right"><?= $budget > 0 ? number_format($budget, 2, ',', '.') . ' €' : '—' ?></td>
              <td style="text-align:right">
                <?php if ($budget <= 0): ?>
                  <span class="status-muted">Sem limite</span>
                <?php elseif ($budgetPct <= 80): ?>
                  <span class="status-ok"><?= number_format($budgetPct, 1, ',', '.') ?>%</span>
                <?php elseif ($budgetPct <= 100): ?>
                  <span class="status-warn"><?= number_format($budgetPct, 1, ',', '.') ?>%</span>
                <?php else: ?>
                  <span class="status-danger">⚠ <?= number_format($budgetPct, 1, ',', '.') ?>%</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <div class="card-title">Gastos por Semana — <?= e($mesesFull[$month]) ?></div>
        <canvas id="weeklyChart" height="160"></canvas>
      </div>
    </div>

    <!-- Lado direito: ordenado + pie + top categorias -->
    <div>
      <div class="card tab-card" style="margin-bottom:14px;">
        <div class="tab-bar">
          <button class="tab-btn active" onclick="switchTab(this,'tab-salary')">Ordenado</button>
          <button class="tab-btn" onclick="switchTab(this,'tab-income')">Outros Rendimentos</button>
        </div>
        <div class="tab-panel active" id="tab-salary">
          <form class="salary-panel" action="../../config/save_salary.php" method="post">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <?= csrf_field() ?>
            <label>Valor para <?= e($mesesFull[$month]) ?></label>
            <input type="number" step="0.01" name="salary" value="<?= e($salary) ?>" required>
            <button type="submit" class="btn" style="width:100%">Guardar</button>
          </form>
        </div>
        <div class="tab-panel" id="tab-income">
          <?php if (!empty($incomeEntries)): ?>
          <div style="margin-bottom:10px;">
            <?php foreach ($incomeEntries as $ie): ?>
            <div class="income-row">
              <div class="income-row-info">
                <div class="income-row-name"><?= e($ie['description']) ?></div>
                <div class="income-row-date"><?= date('d/m/Y', strtotime($ie['date'])) ?></div>
              </div>
              <div class="income-row-right">
                <span class="income-amount">+<?= number_format((float)$ie['amount'], 2, ',', '.') ?> €</span>
                <form method="post" action="dashboard.php" style="display:inline;margin:0;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete_income">
                  <input type="hidden" name="income_id" value="<?= (int)$ie['id'] ?>">
                  <input type="hidden" name="year" value="<?= $year ?>">
                  <input type="hidden" name="month" value="<?= $month ?>">
                  <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Remover rendimento?')" style="margin:0;">×</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <form method="post" action="dashboard.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_income">
            <input type="hidden" name="year" value="<?= $year ?>">
            <input type="hidden" name="month" value="<?= $month ?>">
            <div class="income-form">
              <input type="text" name="income_desc" placeholder="Descrição (ex: Freelance)" maxlength="100" required>
              <div class="income-form-row">
                <input type="number" name="income_amount" step="0.01" min="0.01" placeholder="Valor (€)" required>
                <input type="date" name="income_date" value="<?= date('Y-m-d', mktime(0,0,0,$month,1,$year)) ?>" required>
              </div>
              <button type="submit" class="btn" style="width:100%">Adicionar</button>
            </div>
          </form>
        </div>
      </div>

      <div class="card" style="margin-bottom:14px;">
        <div class="card-title">Distribuição — <?= e($mesesFull[$month]) ?></div>
        <canvas id="pieChart" height="220"></canvas>
      </div>

      <?php if (!empty($topCategories)): ?>
      <div class="card">
        <div class="card-title">Top Categorias</div>
        <div class="top-cat-row">
          <?php foreach ($topCategories as $tc):
            $pct = $maxTop > 0 ? round(($tc['total'] / $maxTop) * 100) : 0;
            $colors = ['#5b8bff','#ff6b6b','#3ecf8e','#f9c851','#c77dff','#4cc9f0'];
            $color  = $colors[array_search($tc, $topCategories) % count($colors)];
          ?>
          <div class="top-cat-item">
            <div class="top-cat-meta">
              <span class="top-cat-name"><?= e($tc['categoria']) ?></span>
              <span class="top-cat-amount"><?= number_format($tc['total'], 2, ',', '.') ?> €</span>
            </div>
            <div class="progress-bar">
              <div class="progress-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Metas de Poupança -->
  <?php if (!empty($savingsGoals)): ?>
  <div class="card" style="margin-bottom:20px;">
    <div class="card-title" style="display:flex;justify-content:space-between;align-items:center;">
      <span>Metas de Poupança</span>
      <a href="../settings/settings.php#goals" class="btn btn-sm btn-ghost">Gerir</a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
      <?php foreach ($savingsGoals as $g):
        $gpct = $g['target_amount'] > 0 ? min(100, round(($g['current_amount'] / $g['target_amount']) * 100)) : 0;
        $gcolor = $gpct >= 100 ? 'var(--green)' : ($gpct >= 60 ? 'var(--accent)' : 'var(--orange)');
      ?>
      <div class="goal-item">
        <div class="goal-meta">
          <span class="goal-name"><?= e($g['name']) ?></span>
          <span class="goal-pct" style="color:<?= $gcolor ?>"><?= $gpct ?>%</span>
        </div>
        <div class="goal-bar">
          <div class="goal-fill" style="width:<?= $gpct ?>%;background:<?= $gcolor ?>"></div>
        </div>
        <div class="goal-amounts">
          <span><?= number_format((float)$g['current_amount'], 2, ',', '.') ?> €</span>
          <span>de <?= number_format((float)$g['target_amount'], 2, ',', '.') ?> €</span>
        </div>
        <?php if ($g['deadline']): ?>
        <div class="goal-deadline">Prazo: <?= date('d/m/Y', strtotime($g['deadline'])) ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- Gráficos -->
  <div style="margin-bottom:20px;">
    <div class="card" style="margin-bottom:14px;">
      <div class="card-title">Saldo Restante por Mês — <?= $year ?></div>
      <canvas id="balanceChart" height="150"></canvas>
    </div>

    <div class="card" style="margin-bottom:14px;">
      <div class="card-title">Ordenado vs Gasto vs Saldo — <?= $year ?></div>
      <canvas id="overviewChart" height="100"></canvas>
    </div>

    <div class="card">
      <div class="card-title"><?= $year ?> vs <?= $yearPrev ?> — Gastos por Mês</div>
      <canvas id="compareChart" height="110"></canvas>
    </div>
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  window.__LP_CHARTS = [];
  const isDark = () => document.documentElement.getAttribute('data-theme') === 'dark';
  const chartColor = () => isDark() ? '#8892a4' : '#6b7280';
  const gridColor  = () => isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)';

  function baseScales() {
    return {
      x: { grid: { color: gridColor() }, ticks: { color: chartColor() } },
      y: { grid: { color: gridColor() }, ticks: { color: chartColor(), callback: v => '€ ' + v.toLocaleString('pt-PT') } }
    };
  }

  const labels    = <?= json_encode(array_values($meses)) ?>;
  const salary    = <?= '[' . implode(',', $jsSalary) . ']' ?>;
  const balance   = <?= '[' . implode(',', $jsBalance) . ']' ?>;
  const spent     = <?= '[' . implode(',', $jsSpent) . ']' ?>;
  const spentPrev = <?= '[' . implode(',', $jsSpentPrev) . ']' ?>;
  const pieData   = <?= json_encode($dataPoints) ?>;
  const weekly    = <?= json_encode($weeklyData) ?>;
  const weekLabels = ['Semana 1','Semana 2','Semana 3','Semana 4'];

  Chart.defaults.color = chartColor();
  Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
  Chart.defaults.font.size = 11;

  const COLORS = ['#5b8bff','#ff6b6b','#3ecf8e','#f9c851','#c77dff','#4cc9f0','#f77f00','#7bed9f','#ff4757','#2ed573'];

  // Pie
  window.__LP_CHARTS.push(new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
      labels: pieData.map(d => d.label),
      datasets: [{ data: pieData.map(d => d.y), backgroundColor: COLORS, borderWidth: 2, borderColor: 'transparent' }]
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { color: chartColor(), boxWidth: 12, padding: 10 } },
        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: € ${ctx.parsed.toLocaleString('pt-PT', {minimumFractionDigits:2})}` } }
      }
    }
  }));

  // Semanal
  window.__LP_CHARTS.push(new Chart(document.getElementById('weeklyChart'), {
    type: 'bar',
    data: {
      labels: weekLabels,
      datasets: [{ label: 'Gasto', data: weekly, backgroundColor: COLORS.slice(0,4), borderRadius: 5, borderWidth: 0 }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: baseScales()
    }
  }));

  // Balance
  window.__LP_CHARTS.push(new Chart(document.getElementById('balanceChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Saldo Final',
        data: balance,
        backgroundColor: balance.map(v => v === null ? 'transparent' : v >= 0 ? 'rgba(62,207,142,0.75)' : 'rgba(255,107,107,0.75)'),
        borderColor:     balance.map(v => v === null ? 'transparent' : v >= 0 ? '#3ecf8e' : '#ff6b6b'),
        borderWidth: 1, borderRadius: 5
      }]
    },
    options: { plugins: { legend: { display: false } }, scales: baseScales() }
  }));

  // Overview
  window.__LP_CHARTS.push(new Chart(document.getElementById('overviewChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Ordenado',    data: salary,  borderColor: '#5b8bff', backgroundColor: 'rgba(91,139,255,0.07)', tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#5b8bff' },
        { label: 'Total Gasto', data: spent,   borderColor: '#ff6b6b', backgroundColor: 'rgba(255,107,107,0.07)', tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#ff6b6b' },
        { label: 'Saldo Final', data: balance, borderColor: '#3ecf8e', backgroundColor: 'rgba(62,207,142,0.07)', tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#3ecf8e' }
      ]
    },
    options: { plugins: { legend: { labels: { color: chartColor(), boxWidth: 12 } } }, scales: baseScales() }
  }));

  // Comparacao anos
  window.__LP_CHARTS.push(new Chart(document.getElementById('compareChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: '<?= $year ?>',     data: spent,     backgroundColor: 'rgba(91,139,255,0.75)',  borderRadius: 4, borderWidth: 0 },
        { label: '<?= $yearPrev ?>', data: spentPrev, backgroundColor: 'rgba(107,114,128,0.4)', borderRadius: 4, borderWidth: 0 }
      ]
    },
    options: {
      plugins: { legend: { labels: { color: chartColor(), boxWidth: 12 } } },
      scales: baseScales()
    }
  }));

  function switchTab(btn, panelId) {
    const card = btn.closest('.tab-card');
    card.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    card.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(panelId).classList.add('active');
  }
</script>

</body>
</html>
