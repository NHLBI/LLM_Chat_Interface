<?php

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Maintenance Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="style.v1.03.2.css" rel="stylesheet">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f8f9fa;
            margin: 0;
        }
        .maintenance-container {
            text-align: center;
            padding: 30px;
            background-color: #fff;
            margin-top: 30px;
        }
        .maintenance-container h1 {
            font-size: 2rem;
            margin-bottom: 20px;
        }
        .maintenance-container p {
            font-size: 1.2rem;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header row align-items-center">
            <div class="col-sm-4">
            <img src="images/NHLBI_Logo_Vector.svg" class="logo" alt="<?php echo $config['app']['app_logo_alt']; ?>">
            </div>
            <div class="col-sm-4 text-center">
                <h1>NHLBI Chat</h1>
            </div>
            <div class="col-sm-4 text-end">
            </div>
        </div>
        <div class="maintenance-container">
        <h1>We'll be back soon!</h1>
        <p>Sorry for the inconvenience but we're performing some maintenance at the moment. We'll be back online shortly!</p>
        <p>— Office of Scientific Information</p>
        </div>
    </div>
</body>
</html>

