<?php
/**
 * Login Page - Welcome home!
 */

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sprint security Phase 3 — reject a login POST without a valid CSRF token. The
    // token is minted per session and embedded in the form below (csrfField); a
    // cross-site forged POST lacks it and is refused before any authenticate attempt.
    if (function_exists('verifyCsrf') && !verifyCsrf()) {
        $error = t('error_invalid_request');
    } else {
    $userId = $_POST['user_id'] ?? '';
    $pin = $_POST['pin'] ?? '';

    if ($userId && $pin) {
        if (authenticateUser($userId, $pin)) {
            header('Location: index.php');
            exit;
        } else {
            // Sprint security Phase 1 — surface the DISTINCT `locked` state. If the
            // throttle now holds this user/ip locked (either because this attempt
            // tipped it over, or it was already locked and never verified), show the
            // lockout message instead of "wrong PIN". The message never reveals
            // whether the user_id exists — an unknown id locks identically.
            if (function_exists('loginIsLockedOut') && loginIsLockedOut(getDB(), $userId)) {
                $error = t('login_locked');
            } else {
                $error = t('login_error');
            }
        }
    }
    } // end CSRF-valid branch
}

// Get all active users - separate children and guardians
$users = getAllUsers();
$activeUsers = array_filter($users, function($u) { return $u['active'] == 1; });
$childUsers = array_filter($activeUsers, function($u) { return $u['type'] === 'child'; });
$guardianUsers = array_filter($activeUsers, function($u) { return $u['type'] === 'guardian'; });

// Safe guardian data for JS (strip sensitive fields)
$guardianUsersJS = array_map(function($u) {
    return ['id' => $u['id'], 'name' => $u['name'], 'avatar_emoji' => $u['avatar_emoji']];
}, array_values($guardianUsers));

// Random fun greeting phrase
$greetingPhrase = getRandomGreetingPhrase();

ob_start();
?>

<main class="container login-container">
    <button class="theme-toggle login-theme-toggle" type="button" aria-label="Toggle theme"></button>

    <article class="login-card">
        <header style="text-align: center;">
            <div class="login-icon" style="font-size: 4rem; margin: 0;">🍽️</div>
            <h2><?php echo t('app_name'); ?></h2>
            <?php if ($greetingPhrase): ?>
            <p class="login-greeting"><?php echo sanitize($greetingPhrase); ?></p>
            <?php endif; ?>
        </header>

        <?php if ($error): ?>
        <div class="error-message" role="alert">
            <?php /* Sprint security Phase 3 — escape the login error: it comes from
                     t() (guardian-editable DB translations), so render it inert. */ ?>
            <?php echo sanitize($error); ?>
        </div>
        <?php endif; ?>

        <form method="POST" id="loginForm">
            <?php echo csrfField(); ?>
            <h3><?php echo t('login_select_user'); ?></h3>

            <div class="user-grid">
                <?php foreach ($childUsers as $user): ?>
                <label class="user-card">
                    <input type="radio" name="user_id" value="<?php echo $user['id']; ?>" required>
                    <?php /* Sprint security Phase 3 — escape the guardian-editable avatar_emoji too (name was already escaped). */ ?>
                    <div class="user-avatar"><?php echo sanitize($user['avatar_emoji']); ?></div>
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
                    <?php echo t('login_submit'); ?> 👋
                </button>
            </div>
        </form>

        <footer style="text-align: center; margin-top: 2rem; font-size: 0.875rem;">
            <a href="?lang=<?php echo getAppLocale() === 'pt' ? 'en' : 'pt'; ?>" style="text-decoration: none;">
                🌐 <?php echo getAppLocale() === 'pt' ? 'English' : 'Português'; ?>
            </a>
            <?php if (count($guardianUsers) > 0): ?>
            <br>
            <a href="#" class="guardian-login-link" onclick="showGuardianLogin(); return false;">
                🔒 <?php echo t('guardian'); ?>
            </a>
            <?php endif; ?>
        </footer>
    </article>
</main>

<script>
// Child user selection
document.querySelectorAll('input[name="user_id"]').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('pinSection').style.display = 'block';
        document.getElementById('pin').focus();

        // Highlight the selected card
        document.querySelectorAll('.user-card').forEach(c => c.style.borderColor = '');
        this.closest('.user-card').style.borderColor = '#4CAF50';
    });
});

// Guardian login - shows guardian users and reveals PIN section
function showGuardianLogin() {
    const guardianUsers = <?php echo json_encode($guardianUsersJS); ?>;
    const grid = document.querySelector('.user-grid');

    // Replace grid with guardian user cards.
    // Sprint security Phase 3 — build each card via createElement + textContent
    // (NOT innerHTML) so a guardian-editable name/avatar can never inject markup.
    grid.innerHTML = '';
    guardianUsers.forEach(function(user) {
        const label = document.createElement('label');
        label.className = 'user-card';

        const input = document.createElement('input');
        input.type = 'radio';
        input.name = 'user_id';
        input.value = user.id;
        input.required = true;

        const avatar = document.createElement('div');
        avatar.className = 'user-avatar';
        avatar.textContent = user.avatar_emoji;   // inert: no HTML parsing

        const nameDiv = document.createElement('div');
        nameDiv.className = 'user-name';
        nameDiv.textContent = user.name;          // inert: no HTML parsing

        label.appendChild(input);
        label.appendChild(avatar);
        label.appendChild(nameDiv);
        grid.appendChild(label);

        // Add event listener
        label.querySelector('input').addEventListener('change', function() {
            document.getElementById('pinSection').style.display = 'block';
            document.getElementById('pin').focus();
            document.querySelectorAll('.user-card').forEach(c => c.style.borderColor = '');
            this.closest('.user-card').style.borderColor = '#4CAF50';
        });
    });

    // Update heading
    document.querySelector('#loginForm h3').textContent = '🔒 <?php echo t('guardian'); ?>';

    // Add back-to-children link
    const link = document.querySelector('.guardian-login-link');
    if (link) {
        link.textContent = '👶 <?php echo t('back'); ?>';
        link.onclick = function(e) { e.preventDefault(); location.reload(); };
    }
}
</script>

<?php
$content = ob_get_clean();
renderLayout(t('login_title'), $content);
?>
