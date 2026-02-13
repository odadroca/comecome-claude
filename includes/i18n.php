<?php
/**
 * Internationalization Functions
 */

// Global translation cache
$GLOBALS['translations'] = [];
$GLOBALS['current_locale'] = DEFAULT_LOCALE;

/**
 * Set current locale
 */
function setAppLocale($locale) {
    $GLOBALS['current_locale'] = $locale;
    $_SESSION['locale'] = $locale;
    loadTranslations($locale);
}

/**
 * Get current locale
 */
function getAppLocale() {
    if (isset($_SESSION['locale'])) {
        return $_SESSION['locale'];
    }
    return DEFAULT_LOCALE;
}

/**
 * Load translations for a locale
 */
function loadTranslations($locale) {
    // Load from JSON file
    $filePath = LOCALES_PATH . '/' . $locale . '.json';
    if (file_exists($filePath)) {
        $json = file_get_contents($filePath);
        $GLOBALS['translations'][$locale] = json_decode($json, true);
    } else {
        $GLOBALS['translations'][$locale] = [];
    }

    // Override with database translations
    $db = getDB();
    $stmt = $db->prepare("SELECT key, value FROM translations WHERE locale = ?");
    $stmt->execute([$locale]);

    while ($row = $stmt->fetch()) {
        $GLOBALS['translations'][$locale][$row['key']] = $row['value'];
    }
}

/**
 * Get translation for a key
 * @param string $key Translation key
 * @param array $params Parameters to replace in translation (e.g., {name})
 * @return string Translated text
 */
function t($key, $params = []) {
    $locale = getAppLocale();

    // Load translations if not loaded
    if (!isset($GLOBALS['translations'][$locale])) {
        loadTranslations($locale);
    }

    // Get translation
    $translation = $GLOBALS['translations'][$locale][$key] ?? $key;

    // Replace parameters
    foreach ($params as $k => $v) {
        $translation = str_replace('{' . $k . '}', $v, $translation);
    }

    return $translation;
}

/**
 * Get all translations for a locale
 */
function getAllTranslations($locale) {
    loadTranslations($locale);
    return $GLOBALS['translations'][$locale] ?? [];
}

/**
 * Save translation to database
 */
function saveTranslation($locale, $key, $value) {
    $db = getDB();
    $stmt = $db->prepare("
        INSERT OR REPLACE INTO translations (locale, key, value, modified_at)
        VALUES (?, ?, ?, datetime('now'))
    ");
    return $stmt->execute([$locale, $key, $value]);
}

/**
 * Get available locales
 */
function getAvailableLocales() {
    $locales = [];
    $files = glob(LOCALES_PATH . '/*.json');

    foreach ($files as $file) {
        $locales[] = basename($file, '.json');
    }

    return $locales;
}

/**
 * Get translation keys that need translation
 */
function getMissingTranslations($sourceLocale, $targetLocale) {
    $source = getAllTranslations($sourceLocale);
    $target = getAllTranslations($targetLocale);

    $missing = [];
    foreach ($source as $key => $value) {
        // Skip section headers (keys starting with _)
        if (strpos($key, '_') === 0) {
            continue;
        }

        if (!isset($target[$key]) || empty($target[$key])) {
            $missing[$key] = $value;
        }
    }

    return $missing;
}

// Initialize locale on first load
if (!isset($_SESSION['locale'])) {
    $defaultLocale = getSetting('default_language', DEFAULT_LOCALE);
    setAppLocale($defaultLocale);
} else {
    $GLOBALS['current_locale'] = $_SESSION['locale'];
    loadTranslations($_SESSION['locale']);
}
