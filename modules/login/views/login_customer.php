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
        <?= form_open($form_location, ['class' => 'login-form']) ?>
        <?= validation_errors('<div class="validation-errors">', '</div>') ?>

        <div class="form-group">
            <?= form_label($identifier_label) ?>
            <?= form_input('identifier', '', ['id' => 'identifier', 'type' => 'email', 'placeholder' => $identifier_label, 'autocomplete' => 'email', 'required' => true]); ?>
        </div>

        <div class="form-group">
            <?= form_label($fields['password']['label']) ?>
            <?= form_password('password', '', ['id' => 'password', 'placeholder' => $fields['password']['label'], 'autocomplete' => 'current-password', 'required' => true]); ?>
        </div>

        <?php if ($allow_remember === 1): ?>
            <label>
                <?= form_checkbox('remember', 1) ?> Remember me
            </label>
        <?php endif; ?>

        <?= form_submit('login', 'Log In') ?>
        <?= form_close() ?>

        <div style="text-align: center; margin-top: 1rem;">
            <?php if (isset($forgot_password_url)): ?>
                <a href="<?= BASE_URL . $forgot_password_url ?>" style="font-size: 0.9rem; color: var(--accent-primary); text-decoration: none;">Forgot your password?</a><br>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>register" style="font-size: 0.9rem; color: var(--accent-primary); text-decoration: none;">Create an account</a>
        </div>
    </main>
</body>

</html>
