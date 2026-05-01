<?php
    session_start();
    include_once "./bd.php";

    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  
        if (!isset($_SESSION['user_id'])) {
            header("Location: ../../index.php");
            exit();
        }

        $user_id = $_SESSION['user_id'];
        $year    = (int)$_POST['year'];
        $month   = (int)$_POST['month'];
        $salary  = (float)$_POST['salary'];

        // 1. Calcular o total gasto atual para garantir que o saldo final fica correto na BD
        $stmtGasto = $conn->prepare("SELECT SUM(amount) as total FROM transactions WHERE user_id = ? AND YEAR(date) = ? AND MONTH(date) = ?");
        $stmtGasto->bind_param("iii", $user_id, $year, $month);
        $stmtGasto->execute();
        $resGasto = $stmtGasto->get_result()->fetch_assoc();
        $total_spent = (float)($resGasto['total'] ?? 0);

        // 2. Calcular o saldo
        $final_balance = $salary - $total_spent;

        // 3. Gravar ou Atualizar na tabela monthly_summary
        $sql = "INSERT INTO monthly_summary (user_id, year, month, total_spent, salary, final_balance)
                VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT(user_id, year, month) DO UPDATE SET
                    salary = excluded.salary,
                    total_spent = excluded.total_spent,
                    final_balance = excluded.final_balance";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiiddd", $user_id, $year, $month, $total_spent, $salary, $final_balance);

        if ($stmt->execute()) {
            // REDIRECIONAMENTO DE VOLTA 
            header("Location: ../main/dashboard/dashboard.php?year=$year&month=$month");
            exit(); // CRUCIAL: Impede que o script continue a correr
        } else {
            echo "Erro ao guardar: " . $conn->error;
        }
    } else {
        // Se tentarem aceder ao ficheiro sem ser por POST, volta ao dashboard
        header("Location: ../main/dashboard/dashboard.php");
        exit();
    }
?>