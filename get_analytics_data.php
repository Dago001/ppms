// Fetch real-time analytics data
function fetchAnalyticsData() {
    fetch('get_analytics_data.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateDashboard(data.data);
                showRealtimeIndicator();
            } else {
                console.error('Failed to fetch analytics:', data.message);
            }
        })
        .catch(error => {
            console.error('Error fetching analytics:', error);
        })
        .finally(() => {
            // Hide spinner if visible
            document.getElementById('dashboardSpinner').style.display = 'none';
        });
}

// Update dashboard with new data
function updateDashboard(data) {
    // Update summary counters
    animateCounter('totalPersonnel', data.summary.total_personnel);
    animateCounter('totalUsers', data.summary.total_users);
    
    // Update NIS formation statistics
    document.getElementById('totalCommands').textContent = data.summary.total_commands.toLocaleString();
    document.getElementById('totalZones').textContent = data.summary.total_zones.toLocaleString();
    document.getElementById('totalPassportOffices').textContent = data.summary.total_passport_offices.toLocaleString();
    document.getElementById('totalBorders').textContent = data.summary.total_borders.toLocaleString();
    document.getElementById('totalHQUnits').textContent = data.summary.total_hq_units.toLocaleString();
    
    // Update Gender Distribution chart
    if (data.gender && data.gender.length > 0) {
        createOrUpdateChart('genderChart', {
            labels: data.gender.map(item => item.gender),
            values: data.gender.map(item => item.count)
        });
    }
    
    // Update Rank Distribution chart
    if (data.currentRank && data.currentRank.length > 0) {
        createOrUpdateChart('currentRankChart', {
            labels: data.currentRank.map(item => item.currentRank),
            values: data.currentRank.map(item => item.count)
        });
    }
    
    // Update Formation Types chart
    if (data.formationStats && data.formationStats.length > 0) {
        createOrUpdateChart('formationChart', {
            labels: data.formationStats.map(item => item.formation_type),
            values: data.formationStats.map(item => item.count)
        }, 'pie');
    }
    
    // Update Commands chart
    if (data.commandStats && data.commandStats.length > 0) {
        createOrUpdateChart('commandsChart', {
            labels: data.commandStats.map(item => item.command_name),
            values: data.commandStats.map(item => item.count)
        }, 'pie');
    }
    
    // Update Zones chart
    if (data.zoneStats && data.zoneStats.length > 0) {
        createOrUpdateChart('zonesChart', {
            labels: data.zoneStats.map(item => item.zone_name),
            values: data.zoneStats.map(item => item.count)
        }, 'pie');
    }
    
    // Update Passport Offices chart
    if (data.passportOfficeStats && data.passportOfficeStats.length > 0) {
        createOrUpdateChart('passportChart', {
            labels: data.passportOfficeStats.map(item => item.office_name),
            values: data.passportOfficeStats.map(item => item.count)
        }, 'pie');
    }
    
    // Update Monthly Personnel Growth bar chart
    if (data.monthlyPersonnelData && data.monthlyPersonnelData.length > 0) {
        createOrUpdateChart('monthlyPersonnelChart', {
            label: 'Monthly Personnel Growth',
            labels: data.monthlyPersonnelData.map(item => item.month),
            values: data.monthlyPersonnelData.map(item => item.count)
        }, 'bar');
    }
    
    // Update Rank Distribution bar chart
    if (data.rankDistributionData && data.rankDistributionData.length > 0) {
        createOrUpdateChart('rankDistributionChart', {
            label: 'Rank Distribution',
            labels: data.rankDistributionData.map(item => item.rank_name),
            values: data.rankDistributionData.map(item => item.count)
        }, 'bar');
    }
    
    // Update Zone Personnel bar chart
    if (data.zonePersonnelData && data.zonePersonnelData.length > 0) {
        createOrUpdateChart('zonePersonnelChart', {
            label: 'Zone-wise Personnel',
            labels: data.zonePersonnelData.map(item => item.zone_name),
            values: data.zonePersonnelData.map(item => item.count)
        }, 'bar');
    }
    
    // Update recent activities
    if (data.recent_activities && data.recent_activities.length > 0) {
        const activityList = document.getElementById('recentActivitiesList');
        if (activityList) {
            activityList.innerHTML = data.recent_activities.map(activity => `
                <li class="activity-item">
                    <div class="activity-content">
                        <h6>${activity.action || 'Activity'}</h6>
                        <small>By ${activity.username || 'User'}</small>
                    </div>
                    <div class="activity-time">
                        ${new Date(activity.created_at).toLocaleString('en-US', {
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit'
                        })}
                    </div>
                </li>
            `).join('');
        }
    }
}

// Update the setInterval call in your DOMContentLoaded event to use the new function
document.addEventListener('DOMContentLoaded', function() {
    // Initial animations
    animateCounter('totalPersonnel', <?php echo $totalPersonnel; ?>);
    animateCounter('totalUsers', <?php echo $totalUsers; ?>);
    
    // Initialize charts with PHP data (same as before)
    // ... your existing chart initialization code ...
    
    // Fetch real-time data immediately and then every 30 seconds
    fetchAnalyticsData();
    setInterval(fetchAnalyticsData, updateInterval);
});