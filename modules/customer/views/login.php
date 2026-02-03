<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>customer_module/css/login.css">
    <title>Log In</title>
</head>

<body>
    <main>
        <h1>Sign In</h1>
        <?= form_open($form_location, array('class' => 'login-form')) ?>
        <?= validation_errors('<div class="validation-errors">', '</div>') ?>

        <div class="form-group">
            <?= form_label('Email') ?>
            <?= form_input('email', '', array('id' => 'email', 'type' => 'email', 'placeholder' => 'Email', 'autocomplete' => 'email', 'required' => true)); ?>
        </div>

        <div class="form-group">
            <?= form_label('Password') ?>
            <?= form_password('password', '', array('id' => 'password', 'placeholder' => 'Password', 'autocomplete' => 'current-password', 'required' => true)); ?>
        </div>

        <label>
            <?= form_checkbox('remember', 1) ?> Remember me
        </label>

        <?= form_submit('login', 'Log In') ?>
        <?= form_close() ?>

        <div style="text-align: center; margin-top: 1rem;">
            <a href="<?= BASE_URL ?>customer/register" style="font-size: 0.9rem; color: var(--accent-primary); text-decoration: none;">Create an account</a>
        </div>
    </main>
</body>

</html>