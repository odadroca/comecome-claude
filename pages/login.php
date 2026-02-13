<?php
/**
 * Login Page
 */

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = $_POST['user_id'] ?? '';
    $pin = $_POST['pin'] ?? '';

    if ($userId && $pin) {
        if (authenticateUser($userId, $pin)) {
            header('Location: index.php');
            exit;
        } else {
            $error = t('login_error');
        }
    }
}

// Get all active users
$users = getAllUsers();
$activeUsers = array_filter($users, function($u) { return $u['active'] == 1; });

ob_start();
?>

<main class="container login-container">
    <article class="login-card">
        <header style="text-align: center;">
            <h1 style="font-size: 4rem; margin: 0;">🍽️</h1>
            <h2><?php echo t('app_name'); ?></h2>
            <p><?php echo t('app_tagline'); ?></p>
        </header>

        <?php if ($error): ?>
        <div class="error-message" role="alert">
            <?php echo $error; ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <h3><?php echo t('login_select_user'); ?></h3>

            <div class="user-grid">
                <?php foreach ($activeUsers as $user): ?>
                <label class="user-card">
                    <input type="radio" name="user_id" value="<?php echo $user['id']; ?>" required>
                    <div class="user-avatar"><?php echo $user['avatar_emoji']; ?></div>
                    <div class="user-name"><?php echo sanitize($user['name']); ?></div>
                </label>
                <?php endforeach; ?>
            </div>

            <div id="pinSection" style="display: none; margin-top: 2rem;">
                <label for="pin">
                    <?php echo t('login_enter_pin'); ?>
                    <input type="password" id="pin" name="pin" inputmode="numeric" pattern="[0-9]*" maxlength="4" placeholder="****" autocomplete="off">
                </label>

                <button type="submit" class="btn-primary">
                    <?php echo t('login_submit'); ?>
                </button>
            </div>
        </form>

        <footer style="text-align: center; margin-top: 2rem; font-size: 0.875rem;">
            <a href="?lang=<?php echo getAppLocale() === 'pt' ? 'en' : 'pt'; ?>" style="text-decoration: none;">
                🌐 <?php echo getAppLocale() === 'pt' ? 'English' : 'Português'; ?>
            </a>
        </footer>
    </article>
</main>

<script>
document.querySelectorAll('input[name="user_id"]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.getElementById('pinSection').style.display = 'block';
        document.getElementById('pin').focus();
    });
});
</script>

<?php
$content = ob_get_clean();
renderLayout(t('login_title'), $content);
?>
