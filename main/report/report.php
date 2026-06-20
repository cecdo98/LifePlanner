<?php
    session_start();
    include_once "../../config/bd.php";
    include_once "../../config/security.php";
    include_once "../../config/repository.php";

    require_login("../../index.php");

    $user_id = $_SESSION['user_id'];
    $year    = validate_year($_GET['year'] ?? null);

    $all_categories = get_categories($conn);
    $navLinks       = build_nav_links($all_categories);

    $mesesFull = [
        1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
        7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro',
    ];
    $mesesAbrev = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
                   7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];

    // ── Resumo mensal + rendimentos extra ─────────────────────────
    $stmtM = $conn->prepare("
        SELECT m.month,
               m.salary,
               m.total_spent,
               COALESCE(i.other_income, 0) AS other_income
        FROM monthly_summary m
        LEFT JOIN (
            SELECT month, SUM(amount) AS other_income
            FROM income_entries
            WHERE user_id = ? AND year = ?
            GROUP BY month
        ) i ON i.month = m.month
        WHERE m.user_id = ? AND m.year = ?
        ORDER BY m.month ASC
    ");
    $stmtM->bind_param("iiii", $user_id, $year, $user_id, $year);
    $stmtM->execute();
    $monthlyRows = $stmtM->get_result()->fetch_all(MYSQLI_ASSOC);

    // ── Breakdown por categoria no ano ────────────────────────────
    $stmtCat = $conn->prepare("
        SELECT COALESCE(c.name,'Sem categoria') AS name,
               SUM(t.amount) AS total
        FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.user_id = ? AND YEAR(t.date) = ?
        GROUP BY c.id, c.name
        ORDER BY total DESC
    ");
    $stmtCat->bind_param("ii", $user_id, $year);
    $stmtCat->execute();
    $catRows = $stmtCat->get_result()->fetch_all(MYSQLI_ASSOC);

    // ── Construir arrays mensais ──────────────────────────────────
    $monthlyMap = [];
    foreach ($monthlyRows as $r) {
        $monthlyMap[(int)$r['month']] = $r;
    }

    $annualIncome = $annualSpent = $annualSavings = 0;
    $bestSavings = PHP_INT_MIN; $worstSavings = PHP_INT_MAX;
    $bestMonth = $worstMonth = null;
    $monthsWithData = 0;

    $jsLabels = $jsIncome = $jsSpent = $jsSavings = $jsSavRate = [];

    for ($m = 1; $m <= 12; $m++) {
        $jsLabels[] = $mesesAbrev[$m];
        if (isset($monthlyMap[$m])) {
            $r        = $monthlyMap[$m];
            $inc      = (float)$r['salary'] + (float)$r['other_income'];
            $spent    = (float)$r['total_spent'];
            $savings  = $inc - $spent;
            $savRate  = $inc > 0 ? round($savings / $inc * 100, 1) : 0;

            $annualIncome  += $inc;
            $annualSpent   += $spent;
            $annualSavings += $savings;
            $monthsWithData++;

            if ($inc > 0) {
                if ($savings > $bestSavings)  { $bestSavings  = $savings; $bestMonth  = $m; }
                if ($savings < $worstSavings) { $worstSavings = $savings; $worstMonth = $m; }
            }

            $jsIncome[]  = round($inc, 2);
            $jsSpent[]   = round($spent, 2);
            $jsSavings[] = round($savings, 2);
            $jsSavRate[] = $savRate;
        } else {
            $jsIncome[]  = 'null';
            $jsSpent[]   = 'null';
            $jsSavings[] = 'null';
            $jsSavRate[] = 'null';
        }
    }

    $annualSavRate = $annualIncome > 0 ? round($annualSavings / $annualIncome * 100, 1) : 0;
    $avgMonthlySavings = $monthsWithData > 0 ? $annualSavings / $monthsWithData : 0;
    $totalCatSpent = array_sum(array_column($catRows, 'total'));
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Relatório <?= $year ?> — LifePlanner</title>
  <link rel="stylesheet" href="./stylesReport.css">
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
  <script src="../../assets/global.js"></script>
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links" id="nav-links">
    <?php foreach (array_slice($navLinks, 0, 3) as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>" <?= strpos($href, 'report') !== false ? 'class="active"' : '' ?>><?= e($label) ?></a></li>
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
      <li><a href="../../config/logout.php" class="btn-danger-nav">Sair</a></li>
    </ul>
    <button id="theme-toggle" class="nav-icon-btn" title="Mudar tema"></button>
    <button id="nav-hamburger" class="nav-icon-btn" title="Menu" aria-expanded="false">
      <span class="bar"></span><span class="bar"></span><span class="bar"></span>
    </button>
  </div>
</nav>

<div class="page">

  <div class="page-header">
    <h1 class="page-title">Relatório <span><?= $year ?></span></h1>
    <form method="get" action="" class="year-bar">
      <select name="year" onchange="this.form.submit()">
        <?php for ($y = (int)date('Y'); $y >= 2026; $y--): ?>
        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <?php if ($monthsWithData === 0): ?>
  <div class="empty-state">Sem dados para <?= $year ?>. Adiciona despesas e rendimentos no dashboard para gerares um relatório.</div>
  <?php else: ?>

  <!-- KPIs anuais -->
  <div class="kpi-row">
    <div class="kpi-card blue">
      <div class="kpi-label">Total Rendimentos</div>
      <div class="kpi-value"><?= number_format($annualIncome, 2, ',', '.') ?> €</div>
      <div class="kpi-note"><?= $monthsWithData ?> mês<?= $monthsWithData !== 1 ? 'es' : '' ?> com dados</div>
    </div>
    <div class="kpi-card red">
      <div class="kpi-label">Total Gasto</div>
      <div class="kpi-value"><?= number_format($annualSpent, 2, ',', '.') ?> €</div>
      <div class="kpi-note">Média <?= number_format($monthsWithData > 0 ? $annualSpent / $monthsWithData : 0, 2, ',', '.') ?> €/mês</div>
    </div>
    <div class="kpi-card <?= $annualSavings >= 0 ? 'green' : 'red' ?>">
      <div class="kpi-label">Total Poupado</div>
      <div class="kpi-value"><?= number_format($annualSavings, 2, ',', '.') ?> €</div>
      <div class="kpi-note">Média <?= number_format($avgMonthlySavings, 2, ',', '.') ?> €/mês</div>
    </div>
    <div class="kpi-card <?= $annualSavRate >= 20 ? 'green' : ($annualSavRate >= 10 ? 'orange' : 'red') ?>">
      <div class="kpi-label">Taxa de Poupança</div>
      <div class="kpi-value"><?= number_format($annualSavRate, 1, ',', '.') ?>%</div>
      <div class="kpi-note">
        <?php if ($annualSavRate >= 20): ?>
          Excelente — acima de 20%
        <?php elseif ($annualSavRate >= 10): ?>
          Boa — acima de 10%
        <?php elseif ($annualSavRate > 0): ?>
          Abaixo do ideal (10%)
        <?php else: ?>
          Saldo negativo
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Destaques -->
  <div class="highlight-grid">
    <?php if ($bestMonth): ?>
    <div class="highlight-card">
      <div class="highlight-label">Melhor Mês</div>
      <div class="highlight-month"><?= e($mesesFull[$bestMonth]) ?></div>
      <div class="highlight-val pos">+<?= number_format($bestSavings, 2, ',', '.') ?> € poupados</div>
    </div>
    <?php endif; ?>
    <?php if ($worstMonth): ?>
    <div class="highlight-card">
      <div class="highlight-label">Pior Mês</div>
      <div class="highlight-month"><?= e($mesesFull[$worstMonth]) ?></div>
      <div class="highlight-val <?= $worstSavings < 0 ? 'neg' : 'pos' ?>"><?= number_format($worstSavings, 2, ',', '.') ?> €</div>
    </div>
    <?php endif; ?>
    <?php if (!empty($catRows)): ?>
    <div class="highlight-card">
      <div class="highlight-label">Maior Categoria</div>
      <div class="highlight-month"><?= e($catRows[0]['name']) ?></div>
      <div class="highlight-val neg"><?= number_format((float)$catRows[0]['total'], 2, ',', '.') ?> €</div>
    </div>
    <?php endif; ?>
    <div class="highlight-card">
      <div class="highlight-label">Média Poupança / Mês</div>
      <div class="highlight-month"><?= number_format($avgMonthlySavings, 2, ',', '.') ?> €</div>
      <div class="highlight-val <?= $avgMonthlySavings >= 0 ? 'pos' : 'neg' ?>">
        <?= $avgMonthlySavings >= 0 ? 'positiva' : 'negativa' ?>
      </div>
    </div>
  </div>

  <!-- Gráficos principais -->
  <div class="charts-2col">
    <div class="card">
      <div class="card-title">Rendimentos vs Gastos — <?= $year ?></div>
      <div style="position:relative;height:220px;"><canvas id="incomeSpentChart"></canvas></div>
    </div>
    <div class="card">
      <div class="card-title">Poupança Mensal — <?= $year ?></div>
      <div style="position:relative;height:220px;"><canvas id="savingsChart"></canvas></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Taxa de Poupança por Mês (%) — <?= $year ?></div>
    <div style="position:relative;height:180px;"><canvas id="savRateChart"></canvas></div>
  </div>

  <!-- Categorias do ano -->
  <?php if (!empty($catRows)): ?>
  <div class="cat-report-grid">
    <div class="card" style="margin-bottom:0;">
      <div class="card-title">Distribuição de Gastos</div>
      <canvas id="catPieChart" height="220"></canvas>
    </div>
    <div class="card" style="margin-bottom:0;">
      <div class="card-title">Categorias — <?= $year ?></div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Categoria</th>
            <th class="text-right">Total Gasto</th>
            <th class="text-right">% do Total</th>
            <th>Distribuição</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($catRows as $cr):
            $pct = $totalCatSpent > 0 ? round((float)$cr['total'] / $totalCatSpent * 100, 1) : 0;
          ?>
          <tr>
            <td><?= e($cr['name']) ?></td>
            <td class="text-right val-neg"><?= number_format((float)$cr['total'], 2, ',', '.') ?> €</td>
            <td class="text-right text-muted"><?= $pct ?>%</td>
            <td>
              <div class="mini-bar-wrap">
                <div class="mini-bar"><div class="mini-bar-fill" style="width:<?= $pct ?>%;background:var(--danger);"></div></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div style="margin-bottom:16px;"></div>
  <?php endif; ?>

  <!-- Detalhe mês a mês -->
  <div class="card">
    <div class="card-title">Detalhe Mensal — <?= $year ?></div>
    <table class="data-table">
      <thead>
        <tr>
          <th>Mês</th>
          <th class="text-right">Rendimentos</th>
          <th class="text-right">Gasto</th>
          <th class="text-right">Poupado</th>
          <th class="text-right">Taxa</th>
          <th>Estado</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($m = 1; $m <= 12; $m++):
          if (!isset($monthlyMap[$m])) continue;
          $r       = $monthlyMap[$m];
          $inc     = (float)$r['salary'] + (float)$r['other_income'];
          $spent   = (float)$r['total_spent'];
          $savings = $inc - $spent;
          $rate    = $inc > 0 ? round($savings / $inc * 100, 1) : 0;
          $isBest  = ($m === $bestMonth);
          $isWorst = ($m === $worstMonth && $worstSavings < 0);
        ?>
        <tr class="<?= $isBest ? 'month-row-best' : ($isWorst ? 'month-row-worst' : '') ?>">
          <td style="font-weight:500"><?= e($mesesFull[$m]) ?></td>
          <td class="text-right"><?= number_format($inc, 2, ',', '.') ?> €</td>
          <td class="text-right val-neg"><?= number_format($spent, 2, ',', '.') ?> €</td>
          <td class="text-right <?= $savings >= 0 ? 'val-pos' : 'val-neg' ?>"><?= number_format($savings, 2, ',', '.') ?> €</td>
          <td class="text-right"><?= number_format($rate, 1, ',', '.') ?>%</td>
          <td>
            <?php if ($rate >= 20): ?>
              <span class="badge badge-green">Excelente</span>
            <?php elseif ($rate >= 10): ?>
              <span class="badge badge-green">Boa</span>
            <?php elseif ($rate > 0): ?>
              <span class="badge badge-orange">Fraca</span>
            <?php else: ?>
              <span class="badge badge-red">Negativa</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endfor; ?>
        <!-- Totais -->
        <tr style="border-top:2px solid var(--border);font-weight:700;">
          <td>Total <?= $year ?></td>
          <td class="text-right"><?= number_format($annualIncome, 2, ',', '.') ?> €</td>
          <td class="text-right val-neg"><?= number_format($annualSpent, 2, ',', '.') ?> €</td>
          <td class="text-right <?= $annualSavings >= 0 ? 'val-pos' : 'val-neg' ?>"><?= number_format($annualSavings, 2, ',', '.') ?> €</td>
          <td class="text-right"><?= number_format($annualSavRate, 1, ',', '.') ?>%</td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>

  <?php endif; ?>
</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
  const isDark    = () => document.documentElement.getAttribute('data-theme') === 'dark';
  const textColor = () => isDark() ? '#8892a4' : '#6b7280';
  const gridColor = () => isDark() ? 'rgba(255,255,255,0.06)' : 'rgba(0,0,0,0.04)';

  Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
  Chart.defaults.font.size   = 11;

  const labels   = <?= json_encode(array_values($mesesAbrev)) ?>;
  const income   = [<?= implode(',', $jsIncome) ?>];
  const spent    = [<?= implode(',', $jsSpent) ?>];
  const savings  = [<?= implode(',', $jsSavings) ?>];
  const savRate  = [<?= implode(',', $jsSavRate) ?>];

  function baseScales() {
    return {
      x: { grid: { color: gridColor() }, ticks: { color: textColor() } },
      y: { grid: { color: gridColor() }, ticks: { color: textColor(), callback: v => '€' + v.toLocaleString('pt-PT') }, beginAtZero: true }
    };
  }

  window.__LP_CHARTS = [];

  // Rendimentos vs Gastos
  window.__LP_CHARTS.push(new Chart(document.getElementById('incomeSpentChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label: 'Rendimentos', data: income, backgroundColor: 'rgba(37,99,235,0.7)', borderRadius: 4, borderWidth: 0 },
        { label: 'Gasto',       data: spent,  backgroundColor: 'rgba(220,38,38,0.7)',  borderRadius: 4, borderWidth: 0 }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { labels: { color: textColor(), boxWidth: 12 } },
        tooltip: { callbacks: { label: ctx => ` ${ctx.dataset.label}: € ${(ctx.parsed.y||0).toLocaleString('pt-PT',{minimumFractionDigits:2})}` } }
      },
      scales: baseScales()
    }
  }));

  // Poupança mensal
  const savColors       = savings.map(v => v === null ? 'transparent' : v >= 0 ? 'rgba(22,163,74,0.75)' : 'rgba(220,38,38,0.75)');
  const savBorderColors = savings.map(v => v === null ? 'transparent' : v >= 0 ? '#16a34a' : '#dc2626');
  window.__LP_CHARTS.push(new Chart(document.getElementById('savingsChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{ label: 'Poupança', data: savings, backgroundColor: savColors, borderColor: savBorderColors, borderWidth: 1, borderRadius: 4 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ` Poupança: € ${(ctx.parsed.y||0).toLocaleString('pt-PT',{minimumFractionDigits:2})}` } }
      },
      scales: { ...baseScales(), y: { ...baseScales().y, beginAtZero: false } }
    }
  }));

  // Taxa de poupança
  window.__LP_CHARTS.push(new Chart(document.getElementById('savRateChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Taxa (%)',
        data: savRate,
        borderColor: '#2563eb',
        backgroundColor: 'rgba(37,99,235,0.07)',
        tension: 0.35,
        fill: true,
        pointRadius: 4,
        pointBackgroundColor: savRate.map(v => v === null ? 'transparent' : v >= 10 ? '#16a34a' : v >= 0 ? '#ea580c' : '#dc2626'),
        pointBorderColor: 'transparent',
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: ctx => ` Taxa: ${(ctx.parsed.y||0).toLocaleString('pt-PT',{minimumFractionDigits:1})}%` } },
        annotation: {}
      },
      scales: {
        x: { grid: { color: gridColor() }, ticks: { color: textColor() } },
        y: { grid: { color: gridColor() }, ticks: { color: textColor(), callback: v => v + '%' } }
      }
    }
  }));

  <?php if (!empty($catRows)): ?>
  const catLabels = <?= json_encode(array_column($catRows, 'name')) ?>;
  const catTotals = <?= json_encode(array_map(fn($r) => round((float)$r['total'], 2), $catRows)) ?>;
  const CAT_COLORS = ['#5b8bff','#ff6b6b','#3ecf8e','#f9c851','#c77dff','#4cc9f0','#f77f00','#7bed9f','#ff4757','#2ed573','#a29bfe','#fd79a8'];
  window.__LP_CHARTS.push(new Chart(document.getElementById('catPieChart'), {
    type: 'doughnut',
    data: {
      labels: catLabels,
      datasets: [{ data: catTotals, backgroundColor: CAT_COLORS, borderWidth: 2, borderColor: 'transparent' }]
    },
    options: {
      plugins: {
        legend: { position: 'bottom', labels: { color: textColor(), boxWidth: 10, padding: 8, font: { size: 11 } } },
        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: € ${ctx.parsed.toLocaleString('pt-PT',{minimumFractionDigits:2})}` } }
      }
    }
  }));
  <?php endif; ?>
</script>

</body>
</html>
