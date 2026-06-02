(function () {
    var mode = document.getElementById('submit-mode');
    var button = document.getElementById('submit-button');
    var groups = document.querySelectorAll('.mode-fields');
    if (!mode || !button) {
        return;
    }
    function syncMode() {
        var selected = mode.value;
        groups.forEach(function (group) {
            var active = group.classList.contains('mode-' + selected);
            group.hidden = !active;
            group.querySelectorAll('input, textarea, select').forEach(function (field) {
                field.disabled = !active;
            });
        });
        button.textContent = selected === 'bulk' ? 'Import jobs' : 'Queue job';
    }
    mode.addEventListener('change', syncMode);
    syncMode();
}());

(function () {
    var select = document.getElementById('job-status-select');
    var tabs = document.querySelector('[data-job-status-tabs]');
    if (!select || !tabs) {
        return;
    }
    function setActive(value) {
        tabs.querySelectorAll('[data-job-status-filter]').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-job-status-filter') === value);
        });
    }
    tabs.querySelectorAll('[data-job-status-filter]').forEach(function (button) {
        button.addEventListener('click', function () {
            select.value = button.getAttribute('data-job-status-filter') || 'all';
            setActive(select.value);
        });
    });
    select.addEventListener('change', function () {
        setActive(select.value);
    });
}());
(function () {
    var more = document.getElementById('active-work-more');
    if (!more) {
        return;
    }
    var card = more.closest('.active-work-card');
    function syncActiveWorkExpansion() {
        if (card) {
            card.classList.toggle('is-expanded', more.open);
        }
    }
    more.addEventListener('toggle', syncActiveWorkExpansion);
    syncActiveWorkExpansion();
}());
(function () {
    // Handle job action forms via AJAX
    var jobsPanel = document.querySelector('.jobs-panel');
    if (!jobsPanel) {
        return;
    }
    jobsPanel.addEventListener('submit', function (event) {
        if (event.defaultPrevented) {
            return;
        }

        var form = event.target;
        var formAction = form.querySelector('input[name="form_action"]');
        if (!formAction || formAction.value !== 'job_action') {
            return;
        }
        event.preventDefault();
        var jobAction = form.querySelector('input[name="job_action"]');
        var taskId = form.querySelector('input[name="task_id"]');
        if (!jobAction || !taskId) {
            return;
        }
        var formData = new FormData();
        formData.append('form_action', 'job_action');
        formData.append('job_action', jobAction.value);
        formData.append('task_id', taskId.value);
        var button = form.querySelector('button[type="submit"]');
        var originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Processing...';
        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (data) {
            var messageContainer = document.querySelector('.jobs-panel');
            var alertDiv = document.createElement('div');
            if (data.success) {
                alertDiv.className = 'alert success';
                alertDiv.textContent = data.message || 'Action completed successfully.';
                form.closest('td').innerHTML = '';
            } else {
                alertDiv.className = 'alert error';
                alertDiv.textContent = data.error || 'An error occurred.';
            }
            messageContainer.insertBefore(alertDiv, messageContainer.firstChild);
            setTimeout(function () {
                alertDiv.remove();
            }, 5000);
        })
        .catch(function (error) {
            console.error('Error:', error);
            var alertDiv = document.createElement('div');
            alertDiv.className = 'alert error';
            alertDiv.textContent = 'Failed to process the request.';
            var messageContainer = document.querySelector('.jobs-panel');
            messageContainer.insertBefore(alertDiv, messageContainer.firstChild);
            setTimeout(function () {
                alertDiv.remove();
            }, 5000);
        })
        .finally(function () {
            button.disabled = false;
            button.textContent = originalText;
        });
    });
}());
(function () {
    // Auto-refresh dashboard data every 10 seconds
    var refreshInterval = 10000; // 10 seconds
    var currentUrl = window.location.href;
    var urlParams = new URL(currentUrl).searchParams;

    // Only set up auto-refresh on the main dashboard view
    if (urlParams.get('job_status') === 'all' && !urlParams.has('job_page')) {
        function refreshDashboard() {
            var refreshUrl = currentUrl + (currentUrl.indexOf('?') > -1 ? '&' : '?') + 'ajax=1&_cache=' + Date.now();

            fetch(refreshUrl, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                // Update metrics
                var metricsSection = document.getElementById('metrics-section');
                if (metricsSection && data.metrics) {
                    metricsSection.innerHTML = data.metrics;
                }

                // Update workers grid
                var workersGrid = document.getElementById('workers-grid');
                if (workersGrid && data.workers) {
                    workersGrid.innerHTML = data.workers;
                }

                // Update jobs table
                var jobsTbody = document.getElementById('jobs-tbody');
                if (jobsTbody && data.jobs) {
                    jobsTbody.innerHTML = data.jobs;
                }

                // Update events table
                var eventsTbody = document.getElementById('events-tbody');
                if (eventsTbody && data.events) {
                    eventsTbody.innerHTML = data.events;
                }

                // Update files table
                var filesTbody = document.getElementById('files-tbody');
                if (filesTbody && data.files) {
                    filesTbody.innerHTML = data.files;
                }
            })
            .catch(function (error) {
                console.error('Dashboard auto-refresh failed:', error);
            });
        }

        // Start auto-refresh timer
        setInterval(refreshDashboard, refreshInterval);
    }
}());
