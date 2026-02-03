<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Temporarily Blocked</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f8f8f8;
            height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }

        .blocked-page {
            text-align: center;
            padding: 40px;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            max-width: 500px;
            width: 90%;
        }

        .blocked-page h1 {
            font-size: 2rem;
            color: #d9534f;
            margin-bottom: 20px;
        }

        .blocked-page p {
            margin-top: 10px;
            font-size: 1rem;
            color: #333;
            line-height: 1.5;
        }

        .blocked-page a {
            display: inline-block;
            margin-top: 25px;
            text-decoration: none;
            color: #fff;
            background-color: #0275d8;
            padding: 10px 20px;
            border-radius: 4px;
            transition: background-color 0.2s;
        }

        .blocked-page a:hover {
            background-color: #025aa5;
        }
    </style>
</head>

<body>
    <main class="blocked-page">
        <h1>Access Temporarily Blocked</h1>
        <?= flashdata() ?>
        <p>Due to multiple unsuccessful login attempts, your account has been temporarily locked.</p>
        <p>Please wait 15 minutes before trying again.</p>
        <p>If you believe this is a mistake, contact support.</p>
        <?php
        echo anchor('customer/login', 'Back to Login');
        ?>
    </main>
</body>

</html>