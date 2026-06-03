(function () {
    'use strict';

    var PLACEHOLDERS = {
        source: { label: 'Source path', description: 'The source path after the source template is expanded.' },
        path: { label: 'Full file path', description: 'The absolute path of the matched file.' },
        root: { label: 'Scan root', description: 'The scan root that matched the file.' },
        relative: { label: 'Relative path', description: 'The file path below the matched scan root.' },
        dir: { label: 'Parent folder', description: 'The folder containing the matched file.' },
        directory: { label: 'Parent folder alias', description: 'Alias for {dir}.' },
        basename: { label: 'Filename', description: 'The filename including the extension.' },
        name: { label: 'Name only', description: 'The filename without the final extension.' },
        ext: { label: 'Extension', description: 'The final extension without the dot.' },
        dot_ext: { label: 'Dot extension', description: 'The final extension including the dot.' },
        size: { label: 'File size', description: 'The file size in bytes. The browser preview uses an example size.' },
        mtime: { label: 'Modified time', description: 'The modified time as a Unix timestamp. The browser preview uses an example timestamp.' },
        worker_path: { label: 'Worker-visible path', description: 'The file path after applying the worker path mapping. Use this for FTP worker sources.' },
        worker_root: { label: 'Worker scan root', description: 'The scan root after applying the worker path mapping.' },
        worker_relative: { label: 'Worker relative path', description: 'The relative path below the mapped worker root.' },
        worker_dir: { label: 'Worker parent folder', description: 'The mapped folder containing the matched file.' },
        worker_basename: { label: 'Worker filename', description: 'The mapped filename including the extension.' },
        worker_name: { label: 'Worker name only', description: 'The mapped filename without the final extension.' },
        worker_ext: { label: 'Worker extension', description: 'The mapped final extension without the dot.' },
        worker_dot_ext: { label: 'Worker dot extension', description: 'The mapped final extension including the dot.' }
    };

    function byId(id) {
        return document.getElementById(id);
    }

    function valueOf(id) {
        var element = byId(id);
        return element ? String(element.value || '') : '';
    }

    function checked(id) {
        var element = byId(id);
        return !!(element && element.checked);
    }

    function taskSpecs() {
        var data = byId('automation-task-contract-data');
        if (!data) {
            return {};
        }
        try {
            return JSON.parse(data.getAttribute('data-task-specs') || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function selectedModule() {
        return valueOf('automation-module-input');
    }

    function selectedTaskSpec() {
        return taskSpecs()[selectedModule()] || {};
    }

    function selectedDeliverySpec() {
        var spec = selectedTaskSpec();
        return spec && spec.delivery ? spec.delivery : {};
    }

    function selectedTaskAutoTemplate() {
        var delivery = selectedDeliverySpec();
        return delivery && delivery.mode === 'auto' && delivery.template ? String(delivery.template) : '';
    }

    function selectedTaskExtension() {
        var delivery = selectedDeliverySpec();
        var extension = String((delivery && delivery.extension) || '');
        if (!extension || extension === 'source') {
            return '';
        }
        return extension.charAt(0) === '.' ? extension.toLowerCase() : '.' + extension.toLowerCase();
    }

    function taskAutoDeliveryActive() {
        var template = selectedTaskAutoTemplate();
        if (!template) {
            return false;
        }
        return valueOf('delivery-mode-input') !== 'template' || valueOf('delivery-template-input').trim() === '';
    }

    function candidateFromPath(path) {
        path = normalizeSlashes(path).replace(/\/+$/, '');
        var dir = dirname(path);
        var base = basename(path) || 'output';
        var parts = splitNameExtension(base);
        return {
            '{source}': path,
            '{path}': path,
            '{root}': '',
            '{relative}': base,
            '{dir}': dir === '.' ? '' : dir,
            '{directory}': dir === '.' ? '' : dir,
            '{basename}': base,
            '{name}': parts.name || base,
            '{ext}': parts.ext,
            '{dot_ext}': parts.ext ? '.' + parts.ext : '',
            '{mtime}': '',
            '{size}': '',
            '{worker_path}': path,
            '{worker_root}': '',
            '{worker_relative}': base,
            '{worker_dir}': dir === '.' ? '' : dir,
            '{worker_basename}': base,
            '{worker_name}': parts.name || base,
            '{worker_ext}': parts.ext,
            '{worker_dot_ext}': parts.ext ? '.' + parts.ext : ''
        };
    }

    function firstScanRoot() {
        return valueOf('scan-roots-input')
            .split(/\r?\n/)
            .map(function (line) { return line.trim(); })
            .filter(Boolean)[0] || '';
    }

    function normalizeSlashes(path) {
        return String(path || '').replace(/\\+/g, '/');
    }

    function dirname(path) {
        path = normalizeSlashes(path).replace(/\/+$/, '');
        var index = path.lastIndexOf('/');
        if (index <= 0) {
            return index === 0 ? '/' : '.';
        }
        return path.slice(0, index);
    }

    function basename(path) {
        path = normalizeSlashes(path).replace(/\/+$/, '');
        var index = path.lastIndexOf('/');
        return index === -1 ? path : path.slice(index + 1);
    }

    function splitNameExtension(fileName) {
        var index = fileName.lastIndexOf('.');
        if (index <= 0 || index === fileName.length - 1) {
            return { name: fileName, ext: '' };
        }
        return { name: fileName.slice(0, index), ext: fileName.slice(index + 1) };
    }

    function relativePath(path, root) {
        path = normalizeSlashes(path);
        root = normalizeSlashes(root).replace(/\/+$/, '');
        if (root !== '' && (path === root || path.indexOf(root + '/') === 0)) {
            return path === root ? '' : path.slice(root.length + 1);
        }
        return basename(path);
    }

    function escapeHtml(value) {
        return String(value || '').replace(/[&<>"']/g, function (character) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[character];
        });
    }

    function shellQuote(value) {
        return "'" + String(value || '').replace(/'/g, "'\\''") + "'";
    }

    function formatBytes(bytes) {
        bytes = Number(bytes || 0);
        if (!isFinite(bytes) || bytes <= 0) {
            return '0 B';
        }
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var index = 0;
        while (bytes >= 1024 && index < units.length - 1) {
            bytes /= 1024;
            index += 1;
        }
        return (index === 0 ? String(bytes) : bytes.toFixed(bytes >= 10 ? 1 : 2)) + ' ' + units[index];
    }

    function formatUnixTime(timestamp) {
        timestamp = Number(timestamp || 0);
        if (!isFinite(timestamp) || timestamp <= 0) {
            return 'invalid timestamp';
        }
        var date = new Date(timestamp * 1000);
        if (isNaN(date.getTime())) {
            return 'invalid timestamp';
        }
        return date.toLocaleString();
    }

    function appendSuffixToPath(path, suffix) {
        path = normalizeSlashes(path);
        suffix = String(suffix || '').trim() || '_processed';
        var dir = dirname(path);
        var base = basename(path);
        var parts = splitNameExtension(base);
        var newName = parts.name + suffix + (parts.ext ? '.' + parts.ext : '');
        return (dir === '' || dir === '.') ? newName : dir.replace(/\/+$/, '') + '/' + newName;
    }

    function parseWorkerMappings() {
        return valueOf('worker-path-mappings-input')
            .split(/\r?\n/)
            .map(function (line) { return line.trim(); })
            .filter(function (line) { return line && line.charAt(0) !== '#'; })
            .map(function (line) {
                var separator = line.indexOf('=>');
                var separatorLength = 2;
                if (separator === -1) {
                    separator = line.indexOf('=');
                    separatorLength = 1;
                }
                if (separator === -1) {
                    return null;
                }
                var from = normalizeSlashes(line.slice(0, separator).trim()).replace(/\/+$/, '');
                var to = normalizeSlashes(line.slice(separator + separatorLength).trim()).replace(/\/+$/, '');
                return from ? { from: from, to: to } : null;
            })
            .filter(Boolean)
            .sort(function (a, b) { return b.from.length - a.from.length; });
    }

    function mapMasterPathToWorkerPath(path) {
        path = normalizeSlashes(path);
        var mappings = parseWorkerMappings();
        for (var i = 0; i < mappings.length; i += 1) {
            var from = mappings[i].from;
            var to = mappings[i].to;
            if (path === from || path.indexOf(from + '/') === 0) {
                var suffix = path === from ? '' : path.slice(from.length);
                if (to === '') {
                    return suffix.replace(/^\/+/, '');
                }
                if (to === '/') {
                    return '/' + suffix.replace(/^\/+/, '');
                }
                return to + suffix;
            }
        }
        return path;
    }

    function candidateFromExample() {
        var path = normalizeSlashes(valueOf('template-sample-path').trim() || '/volume1/video/Movies/Example Movie (2024)/Example Movie.mkv');
        var root = firstScanRoot() || dirname(path);
        root = normalizeSlashes(root).replace(/\/+$/, '');
        var rel = relativePath(path, root);
        var dir = dirname(path);
        var base = basename(path);
        var parts = splitNameExtension(base);
        var workerPath = mapMasterPathToWorkerPath(path);
        var workerRoot = mapMasterPathToWorkerPath(root);
        var workerRel = relativePath(workerPath, workerRoot);
        var workerDir = dirname(workerPath);
        var workerBase = basename(workerPath);
        var workerParts = splitNameExtension(workerBase);
        return {
            '{source}': path,
            '{path}': path,
            '{root}': root,
            '{relative}': rel,
            '{dir}': dir,
            '{directory}': dir,
            '{basename}': base,
            '{name}': parts.name,
            '{ext}': parts.ext,
            '{dot_ext}': parts.ext ? '.' + parts.ext : '',
            '{mtime}': '1780241000',
            '{size}': '7340032000',
            '{worker_path}': workerPath,
            '{worker_root}': workerRoot,
            '{worker_relative}': workerRel,
            '{worker_dir}': workerDir,
            '{worker_basename}': workerBase,
            '{worker_name}': workerParts.name,
            '{worker_ext}': workerParts.ext,
            '{worker_dot_ext}': workerParts.ext ? '.' + workerParts.ext : ''
        };
    }

    function applyTemplate(template, candidate, shellEscaped) {
        template = String(template || '').trim();
        if (template === '') {
            return '';
        }
        Object.keys(candidate).forEach(function (key) {
            var replacement = shellEscaped ? shellQuote(candidate[key]) : candidate[key];
            template = template.split(key).join(replacement);
        });
        return template;
    }

    function setCode(id, value) {
        var element = byId(id);
        if (element) {
            element.textContent = value || '—';
        }
    }

    function levenshtein(a, b) {
        a = String(a || '');
        b = String(b || '');
        var matrix = [];
        var i;
        var j;
        for (i = 0; i <= b.length; i += 1) {
            matrix[i] = [i];
        }
        for (j = 0; j <= a.length; j += 1) {
            matrix[0][j] = j;
        }
        for (i = 1; i <= b.length; i += 1) {
            for (j = 1; j <= a.length; j += 1) {
                matrix[i][j] = b.charAt(i - 1) === a.charAt(j - 1)
                    ? matrix[i - 1][j - 1]
                    : Math.min(
                        matrix[i - 1][j - 1] + 1,
                        matrix[i][j - 1] + 1,
                        matrix[i - 1][j] + 1
                    );
            }
        }
        return matrix[b.length][a.length];
    }

    function suggestionFor(name) {
        var best = null;
        var bestScore = 999;
        Object.keys(PLACEHOLDERS).forEach(function (candidate) {
            var score = levenshtein(name, candidate);
            if (score < bestScore) {
                bestScore = score;
                best = candidate;
            }
        });
        return bestScore <= Math.max(2, Math.floor(String(name).length / 3)) ? best : null;
    }

    function validateTemplateString(template) {
        var errors = [];
        var names = [];
        var match;
        var regex = /\{([^{}]+)\}/g;
        template = String(template || '');

        if ((template.match(/\{/g) || []).length !== (template.match(/\}/g) || []).length) {
            errors.push('Unmatched curly brace. Every { needs a matching }.');
        }

        while ((match = regex.exec(template)) !== null) {
            names.push(match[1]);
            if (!Object.prototype.hasOwnProperty.call(PLACEHOLDERS, match[1])) {
                var suggestion = suggestionFor(match[1]);
                errors.push('Unknown placeholder {' + match[1] + '}' + (suggestion ? '. Did you mean {' + suggestion + '}?' : '.'));
            }
        }

        return {
            valid: errors.length === 0,
            names: names,
            errors: errors
        };
    }

    function fieldIsActive(fieldId) {
        if (fieldId === 'delivery-template-input') {
            return valueOf('delivery-mode-input') === 'template' && !taskAutoDeliveryActive();
        }
        if (fieldId === 'command-template-input') {
            return valueOf('command-mode-input') !== 'disabled';
        }
        return true;
    }

    function statusIdForField(fieldId) {
        return fieldId.replace('-input', '-status');
    }

    function setFieldStatus(input, validation, active) {
        var status = byId(statusIdForField(input.id));
        input.classList.remove('is-valid', 'is-invalid', 'is-muted');
        if (!active) {
            input.classList.add('is-muted');
            if (status) {
                status.className = 'field-status muted';
                status.textContent = 'Not used with the current settings.';
            }
            return [];
        }
        if (validation.valid) {
            input.classList.add('is-valid');
            if (status) {
                status.className = 'field-status valid';
                status.textContent = validation.names.length
                    ? 'Valid placeholders: ' + validation.names.map(function (name) { return '{' + name + '}'; }).join(', ')
                    : 'No placeholders used.';
            }
            return [];
        }
        input.classList.add('is-invalid');
        if (status) {
            status.className = 'field-status invalid';
            status.textContent = validation.errors.join(' ');
        }
        return validation.errors.map(function (error) {
            return (input.getAttribute('data-template-label') || 'Template') + ': ' + error;
        });
    }

    function renderPlaceholderChips() {
        var row = byId('placeholder-chip-row');
        if (!row) {
            return;
        }
        row.innerHTML = Object.keys(PLACEHOLDERS).map(function (name) {
            var info = PLACEHOLDERS[name];
            return '<button type="button" class="placeholder-chip" data-placeholder="{' + escapeHtml(name) + '}" title="' + escapeHtml(info.description) + '">{' + escapeHtml(name) + '}</button>';
        }).join('');
        row.querySelectorAll('.placeholder-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var active = document.activeElement;
                var placeholder = chip.getAttribute('data-placeholder') || '';
                if (!active || !active.classList || !active.classList.contains('template-input')) {
                    active = byId('source-template-input');
                }
                if (!active) {
                    return;
                }
                var start = active.selectionStart || active.value.length;
                var end = active.selectionEnd || active.value.length;
                active.value = active.value.slice(0, start) + placeholder + active.value.slice(end);
                active.focus();
                active.setSelectionRange(start + placeholder.length, start + placeholder.length);
                updateTemplatePreview();
            });
        });
    }

    function validateTemplates() {
        var allErrors = [];
        document.querySelectorAll('.template-input').forEach(function (input) {
            var active = fieldIsActive(input.id);
            var validation = validateTemplateString(input.value);
            allErrors = allErrors.concat(setFieldStatus(input, validation, active));
        });

        var summary = byId('template-validation-summary');
        if (summary) {
            if (allErrors.length === 0) {
                summary.className = 'template-validation-summary valid';
                summary.textContent = 'All active templates look valid.';
            } else {
                summary.className = 'template-validation-summary invalid';
                summary.innerHTML = '<strong>Fix template problems before saving:</strong><ul>' + allErrors.map(function (error) {
                    return '<li>' + escapeHtml(error) + '</li>';
                }).join('') + '</ul>';
            }
        }

        var form = byId('automation-rule-form');
        if (form) {
            form.setAttribute('data-template-valid', allErrors.length === 0 ? '1' : '0');
        }
        return allErrors;
    }

    function updateTemplatePreview() {
        var candidate = candidateFromExample();
        var source = applyTemplate(valueOf('source-template-input') || '{path}', candidate, false) || candidate['{path}'];
        var deliveryMode = valueOf('delivery-mode-input') || 'template';
        var delivery = '';
        var autoTemplate = selectedTaskAutoTemplate();
        var autoActive = taskAutoDeliveryActive();
        if (autoActive) {
            delivery = applyTemplate(autoTemplate, candidateFromPath(source), false);
        } else if (deliveryMode === 'same_as_source') {
            delivery = checked('overwrite-allowed-input')
                ? source
                : appendSuffixToPath(source, valueOf('output-suffix-input'));
        } else {
            delivery = applyTemplate(valueOf('delivery-template-input'), candidate, false);
        }
        var suffixExample = appendSuffixToPath(source, valueOf('output-suffix-input'));
        var command = applyTemplate(valueOf('command-template-input'), candidate, true);

        setCode('preview-root', candidate['{root}']);
        setCode('preview-relative', candidate['{relative}']);
        setCode('preview-worker-path', candidate['{worker_path}']);
        setCode('preview-source', source);
        setCode('preview-delivery', delivery || 'No delivery target configured');
        setCode('source-template-preview', 'Example: ' + source);
        setCode('delivery-template-preview', autoActive
            ? 'Task automatic delivery: ' + (delivery || 'No delivery target configured')
            : (deliveryMode === 'template'
                ? 'Example: ' + (delivery || 'No delivery target configured')
                : 'Ignored while delivery target is “same as source location”'));
        setCode('suffix-template-preview', 'Example if not overwriting: ' + suffixExample);
        setCode('command-template-preview', command ? 'Example command: ' + command : 'No command template configured');

        var contractSummary = byId('automation-task-contract-summary');
        var contractNote = byId('task-delivery-contract-note');
        var deliverySpec = selectedDeliverySpec();
        var extension = selectedTaskExtension();
        var summaryText = 'Task delivery: ' + (deliverySpec.mode || 'optional');
        if (autoTemplate) {
            summaryText += ' · automatic template ' + autoTemplate;
        }
        if (extension) {
            summaryText += ' · required output ' + extension;
        }
        if (contractSummary) {
            contractSummary.textContent = summaryText;
        }
        if (contractNote) {
            contractNote.textContent = autoActive
                ? 'This task declares automatic delivery, so this rule will queue the generated output path shown in the preview. The old same-as-source setting is ignored for this task.'
                : '';
        }

        var grid = byId('placeholder-preview-grid');
        if (grid) {
            grid.innerHTML = Object.keys(PLACEHOLDERS).map(function (name) {
                var key = '{' + name + '}';
                var value = candidate[key];
                var extra = '';
                if (name === 'size') {
                    extra = '<em>' + escapeHtml(formatBytes(value)) + '</em>';
                } else if (name === 'mtime') {
                    extra = '<em>' + escapeHtml(formatUnixTime(value)) + '</em>';
                }
                return '<div class="placeholder-value-card">'
                    + '<div class="placeholder-value-head"><code>' + escapeHtml(key) + '</code><strong>' + escapeHtml(PLACEHOLDERS[name].label) + '</strong></div>'
                    + '<p>' + escapeHtml(PLACEHOLDERS[name].description) + '</p>'
                    + '<span>Example value</span>'
                    + '<code>' + escapeHtml(value) + '</code>'
                    + extra
                    + '</div>';
            }).join('');
        }

        validateTemplates();
    }

    document.querySelectorAll('.preset-chip[data-extensions]').forEach(function (button) {
        button.addEventListener('click', function () {
            var input = byId('extensions-input');
            if (!input) {
                return;
            }
            input.value = button.getAttribute('data-extensions') || '';
            input.focus();
            updateTemplatePreview();
        });
    });

    [
        'automation-module-input',
        'scan-roots-input',
        'worker-path-mappings-input',
        'template-sample-path',
        'source-template-input',
        'delivery-mode-input',
        'delivery-template-input',
        'output-suffix-input',
        'overwrite-allowed-input',
        'command-mode-input',
        'command-template-input'
    ].forEach(function (id) {
        var element = byId(id);
        if (!element) {
            return;
        }
        element.addEventListener('input', updateTemplatePreview);
        element.addEventListener('change', updateTemplatePreview);
    });

    function setupCollapsibleSections() {
        var sections = Array.prototype.slice.call(document.querySelectorAll('.collapsible-form-block[data-section-key]'));
        if (!sections.length) {
            return;
        }

        sections.forEach(function (section) {
            var key = 'reflection.automation.section.' + (section.getAttribute('data-section-key') || 'section');
            try {
                var stored = window.localStorage ? window.localStorage.getItem(key) : null;
                if (stored === 'open') {
                    section.open = true;
                } else if (stored === 'closed') {
                    section.open = false;
                }
            } catch (ignore) {
                // localStorage may be blocked; the native details element still works.
            }
            section.addEventListener('toggle', function () {
                try {
                    if (window.localStorage) {
                        window.localStorage.setItem(key, section.open ? 'open' : 'closed');
                    }
                } catch (ignore) {
                    // Ignore storage failures.
                }
            });
        });

        var openAll = byId('open-all-sections');
        var closeAll = byId('close-all-sections');
        if (openAll) {
            openAll.addEventListener('click', function () {
                sections.forEach(function (section) { section.open = true; });
            });
        }
        if (closeAll) {
            closeAll.addEventListener('click', function () {
                sections.forEach(function (section) { section.open = false; });
            });
        }
    }

    var form = byId('automation-rule-form');
    if (form) {
        form.addEventListener('submit', function (event) {
            var errors = validateTemplates();
            if (errors.length > 0) {
                event.preventDefault();
                var templateSection = document.querySelector('.collapsible-form-block[data-section-key="job-templates"]');
                if (templateSection) {
                    templateSection.open = true;
                }
                var summary = byId('template-validation-summary');
                if (summary) {
                    summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    }

    setupCollapsibleSections();
    renderPlaceholderChips();
    updateTemplatePreview();
}());
