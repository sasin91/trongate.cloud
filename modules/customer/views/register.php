<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<?= BASE_URL ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?= BASE_URL ?>css/trongate.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>customer_module/css/login.css">
    <title>Register</title>
</head>

<body>
    <main>
        <h1>Create Account</h1>
        <?= form_open($form_location, ['class' => 'login-form']) ?>
        <?= validation_errors('<div class="validation-errors">', '</div>') ?>

        <div class="form-group">
            <?= form_label('Email') ?>
            <?= form_input('email', '', ['id' => 'email', 'type' => 'email', 'placeholder' => 'Email address', 'autocomplete' => 'email', 'required' => true]); ?>
        </div>

        <div class="form-group">
            <?= form_label('Password') ?>
            <?= form_password('password', '', ['id' => 'password', 'placeholder' => 'Create a password', 'autocomplete' => 'new-password', 'required' => true]); ?>
        </div>

        <div class="form-group">
            <?= form_label('Repeat Password') ?>
            <?= form_password('password_confirm', '', ['id' => 'password_confirm', 'placeholder' => 'Repeat password', 'autocomplete' => 'new-password', 'required' => true]); ?>
        </div>

        <?= form_submit('submit', 'Create Account') ?>
        <?= form_close() ?>

        <div style="text-align: center; margin-top: 1rem;">
            <a href="<?= BASE_URL ?>login" style="font-size: 0.9rem; color: var(--accent-primary); text-decoration: none;">Already have an account? Sign In</a>
        </div>
    </main>
</body>

</html>