<?php
/**
 * Guardian Navigation Component (collapsible on mobile)
 */
$currentPage = $_GET['page'] ?? 'dashboard';
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
