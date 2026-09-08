<?php
// includes/footer.php
// Official NIS-PPMS Global Footer
?>
<footer class="app-global-footer">
    <div class="footer-container">
        <span>&copy; <?php echo date('Y'); ?> All Rights Reserved by Nigeria Immigration Service.</span>
        <span class="footer-separator">|</span>
        <span>Designed and Developed by <a href="https://www.immigration.gov.ng" target="_blank" class="footer-link">NIS Web Team</a></span>
    </div>
</footer>

<style>
.app-global-footer {
    background: #ffffff;
    border-top: 1px solid #e2e8f0;
    padding: 0.85rem 1.25rem;
    text-align: center;
    font-size: 0.775rem;
    color: #475569;
    margin-top: 2rem;
    width: 100%;
    box-sizing: border-box;
}
.footer-container {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}
.footer-separator {
    color: #cbd5e1;
}
.footer-link {
    color: #1a5632;
    font-weight: 600;
    text-decoration: none;
}
.footer-link:hover {
    text-decoration: underline;
}
@media (max-width: 600px) {
    .footer-container {
        flex-direction: column;
        gap: 0.2rem;
    }
    .footer-separator {
        display: none;
    }
}
</style>