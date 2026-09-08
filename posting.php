<?php
session_start();
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

if (!isLoggedIn()) {
    header('Location: login');
    exit();
}

// This page (and its ajax2/get_postings.php + ajax2/get_commands.php AJAX
// endpoints) predates the current tbl_emppersonal/tbl_employment schema -
// it targets a `commands`/`personnel`/`user_commands` table set that no
// longer exists, calls helper functions (getDashboardStats(), getUserRole(),
// etc.) that were never migrated over, and requires includes/functions.php,
// which doesn't exist either. It isn't linked from any current navigation;
// officer posting is handled by search.php + editp.php, and this stats view
// by dashboard.php. Redirect rather than let it fatal for anyone who still
// has this URL bookmarked.
header('Location: dashboard');
exit();
?>
<style>
    .posting-container {
        padding: 20px;
    }
    
    .posting-stats {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }
    
    .stat-card {
        background: white;
        padding: 1.2rem;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border: 1px solid #e2e8f0;
    }
    
    .stat-card h3 {
        color: #64748b;
        font-size: 0.8rem;
        margin: 0 0 0.5rem 0;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
    }
    
    .stat-card .stat-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #1e293b;
    }
    
    .posting-form-section {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        margin-bottom: 1.5rem;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        border: 1px solid #e2e8f0;
    }
    
    .section-title {
        font-size: 1rem;
        font-weight: 600;
        color: #27ae60;
        margin: 0 0 1rem 0;
        display: flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-bottom: 1rem;
    }
    
    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }
    }
    
    .form-group {
        margin-bottom: 1rem;
    }
    
    .form-group label {
        display: block;
        font-weight: 600;
        color: #475569;
        margin-bottom: 0.3rem;
        font-size: 0.8rem;
    }
    
    .form-control {
        width: 100%;
        padding: 0.6rem 0.75rem;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        font-size: 0.85rem;
        transition: border-color 0.2s;
        background: white;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #27ae60;
        box-shadow: 0 0 0 3px rgba(39, 174, 96, 0.1);
    }
    
    .btn {
        padding: 0.6rem 1.2rem;
        border-radius: 6px;
        font-size: 0.85rem;
        font-weight: 500;
        border: none;
        cursor: pointer;
        transition: all 0.2s;
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
    }
    
    .btn-success {
        background: #27ae60;
        color: white;
    }
    
    .btn-success:hover {
        background: #219a52;
    }
    
    .btn-primary {
        background: #3498db;
        color: white;
    }
    
    .btn-primary:hover {
        background: #2980b9;
    }
    
    .officer-suggestions {
        position: absolute;
        background: white;
        border: 1px solid #e2e8f0;
        border-radius: 6px;
        max-height: 200px;
        overflow-y: auto;
        width: 100%;
        z-index: 1000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        margin-top: 2px;
    }
    
    .officer-item {
        padding: 0.6rem 0.75rem;
        cursor: pointer;
        border-bottom: 1px solid #f1f5f9;
        font-size: 0.8rem;
        transition: background 0.15s;
    }
    
    .officer-item:hover {
        background: #f0fdf4;
    }
    
    .officer-item strong {
        color: #1e293b;
    }
    
    .officer-item .rank-badge {
        background: #dbeafe;
        color: #1e40af;
        padding: 0.1rem 0.4rem;
        border-radius: 4px;
        font-size: 0.7rem;
        margin-left: 0.5rem;
    }
    
    .selected-officer-box {
        background: #f0fdf4;
        border: 1px solid #27ae60;
        padding: 0.75rem;
        border-radius: 6px;
        margin-top: 0.5rem;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 0.85rem;
    }
    
    .remove-btn {
        color: #dc3545;
        cursor: pointer;
        padding: 0.3rem 0.5rem;
        border-radius: 4px;
        border: 1px solid #dc3545;
        background: white;
        font-size: 0.75rem;
    }
    
    .remove-btn:hover {
        background: #dc3545;
        color: white;
    }
    
    .postings-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.8rem;
    }
    
    .postings-table th {
        background: #f8fafc;
        padding: 0.6rem;
        text-align: left;
        font-weight: 600;
        color: #1e293b;
        border-bottom: 2px solid #e2e8f0;
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }
    
    .postings-table td {
        padding: 0.6rem;
        border-bottom: 1px solid #f1f5f9;
    }
    
    .postings-table tr:hover {
        background: #f8fafc;
    }
    
    .badge {
        display: inline-block;
        padding: 0.2rem 0.5rem;
        border-radius: 4px;
        font-size: 0.7rem;
        font-weight: 500;
    }
    
    .badge-success {
        background: #d4edda;
        color: #155724;
    }
    
    .badge-primary {
        background: #dbeafe;
        color: #1e40af;
    }
    
    .empty-state {
        text-align: center;
        padding: 2rem;
        color: #94a3b8;
    }
    
    .alert {
        padding: 0.75rem 1rem;
        border-radius: 6px;
        margin-bottom: 1rem;
        font-size: 0.85rem;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
</style>

<div class="posting-container">
    <div class="page-title">
        <h1><i class="fas fa-exchange-alt"></i> Officer Posting Management</h1>
        <p style="color: #64748b; margin: 0;">
            Role: <strong><?php echo htmlspecialchars($userRole['name']); ?></strong>
        </p>
    </div>

    <?php
    // Get stats
    $stats = getDashboardStats($_SESSION['user_id']);
    ?>

    <!-- Statistics -->
    <div class="posting-stats">
        <div class="stat-card">
            <h3>Total Posted Officers</h3>
            <div class="stat-value"><?php echo number_format($stats['total_posted']); ?></div>
        </div>
        <div class="stat-card">
            <h3>Recent Postings (30d)</h3>
            <div class="stat-value"><?php echo number_format($stats['recent_postings']); ?></div>
        </div>
        <div class="stat-card">
            <h3>My Formations</h3>
            <div class="stat-value"><?php echo number_format($stats['total_commands']); ?></div>
        </div>
    </div>

    <?php if ($canPost): ?>
    <!-- Post Officer Form -->
    <div class="posting-form-section">
        <h3 class="section-title"><i class="fas fa-user-plus"></i> Post Officer to Formation</h3>
        
        <form id="postingForm">
            <!-- Officer Search -->
            <div class="form-group" style="position: relative;">
                <label><i class="fas fa-search"></i> Search Officer (Service Number or Name)</label>
                <input type="text" id="officerSearch" class="form-control" 
                       placeholder="Type service number or name..." autocomplete="off">
                <div id="officerSuggestions" class="officer-suggestions" style="display: none;"></div>
                <div id="selectedOfficerDisplay" style="display: none;"></div>
                <input type="hidden" id="selectedServiceNo" name="serviceNo">
            </div>
            
            <!-- Zone and Command Selection -->
            <div class="form-row">
                <div class="form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Zone</label>
                    <select id="zoneSelect" class="form-control" onchange="loadCommands()">
                        <option value="">Select Zone</option>
                        <?php foreach ($allZones as $zone): ?>
                            <option value="<?php echo $zone['id']; ?>">
                                <?php echo htmlspecialchars($zone['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-building"></i> Command / Formation</label>
                    <select id="commandSelect" class="form-control" name="command_id" required disabled>
                        <option value="">Select a zone first</option>
                    </select>
                </div>
            </div>
            
            <button type="submit" class="btn btn-success" id="postBtn">
                <i class="fas fa-paper-plane"></i> Post Officer
            </button>
        </form>
    </div>
    <?php else: ?>
    <div class="posting-form-section">
        <div class="alert" style="background: #e3f2fd; color: #1565c0; border: 1px solid #bbdefb;">
            <i class="fas fa-info-circle"></i> You have <strong>view-only</strong> access. You cannot make postings.
        </div>
    </div>
    <?php endif; ?>

    <!-- View Current Postings -->
    <div class="posting-form-section">
        <h3 class="section-title"><i class="fas fa-list"></i> View Current Postings</h3>
        
        <div class="form-row">
            <div class="form-group">
                <label>Filter by Zone</label>
                <select id="filterZone" class="form-control" onchange="loadFilterCommands()">
                    <option value="">All Zones</option>
                    <?php foreach ($allZones as $zone): ?>
                        <option value="<?php echo $zone['id']; ?>">
                            <?php echo htmlspecialchars($zone['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Filter by Command</label>
                <select id="filterCommand" class="form-control" onchange="loadPostings()">
                    <option value="">All Commands</option>
                </select>
            </div>
        </div>
        
        <div id="postingsTableContainer">
            <div class="empty-state">
                <i class="fas fa-info-circle"></i>
                <p>Select a command to view currently posted officers</p>
            </div>
        </div>
    </div>
</div>

<script>
// Officer search with debounce
let searchTimeout;
document.getElementById('officerSearch').addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const search = this.value.trim();
    
    if (search.length < 2) {
        document.getElementById('officerSuggestions').style.display = 'none';
        return;
    }
    
    searchTimeout = setTimeout(() => {
        fetch('../ajax/search_officers.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'search=' + encodeURIComponent(search)
        })
        .then(response => response.json())
        .then(data => {
            const suggestions = document.getElementById('officerSuggestions');
            suggestions.innerHTML = '';
            
            if (data.length === 0) {
                suggestions.innerHTML = '<div class="officer-item" style="color: #94a3b8;">No officers found</div>';
                suggestions.style.display = 'block';
                return;
            }
            
            data.forEach(officer => {
                const div = document.createElement('div');
                div.className = 'officer-item';
                div.innerHTML = `
                    <strong>${officer.service_number}</strong> - 
                    ${officer.surname}, ${officer.firstname} ${officer.middlename || ''}
                    <span class="rank-badge">${officer.rank}</span>
                `;
                div.onclick = () => selectOfficer(officer);
                suggestions.appendChild(div);
            });
            suggestions.style.display = 'block';
        })
        .catch(error => {
            console.error('Search error:', error);
        });
    }, 300);
});

// Hide suggestions on outside click
document.addEventListener('click', function(e) {
    if (!e.target.closest('#officerSearch') && !e.target.closest('#officerSuggestions')) {
        document.getElementById('officerSuggestions').style.display = 'none';
    }
});

function selectOfficer(officer) {
    document.getElementById('selectedServiceNo').value = officer.service_number;
    document.getElementById('officerSearch').value = '';
    document.getElementById('officerSuggestions').style.display = 'none';
    
    const display = document.getElementById('selectedOfficerDisplay');
    display.innerHTML = `
        <div class="selected-officer-box">
            <div>
                <strong>${officer.service_number}</strong> - 
                <span class="rank-badge">${officer.rank}</span> 
                ${officer.surname}, ${officer.firstname} ${officer.middlename || ''}
                (${officer.gender})
            </div>
            <button type="button" class="remove-btn" onclick="clearSelectedOfficer()">
                <i class="fas fa-times"></i> Remove
            </button>
        </div>
    `;
    display.style.display = 'block';
}

function clearSelectedOfficer() {
    document.getElementById('selectedServiceNo').value = '';
    document.getElementById('selectedOfficerDisplay').style.display = 'none';
    document.getElementById('officerSearch').focus();
}

// Load commands for posting
function loadCommands() {
    const zoneId = document.getElementById('zoneSelect').value;
    const commandSelect = document.getElementById('commandSelect');
    
    if (!zoneId) {
        commandSelect.innerHTML = '<option value="">Select a zone first</option>';
        commandSelect.disabled = true;
        return;
    }
    
    commandSelect.innerHTML = '<option value="">Loading...</option>';
    commandSelect.disabled = true;
    
    fetch('../ajax/get_commands.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'zone_id=' + zoneId
    })
    .then(response => response.json())
    .then(data => {
        commandSelect.innerHTML = '<option value="">Select Command</option>';
        if (data.length === 0) {
            commandSelect.innerHTML += '<option value="" disabled>No commands available</option>';
        } else {
            data.forEach(cmd => {
                commandSelect.innerHTML += `<option value="${cmd.id}">${cmd.name}</option>`;
            });
        }
        commandSelect.disabled = false;
    })
    .catch(error => {
        commandSelect.innerHTML = '<option value="">Error loading commands</option>';
        console.error('Error:', error);
    });
}

// Load commands for filtering
function loadFilterCommands() {
    const zoneId = document.getElementById('filterZone').value;
    const commandSelect = document.getElementById('filterCommand');
    
    if (!zoneId) {
        commandSelect.innerHTML = '<option value="">All Commands</option>';
        loadPostings();
        return;
    }
    
    commandSelect.innerHTML = '<option value="">Loading...</option>';
    
    fetch('../ajax/get_commands.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'zone_id=' + zoneId
    })
    .then(response => response.json())
    .then(data => {
        commandSelect.innerHTML = '<option value="">All Commands</option>';
        data.forEach(cmd => {
            commandSelect.innerHTML += `<option value="${cmd.id}">${cmd.name}</option>`;
        });
        loadPostings();
    });
}

// Load postings
function loadPostings() {
    const commandId = document.getElementById('filterCommand').value;
    const container = document.getElementById('postingsTableContainer');
    
    if (!commandId) {
        container.innerHTML = '<div class="empty-state"><i class="fas fa-info-circle"></i><p>Select a command to view posted officers</p></div>';
        return;
    }
    
    container.innerHTML = '<div class="empty-state"><i class="fas fa-spinner fa-spin"></i><p>Loading postings...</p></div>';
    
    fetch('../ajax/get_postings.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'command_id=' + commandId
    })
    .then(response => response.text())
    .then(html => {
        container.innerHTML = html;
    })
    .catch(error => {
        container.innerHTML = '<div class="empty-state" style="color: red;">Error loading postings</div>';
    });
}

// Submit posting form
document.getElementById('postingForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const serviceNo = document.getElementById('selectedServiceNo').value;
    const commandId = document.getElementById('commandSelect').value;
    
    if (!serviceNo) {
        alert('Please select an officer first');
        return;
    }
    
    if (!commandId) {
        alert('Please select a command');
        return;
    }
    
    if (!confirm('Are you sure you want to post this officer?')) {
        return;
    }
    
    const postBtn = document.getElementById('postBtn');
    postBtn.disabled = true;
    postBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting...';
    
    fetch('../ajax/post_officer.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `serviceNo=${encodeURIComponent(serviceNo)}&command_id=${commandId}`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('✅ ' + data.message);
            clearSelectedOfficer();
            document.getElementById('commandSelect').value = '';
            
            // Refresh postings if viewing same command
            const filterCommand = document.getElementById('filterCommand').value;
            if (filterCommand == commandId) {
                loadPostings();
            }
        } else {
            alert('❌ ' + data.message);
        }
    })
    .catch(error => {
        alert('Network error occurred. Please try again.');
        console.error('Error:', error);
    })
    .finally(() => {
        postBtn.disabled = false;
        postBtn.innerHTML = '<i class="fas fa-paper-plane"></i> Post Officer';
    });
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    const zoneSelect = document.getElementById('zoneSelect');
    if (zoneSelect.value) {
        loadCommands();
    }
});
</script>

<?php include '../includes/footer.php'; ?>