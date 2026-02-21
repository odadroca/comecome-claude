# 🍽️ ComeCome v0.8 - ADHD-Friendly Food Tracking

A compassionate nutrition tracking application designed specifically for neuro-divergent children, particularly those with ADHD and medication-induced appetite challenges.

## 🎯 Purpose

ComeCome helps families monitor their children's eating habits with **minimal friction** and **maximum independence**. Built with input from parents of ADHD children on medication regimes that affect appetite.

## ✨ Key Features

### For Children
- **Tap to log, not type** - Big emoji food buttons
- **Auto-detect meal times** - One less decision to make
- **Simple portions** - "A little / Some / A lot / All" (no calorie counting)
- **Favorites rise to top** - Most-used items need fewest taps
- **Daily check-in** - Track appetite, mood, and medication
- **Weight tracking** - Simple visual charts
- **History view** - See what you ate

### For Parents/Guardians
- **Family dashboard** - Visual analytics and trends
- **Multiple children** - Manage whole family
- **Clinician reports** - Export as HTML, CSV, or JSON
- **Guest links** - Time-limited access for doctors
- **Full data control** - Backup, restore, or delete

### ADHD-Friendly Design
- ✅ Large touch targets (48px+)
- ✅ Clear visual hierarchy
- ✅ No overwhelming choices
- ✅ Dark mode support
- ✅ `prefers-reduced-motion` respect
- ✅ Haptic feedback
- ✅ Offline-first PWA

## 🚀 Installation

### Requirements
- PHP 7.4+ with SQLite support
- Any web server (Apache, Nginx, etc.)
- Modern web browser

### Quick Start

1. **Clone or download** this repository to your web server
2. **Ensure permissions** on the `db/` directory (writable)
3. **Access via browser** - The app will auto-initialize the database
4. **Default login**: Username: "Guardião", PIN: "0000"

```bash
# Example installation
cd /var/www/html
git clone [this-repo] comecome
chmod 755 -R comecome
chmod 775 comecome/db
chown www-data:www-data comecome/db
```

### No Build Step Required!
This application uses vanilla PHP, SQLite, and pure CSS/JS. Just upload and run.

## 📱 PWA Installation

ComeCome works as a Progressive Web App. On mobile:
1. Open in browser
2. Tap "Add to Home Screen"
3. Use like a native app!

## 🌍 Internationalization (i18n)

### Built-in Languages
- 🇵🇹 **Portuguese** (default)
- 🇬🇧 **English**

### Adding Your Language
1. Go to **Guardian Panel → Translations**
2. Select your target language
3. Edit translations directly in the interface
4. Translations are stored in database and override defaults

### Contributing Translations
ComeCome is designed for easy community translation:
- All strings use key-based translation (`t('key')`)
- Translation management UI included
- JSON files in `locales/` for base translations
- Database overrides for customization

## 📊 Export Formats

### HTML Report (Print-Ready)
Clean, professional format for medical appointments. Includes:
- Weight timeline with changes
- Medication adherence
- Daily meal counts
- Meals by type
- Intake by category

### CSV Export
Opens in Excel, Google Sheets, or LibreOffice. Perfect for:
- Data analysis
- Custom charts
- Clinical software import

### JSON Export
Complete data structure for:
- Technical integrations
- Backup purposes
- Data portability

## 🔐 Security

- **PIN-based authentication** (child-friendly 4-digit PINs)
- **Session management** with secure tokens
- **Guest access tokens** with expiration
- **SQLite database** - No external DB server needed
- **Input sanitization** and prepared statements
- **No external dependencies** for core functionality

## 🗄️ Database Schema

ComeCome uses SQLite with a clean, normalized schema:
- **users** - Children and guardians
- **meals** - 6 configurable meal types
- **foods** - Extensible food catalog
- **food_log** - Daily intake tracking
- **daily_checkin** - Appetite, mood, medication
- **weight_log** - Weight tracking
- **medications** - Medication management
- **translations** - i18n overrides
- **guest_tokens** - Temporary clinician access

## 🎨 Customization

### Meals
Portuguese meals are pre-configured:
- Pequeno Almoço
- Lanche da Manhã
- Almoço
- Lanche da Tarde
- Jantar
- Ceia

Edit meal names via the Translation interface.

### Foods
60+ foods included with emoji. Categories:
- Fruits, Vegetables, Proteins, Grains
- Dairy, Snacks, Drinks, Sweets

Add custom foods from the child interface!

### Settings
- Toggle medication visibility for young children
- Change default language
- Configure meal times

## 📖 Usage Guide

### First-Time Setup
1. Log in with default guardian account
2. Add your children (name, PIN, emoji avatar)
3. Add medications if needed
4. Configure settings

### Daily Use (Child)
1. Tap your name and enter PIN
2. Select current meal (auto-detected by time)
3. Tap food emoji
4. Choose portion size
5. Done! 🎉

### Monitoring (Guardian)
1. View dashboard for insights
2. Export reports for doctor visits
3. Generate guest links for clinicians
4. Manage family settings

## 🤝 Contributing

This is a FOSS project built with love for families managing ADHD.

**Ways to contribute:**
- 🌍 Add translations for your language
- 🍎 Suggest food items for your culture
- 🐛 Report bugs or suggestions
- 📖 Improve documentation
- 💻 Submit pull requests

## 📋 Technical Stack

- **Backend**: Vanilla PHP (no frameworks)
- **Database**: SQLite3
- **Frontend**: HTML5, CSS3, JavaScript
- **Styling**: Custom CSS (ADHD-optimized)
- **Charts**: Chart.js (CDN)
- **PWA**: Service Worker + Web Manifest

**Design Philosophy**: Keep it simple, accessible, and offline-capable.

## 🏥 Medical Disclaimer

ComeCome is a tracking tool, not medical software. Always consult healthcare professionals for medical advice. This tool is meant to facilitate communication with clinicians, not replace it.

## 📄 License

Open Source - Free to use, modify, and distribute.

## ❤️ Acknowledgments

Built for Eduardo and all children navigating ADHD with courage.

Special thanks to parents and caregivers who provided insights into real-world challenges.

---

**Made with 💚 by parents, for parents**

*"Making food tracking simple, so families can focus on what matters."*
