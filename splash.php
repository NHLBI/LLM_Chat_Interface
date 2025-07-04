<?php

$_SESSION['splash'] = true;
$error = '';

require_once 'staticpages/disclaimer_text.php';

#file_put_contents("mylog.log", "\$_SESSION in Splash.php BEFORE changes = ".print_r($_SESSION,1)."\n\n\n", FILE_APPEND);

if (!empty($_SESSION['user_data']['userid']) && (empty($_SESSION['authorized']) || $_SESSION['authorized'] !== true)){
    $error = '<br>The user ' . $_SESSION['user_data']['userid'] . " is not authorized to use this tool<br>\n";
    $_SESSION['splash'] = false;
}

#file_put_contents("mylog.log", "\$_SESSION in Splash.php AFTER changes = ".print_r($_SESSION,1)."\n\n\n", FILE_APPEND);
#file_put_contents("mylog.log", "- - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - - \n\n\n\n\n\n\n", FILE_APPEND);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['app']['app_title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.v2.02.css" rel="stylesheet">
</head>
<body>
    <div class="container">
        <div class="header row align-items-center">
            <div class="col-sm-4">
            <img src="images/<?php echo $config['app']['app_logo']; ?>" class="logo" alt="<?php echo $config['app']['app_logo_alt']; ?>">
            </div>
            <div class="col-sm-4 text-center">
                <h1><?php echo $config['app']['app_title']; ?></h1>
            </div>
            <div class="col-sm-4 text-end">
<?php

if (!empty($user)) echo '<p id="username">Hello '.$user.'</p>'."\n";

?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-1 columns">
            </div>
            <div class="col-md-10 columns">
                <div>

    <?php echo $topbox; ?>

<?php

if (!empty($error)) echo '<span style="color:red">'.$error.'</span></p>'."\n";

require_once 'staticpages/notification_center.html';

    echo $maintext; 

# require_once 'staticpages/model_text.html';

?>                    
                    <p class="borderedbox" style="text-align: center;">
                    <a title="Click here to go to authentication" href="auth_redirect.php">Proceed</a></p>
                    <!-- Chat messages will be added here -->
                </div>
                <div class="footer">
                    <p><a title="Open the disclosure information in a new window" href="<?php echo $config['app']['disclosure_link']; ?>" target="_Blank" title="Vulnerability Disclosure">Vulnerability Disclosure</a></p>
                </div>
            </div>
            <div class="col-md-1 columns">
            </div>
        </div>
    </div>
</body>
</html>

