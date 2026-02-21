<?php
/**
 * Guardian Navigation Component
 */
$currentPage = $_GET['page'] ?? 'dashboard';
?>

<nav class="guardian-nav">
    <input type="checkbox" id="guardian-nav-toggle" class="guardian-nav-toggle">
    <div class="nav-brand">
        <div>
            <h1 style="margin:0;font-size:1.5rem;">🍽️ <?php echo t('app_name'); ?></h1>
            <p style="margin:0;font-size:0.875rem;opacity:0.8;">
                <?php echo t('welcome', ['name' => $user['name']]); ?>
            </p>
        </div>
        <label for="guardian-nav-toggle" class="guardian-nav-toggle-label">☰</label>
    </div>

    <ul class="nav-menu">
        <li><a href="?page=dashboard" class="<?php echo $currentPage === 'dashboard' ? 'active' : ''; ?>">
            📊 <?php echo t('guardian_dashboard'); ?>
        </a></li>
        <li><a href="?page=manage-children" class="<?php echo $currentPage === 'manage-children' ? 'active' : ''; ?>">
            👶 <?php echo t('manage_children'); ?>
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
