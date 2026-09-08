document.addEventListener('DOMContentLoaded', function() {
    // Add posting functionality
    const addPostingBtn = document.getElementById('addPosting');
    const postingsContainer = document.getElementById('postingsContainer');
    
    if (addPostingBtn && postingsContainer) {
        addPostingBtn.addEventListener('click', function() {
            const newPosting = document.createElement('div');
            newPosting.className = 'posting-entry mb-3';
            newPosting.innerHTML = `
                <div class="row">
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Posting Type*</label>
                            <select class="form-control posting-type" name="posting_type[]" required>
                                <option value="">Select Type</option>
                                <option value="Previous">Previous Posting</option>
                                <option value="Current">Current Posting</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-group">
                            <label>Location*</label>
                            <input type="text" class="form-control" name="location[]" required>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="form-group">
                            <label>Date of Posting*</label>
                            <input type="date" class="form-control" name="date_of_posting[]" required>
                        </div>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="button" class="btn btn-danger btn-sm remove-posting">×</button>
                    </div>
                </div>
            `;
            postingsContainer.appendChild(newPosting);
        });
        
        // Remove posting functionality
        postingsContainer.addEventListener('click', function(e) {
            if (e.target.classList.contains('remove-posting')) {
                e.target.closest('.posting-entry').remove();
            }
        });
    }
    
    // Service number validation
    document.getElementById('service_number').addEventListener('blur', async function() {
        // ... service number validation code ...
    });
    
    // Form validation
    const form = document.getElementById('personnelForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            let valid = true;
            
            // Check if at least one current posting exists
            const postingTypes = document.getElementsByName('posting_type[]');
            let hasCurrentPosting = false;
            
            for (let i = 0; i < postingTypes.length; i++) {
                if (postingTypes[i].value === 'Current') {
                    hasCurrentPosting = true;
                    break;
                }
            }
            
            if (!hasCurrentPosting && postingTypes.length > 0) {
                alert('Please specify at least one Current Posting.');
                valid = false;
            }
            
            if (!valid) {
                e.preventDefault();
            }
        });
    }
});