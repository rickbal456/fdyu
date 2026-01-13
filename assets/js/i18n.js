/**
 * AIKAFLOW Internationalization (i18n) Module
 * 
 * Handles language switching, translation, and RTL support.
 * Supports: English (en), Bahasa Indonesia (id), Arabic (ar)
 */

(function () {
    'use strict';

    const SUPPORTED_LANGUAGES = ['en', 'id', 'ar'];
    const DEFAULT_LANGUAGE = 'en';
    const STORAGE_KEY = 'aikaflow_language';

    class I18n {
        constructor() {
            this.currentLanguage = DEFAULT_LANGUAGE;
            this.translations = {};
            this.loadedLanguages = new Set();
            this.initialized = false;
        }

        /**
         * Initialize the i18n system
         */
        async init() {
            // Get saved language preference
            this.currentLanguage = this.getSavedLanguage();

            // Load the current language
            await this.loadLanguage(this.currentLanguage);

            // Apply RTL if needed
            this.applyDirection();

            // Translate existing elements
            this.translatePage();

            // Update language switcher to show current language
            this.updateLanguageSwitcher();

            this.initialized = true;

            // Dispatch event for other modules
            window.dispatchEvent(new CustomEvent('i18n:ready', {
                detail: { language: this.currentLanguage }
            }));
        }

        /**
         * Get saved language from localStorage or user data
         */
        getSavedLanguage() {
            // First check localStorage
            const saved = localStorage.getItem(STORAGE_KEY);
            if (saved && SUPPORTED_LANGUAGES.includes(saved)) {
                return saved;
            }

            // Check if user has language set (from PHP)
            if (window.AIKAFLOW?.user?.language && SUPPORTED_LANGUAGES.includes(window.AIKAFLOW.user.language)) {
                return window.AIKAFLOW.user.language;
            }

            // Browser language detection
            const browserLang = navigator.language?.split('-')[0];
            if (browserLang && SUPPORTED_LANGUAGES.includes(browserLang)) {
                return browserLang;
            }

            return DEFAULT_LANGUAGE;
        }

        /**
         * Load a language file
         */
        async loadLanguage(langCode) {
            if (this.loadedLanguages.has(langCode)) {
                return true;
            }

            try {
                const response = await fetch(`${window.AIKAFLOW?.baseUrl || ''}/assets/lang/${langCode}.json`);
                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                this.translations[langCode] = await response.json();
                this.loadedLanguages.add(langCode);
                return true;
            } catch (error) {
                console.error(`Failed to load language: ${langCode}`, error);

                // Fallback to English if available
                if (langCode !== DEFAULT_LANGUAGE && this.loadedLanguages.has(DEFAULT_LANGUAGE)) {
                    return false;
                }
                return false;
            }
        }

        /**
         * Switch to a different language
         */
        async setLanguage(langCode) {
            if (!SUPPORTED_LANGUAGES.includes(langCode)) {
                console.warn(`Unsupported language: ${langCode}`);
                return false;
            }

            if (langCode === this.currentLanguage) {
                return true;
            }

            // Load the language if not already loaded
            const loaded = await this.loadLanguage(langCode);
            if (!loaded && langCode !== DEFAULT_LANGUAGE) {
                console.warn(`Failed to load ${langCode}, staying on ${this.currentLanguage}`);
                return false;
            }

            this.currentLanguage = langCode;

            // Save preference
            localStorage.setItem(STORAGE_KEY, langCode);

            // Save to server
            this.saveLanguageToServer(langCode);

            // Apply RTL/LTR
            this.applyDirection();

            // Translate page
            this.translatePage();

            // Update language switcher UI
            this.updateLanguageSwitcher();

            // Dispatch event
            window.dispatchEvent(new CustomEvent('i18n:changed', {
                detail: { language: langCode }
            }));

            return true;
        }

        /**
         * Save language preference to server
         */
        async saveLanguageToServer(langCode) {
            try {
                await fetch(`${window.AIKAFLOW?.apiUrl || '/api'}/user/language.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ language: langCode })
                });
            } catch (error) {
                console.error('Failed to save language preference:', error);
            }
        }

        /**
         * Apply text direction (RTL for Arabic)
         */
        applyDirection() {
            const langData = this.translations[this.currentLanguage];
            const dir = langData?.meta?.dir || 'ltr';

            document.documentElement.setAttribute('dir', dir);
            document.documentElement.setAttribute('lang', this.currentLanguage);

            if (dir === 'rtl') {
                document.body.classList.add('rtl');
            } else {
                document.body.classList.remove('rtl');
            }
        }

        /**
         * Get translation for a key
         * @param {string} key - Dot notation key, e.g., 'menu.settings'
         * @param {object} params - Optional parameters for interpolation
         */
        t(key, params = {}) {
            const keys = key.split('.');
            let value = this.translations[this.currentLanguage];

            for (const k of keys) {
                if (value && typeof value === 'object' && k in value) {
                    value = value[k];
                } else {
                    // Fallback to English
                    value = this.getFallback(key);
                    break;
                }
            }

            if (typeof value !== 'string') {
                return key; // Return the key if translation not found
            }

            // Parameter interpolation
            return value.replace(/\{\{(\w+)\}\}/g, (match, paramKey) => {
                return params[paramKey] !== undefined ? params[paramKey] : match;
            });
        }

        /**
         * Get fallback translation from English
         */
        getFallback(key) {
            const keys = key.split('.');
            let value = this.translations[DEFAULT_LANGUAGE];

            for (const k of keys) {
                if (value && typeof value === 'object' && k in value) {
                    value = value[k];
                } else {
                    return null;
                }
            }

            return value;
        }

        /**
         * Translate all elements with data-i18n attribute
         */
        translatePage() {
            document.querySelectorAll('[data-i18n]').forEach(el => {
                const key = el.getAttribute('data-i18n');
                const translation = this.t(key);

                if (translation && translation !== key) {
                    // Check if we should update innerHTML or specific attribute
                    const attr = el.getAttribute('data-i18n-attr');
                    if (attr) {
                        el.setAttribute(attr, translation);
                    } else {
                        el.textContent = translation;
                    }
                }
            });

            // Translate placeholders
            document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
                const key = el.getAttribute('data-i18n-placeholder');
                const translation = this.t(key);
                if (translation && translation !== key) {
                    el.placeholder = translation;
                }
            });

            // Translate titles
            document.querySelectorAll('[data-i18n-title]').forEach(el => {
                const key = el.getAttribute('data-i18n-title');
                const translation = this.t(key);
                if (translation && translation !== key) {
                    el.title = translation;
                }
            });
        }

        /**
         * Update the language switcher UI elements
         */
        updateLanguageSwitcher() {
            const langData = this.translations[this.currentLanguage];
            const langName = langData?.meta?.name || this.currentLanguage.toUpperCase();
            const langCode = this.currentLanguage.toUpperCase();

            // Update desktop switcher
            const desktopLabel = document.getElementById('lang-current-desktop');
            if (desktopLabel) {
                desktopLabel.textContent = langCode;
            }

            // Update mobile switcher
            const mobileLabel = document.getElementById('lang-current-mobile');
            if (mobileLabel) {
                mobileLabel.textContent = langName;
            }

            // Update active state in dropdowns
            document.querySelectorAll('[data-lang]').forEach(el => {
                if (el.getAttribute('data-lang') === this.currentLanguage) {
                    el.classList.add('active', 'bg-primary-500/20', 'text-primary-400');
                } else {
                    el.classList.remove('active', 'bg-primary-500/20', 'text-primary-400');
                }
            });
        }

        /**
         * Get current language code
         */
        getLanguage() {
            return this.currentLanguage;
        }

        /**
         * Get all supported languages
         */
        getSupportedLanguages() {
            return SUPPORTED_LANGUAGES.map(code => ({
                code,
                name: this.translations[code]?.meta?.name || code,
                dir: this.translations[code]?.meta?.dir || 'ltr'
            }));
        }

        /**
         * Check if current language is RTL
         */
        isRTL() {
            return this.translations[this.currentLanguage]?.meta?.dir === 'rtl';
        }
    }

    // Create global instance
    window.I18n = new I18n();

    // Convenience function for translations
    window.t = (key, params) => window.I18n.t(key, params);

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => window.I18n.init());
    } else {
        window.I18n.init();
    }

})();
