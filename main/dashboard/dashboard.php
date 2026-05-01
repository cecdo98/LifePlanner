<?php
    session_start();
    include_once "../../config/bd.php";

    // 1. Verificação de Segurança
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../../index.php");
        exit();
    }

    $user_id = $_SESSION['user_id'];

    // 2. Obter valores do formulário ou definir padrões
    $year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    $nif   = isset($_GET['nif'])   ? $_GET['nif']        : '1';

    // 3. Buscar salário guardado
    $stmtSalary = $conn->prepare("SELECT salary FROM monthly_summary WHERE user_id = ? AND year = ? AND month = ?");
    $stmtSalary->bind_param("iii", $user_id, $year, $month);
    $stmtSalary->execute();
    $resSalary = $stmtSalary->get_result()->fetch_assoc();

    $salary = $resSalary ? (float)$resSalary['salary'] : (isset($_GET['salary']) ? (float)$_GET['salary'] : 1350.00);

    // 4. Calcular total gasto atual
    $stmtTotal = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
    $stmtTotal->bind_param("iii", $user_id, $year, $month);
    $stmtTotal->execute();
    $totalGasto = (float)($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
    $restante   = $salary - $totalGasto;

    // 5. Sincronizar monthly_summary
    $stmtSync = $conn->prepare("
        INSERT INTO monthly_summary (user_id, year, month, total_spent, salary, final_balance)
        VALUES (?, ?, ?, ?, ?, ?)
        ON CONFLICT(user_id, year, month) DO UPDATE SET
            total_spent   = excluded.total_spent,
            salary        = excluded.salary,
            final_balance = excluded.final_balance
    ");
    $stmtSync->bind_param("iiidd", $user_id, $year, $month, $totalGasto, $salary, $restante);
    $stmtSync->execute();

    // 6. Query para categorias (gráfico de pizza + tabela)
    $stmt = $conn->prepare("
        SELECT c.name AS categoria, COALESCE(SUM(t.amount), 0) AS total
        FROM categories c
        LEFT JOIN transactions t
            ON t.category_id = c.id
            AND YEAR(t.date)  = ?
            AND MONTH(t.date) = ?
            AND t.nif         = ?
            AND t.user_id     = ?
        GROUP BY c.id, c.name
        ORDER BY c.name
    ");
    $stmt->bind_param("iiii", $year, $month, $nif, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    // 7. Dados anuais: salário e saldo restante mês a mês
    $stmtAnual = $conn->prepare("
        SELECT month, salary, final_balance, total_spent
        FROM monthly_summary
        WHERE user_id = ? AND year = ?
        ORDER BY month ASC
    ");
    $stmtAnual->bind_param("ii", $user_id, $year);
    $stmtAnual->execute();
    $resAnual = $stmtAnual->get_result();

    $meses = [
        1=>"Jan",2=>"Fev",3=>"Mar",4=>"Abr",5=>"Mai",6=>"Jun",
        7=>"Jul",8=>"Ago",9=>"Set",10=>"Out",11=>"Nov",12=>"Dez"
    ];
    $mesesFull = [
        1=>"Janeiro",2=>"Fevereiro",3=>"Março",4=>"Abril",5=>"Maio",6=>"Junho",
        7=>"Julho",8=>"Agosto",9=>"Setembro",10=>"Outubro",11=>"Novembro",12=>"Dezembro"
    ];

    // Inicializar arrays anuais
    $salaryAnual   = array_fill(1, 12, null);
    $balanceAnual  = array_fill(1, 12, null);
    $spentAnual    = array_fill(1, 12, null);

    while ($row = $resAnual->fetch_assoc()) {
        $m = (int)$row['month'];
        $salaryAnual[$m]  = (float)$row['salary'];
        $balanceAnual[$m] = (float)$row['final_balance'];
        $spentAnual[$m]   = (float)$row['total_spent'];
    }

    // Preparar dados para gráfico de pizza
    $dataPoints = [];
    while ($row = $result->fetch_assoc()) {
        $dataPoints[] = ["label" => $row['categoria'], "y" => (float)$row['total']];
    }

    // Preparar arrays JS para gráficos anuais
    $jsLabels  = [];
    $jsSalary  = [];
    $jsBalance = [];
    $jsSpent   = [];
    for ($m = 1; $m <= 12; $m++) {
        $jsLabels[]  = $meses[$m];
        $jsSalary[]  = $salaryAnual[$m]  !== null ? $salaryAnual[$m]  : 'null';
        $jsBalance[]  = $balanceAnual[$m] !== null ? $balanceAnual[$m] : 'null';
        $jsSpent[]   = $spentAnual[$m]   !== null ? $spentAnual[$m]   : 'null';
    }

    $navLinks = [
        ["../dashboard/dashboard.php", "Inicio"],
        ["../options/option.php?cat=1", "Carro"],
        ["../options/option.php?cat=2", "Ginásio"],
        ["../options/option.php?cat=3", "Entretenimento"],
        ["../options/option.php?cat=4", "Saúde"],
        ["../options/option.php?cat=5", "Educação"],
        ["../options/option.php?cat=6", "Outros"],
    ];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Financeiro</title>
    <link rel="stylesheet" href="./stylesDashboard.css">
</head>
<body>

<nav>
  <span class="nav-brand">Life Planner</span>
  <ul class="nav-links">
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= $href ?>"><?= $label ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="nav-right">
    <li><a href="../settings/settings.php">Definições</a></li>
    <li><a href="../../config/logout.php">Sair</a></li>
  </ul>
</nav>

<div class="page">

  <div class="page-header">
    <h1 class="page-title">
      Dashboard <span><?= $mesesFull[$month] . ' ' . $year ?></span>
    </h1>
  </div>

  <!-- ── Filtros ── -->
  <form action="" method="get">
    <div class="filter-bar">
      <div class="filter-group">
        <label>Ano</label>
        <select name="year">
          <?php for ($i = 2024; $i <= 2070; $i++): ?>
          <option value="<?= $i ?>" <?= $i == $year ? 'selected' : '' ?>><?= $i ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Mês</label>
        <select name="month">
          <?php foreach ($mesesFull as $num => $nome): ?>
          <option value="<?= $num ?>" <?= $num == $month ? 'selected' : '' ?>><?= $nome ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>NIF</label>
        <div class="radio-group">
          <label><input type="radio" name="nif" value="0" <?= $nif == '0' ? 'checked' : '' ?>> Não</label>
          <label><input type="radio" name="nif" value="1" <?= $nif == '1' ? 'checked' : '' ?>> Sim</label>
        </div>
      </div>
      <button type="submit" class="btn">Filtrar</button>
    </div>
  </form>

  <!-- ── KPIs ── -->
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
  </div>

  <!-- ── Main Grid: Tabela + Painel Lateral ── -->
  <div class="main-grid">

    <!-- Tabela de categorias -->
    <div class="card">
      <div class="card-title">Despesas por Categoria — <?= $mesesFull[$month] ?></div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Categoria</th>
            <th style="text-align:right">Valor Gasto</th>
          </tr>
        </thead>
        <tbody>
          <?php
          // Re-executar query pois o ponteiro está no fim
          $stmt->execute();
          $result2 = $stmt->get_result();
          while ($row = $result2->fetch_assoc()):
            $amount = (float)$row['total'];
          ?>
          <tr>
            <td class="cat-name"><?= htmlspecialchars($row['categoria']) ?></td>
            <td style="text-align:right" class="<?= $amount > 0 ? 'amount-pos' : 'amount-zero' ?>">
              <?= number_format($amount, 2, ',', '.') ?> €
            </td>
          </tr>
          <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <!-- Painel lateral -->
    <div>
      <!-- Definir ordenado -->
      <div class="card salary-panel" style="margin-bottom:16px;">
        <div class="card-title">Definir Ordenado</div>
        <form action="../../config/save_salary.php" method="post">
          <input type="hidden" name="year"  value="<?= $year ?>">
          <input type="hidden" name="month" value="<?= $month ?>">
          <label>Valor para <?= $mesesFull[$month] ?></label>
          <input type="number" step="0.01" name="salary" value="<?= $salary ?>" required>
          <button type="submit" class="btn" style="width:100%">Guardar</button>
        </form>
      </div>

      <!-- Gráfico de pizza -->
      <div class="card">
        <div class="card-title">Distribuição — <?= $mesesFull[$month] ?></div>
        <canvas id="pieChart" height="240"></canvas>
      </div>
    </div>

  </div>

  <!-- ── Gráficos Anuais ── -->
  <div class="charts-section">
    <div class="charts-grid">

      <div class="card">
        <div class="card-title">Salário Mensal — <?= $year ?></div>
        <canvas id="salaryChart" height="200"></canvas>
      </div>

      <div class="card">
        <div class="card-title">Saldo Restante por Mês — <?= $year ?></div>
        <canvas id="balanceChart" height="200"></canvas>
      </div>

    </div>

    <div class="card">
      <div class="card-title">Visão Geral Anual — Ordenado vs Gasto vs Saldo (<?= $year ?>)</div>
      <canvas id="overviewChart" height="120"></canvas>
    </div>
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Dados do PHP ──────────────────────────────────────────
const labels  = <?= json_encode(array_values($meses)) ?>;
const salary  = <?= '[' . implode(',', $jsSalary)  . ']' ?>;
const balance = <?= '[' . implode(',', $jsBalance) . ']' ?>;
const spent   = <?= '[' . implode(',', $jsSpent)   . ']' ?>;
const pieData = <?= json_encode($dataPoints) ?>;

// ── Defaults de estilo ────────────────────────────────────
Chart.defaults.color       = '#6b7280';
Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
Chart.defaults.font.size   = 11;

const gridColor  = 'rgba(255,255,255,0.05)';
const tickColor  = '#6b7080';

function baseScales() {
  return {
    x: { grid: { color: gridColor }, ticks: { color: tickColor } },
    y: { grid: { color: gridColor }, ticks: { color: tickColor, callback: v => '€' + v.toLocaleString('pt-PT') } }
  };
}

// ── Gráfico Pizza ─────────────────────────────────────────
const pieLabels  = pieData.map(d => d.label);
const pieValues  = pieData.map(d => d.y);
const pieColors  = [
  '#5b8bff','#ff6b6b','#3ecf8e','#f9c851','#c77dff',
  '#4cc9f0','#f77f00','#7bed9f','#ff4757','#2ed573'
];

new Chart(document.getElementById('pieChart'), {
  type: 'doughnut',
  data: {
    labels: pieLabels,
    datasets: [{
      data: pieValues,
      backgroundColor: pieColors.slice(0, pieLabels.length),
      borderWidth: 2,
      borderColor: '#1c1f28'
    }]
  },
  options: {
    plugins: {
      legend: { position: 'bottom', labels: { color: '#6b7080', boxWidth: 12, padding: 10 } },
      tooltip: {
        callbacks: {
          label: ctx => ` ${ctx.label}: €${ctx.parsed.toLocaleString('pt-PT', {minimumFractionDigits:2})}`
        }
      }
    }
  }
});

// ── Gráfico Salário Mensal ────────────────────────────────
new Chart(document.getElementById('salaryChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Ordenado',
      data: salary,
      backgroundColor: 'rgba(91,139,255,0.7)',
      borderColor: '#5b8bff',
      borderWidth: 1,
      borderRadius: 5
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: baseScales()
  }
});

// ── Gráfico Saldo Restante ────────────────────────────────
new Chart(document.getElementById('balanceChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Saldo Final',
      data: balance,
      backgroundColor: balance.map(v => v === null ? 'transparent' : v >= 0 ? 'rgba(62,207,142,0.7)' : 'rgba(255,107,107,0.7)'),
      borderColor:     balance.map(v => v === null ? 'transparent' : v >= 0 ? '#3ecf8e' : '#ff6b6b'),
      borderWidth: 1,
      borderRadius: 5
    }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: baseScales()
  }
});

// ── Gráfico Visão Geral Anual (linha) ─────────────────────
new Chart(document.getElementById('overviewChart'), {
  type: 'line',
  data: {
    labels,
    datasets: [
      {
        label: 'Ordenado',
        data: salary,
        borderColor: '#5b8bff',
        backgroundColor: 'rgba(91,139,255,0.08)',
        tension: 0.3,
        fill: true,
        pointRadius: 4,
        pointBackgroundColor: '#5b8bff'
      },
      {
        label: 'Total Gasto',
        data: spent,
        borderColor: '#ff6b6b',
        backgroundColor: 'rgba(255,107,107,0.08)',
        tension: 0.3,
        fill: true,
        pointRadius: 4,
        pointBackgroundColor: '#ff6b6b'
      },
      {
        label: 'Saldo Final',
        data: balance,
        borderColor: '#3ecf8e',
        backgroundColor: 'rgba(62,207,142,0.08)',
        tension: 0.3,
        fill: true,
        pointRadius: 4,
        pointBackgroundColor: '#3ecf8e'
      }
    ]
  },
  options: {
    plugins: {
      legend: { labels: { color: '#6b7080', boxWidth: 12 } },
      tooltip: {
        callbacks: {
          label: ctx => ` ${ctx.dataset.label}: €${(ctx.parsed.y ?? 0).toLocaleString('pt-PT', {minimumFractionDigits:2})}`
        }
      }
    },
    scales: baseScales()
  }
});
</script>

</body>
</html>