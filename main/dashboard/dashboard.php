<?php
    session_start();
    include_once "../../config/bd.php";
    include_once "../../config/security.php";
    include_once "../../config/repository.php";

    require_login("../../index.php");

    $user_id = $_SESSION['user_id'];
    $year  = validate_year($_GET['year'] ?? null);
    $month = validate_month($_GET['month'] ?? null);
    $nif   = validate_nif_filter($_GET['nif'] ?? 'all');
    $success = flash_message($_GET['msg'] ?? '');

    $meses = [
        1=>"Jan",2=>"Fev",3=>"Mar",4=>"Abr",5=>"Mai",6=>"Jun",
        7=>"Jul",8=>"Ago",9=>"Set",10=>"Out",11=>"Nov",12=>"Dez"
    ];
    $mesesFull = [
        1=>"Janeiro",2=>"Fevereiro",3=>"Marco",4=>"Abril",5=>"Maio",6=>"Junho",
        7=>"Julho",8=>"Agosto",9=>"Setembro",10=>"Outubro",11=>"Novembro",12=>"Dezembro"
    ];

    $stmtSalary = $conn->prepare("SELECT salary FROM monthly_summary WHERE user_id = ? AND year = ? AND month = ?");
    $stmtSalary->bind_param("iii", $user_id, $year, $month);
    $stmtSalary->execute();
    $resSalary = $stmtSalary->get_result()->fetch_assoc();
    $salary = $resSalary ? (float)$resSalary['salary'] : 0.00;

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

    $stmtTotalAll = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
    $stmtTotalAll->bind_param("iii", $user_id, $year, $month);
    $stmtTotalAll->execute();
    $totalGastoAll = (float)($stmtTotalAll->get_result()->fetch_assoc()['total'] ?? 0);
    $restanteAll = $salary - $totalGastoAll;

    $prevMonth = $month === 1 ? 12 : $month - 1;
    $prevYear = $month === 1 ? $year - 1 : $year;
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
    while ($row = $result->fetch_assoc()) {
        $row['total'] = (float)$row['total'];
        $row['budget'] = (float)$row['budget'];
        $categoryRows[] = $row;
        $dataPoints[] = ["label" => $row['categoria'], "y" => $row['total']];
    }

    $stmtAnual = $conn->prepare("
        SELECT month, salary, final_balance, total_spent
        FROM monthly_summary
        WHERE user_id = ? AND year = ?
        ORDER BY month ASC
    ");
    $stmtAnual->bind_param("ii", $user_id, $year);
    $stmtAnual->execute();
    $resAnual = $stmtAnual->get_result();

    $salaryAnual = array_fill(1, 12, null);
    $balanceAnual = array_fill(1, 12, null);
    $spentAnual = array_fill(1, 12, null);
    while ($row = $resAnual->fetch_assoc()) {
        $m = (int)$row['month'];
        $salaryAnual[$m] = (float)$row['salary'];
        $balanceAnual[$m] = (float)$row['final_balance'];
        $spentAnual[$m] = (float)$row['total_spent'];
    }

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

    $jsLabels = [];
    $jsSalary = [];
    $jsBalance = [];
    $jsSpent = [];
    for ($m = 1; $m <= 12; $m++) {
        $jsLabels[] = $meses[$m];
        $jsSalary[] = $salaryAnual[$m] !== null ? $salaryAnual[$m] : 'null';
        $jsBalance[] = $balanceAnual[$m] !== null ? $balanceAnual[$m] : 'null';
        $jsSpent[] = $spentAnual[$m] !== null ? $spentAnual[$m] : 'null';
    }

    $all_categories = get_categories($conn);
    $navLinks = build_nav_links($all_categories);
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Financeiro</title>
    <link rel="stylesheet" href="./stylesDashboard.css">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
</head>
<body>

<nav>
  <span class="nav-brand">Life Planner</span>
  <ul class="nav-links">
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="nav-right">
    <li><a href="../settings/settings.php">Definições</a></li>
    <li><a href="../../config/logout.php" class="btn btn-danger">Sair</a></li>
  </ul>
</nav>

<div class="page">
  <div class="page-header">
    <h1 class="page-title">Dashboard <span><?= e($mesesFull[$month] . ' ' . $year) ?></span></h1>
  </div>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
  <?php endif; ?>

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
        <label>Mes</label>
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
          <label><input type="radio" name="nif" value="0" <?= $nif == '0' ? 'checked' : '' ?>> Nao</label>
          <label><input type="radio" name="nif" value="1" <?= $nif == '1' ? 'checked' : '' ?>> Sim</label>
        </div>
      </div>
      <button type="submit" class="btn">Filtrar</button>
    </div>
  </form>

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
          Sem comparacao anterior
        <?php else: ?>
          <?= $diffAnterior >= 0 ? '+' : '' ?><?= number_format($diffAnteriorPct, 1, ',', '.') ?>% vs mes anterior
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="main-grid">
    <div class="card">
      <div class="card-title">Despesas por Categoria - <?= e($mesesFull[$month]) ?></div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Categoria</th>
            <th style="text-align:right">Valor Gasto</th>
            <th style="text-align:right">Orcamento</th>
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
            <td style="text-align:right"><?= $budget > 0 ? number_format($budget, 2, ',', '.') . ' €' : '-' ?></td>
            <td style="text-align:right">
              <?php if ($budget <= 0): ?>
                <span class="status-muted">Sem limite</span>
              <?php elseif ($amount <= $budget): ?>
                <span class="status-ok"><?= number_format($budgetPct, 1, ',', '.') ?>%</span>
              <?php else: ?>
                <span class="status-danger"><?= number_format($budgetPct, 1, ',', '.') ?>%</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div>
      <div class="card salary-panel" style="margin-bottom:16px;">
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

      <div class="card">
        <div class="card-title">Distribuicao - <?= e($mesesFull[$month]) ?></div>
        <canvas id="pieChart" height="240"></canvas>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-title" style="display: flex; justify-content: space-between; align-items: center; cursor: pointer;" onclick="toggleLatest()">
        Ultimos Movimentos
        <span id="latest-icon">+</span>
      </div>
      <div id="latest-content" style="display: none;">
        <?php if (empty($latestTransactions)): ?>
          <p class="text-muted">Ainda sem movimentos registados.</p>
        <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Data</th>
              <th>Categoria</th>
              <th>Descricao</th>
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
              <td><?= (int)$tx['nif'] === 1 ? 'Sim' : 'Nao' ?></td>
              <td style="text-align:right" class="amount-pos"><?= number_format((float)$tx['amount'], 2, ',', '.') ?> €</td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
  </div>
  <br>

  <div class="charts-section">
    <div class="charts-grid">
      <div class="card">
        <div class="card-title">Salario Mensal - <?= $year ?></div>
        <canvas id="salaryChart" height="200"></canvas>
      </div>
      <div class="card">
        <div class="card-title">Saldo Restante por Mes - <?= $year ?></div>
        <canvas id="balanceChart" height="200"></canvas>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Visao Geral Anual - Ordenado vs Gasto vs Saldo (<?= $year ?>)</div>
      <canvas id="overviewChart" height="120"></canvas>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const labels  = <?= json_encode(array_values($meses)) ?>;
  const salary  = <?= '[' . implode(',', $jsSalary) . ']' ?>;
  const balance = <?= '[' . implode(',', $jsBalance) . ']' ?>;
  const spent   = <?= '[' . implode(',', $jsSpent) . ']' ?>;
  const pieData = <?= json_encode($dataPoints) ?>;

  Chart.defaults.color = '#6b7280';
  Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
  Chart.defaults.font.size = 11;

  function baseScales() {
    return {
      x: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { color: '#6b7080' } },
      y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { color: '#6b7080', callback: v => '€ ' + v.toLocaleString('pt-PT') } }
    };
  }

  new Chart(document.getElementById('pieChart'), {
    type: 'doughnut',
    data: {
      labels: pieData.map(d => d.label),
      datasets: [{
        data: pieData.map(d => d.y),
        backgroundColor: ['#5b8bff','#ff6b6b','#3ecf8e','#f9c851','#c77dff','#4cc9f0','#f77f00','#7bed9f','#ff4757','#2ed573'],
        borderWidth: 2,
        borderColor: '#fff'
      }]
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { color: '#6b7080', boxWidth: 12, padding: 10 } },
        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: € ${ctx.parsed.toLocaleString('pt-PT', {minimumFractionDigits:2})}` } }
      }
    }
  });

  new Chart(document.getElementById('salaryChart'), {
    type: 'bar',
    data: { labels, datasets: [{ label: 'Ordenado', data: salary, backgroundColor: 'rgba(91,139,255,0.7)', borderColor: '#5b8bff', borderWidth: 1, borderRadius: 5 }] },
    options: { plugins: { legend: { display: false } }, scales: baseScales() }
  });

  new Chart(document.getElementById('balanceChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Saldo Final',
        data: balance,
        backgroundColor: balance.map(v => v === null ? 'transparent' : v >= 0 ? 'rgba(62,207,142,0.7)' : 'rgba(255,107,107,0.7)'),
        borderColor: balance.map(v => v === null ? 'transparent' : v >= 0 ? '#3ecf8e' : '#ff6b6b'),
        borderWidth: 1,
        borderRadius: 5
      }]
    },
    options: { plugins: { legend: { display: false } }, scales: baseScales() }
  });

  new Chart(document.getElementById('overviewChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label: 'Ordenado', data: salary, borderColor: '#5b8bff', backgroundColor: 'rgba(91,139,255,0.08)', tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#5b8bff' },
        { label: 'Total Gasto', data: spent, borderColor: '#ff6b6b', backgroundColor: 'rgba(255,107,107,0.08)', tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#ff6b6b' },
        { label: 'Saldo Final', data: balance, borderColor: '#3ecf8e', backgroundColor: 'rgba(62,207,142,0.08)', tension: 0.3, fill: true, pointRadius: 4, pointBackgroundColor: '#3ecf8e' }
      ]
    },
    options: {
      plugins: { legend: { labels: { color: '#6b7080', boxWidth: 12 } } },
      scales: baseScales()
    }
  });

  function toggleLatest() {
    const content = document.getElementById('latest-content');
    const icon = document.getElementById('latest-icon');
    
    if (content.style.display === "none") {
      content.style.display = "block";
      icon.innerText = "−"; 
    } else {
      content.style.display = "none";
      icon.innerText = "+"; 
    }
  }
</script>

</body>
</html>
