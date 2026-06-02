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
    var refreshInterval = 5000;
    var activeRefreshController = null;
    var refreshRequestId = 0;

    function setActiveJobFilter(value) {
        document.querySelectorAll('[data-job-status-filter]').forEach(function (button) {
            button.classList.toggle('active', button.getAttribute('data-job-status-filter') === value);
        });
    }

    function syncJobFormFromData(data) {
        if (!data) {
            return;
        }
        var statusSelect = document.getElementById('job-status-select');
        if (statusSelect && data.job_status) {
            statusSelect.value = data.job_status;
            setActiveJobFilter(data.job_status);
        }
        var perPageSelect = document.querySelector('#job-filter-form select[name="job_per_page"]');
        if (perPageSelect && data.job_per_page) {
            perPageSelect.value = String(data.job_per_page);
        }
    }

    function updateDashboardSections(data) {
        var metricsSection = document.getElementById('metrics-section');
        if (metricsSection && data.metrics) {
            metricsSection.innerHTML = data.metrics;
        }

        var workerSummary = document.getElementById('worker-summary');
        if (workerSummary && typeof data.worker_summary === 'string') {
            workerSummary.innerHTML = data.worker_summary;
        }

        var workersGrid = document.getElementById('workers-grid');
        if (workersGrid && typeof data.workers === 'string') {
            workersGrid.innerHTML = data.workers;
        }

        var jobSummary = document.getElementById('jobs-summary');
        if (jobSummary && data.job_summary) {
            jobSummary.textContent = data.job_summary;
        }

        var jobTabs = document.getElementById('job-status-tabs');
        if (jobTabs && data.job_tabs) {
            jobTabs.innerHTML = data.job_tabs;
        }

        var jobsTbody = document.getElementById('jobs-tbody');
        if (jobsTbody && typeof data.jobs === 'string') {
            jobsTbody.innerHTML = data.jobs;
        }

        var jobsPagination = document.getElementById('jobs-pagination');
        if (jobsPagination && data.job_pagination) {
            jobsPagination.innerHTML = data.job_pagination;
        }

        var eventsTbody = document.getElementById('events-tbody');
        if (eventsTbody && data.events) {
            eventsTbody.innerHTML = data.events;
        }

        var filesTbody = document.getElementById('files-tbody');
        if (filesTbody && data.files) {
            filesTbody.innerHTML = data.files;
        }

        syncJobFormFromData(data);
    }

    function ajaxUrl(url) {
        var target = new URL(url, window.location.href);
        target.searchParams.set('ajax', '1');
        target.searchParams.set('_cache', String(Date.now()));
        return target;
    }

    function cleanUrl(url) {
        var target = new URL(url, window.location.href);
        target.searchParams.delete('ajax');
        target.searchParams.delete('_cache');
        return target;
    }

    function loadDashboard(url, pushHistory) {
        var id = ++refreshRequestId;
        var target = ajaxUrl(url || window.location.href);

        if (activeRefreshController) {
            activeRefreshController.abort();
        }
        activeRefreshController = new AbortController();

        return fetch(target.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: activeRefreshController.signal
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            if (id !== refreshRequestId) {
                return;
            }
            updateDashboardSections(data);
            if (pushHistory) {
                window.history.pushState({}, '', cleanUrl(target).toString());
            }
        })
        .catch(function (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Dashboard AJAX refresh failed:', error);
        });
    }

    function urlFromJobFilterForm() {
        var form = document.getElementById('job-filter-form');
        var target = new URL(window.location.href);
        if (!form) {
            return target;
        }
        var formData = new FormData(form);
        formData.forEach(function (value, key) {
            target.searchParams.set(key, value);
        });
        target.searchParams.delete('job_page');
        target.searchParams.delete('ajax');
        target.searchParams.delete('_cache');
        return target;
    }

    window.ReflectionDashboard = {
        refresh: function () {
            return loadDashboard(window.location.href, false);
        },
        load: loadDashboard
    };

    document.addEventListener('click', function (event) {
        var filterButton = event.target.closest && event.target.closest('[data-job-status-filter]');
        if (filterButton) {
            event.preventDefault();
            var select = document.getElementById('job-status-select');
            var value = filterButton.getAttribute('data-job-status-filter') || 'all';
            if (select) {
                select.value = value;
            }
            setActiveJobFilter(value);
            loadDashboard(urlFromJobFilterForm().toString(), true);
            return;
        }

        var pageLink = event.target.closest && event.target.closest('#jobs-pagination a');
        if (pageLink && !pageLink.classList.contains('disabled')) {
            event.preventDefault();
            loadDashboard(pageLink.href, true);
        }
    });

    document.addEventListener('change', function (event) {
        var select = event.target.closest && event.target.closest('#job-status-select');
        if (select) {
            setActiveJobFilter(select.value);
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest && event.target.closest('#job-filter-form');
        if (!form) {
            return;
        }
        event.preventDefault();
        loadDashboard(urlFromJobFilterForm().toString(), true);
    });

    window.addEventListener('popstate', function () {
        loadDashboard(window.location.href, false);
    });

    setInterval(function () {
        loadDashboard(window.location.href, false);
    }, refreshInterval);
}());

(function () {
    // Handle job action forms via AJAX.
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
        var originalText = button ? button.textContent : '';
        if (button) {
            button.disabled = true;
            button.textContent = 'Processing...';
        }
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
                if (window.ReflectionDashboard) {
                    window.ReflectionDashboard.refresh();
                }
            } else {
                alertDiv.className = 'alert error';
                alertDiv.textContent = data.error || 'An error occurred.';
            }
            if (messageContainer) {
                messageContainer.insertBefore(alertDiv, messageContainer.firstChild);
                setTimeout(function () {
                    alertDiv.remove();
                }, 5000);
            }
        })
        .catch(function (error) {
            console.error('Error:', error);
            var alertDiv = document.createElement('div');
            alertDiv.className = 'alert error';
            alertDiv.textContent = 'Failed to process the request.';
            var messageContainer = document.querySelector('.jobs-panel');
            if (messageContainer) {
                messageContainer.insertBefore(alertDiv, messageContainer.firstChild);
                setTimeout(function () {
                    alertDiv.remove();
                }, 5000);
            }
        })
        .finally(function () {
            if (button) {
                button.disabled = false;
                button.textContent = originalText;
            }
        });
    });
}());
