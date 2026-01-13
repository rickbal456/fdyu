/**
 * AIKAFLOW - Utility Functions
 * 
 * Common helper functions used throughout the application.
 */

const Utils = {
    /**
     * Generate a unique ID
     * @param {string} prefix - Optional prefix for the ID
     * @returns {string}
     */
    generateId(prefix = 'node') {
        const timestamp = Date.now().toString(36);
        const random = Math.random().toString(36).substring(2, 9);
        return `${prefix}_${timestamp}_${random}`;
    },

    /**
     * Deep clone an object
     * @param {Object} obj - Object to clone
     * @returns {Object}
     */
    deepClone(obj) {
        if (obj === null || typeof obj !== 'object') {
            return obj;
        }

        if (obj instanceof Date) {
            return new Date(obj.getTime());
        }

        if (Array.isArray(obj)) {
            return obj.map(item => this.deepClone(item));
        }

        const cloned = {};
        for (const key in obj) {
            if (Object.prototype.hasOwnProperty.call(obj, key)) {
                cloned[key] = this.deepClone(obj[key]);
            }
        }
        return cloned;
    },

    /**
     * Debounce function execution
     * @param {Function} func - Function to debounce
     * @param {number} wait - Wait time in ms
     * @returns {Function}
     */
    debounce(func, wait = 300) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func.apply(this, args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },

    /**
     * Throttle function execution
     * @param {Function} func - Function to throttle
     * @param {number} limit - Time limit in ms
     * @returns {Function}
     */
    throttle(func, limit = 100) {
        let inThrottle;
        return function executedFunction(...args) {
            if (!inThrottle) {
                func.apply(this, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },

    /**
     * Clamp a number between min and max
     * @param {number} value - Value to clamp
     * @param {number} min - Minimum value
     * @param {number} max - Maximum value
     * @returns {number}
     */
    clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    },

    /**
     * Linear interpolation between two values
     * @param {number} a - Start value
     * @param {number} b - End value
     * @param {number} t - Interpolation factor (0-1)
     * @returns {number}
     */
    lerp(a, b, t) {
        return a + (b - a) * t;
    },

    /**
     * Map a value from one range to another
     * @param {number} value - Value to map
     * @param {number} inMin - Input range minimum
     * @param {number} inMax - Input range maximum
     * @param {number} outMin - Output range minimum
     * @param {number} outMax - Output range maximum
     * @returns {number}
     */
    mapRange(value, inMin, inMax, outMin, outMax) {
        return ((value - inMin) * (outMax - outMin)) / (inMax - inMin) + outMin;
    },

    /**
     * Format file size to human readable string
     * @param {number} bytes - Size in bytes
     * @returns {string}
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';

        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));

        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    /**
     * Format duration in seconds to human readable string
     * @param {number} seconds - Duration in seconds
     * @returns {string}
     */
    formatDuration(seconds) {
        if (seconds < 60) {
            return `${Math.round(seconds)}s`;
        }

        const mins = Math.floor(seconds / 60);
        const secs = Math.round(seconds % 60);

        if (mins < 60) {
            return secs > 0 ? `${mins}m ${secs}s` : `${mins}m`;
        }

        const hours = Math.floor(mins / 60);
        const remainingMins = mins % 60;
        return `${hours}h ${remainingMins}m`;
    },

    /**
     * Format date to relative time string
     * @param {Date|string} date - Date to format
     * @returns {string}
     */
    formatRelativeTime(date) {
        const now = new Date();
        const then = new Date(date);
        const seconds = Math.floor((now - then) / 1000);

        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;

        return then.toLocaleDateString();
    },

    /**
     * Escape HTML special characters
     * @param {string} str - String to escape
     * @returns {string}
     */
    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    /**
     * Parse HTML string to DOM elements
     * @param {string} html - HTML string
     * @returns {DocumentFragment}
     */
    parseHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        return template.content;
    },

    /**
     * Create DOM element with attributes and children
     * @param {string} tag - HTML tag name
     * @param {Object} attrs - Attributes object
     * @param {Array|string} children - Child elements or text
     * @returns {HTMLElement}
     */
    createElement(tag, attrs = {}, children = []) {
        const element = document.createElement(tag);

        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'className') {
                element.className = value;
            } else if (key === 'style' && typeof value === 'object') {
                Object.assign(element.style, value);
            } else if (key.startsWith('on') && typeof value === 'function') {
                element.addEventListener(key.substring(2).toLowerCase(), value);
            } else if (key === 'dataset') {
                Object.assign(element.dataset, value);
            } else {
                element.setAttribute(key, value);
            }
        }

        if (typeof children === 'string') {
            element.textContent = children;
        } else if (Array.isArray(children)) {
            children.forEach(child => {
                if (typeof child === 'string') {
                    element.appendChild(document.createTextNode(child));
                } else if (child instanceof Node) {
                    element.appendChild(child);
                }
            });
        }

        return element;
    },

    /**
     * Get element's position relative to the page
     * @param {HTMLElement} element - Target element
     * @returns {Object} { x, y, width, height }
     */
    getElementPosition(element) {
        const rect = element.getBoundingClientRect();
        return {
            x: rect.left + window.scrollX,
            y: rect.top + window.scrollY,
            width: rect.width,
            height: rect.height
        };
    },

    /**
     * Check if point is inside rectangle
     * @param {Object} point - { x, y }
     * @param {Object} rect - { x, y, width, height }
     * @returns {boolean}
     */
    pointInRect(point, rect) {
        return (
            point.x >= rect.x &&
            point.x <= rect.x + rect.width &&
            point.y >= rect.y &&
            point.y <= rect.y + rect.height
        );
    },

    /**
     * Check if two rectangles intersect
     * @param {Object} rect1 - { x, y, width, height }
     * @param {Object} rect2 - { x, y, width, height }
     * @returns {boolean}
     */
    rectsIntersect(rect1, rect2) {
        return !(
            rect1.x + rect1.width < rect2.x ||
            rect2.x + rect2.width < rect1.x ||
            rect1.y + rect1.height < rect2.y ||
            rect2.y + rect2.height < rect1.y
        );
    },

    /**
     * Calculate distance between two points
     * @param {Object} p1 - { x, y }
     * @param {Object} p2 - { x, y }
     * @returns {number}
     */
    distance(p1, p2) {
        const dx = p2.x - p1.x;
        const dy = p2.y - p1.y;
        return Math.sqrt(dx * dx + dy * dy);
    },

    /**
     * Get bezier curve control points for connection
     * @param {Object} start - { x, y }
     * @param {Object} end - { x, y }
     * @returns {Object} { cp1: {x, y}, cp2: {x, y} }
     */
    getBezierControlPoints(start, end) {
        const dx = Math.abs(end.x - start.x);
        const dy = Math.abs(end.y - start.y);
        const offset = Math.min(dx * 0.5, 150);

        return {
            cp1: { x: start.x + offset, y: start.y },
            cp2: { x: end.x - offset, y: end.y }
        };
    },

    /**
     * Generate SVG path for bezier connection
     * @param {Object} start - { x, y }
     * @param {Object} end - { x, y }
     * @param {string} style - 'bezier', 'straight', 'step'
     * @returns {string}
     */
    generateConnectionPath(start, end, style = 'bezier') {
        if (style === 'straight') {
            return `M ${start.x} ${start.y} L ${end.x} ${end.y}`;
        }

        if (style === 'step') {
            const midX = (start.x + end.x) / 2;
            return `M ${start.x} ${start.y} H ${midX} V ${end.y} H ${end.x}`;
        }

        // Default: bezier
        const { cp1, cp2 } = this.getBezierControlPoints(start, end);
        return `M ${start.x} ${start.y} C ${cp1.x} ${cp1.y}, ${cp2.x} ${cp2.y}, ${end.x} ${end.y}`;
    },

    /**
     * Snap value to grid
     * @param {number} value - Value to snap
     * @param {number} gridSize - Grid size
     * @returns {number}
     */
    snapToGrid(value, gridSize = 20) {
        return Math.round(value / gridSize) * gridSize;
    },

    /**
     * Copy text to clipboard
     * @param {string} text - Text to copy
     * @returns {Promise<boolean>}
     */
    async copyToClipboard(text) {
        try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                await navigator.clipboard.writeText(text);
                return true;
            }

            // Fallback
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            const success = document.execCommand('copy');
            document.body.removeChild(textarea);
            return success;
        } catch (err) {
            console.error('Failed to copy:', err);
            return false;
        }
    },

    /**
     * Read text from clipboard
     * @returns {Promise<string>}
     */
    async readFromClipboard() {
        try {
            if (navigator.clipboard && navigator.clipboard.readText) {
                return await navigator.clipboard.readText();
            }
            return '';
        } catch (err) {
            console.error('Failed to read clipboard:', err);
            return '';
        }
    },

    /**
     * Download file from data
     * @param {string} filename - File name
     * @param {string} data - File data
     * @param {string} type - MIME type
     */
    downloadFile(filename, data, type = 'application/json') {
        const blob = new Blob([data], { type });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    },

    /**
     * Read file as text
     * @param {File} file - File to read
     * @returns {Promise<string>}
     */
    readFileAsText(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(reader.error);
            reader.readAsText(file);
        });
    },

    /**
     * Read file as data URL
     * @param {File} file - File to read
     * @returns {Promise<string>}
     */
    readFileAsDataURL(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(reader.result);
            reader.onerror = () => reject(reader.error);
            reader.readAsDataURL(file);
        });
    },

    /**
     * Validate URL
     * @param {string} url - URL to validate
     * @returns {boolean}
     */
    isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    },

    /**
     * Get file extension from URL or filename
     * @param {string} str - URL or filename
     * @returns {string}
     */
    getFileExtension(str) {
        const match = str.match(/\.([^./?#]+)(?:[?#]|$)/);
        return match ? match[1].toLowerCase() : '';
    },

    /**
     * Determine media type from URL or filename
     * @param {string} str - URL or filename
     * @returns {string} 'image', 'video', 'audio', or 'unknown'
     */
    getMediaType(str) {
        const ext = this.getFileExtension(str);

        const imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
        const videoExts = ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'];
        const audioExts = ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac'];

        if (imageExts.includes(ext)) return 'image';
        if (videoExts.includes(ext)) return 'video';
        if (audioExts.includes(ext)) return 'audio';

        return 'unknown';
    },

    /**
     * Simple object equality check
     * @param {Object} obj1 - First object
     * @param {Object} obj2 - Second object
     * @returns {boolean}
     */
    isEqual(obj1, obj2) {
        return JSON.stringify(obj1) === JSON.stringify(obj2);
    },

    /**
     * Get nested property from object using dot notation
     * @param {Object} obj - Source object
     * @param {string} path - Property path (e.g., 'a.b.c')
     * @param {*} defaultValue - Default value if not found
     * @returns {*}
     */
    getNestedProperty(obj, path, defaultValue = undefined) {
        const keys = path.split('.');
        let result = obj;

        for (const key of keys) {
            if (result === null || result === undefined) {
                return defaultValue;
            }
            result = result[key];
        }

        return result !== undefined ? result : defaultValue;
    },

    /**
     * Set nested property on object using dot notation
     * @param {Object} obj - Target object
     * @param {string} path - Property path (e.g., 'a.b.c')
     * @param {*} value - Value to set
     */
    setNestedProperty(obj, path, value) {
        const keys = path.split('.');
        let current = obj;

        for (let i = 0; i < keys.length - 1; i++) {
            const key = keys[i];
            if (!(key in current) || typeof current[key] !== 'object') {
                current[key] = {};
            }
            current = current[key];
        }

        current[keys[keys.length - 1]] = value;
    },

    /**
     * Sleep for specified milliseconds
     * @param {number} ms - Milliseconds to sleep
     * @returns {Promise}
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    },

    /**
     * Retry a function with exponential backoff
     * @param {Function} fn - Async function to retry
     * @param {number} maxRetries - Maximum number of retries
     * @param {number} baseDelay - Base delay in ms
     * @returns {Promise}
     */
    async retry(fn, maxRetries = 3, baseDelay = 1000) {
        let lastError;

        for (let i = 0; i < maxRetries; i++) {
            try {
                return await fn();
            } catch (error) {
                lastError = error;
                if (i < maxRetries - 1) {
                    await this.sleep(baseDelay * Math.pow(2, i));
                }
            }
        }

        throw lastError;
    },

    /**
     * Create a cancelable promise
     * @param {Promise} promise - Promise to wrap
     * @returns {Object} { promise, cancel }
     */
    makeCancelable(promise) {
        let isCanceled = false;

        const wrappedPromise = new Promise((resolve, reject) => {
            promise.then(
                value => isCanceled ? reject({ isCanceled: true }) : resolve(value),
                error => isCanceled ? reject({ isCanceled: true }) : reject(error)
            );
        });

        return {
            promise: wrappedPromise,
            cancel() {
                isCanceled = true;
            }
        };
    },

    /**
     * Get color for node category
     * @param {string} category - Node category
     * @returns {Object} { bg, text, border }
     */
    getCategoryColor(category) {
        const colors = {
            input: { bg: 'rgba(59, 130, 246, 0.2)', text: '#3b82f6', border: '#3b82f6' },
            generation: { bg: 'rgba(139, 92, 246, 0.2)', text: '#8b5cf6', border: '#8b5cf6' },
            audio: { bg: 'rgba(34, 197, 94, 0.2)', text: '#22c55e', border: '#22c55e' },
            editing: { bg: 'rgba(249, 115, 22, 0.2)', text: '#f97316', border: '#f97316' },
            output: { bg: 'rgba(6, 182, 212, 0.2)', text: '#06b6d4', border: '#06b6d4' },
            utility: { bg: 'rgba(107, 114, 128, 0.2)', text: '#6b7280', border: '#6b7280' },
            control: { bg: 'rgba(16, 185, 129, 0.2)', text: '#10b981', border: '#10b981' }
        };


        return colors[category] || colors.utility;
    },

    /**
     * Get icon name for node type
     * @param {string} nodeType - Node type
     * @returns {string}
     */
    getNodeIcon(nodeType) {
        const icons = {
            'image-input': 'image',
            'video-input': 'video',
            'audio-input': 'music',
            'text-input': 'type',
            'text-to-image': 'image-plus',
            'image-to-video': 'clapperboard',
            'text-to-video': 'film',
            'text-to-speech': 'volume-2',
            'music-gen': 'music-2',
            'audio-merge': 'git-merge',
            'audio-trim': 'scissors',
            'voice-clone': 'mic',
            'speech-to-text': 'file-text',
            'video-merge': 'layers',
            'video-trim': 'scissors',
            'add-audio': 'volume-2',
            'add-subtitles': 'subtitles',
            'resize': 'maximize-2',
            'filters': 'palette',
            'video-output': 'film',
            'image-output': 'image',
            'audio-output': 'music',
            'delay': 'timer',
            'condition': 'git-branch',
            'loop': 'repeat',
            'manual-trigger': 'play-circle',
            'set-variable': 'box',
            'get-variable': 'package',
            'flow-merge': 'git-merge'
        };


        return icons[nodeType] || 'box';
    },

    /**
     * Show a toast notification
     * @param {string} message - Message to display
     * @param {string} type - 'success', 'error', 'warning', or 'info'
     * @param {number} duration - Duration in ms (default: 3000)
     */
    showToast(message, type = 'info', duration = 3000) {
        // Create toast container if it doesn't exist
        let container = document.getElementById('toast-container');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toast-container';
            container.className = 'fixed top-4 right-4 z-[9999] flex flex-col gap-2 pointer-events-none';
            document.body.appendChild(container);
        }

        // Create toast element
        const toast = document.createElement('div');
        toast.className = `pointer-events-auto max-w-sm w-full px-4 py-3 rounded-lg shadow-lg transform translate-x-full transition-all duration-300 flex items-start gap-3`;

        // Type-specific styles
        const typeStyles = {
            success: 'bg-green-500/90 text-white border-l-4 border-green-300',
            error: 'bg-red-500/90 text-white border-l-4 border-red-300',
            warning: 'bg-yellow-500/90 text-yellow-900 border-l-4 border-yellow-300',
            info: 'bg-blue-500/90 text-white border-l-4 border-blue-300'
        };

        const typeIcons = {
            success: 'check-circle',
            error: 'x-circle',
            warning: 'alert-triangle',
            info: 'info'
        };

        toast.className += ' ' + (typeStyles[type] || typeStyles.info);

        toast.innerHTML = `
            <i data-lucide="${typeIcons[type] || 'info'}" class="w-5 h-5 flex-shrink-0 mt-0.5"></i>
            <span class="flex-1 text-sm font-medium">${this.escapeHtml(message)}</span>
            <button onclick="this.parentElement.remove()" class="flex-shrink-0 hover:opacity-70">
                <i data-lucide="x" class="w-4 h-4"></i>
            </button>
        `;

        container.appendChild(toast);

        // Initialize Lucide icons in toast
        if (window.lucide) {
            lucide.createIcons({ nodes: [toast] });
        }

        // Animate in
        requestAnimationFrame(() => {
            toast.classList.remove('translate-x-full');
            toast.classList.add('translate-x-0');
        });

        // Auto remove after duration
        setTimeout(() => {
            toast.classList.remove('translate-x-0');
            toast.classList.add('translate-x-full', 'opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, duration);
    }
};

// Make Utils available globally
window.Utils = Utils;

// Expose showToast on window for easy access
window.showToast = Utils.showToast.bind(Utils);