<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>422 - Unprocessable Entity</title>
    <link rel="stylesheet" href="css/trongate.css">
</head>
<body>
    <div class="container-sm">
        <div class="text-center mt-7">
            <h1>422</h1>
            <?= $content ?>
            
            <div class="mt-3">
                <?php
                echo anchor(BASE_URL, 'Go to Homepage', ['class' => 'button']);
    echo form_button('back', 'Go Back', ['class' => 'button alt', 'onclick' => 'history.back()']);
    ?>
            </div>
        </div>
    </div>
</body>
</html>