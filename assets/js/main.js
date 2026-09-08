document.addEventListener('DOMContentLoaded', function() {
    // Mobile menu toggle
    const menuToggle = document.querySelector('.mobile-menu-toggle');
    const topNav = document.querySelector('.top-nav');

    if (menuToggle && topNav) {
        menuToggle.addEventListener('click', function() {
            topNav.classList.toggle('show');
        });

        // Close menu when clicking outside
        document.addEventListener('click', function(e) {
            if (!topNav.contains(e.target) && !menuToggle.contains(e.target)) {
                topNav.classList.remove('show');
            }
        });
    }
});