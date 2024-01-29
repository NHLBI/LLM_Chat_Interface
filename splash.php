<?php

if (empty($_SESSION['user_data']) || empty($_SESSION['user_data']['userid'])) {
    $_SESSION['splash'] = true;

} else if (empty($_SESSION['authorized']) || $_SESSION['authorized'] !== true) {
    $error = '<br>The user ' . $_SESSION['user_data']['userid'] . " is not athorized to use this tool<br>\n";
    $_SESSION['splash'] = false;
} else {
    $_SESSION['splash'] = true;
    $error = '';
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['app']['app_title']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.v1.01b.css" rel="stylesheet">
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
            <div class="col-md-2 columns">
            </div>
            <div class="col-md-8 columns">
                <div>
                    <p class="newchat" style="text-align: center;">
                    <?php echo $config['app']['help_text1']; ?>
<?php

if (!empty($error)) echo '<span style="color:red">'.$error.'</span></p>'."\n";

echo $config['app']['disclaimer_text'];

?>                    

                    <p class=""><a title="Open the training video in a new window" href="<?php echo $config['app']['video_link']; ?>" target="_blank">Training Video</a></p>
                    <p class="newchat" style="text-align: center;">
                    <a title="Click here to go to authentication" href="index.php">Proceed</a></p>
                    <!-- Chat messages will be added here -->
                </div>
                <div class="footer">
                    <p><a title="Open the disclosure information in a new window" href="<?php echo $config['app']['disclosure_link']; ?>" target="_Blank" title="Vulnerability Disclosure">Vulnerability Disclosure</a></p>
                </div>
            </div>
            <div class="col-md-2 columns">
            </div>
        </div>
    </div>
</body>
</html>

