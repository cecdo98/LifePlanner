<?php
    session_start();
    
    include_once "./config/bd.php";
    
    $usernameErr = $passwordErr = $loginErr = "";
    $username = $password = "";

    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        if (empty($_POST["username"])) {
            $usernameErr = "Username is required";
        } else {
            $username = $_POST["username"];

            if (!preg_match("/^[a-zA-Z-' ]*$/",$username)) {
            $usernameErr = "Only letters and white space allowed";
            }
        }
        
        if (empty($_POST["password"])) {
            $passwordErr = "Password is required";
        } else {
            $password = $_POST["password"];
  
        }

        $loginErr= login($conn,$username,$password);
        
    }


    function login($conn, $username, $password) {

        $stmt = $conn->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password_hash'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                
                redirect();
                
            } else {
                return "Password incorreta.";
            }
        } else {
            return "Utilizador não encontrado.";
        }
    }


    function redirect() {
        $url = './main/dashboard/dashboard.php';
        header('Location: '.$url);
        die();
    }

?> 

<!DOCTYPE html>
<html>
<head>
    <title>Life Planner</title>
    <link rel="stylesheet" href="./stylesLogin.css">
</head>
<body>

<div class="login-container">
    <h2>Login</h2>

    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" autocomplete="off">  

        <div class="form-group">
            <label>Username:</label>
            <input type="text" name="username" value="<?php echo $username;?>">
            <span class="error"><?php echo $usernameErr;?></span>
        </div>

        <div class="form-group">
            <label>Password:</label>
            <input type="password" name="password" value="<?php echo $password;?>">
            <span class="error"><?php echo $passwordErr;?></span>
        </div>

        <div class="form-group">
            <input type="submit" name="submit" value="Entrar">
        </div>

        <span class="error"><?php echo $loginErr;?></span>

    </form>
</div>


</body>
</html>
