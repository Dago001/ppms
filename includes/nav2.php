<?php 
require_once __DIR__ . '/permissions.php';
if (isLoggedIn()): ?>
    
        <div class="container-fluid">
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/dashboard.php">Dashboard</a>
                    </li>
                    <?php if (hasPermission('view_report')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/search.php">Search Records</a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasPermission('add_report')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/form.php">Add New Record</a>
                    </li>
                    <?php endif; ?>
                    <?php if (hasPermission('manage_users')): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/admin/users.php">Manage Users</a>
                    </li>
                    <?php endif; ?>
                    <?php if (isSuperAdmin()): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/admin/super_admin.php">Super Admin</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/edit_profile.php">Profile</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="/nisposting/logout.php">Logout</a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
<?php endif; ?>
