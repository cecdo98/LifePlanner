<?php
    class MockMysqliStmt {
        private $pdoStmt;
        private $params = [];
        private $result;
        
        public function __construct($pdoStmt) {
            $this->pdoStmt = $pdoStmt;
        }

        public function bind_param($types, &...$vars) {
            $this->params = &$vars;
        }

        public function execute() {
            foreach ($this->params as $k => $v) {
                $type = PDO::PARAM_STR;
                if (is_int($v)) {
                    $type = PDO::PARAM_INT;
                } elseif (is_bool($v)) {
                    $type = PDO::PARAM_BOOL;
                } elseif (is_null($v)) {
                    $type = PDO::PARAM_NULL;
                }
                $this->pdoStmt->bindValue($k + 1, $v, $type);
            }
            $res = $this->pdoStmt->execute();
            if ($res && preg_match('/^\s*SELECT/i', $this->pdoStmt->queryString)) {
                $this->result = $this->pdoStmt->fetchAll(PDO::FETCH_ASSOC);
            }
            return $res;
        }

        public function get_result() {
            return new MockMysqliResult($this->result ?: []);
        }

        public function close() {
            $this->pdoStmt = null;
        }
    }

    class MockMysqliResult {
        private $rows;
        private $index = 0;
        public $num_rows;

        public function __construct($rows) {
            $this->rows = $rows;
            $this->num_rows = count($rows);
        }

        public function fetch_assoc() {
            if ($this->index < $this->num_rows) {
                return $this->rows[$this->index++];
            }
            return null;
        }
    }

    class MockMysqli {
        private $pdo;
        public $connect_error = null;
        public $error = null;
        
        public function __construct($dbFile) {
            try {
                // Mudança: Agora usa o caminho completo passado por argumento
                $this->pdo = new PDO("sqlite:" . $dbFile);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->pdo->setAttribute(PDO::ATTR_CASE, PDO::CASE_LOWER);
                
                $this->pdo->sqliteCreateFunction('YEAR', function($date) {
                    return (int)date('Y', strtotime($date));
                }, 1);
                $this->pdo->sqliteCreateFunction('MONTH', function($date) {
                    return (int)date('m', strtotime($date));
                }, 1);
            } catch (PDOException $e) {
                $this->connect_error = $e->getMessage();
            }
        }

        public function prepare($sql) {
            try {
                $stmt = $this->pdo->prepare($sql);
                return new MockMysqliStmt($stmt);
            } catch (Exception $e) {
                $this->error = $e->getMessage();
                return false;
            }
        }
    }

    // ==========================================
    // CONFIGURAÇÃO DE CAMINHOS (ONEDRIVE)
    // ==========================================

    // PC 1 Portátil
    $caminhoPC1 = "C:\Users\carlo\OneDrive\Ambiente de Trabalho\Engenharia de Informática\pessoal/financas.sqlite";

    // PC 2 Desktop
    $caminhoPC2 = "C:\Users\Carlos\OneDrive\Ambiente de Trabalho\Engenharia de Informática\pessoal/financas.sqlite";

    // Fallback (Se não encontrar nenhum, procura na pasta do script)
    $caminhoLocal = __DIR__ . "/financas.sqlite";

    // Lógica para decidir qual ficheiro usar
    if (file_exists($caminhoPC1)) {
        $dbFinal = $caminhoPC1;
    } elseif (file_exists($caminhoPC2)) {
        $dbFinal = $caminhoPC2;
    } else {
        $dbFinal = $caminhoLocal;
    }

    // Inicia a conexão
    $conn = new MockMysqli($dbFinal);

    if ($conn->connect_error) {
        die("Falha na ligação: " . $conn->connect_error . " (Ficheiro não encontrado em: $dbFinal)");
    }


?>