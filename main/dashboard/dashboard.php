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

    // Aplicar recorrentes se pedido
    if (isset($_GET['apply_recurring']) && $_GET['apply_recurring'] === '1') {
        verify_csrf_token();
        $n = apply_recurring_for_month($conn, $user_id, $year, $month);
        header("Location: dashboard.php?year=$year&month=$month&msg=" . ($n > 0 ? 'recurring_applied' : 'recurring_applied'));
        exit();
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
    $restante = $salary - $totalGasto;
    $percentSpent = $salary > 0 ? min(999, round(($totalGasto / $salary) * 100, 1)) : 0;

    // Total gasto sem filtro NIF (para sync)
    $stmtTotalAll = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
    $stmtTotalAll->bind_param("iii", $user_id, $year, $month);
    $stmtTotalAll->execute();
    $totalGastoAll = (float)($stmtTotalAll->get_result()->fetch_assoc()['total'] ?? 0);
    $restanteAll = $salary - $totalGastoAll;

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
    $today = new DateTime();
    $currentYear  = (int)$today->format('Y');
    $currentMonth = (int)$today->format('n');
    $forecast = null;
    $daysElapsed = null;
    $daysInMonth = null;
    if ($year === $currentYear && $month === $currentMonth && $totalGasto > 0) {
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

    // Ultimos movimentos
    $stmtLatest = $conn->prepare("
        SELECT t.amount, t.date, t.description, t.nif, c.name AS categoria
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = ?
        ORDER BY t.date DESC, t.id DESC
        LIMIT 8
    ");
    $stmtLatest->bind_param("i", $user_id);
    $stmtLatest->execute();
    $latestTransactions = [];
    $resLatest = $stmtLatest->get_result();
    while ($tx = $resLatest->fetch_assoc()) {
        $latestTransactions[] = $tx;
    }

    // Metas de poupança
    $savingsGoals = get_savings_goals($conn, $user_id);

    // Verificar recorrentes pendentes para este mes
    $recurring = get_recurring_expenses($conn, $user_id);
    $pendingRecurring = 0;
    foreach ($recurring as $r) {
        if (!$r['active']) continue;
        $rid = (int)$r['id'];
        $chk = $conn->prepare("SELECT 1 FROM recurring_applied WHERE recurring_id=? AND user_id=? AND year=? AND month=?");
        $chk->bind_param("iiii", $rid, $user_id, $year, $month);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            $pendingRecurring++;
        }
    }

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
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
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

  <?php if ($pendingRecurring > 0): ?>
  <div class="alert alert-warning" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
    <span>📋 <?= $pendingRecurring ?> despesa<?= $pendingRecurring > 1 ? 's' : '' ?> recorrente<?= $pendingRecurring > 1 ? 's' : '' ?> por aplicar em <?= e($mesesFull[$month]) ?>.</span>
    <a href="dashboard.php?year=<?= $year ?>&month=<?= $month ?>&apply_recurring=1&csrf_token=<?= e(csrf_token()) ?>" class="btn btn-sm" style="white-space:nowrap">Aplicar agora</a>
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
      <div class="kpi-label">Ordenado</div>
      <div class="kpi-value"><?= number_format($salary, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card <?= $restante >= 0 ? 'green' : 'red' ?>">
      <div class="kpi-label">Saldo Restante</div>
      <div class="kpi-value"><?= number_format($restante, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">% do Ordenado</div>
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

    <!-- Tabela de categorias -->
    <div class="card">
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

    <!-- Lado direito: ordenado + pie + top categorias -->
    <div>
      <div class="card salary-panel" style="margin-bottom:14px;">
        <div class="card-title">Definir Ordenado</div>
        <form action="../../config/save_salary.php" method="post">
          <input type="hidden" name="year" value="<?= $year ?>">
          <input type="hidden" name="month" value="<?= $month ?>">
          <?= csrf_field() ?>
          <label>Valor para <?= e($mesesFull[$month]) ?></label>
          <input type="number" step="0.01" name="salary" value="<?= e($salary) ?>" required>
          <button type="submit" class="btn" style="width:100%">Guardar</button>
        </form>
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

  <!-- Ultimos movimentos -->
  <div class="card" style="margin-bottom:20px;">
    <div class="card-title collapse-header" onclick="toggleLatest()">
      <span>Últimos Movimentos</span>
      <span class="collapse-icon" id="latest-icon">+</span>
    </div>
    <div id="latest-content" style="display:none;">
      <?php if (empty($latestTransactions)): ?>
        <p class="text-muted">Ainda sem movimentos registados.</p>
      <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Data</th>
            <th>Categoria</th>
            <th>Descrição</th>
            <th>NIF</th>
            <th style="text-align:right">Valor</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($latestTransactions as $tx): ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($tx['date'])) ?></td>
            <td><?= e($tx['categoria'] ?? 'Sem categoria') ?></td>
            <td><?= e($tx['description']) ?></td>
            <td><?= (int)$tx['nif'] === 1 ? 'Sim' : 'Não' ?></td>
            <td style="text-align:right" class="amount-pos"><?= number_format((float)$tx['amount'], 2, ',', '.') ?> €</td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Gráficos -->
  <div style="margin-bottom:20px;">
    <div class="charts-grid">
      <div class="card">
        <div class="card-title">Gastos por Semana — <?= e($mesesFull[$month]) ?></div>
        <canvas id="weeklyChart" height="200"></canvas>
      </div>
      <div class="card">
        <div class="card-title">Saldo Restante por Mês — <?= $year ?></div>
        <canvas id="balanceChart" height="200"></canvas>
      </div>
    </div>

    <div class="card" style="margin-bottom:14px;">
      <div class="card-title">Ordenado vs Gasto vs Saldo — <?= $year ?></div>
      <canvas id="overviewChart" height="120"></canvas>
    </div>

    <div class="card">
      <div class="card-title"><?= $year ?> vs <?= $yearPrev ?> — Gastos por Mês</div>
      <canvas id="compareChart" height="140"></canvas>
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

  function toggleLatest() {
    const content = document.getElementById('latest-content');
    const icon    = document.getElementById('latest-icon');
    const open = content.style.display === 'none';
    content.style.display = open ? 'block' : 'none';
    icon.textContent = open ? '−' : '+';
    icon.classList.toggle('open', open);
  }
</script>

</body>
</html>
