(function () {
    var mode = document.getElementById('submit-mode');
    var button = document.getElementById('submit-button');
    var groups = document.querySelectorAll('.mode-fields');
    var moduleSelect = document.getElementById('task-module');
    var specData = document.getElementById('task-contract-data');
    var specs = {};

    if (specData && specData.getAttribute('data-task-specs')) {
        try {
            specs = JSON.parse(specData.getAttribute('data-task-specs')) || {};
        } catch (error) {
            console.error('Could not parse task specs:', error);
        }
    }

    if (!mode || !button) {
        return;
    }

    function selectedSpec() {
        var moduleName = moduleSelect ? moduleSelect.value : '';
        return specs[moduleName] || {};
    }

    function field(id) {
        return document.getElementById(id);
    }

    function setLabelText(id, text) {
        var element = field(id);
        if (element && text) {
            element.textContent = text;
        }
    }

    function setHelpText(id, text) {
        var element = field(id);
        if (element) {
            element.textContent = text || '';
        }
    }

    function setFieldHidden(id, hidden) {
        var element = field(id);
        if (!element) {
            return;
        }
        element.hidden = !!hidden;
        element.querySelectorAll('input, textarea, select').forEach(function (input) {
            input.disabled = !!hidden || !!element.closest('[hidden]');
        });
    }

    function pathParts(source) {
        source = String(source || '').replace(/\\/g, '/').trim();
        var sourceForName = source.replace(/\/+$/g, '');
        var slash = sourceForName.lastIndexOf('/');
        var dir = slash >= 0 ? sourceForName.slice(0, slash) : '';
        var basename = slash >= 0 ? sourceForName.slice(slash + 1) : sourceForName;
        if (!basename) {
            basename = 'output';
        }
        var dot = basename.lastIndexOf('.');
        var ext = dot > 0 ? basename.slice(dot + 1) : '';
        var dotExt = ext ? '.' + ext : '';
        var name = dot > 0 ? basename.slice(0, dot) : basename;
        return {
            source: source,
            dir: dir,
            directory: dir,
            basename: basename,
            name: name || basename,
            ext: ext,
            dot_ext: dotExt
        };
    }

    function renderTemplate(template, source) {
        var parts = pathParts(source || 'ftp://storage/incoming/example.dat');
        var rendered = String(template || '').replace(/\{source\}|\{dir\}|\{directory\}|\{basename\}|\{name\}|\{ext\}|\{dot_ext\}/g, function (token) {
            return parts[token.slice(1, -1)] || '';
        });
        if (!parts.dir && /^\s*\{(?:dir|directory)\}\//.test(String(template || ''))) {
            rendered = rendered.replace(/^\/+/, '');
        }
        return rendered.replace(/([^:])\/+/g, '$1/');
    }

    function updateContract() {
        var spec = selectedSpec();
        var source = spec.source || {};
        var delivery = spec.delivery || {};
        var output = spec.output || {};
        var sourceMode = source.mode || 'required';
        var deliveryMode = delivery.mode || 'optional';
        var title = field('task-contract-title');
        var summary = field('task-contract-summary');
        var moduleName = moduleSelect ? moduleSelect.value : 'task';
        var parts = [];

        if (title) {
            title.textContent = moduleName + ' contract';
        }
        parts.push('source ' + sourceMode);
        parts.push('delivery ' + deliveryMode);
        if (delivery.extension) {
            parts.push('must end with ' + delivery.extension);
        } else if (output.extension === 'source') {
            parts.push('keeps source extension');
        }
        if (delivery.template) {
            parts.push('template ' + delivery.template);
        }
        if (summary) {
            summary.textContent = parts.join(' · ');
        }

        setLabelText('single-source-label', source.label || 'Source path or URI');
        setLabelText('bulk-source-label', source.label ? source.label + ' list' : 'Source list');
        setHelpText('single-source-help', source.help || 'Use an FTP URL or any path the worker can read.');
        setHelpText('bulk-source-help', source.help ? source.help + ' One per line.' : 'One source per line, or paste a JSON array.');

        setLabelText('single-delivery-label', delivery.label || 'Delivery path or URI');
        setLabelText('bulk-delivery-label', delivery.mode === 'auto' ? 'Delivery override template' : 'Delivery template');
        setHelpText('single-delivery-help', delivery.help || 'Optional. The master passes this value through; workers do the writing.');
        setHelpText('bulk-delivery-help', delivery.help || 'Supports {source}, {dir}, {basename}, {name}, {ext}, and {dot_ext}.');

        var sourceHidden = sourceMode === 'none';
        var deliveryHidden = deliveryMode === 'none' || deliveryMode === 'auto';
        var storageHidden = sourceMode === 'none' && (deliveryMode === 'none' || deliveryMode === 'optional');
        setFieldHidden('single-source-field', sourceHidden);
        setFieldHidden('bulk-source-field', sourceHidden);
        setFieldHidden('bulk-upload-field', sourceHidden);
        setFieldHidden('single-delivery-field', deliveryHidden);
        setFieldHidden('bulk-delivery-field', deliveryHidden);
        setFieldHidden('storage-server-field', storageHidden);

        var singleSource = field('single-source-input');
        var singlePreview = field('single-delivery-preview');
        if (singlePreview) {
            if (deliveryMode === 'auto' && delivery.template) {
                singlePreview.textContent = 'Delivery will be generated automatically, for example: ' + renderTemplate(delivery.template, singleSource ? singleSource.value : '');
                singlePreview.hidden = false;
            } else {
                singlePreview.textContent = '';
                singlePreview.hidden = true;
            }
        }

        var singleDelivery = field('single-delivery-input');
        var bulkDelivery = field('bulk-delivery-input');
        if (singleDelivery && delivery.template) {
            singleDelivery.placeholder = renderTemplate(delivery.template, singleSource ? singleSource.value : '');
        }
        if (bulkDelivery && delivery.template) {
            bulkDelivery.placeholder = delivery.template;
        }
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
        updateContract();
    }

    mode.addEventListener('change', syncMode);
    if (moduleSelect) {
        moduleSelect.addEventListener('change', updateContract);
    }
    var singleSourceInput = field('single-source-input');
    if (singleSourceInput) {
        singleSourceInput.addEventListener('input', updateContract);
    }
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
