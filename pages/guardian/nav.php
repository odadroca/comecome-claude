<?php
/**
 * Guardian Navigation Component (collapsible on mobile)
 */
$currentPage = $_GET['page'] ?? 'dashboard';
// config.php defines these constants in the real app; guard here so nav.php is
// self-contained in any include context that loads db.php but not config.php.
if (!defined('SAFEGUARD_MOOD_CRITICAL')) { define('SAFEGUARD_MOOD_CRITICAL', 1); }
if (!defined('SAFEGUARD_MOOD_LOW'))      { define('SAFEGUARD_MOOD_LOW', 2); }
if (!defined('SAFEGUARD_LOW_COUNT'))     { define('SAFEGUARD_LOW_COUNT', 2); }
if (!defined('SAFEGUARD_WINDOW_DAYS'))   { define('SAFEGUARD_WINDOW_DAYS', 7); }
require_once __DIR__ . '/../../includes/safeguarding.php';
$sgEnabled = getSetting('show_safeguarding_alerts', '1') === '1';
$sgCount   = $sgEnabled ? count(computeSafeguardingFlags(getDB())) : 0;
?>

<nav class="guardian-nav">
    <div class="nav-header">
        <div class="nav-brand">
            <h1 style="margin:0;font-size:1.5rem;">🍽️ <?php echo t('app_name'); ?></h1>
            <p style="margin:0;font-size:0.875rem;opacity:0.8;">
                <?php echo t('welcome', ['name' => $user['name']]); ?>
            </p>
        </div>
        <button class="nav-toggle" onclick="document.querySelector('.guardian-nav').classList.toggle('nav-open')" aria-label="Menu">
            <span></span><span></span><span></span>
        </button>
    </div>

    <ul class="nav-menu">
        <li><a href="?page=dashboard" class="<?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
            📊 <?php echo t('guardian_dashboard'); ?>
        </a></li>
        <li><a href="?page=manage-users" class="<?php echo in_array($currentPage, ['manage-users', 'manage-children']) ? 'active' : ''; ?>">
            👥 <?php echo t('manage_users'); ?>
        </a></li>
        <li><a href="?page=manage-foods" class="<?php echo $currentPage === 'manage-foods' ? 'active' : ''; ?>">
            🍎 <?php echo t('manage_foods'); ?>
        </a></li>
        <li><a href="?page=manage-meals" class="<?php echo $currentPage === 'manage-meals' ? 'active' : ''; ?>">
            🍽️ <?php echo t('manage_meals'); ?>
        </a></li>
        <li><a href="?page=manage-medications" class="<?php echo $currentPage === 'manage-medications' ? 'active' : ''; ?>">
            💊 <?php echo t('manage_medications'); ?>
        </a></li>
        <li><a href="?page=manage-sleep" class="<?php echo $currentPage === 'manage-sleep' ? 'active' : ''; ?>">
            😴 <?php echo t('manage_sleep'); ?>
        </a></li>
        <?php if ($sgEnabled): ?>
        <li><a href="?page=safeguarding" class="<?php echo $currentPage === 'safeguarding' ? 'active' : ''; ?>">
            🛟 <?php echo t('safeguarding_nav'); ?>
            <?php if ($sgCount > 0): ?><span class="nav-badge"><?php echo (int) $sgCount; ?></span><?php endif; ?>
        </a></li>
        <?php endif; ?>
        <li><a href="?page=manage-logs" class="<?php echo $currentPage === 'manage-logs' ? 'active' : ''; ?>">
            📋 <?php echo t('manage_logs'); ?>
        </a></li>
        <li><a href="?page=export" class="<?php echo $currentPage === 'export' ? 'active' : ''; ?>">
            📤 <?php echo t('export_data'); ?>
        </a></li>
        <li><a href="?page=translations" class="<?php echo $currentPage === 'translations' ? 'active' : ''; ?>">
            🌐 <?php echo t('translations'); ?>
        </a></li>
        <li><a href="?page=settings" class="<?php echo $currentPage === 'settings' ? 'active' : ''; ?>">
            ⚙️ <?php echo t('settings'); ?>
        </a></li>
        <li><a href="?page=database" class="<?php echo $currentPage === 'database' ? 'active' : ''; ?>">
            💾 <?php echo t('database'); ?>
        </a></li>
        <li><a href="?page=logout">
            🚪 <?php echo t('logout'); ?>
        </a></li>
    </ul>
</nav>
