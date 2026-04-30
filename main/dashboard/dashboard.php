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
    $year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
    $month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
    $nif = isset($_GET['nif']) ? $_GET['nif'] : '1';

    // 3. BUSCAR O SALÁRIO GUARDADO NA TABELA monthly_summary (se existir)
    $stmtSalary = $conn->prepare("SELECT salary FROM monthly_summary WHERE user_id = ? AND year = ? AND month = ?");
    $stmtSalary->bind_param("iii", $user_id, $year, $month);
    $stmtSalary->execute();
    $resSalary = $stmtSalary->get_result()->fetch_assoc();
    
    // Se existir na tabela, usa esse. Se não, usa o que vem no GET ou o padrão 1350.
    if ($resSalary) {
        $salary = (float)$resSalary['salary'];
    } else {
        $salary = isset($_GET['salary']) ? (float)$_GET['salary'] : 1350.00;
    }

    // 4. CALCULAR O TOTAL GASTO ATUAL (Sincronização)
    $stmtTotal = $conn->prepare("SELECT SUM(amount) AS total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ? AND nif = ?");
    $stmtTotal->bind_param("iiii", $user_id, $year, $month, $nif);
    $stmtTotal->execute();
    $totalGasto = (float)($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
    $restante = $salary - $totalGasto;

    // 5. ATUALIZAR AUTOMATICAMENTE A TABELA monthly_summary
    $stmtSync = $conn->prepare("
        INSERT INTO monthly_summary (user_id, year, month, total_spent, salary, final_balance)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            total_spent = VALUES(total_spent),
            salary = VALUES(salary),
            final_balance = VALUES(final_balance)
    ");
    $stmtSync->bind_param("iiiddd", $user_id, $year, $month, $totalGasto, $salary, $restante);
    $stmtSync->execute();

    // 6. QUERY PARA A LISTA DE CATEGORIAS
    $stmt = $conn->prepare("
        SELECT 
            ? AS ano,
            ? AS mes,
            c.name AS categoria,
            COALESCE(SUM(t.amount), 0) AS total
        FROM categories c
        LEFT JOIN transactions t 
            ON t.category_id = c.id
            AND YEAR(t.date) = ?
            AND MONTH(t.date) = ?
            AND t.nif = ?
            AND t.user_id = ?
        GROUP BY c.id, c.name
        ORDER BY c.name;
    ");
    $stmt->bind_param("iiiiii", $year, $month, $year, $month, $nif, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $meses = [
        1 => "Janeiro", 2 => "Fevereiro", 3 => "Março", 4 => "Abril",
        5 => "Maio", 6 => "Junho", 7 => "Julho", 8 => "Agosto",
        9 => "Setembro", 10 => "Outubro", 11 => "Novembro", 12 => "Dezembro"
    ];
?>

<html>
<head>
    <title>Dashboard</title>
    <style>
        table { border-collapse: collapse; margin-bottom: 20px; font-family: sans-serif; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 10px; text-align: center; }
        th { background-color: #f2f2f2; }
        .box-salario { border: 1px solid #007bff; background: #eef7ff; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
    <link rel="stylesheet" href="../../styles.css">
</head>
<body>
    <ul>
        <li><a href="../dashboard/dashboard.php">Home</a></li>
        <li><a href="../options/option.php?cat=1">Carro</a></li>
        <li><a href="../options/option.php?cat=2">Ginásio</a></li>
        <li><a href="../options/option.php?cat=3">Entretenimento</a></li>
        <li><a href="../options/option.php?cat=4">Saúde</a></li>
        <li><a href="../options/option.php?cat=5">Educação</a></li>
        <li><a href="../options/option.php?cat=6">Outros</a></li>
    </ul>

    <h1>Dashboard de <?php echo $meses[$month] . " " . $year; ?></h1>

    <div style="display:flex; gap: 50px;">
        <div style="flex-grow: 2;">
            <form action="" method="get">
                <table style="width: auto;">
                    <tr>
                        <td>Ano: 
                            <select name="year">
                                <?php for ($i = 2024; $i <= 2030; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php if ($i == $year) echo 'selected'; ?>><?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </td>
                        <td>Mês: 
                            <select name="month">
                                <?php foreach ($meses as $num => $nome): ?>
                                    <option value="<?php echo $num; ?>" <?php if ($num == $month) echo 'selected'; ?>><?php echo $nome; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>NIF: 
                            <input type="radio" name="nif" value="0" <?php if ($nif == '0') echo 'checked'; ?>> Não
                            <input type="radio" name="nif" value="1" <?php if ($nif == '1') echo 'checked'; ?>> Sim
                        </td>
                        <td><input type="submit" value="Filtrar"></td>
                    </tr>
                </table>
            </form>

            <table>
                <tr>
                    <th>Categoria</th>
                    <th>Valor Gasto</th>
                </tr>
                <?php while($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo $row['categoria']; ?></td>
                    <td><?php echo number_format($row['total'], 2, ',', '.') . " €"; ?></td>
                </tr>
                <?php endwhile; ?>
            </table>
        </div>

        <div style="flex-grow: 1;">
            <div class="box-salario">
                <h3>Definir Ordenado Mensal</h3>
                <form action="save_salary.php" method="post">
                    <input type="hidden" name="year" value="<?php echo $year; ?>">
                    <input type="hidden" name="month" value="<?php echo $month; ?>">
                    <label>Valor para <?php echo $meses[$month]; ?>:</label><br><br>
                    <input type="number" step="0.01" name="salary" value="<?php echo $salary; ?>" required style="width: 100px;">
                    <input type="submit" value="Guardar na BD">
                </form>
            </div>

            <h3>Resumo Financeiro</h3>
            <table>
                <tr>
                    <th>Total Gasto</th>
                    <th>Ordenado</th>
                    <th>Restante</th>
                </tr>
                <tr>
                    <td style="color: red;"><?php echo number_format($totalGasto, 2, ',', '.') . " €"; ?></td>
                    <td style="color: blue;"><?php echo number_format($salary, 2, ',', '.') . " €"; ?></td>
                    <td style="color: <?php echo $restante >= 0 ? 'green' : 'red'; ?>;">
                        <?php echo number_format($restante, 2, ',', '.') . " €"; ?>
                    </td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>