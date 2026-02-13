/**
 * ComeCome - Main Application JavaScript
 * ADHD-Friendly interactions with celebrations & warmth
 */

// Register service worker for PWA
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then(reg => console.log('Service Worker registered'))
            .catch(err => console.log('Service Worker registration failed', err));
    });
}

// Theme switcher (respects system preference)
function initTheme() {
    if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
        document.documentElement.setAttribute('data-theme', 'dark');
    }

    // Listen for changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', e => {
        document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
    });
}

// Enhanced touch interactions for mobile
function initTouchInteractions() {
    // Prevent double-tap zoom on buttons
    document.querySelectorAll('button, .btn-primary, .btn-secondary').forEach(btn => {
        btn.addEventListener('touchend', function(e) {
            e.preventDefault();
            this.click();
        });
    });
}

// Dialog polyfill for older browsers
function initDialogs() {
    if (!window.HTMLDialogElement) {
        console.warn('Dialog element not supported');
    }
}

// Auto-save forms (for check-ins)
function initAutoSave() {
    const forms = document.querySelectorAll('[data-autosave]');
    forms.forEach(form => {
        const inputs = form.querySelectorAll('input, textarea, select');
        inputs.forEach(input => {
            input.addEventListener('change', () => {
                const formData = new FormData(form);
                const key = 'autosave_' + form.id;
                const data = Object.fromEntries(formData);
                localStorage.setItem(key, JSON.stringify(data));
            });
        });

        // Restore on load
        const key = 'autosave_' + form.id;
        const saved = localStorage.getItem(key);
        if (saved) {
            const data = JSON.parse(saved);
            Object.keys(data).forEach(name => {
                const input = form.querySelector(`[name="${name}"]`);
                if (input) input.value = data[name];
            });
        }
    });
}

// Vibration feedback for important actions
function vibrate(pattern = 50) {
    if ('vibrate' in navigator) {
        navigator.vibrate(pattern);
    }
}

// ============================================================
// CONFETTI CELEBRATION SYSTEM
// Because logging food when you have no appetite is HEROIC
// ============================================================
function launchConfetti() {
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) return; // Respect accessibility

    const container = document.createElement('div');
    container.className = 'confetti-container';
    container.setAttribute('aria-hidden', 'true');
    document.body.appendChild(container);

    const emojis = ['🎉', '🌟', '⭐', '✨', '💫', '🎊', '🥳', '💪', '🌈', '❤️', '🦋', '🎵'];
    const count = 40;

    for (let i = 0; i < count; i++) {
        const piece = document.createElement('div');
        piece.className = 'confetti-piece';
        piece.textContent = emojis[Math.floor(Math.random() * emojis.length)];
        piece.style.left = Math.random() * 100 + 'vw';
        piece.style.animationDelay = Math.random() * 0.8 + 's';
        piece.style.animationDuration = (1.5 + Math.random() * 2) + 's';
        piece.style.fontSize = (1 + Math.random() * 1.5) + 'rem';
        container.appendChild(piece);
    }

    // Clean up after animation
    setTimeout(() => container.remove(), 4000);
}

// ============================================================
// STREAK TRACKING
// Showing up is everything. Celebrate consistency.
// ============================================================
function getStreak() {
    const streakData = JSON.parse(localStorage.getItem('comecome_streak') || '{"count":0,"lastDate":""}');
    const today = new Date().toISOString().split('T')[0];

    if (streakData.lastDate === today) {
        return streakData.count;
    }

    const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
    if (streakData.lastDate === yesterday) {
        return streakData.count; // Will be incremented when they log
    }

    return 0; // Streak broken, but that's OK
}

function updateStreak() {
    const streakData = JSON.parse(localStorage.getItem('comecome_streak') || '{"count":0,"lastDate":""}');
    const today = new Date().toISOString().split('T')[0];

    if (streakData.lastDate === today) {
        return streakData.count; // Already counted today
    }

    const yesterday = new Date(Date.now() - 86400000).toISOString().split('T')[0];
    let newCount;
    if (streakData.lastDate === yesterday) {
        newCount = streakData.count + 1;
    } else {
        newCount = 1; // Fresh start - and that's perfectly fine
    }

    localStorage.setItem('comecome_streak', JSON.stringify({ count: newCount, lastDate: today }));
    return newCount;
}

function getStreakEmoji(count) {
    if (count >= 30) return '🏆';
    if (count >= 14) return '🔥';
    if (count >= 7) return '🌟';
    if (count >= 3) return '⭐';
    return '✨';
}

// ============================================================
// STAGGERED FOOD CARD ANIMATIONS
// Each card pops in with a little delay - feels alive!
// ============================================================
function initFoodCardAnimations() {
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReducedMotion) return;

    const cards = document.querySelectorAll('.food-card');
    cards.forEach((card, index) => {
        card.style.animationDelay = (index * 0.03) + 's';
        card.classList.add('card-pop-in');
    });

    // Also animate meal buttons
    const mealBtns = document.querySelectorAll('.meal-btn');
    mealBtns.forEach((btn, index) => {
        btn.style.animationDelay = (index * 0.08) + 's';
        btn.classList.add('card-pop-in');
    });

    // Animate face options in check-in
    const faceOptions = document.querySelectorAll('.face-option');
    faceOptions.forEach((opt, index) => {
        opt.style.animationDelay = (index * 0.1) + 's';
        opt.classList.add('card-pop-in');
    });

    // Animate user cards on login
    const userCards = document.querySelectorAll('.user-card');
    userCards.forEach((card, index) => {
        card.style.animationDelay = (index * 0.12) + 's';
        card.classList.add('card-pop-in');
    });
}

// ============================================================
// TIME-BASED GREETING HELPER
// ============================================================
function getTimeOfDay() {
    const hour = new Date().getHours();
    if (hour < 6) return 'night';
    if (hour < 12) return 'morning';
    if (hour < 18) return 'afternoon';
    return 'evening';
}

function getTimeGreetingEmoji() {
    const tod = getTimeOfDay();
    const emojis = {
        'night': '🌙',
        'morning': '🌅',
        'afternoon': '☀️',
        'evening': '🌆'
    };
    return emojis[tod] || '👋';
}

// ============================================================
// ENCOURAGING MESSAGES
// Rotate through kind words - because words matter
// ============================================================
function getRandomEncouragement(type) {
    // These are defined here as fallbacks; the PHP templates
    // will use translated versions from the i18n system
    const messages = {
        food: [
            "You're doing amazing!",
            "Every bite counts!",
            "You're taking care of yourself!",
            "That's wonderful!",
            "Your body says thank you!"
        ],
        checkin: [
            "Thanks for checking in!",
            "Your feelings matter!",
            "You're so brave for sharing!",
            "That took courage!",
            "You're doing great!"
        ],
        weight: [
            "Thanks for tracking!",
            "You're growing!",
            "Every number tells a story!",
            "You're doing awesome!",
            "Great job keeping track!"
        ]
    };
    const list = messages[type] || messages.food;
    return list[Math.floor(Math.random() * list.length)];
}

// ============================================================
// FACE SCALE INTERACTION ENHANCEMENT
// Make selecting mood/appetite feel more responsive
// ============================================================
function initFaceScaleInteractions() {
    document.querySelectorAll('.face-option').forEach(option => {
        option.addEventListener('click', function() {
            // Remove active from siblings
            const scale = this.closest('.face-scale');
            scale.querySelectorAll('.face-option').forEach(o => o.classList.remove('face-selected'));
            this.classList.add('face-selected');
            vibrate(30);
        });
    });
}

// ============================================================
// OFFLINE BANNER
// Gentle notification, not scary
// ============================================================
function initOfflineBanner() {
    window.addEventListener('offline', () => {
        let banner = document.getElementById('offlineBanner');
        if (!banner) {
            banner = document.createElement('div');
            banner.id = 'offlineBanner';
            banner.className = 'offline-banner';
            banner.innerHTML = '📡 You\'re offline - no worries, keep going!';
            document.body.prepend(banner);
        }
        banner.classList.add('visible');
    });

    window.addEventListener('online', () => {
        const banner = document.getElementById('offlineBanner');
        if (banner) {
            banner.classList.remove('visible');
            setTimeout(() => banner.remove(), 500);
        }
    });
}

// ============================================================
// INIT EVERYTHING
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
    initTheme();
    initTouchInteractions();
    initDialogs();
    initAutoSave();
    initFoodCardAnimations();
    initFaceScaleInteractions();
    initOfflineBanner();

    // Add success vibration to food logging
    document.querySelectorAll('.portion-btn').forEach(btn => {
        btn.addEventListener('click', () => vibrate([50, 100, 50]));
    });

    // Add the streak display if on child interface
    const streakDisplay = document.getElementById('streakDisplay');
    if (streakDisplay) {
        const count = getStreak();
        if (count > 0) {
            streakDisplay.textContent = getStreakEmoji(count) + ' ' + count;
            streakDisplay.style.display = 'inline-flex';
        }
    }
});

// Offline detection
window.addEventListener('online', () => {
    console.log('Back online');
});

window.addEventListener('offline', () => {
    console.log('Offline mode');
});
