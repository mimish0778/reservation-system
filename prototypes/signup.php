<?php
require_once dirname(__DIR__) . '/config.php';
$user_tablename = 'userTable';
mysqli_report(MYSQLI_REPORT_OFF);


//ページ遷移
if (!array_key_exists('transition', $_POST))
{
    echo_page_signup();
}
elseif ($_POST['transition'] == "trans_signup")
{
    echo_page_signup();
}
elseif ($_POST['transition'] == "trans_confirmation")
{
    echo_page_confirmation();
}


//サインアップ認証
function auth_signup()
{
    global $hostname, $username, $password, $dbname, $user_tablename;
    $isValid = 0;

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    $link = mysqli_connect($hostname, $username, $password, $dbname);
    if (!$link){ exit("Connect error!"); }
    
    $result = mysqli_query($link, "SELECT * FROM $user_tablename");
    if (!$result){ exit("Select error on table ($user_tablename)!"); } 

    while ($row_assoc = mysqli_fetch_assoc($result))
    {
        if ($row_assoc['user'] == $user)
        {
            $isValid = 1;
        }
    }

    return $isValid;

    mysqli_free_result($result);
    mysqli_close($link);
}

//サインアップ画面表示
function echo_page_signup()
{
    echo <<<EOT
    <!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8" />
            <title>Questioner</title>
        </head>
        <body>
            <h1>Sign Up Page</h1>
            <form method="POST" action="signup.php">
                username <input type="text" name="user"><br>
                password <input type="password" name="pass"><br>
                <button type="submit" name="btn_next">Next</button>
                <input type="hidden" name="transition" value="trans_confirmation">
            </form>
            <form method="POST" action="main.php">
                <button type="submit" name="btn_prev">Prev</button>
            </form>
        </body>
    </html>
EOT;
}

//確認画面表示
function echo_page_confirmation()
{
    $isValid = auth_signup();

    $user = $_POST['user'];
    $pass = $_POST['pass'];

    echo <<<EOT
    <!DOCTYPE html>
    <html>
        <head>
            <meta charset="UTF-8" />
            <title>Questioner</title>
        </head>
        <body>
            <h1>Confirmation Page</h1>
            $user<br>
            $pass<br>
            <form method="POST" action="main.php">
                <button type="submit" name="btn_next">Next</button>
            </form>
            <form method="POST" action="signup.php">
                <button type="submit" name="btn_back">Back</button>
            </form>
        </body>
    </html>
EOT;
}
?>