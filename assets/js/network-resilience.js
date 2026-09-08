/**
 * NIS-PPMS Low-Network & Resilience Engine
 * Provides instant feedback, weak connection detection (2G/3G),
 * automatic request retry with exponential backoff, and offline status toasts.
 */

(function () {
    'use strict';

    // 1. Create Toast UI Element
    let toastElem = null;

    function createNetworkToast() {
        if (document.getElementById('nis-net-toast')) return;

        toastElem = document.createElement('div');
        toastElem.id = 'nis-net-toast';
        toastElem.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 999999;
            padding: 10px 18px;
            border-radius: 30px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            font-size: 0.82rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.25);
            transition: all 0.3s ease;
            transform: translateY(100px);
            opacity: 0;
            pointer-events: none;
        `;
        document.body.appendChild(toastElem);
    }

    function showToast(message, type = 'info', duration = 4000) {
        if (!toastElem) createNetworkToast();
        if (!toastElem) return;

        let bg = '#1e293b';
        let text = '#ffffff';
        let icon = '⚡';

        if (type === 'offline') {
            bg = '#7f1d1d';
            text = '#fef2f2';
            icon = '📡';
        } else if (type === 'weak') {
            bg = '#78350f';
            text = '#fffbeb';
            icon = '⏳';
        } else if (type === 'online') {
            bg = '#145226';
            text = '#f0fdf4';
            icon = '✅';
        }

        toastElem.innerHTML = `<span>${icon}</span> <span>${message}</span>`;
        toastElem.style.background = bg;
        toastElem.style.color = text;
        toastElem.style.transform = 'translateY(0)';
        toastElem.style.opacity = '1';

        if (duration > 0) {
            setTimeout(() => {
                toastElem.style.transform = 'translateY(100px)';
                toastElem.style.opacity = '0';
            }, duration);
        }
    }

    // 2. Network Status Monitoring
    function updateNetworkStatus() {
        if (!navigator.onLine) {
            showToast('You are currently offline. Pages will load from local cache.', 'offline', 6000);
            return;
        }

        const conn = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (conn) {
            const type = conn.effectiveType;
            if (type === 'slow-2g' || type === '2g') {
                showToast('Very Weak Network (2G). Optimizing load speed...', 'weak', 5000);
            } else if (type === '3g' && conn.rtt > 1500) {
                showToast('High Latency Network. Content cached for speed.', 'weak', 4000);
            }
        }
    }

    window.addEventListener('online', () => {
        showToast('Network connection restored!', 'online', 3000);
    });

    window.addEventListener('offline', () => {
        showToast('Network lost. Working in Low-Network Offline Mode.', 'offline', 6000);
    });

    if (navigator.connection) {
        navigator.connection.addEventListener('change', updateNetworkStatus);
    }

    // 3. Auto Retry Fetch Wrapper with Exponential Backoff
    window.nisFetchWithRetry = async function (url, options = {}, retries = 2, delay = 1000) {
        try {
            const response = await fetch(url, options);
            if (!response.ok && retries > 0 && response.status >= 500) {
                throw new Error(`Server returned ${response.status}`);
            }
            return response;
        } catch (err) {
            if (retries <= 0) throw err;
            await new Promise(res => setTimeout(res, delay));
            return window.nisFetchWithRetry(url, options, retries - 1, delay * 1.5);
        }
    };

    // Initialize on DOM Ready
    document.addEventListener('DOMContentLoaded', () => {
        createNetworkToast();
        updateNetworkStatus();
    });

})();
