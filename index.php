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


    function login($conn,$username,$password) {
       
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND password_hash = ?");

        $stmt->bind_param("ss", $username, $password);

        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
        
        $user = $result->fetch_assoc();
        
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];

        redirect();
    } else {
        return "Utilizador ou password incorretos.";
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
    <style>
        .error {color: #FF0000;}
    </style>
    <link rel="stylesheet" href="styles.css">
</head>
<body>



<div class="login-container">
    <h2>Login</h2>
    <p><span class="error">* required field</span></p>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]);?>" autocomplete="off">  

        Username: <input type="text" name="username" value="<?php echo $username;?>">
        <span class="error"> <?php echo $usernameErr;?></span>

        Password: <input type="password" name="password" value="<?php echo $password;?>">
        <span class="error"><?php echo $passwordErr;?></span>

    <input type="submit" name="submit" value="Submit">  
    <span class="error"> <?php echo $loginErr;?></span>
    </form>
</div>


</body>
</html>
