/**
 * Global Security & Anti-Inspection Engine - NIS-PPMS
 * Disables right-click context menu & developer tools shortcuts across the application.
 */
document.addEventListener('DOMContentLoaded', function() {
    // 1. Disable Right Click (Context Menu) globally
    document.addEventListener('contextmenu', function(e) {
        e.preventDefault();
        return false;
    }, true);

    // 2. Disable inspect element & source viewing keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // F12 key
        if (e.key === 'F12' || e.keyCode === 123) {
            e.preventDefault();
            return false;
        }
        // Ctrl+Shift+I (Inspect), Ctrl+Shift+J (Console), Ctrl+Shift+C (Element Picker), Ctrl+Shift+K (Firefox Console)
        if (e.ctrlKey && e.shiftKey && (e.key === 'I' || e.key === 'i' || e.key === 'J' || e.key === 'j' || e.key === 'C' || e.key === 'c' || e.key === 'K' || e.key === 'k')) {
            e.preventDefault();
            return false;
        }
        // Ctrl+U (View Source)
        if (e.ctrlKey && (e.key === 'U' || e.key === 'u')) {
            e.preventDefault();
            return false;
        }
        // Ctrl+S (Save Page)
        if (e.ctrlKey && (e.key === 'S' || e.key === 's')) {
            e.preventDefault();
            return false;
        }
    }, true);
});

// Immediate execution fallback before DOMContentLoaded fires
document.oncontextmenu = function(e) {
    if (e) e.preventDefault();
    return false;
};
