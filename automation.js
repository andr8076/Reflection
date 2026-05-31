(function () {
    'use strict';

    var PLACEHOLDERS = {
        path: { label: 'Full file path', description: 'The absolute path of the matched file.' },
        root: { label: 'Scan root', description: 'The scan root that matched the file.' },
        relative: { label: 'Relative path', description: 'The file path below the matched scan root.' },
        dir: { label: 'Parent folder', description: 'The folder containing the matched file.' },
        basename: { label: 'Filename', description: 'The filename including the extension.' },
        name: { label: 'Name only', description: 'The filename without the final extension.' },
        ext: { label: 'Extension', description: 'The final extension without the dot.' },
        dot_ext: { label: 'Dot extension', description: 'The final extension including the dot.' },
        size: { label: 'File size', description: 'The file size in bytes. The browser preview uses an example size.' },
        mtime: { label: 'Modified time', description: 'The modified time as a Unix timestamp. The browser preview uses an example timestamp.' }
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

    function candidateFromExample() {
        var path = normalizeSlashes(valueOf('template-sample-path').trim() || '/volume1/video/Movies/Example Movie (2024)/Example Movie.mkv');
        var root = firstScanRoot() || dirname(path);
        root = normalizeSlashes(root).replace(/\/+$/, '');
        var rel = relativePath(path, root);
        var dir = dirname(path);
        var base = basename(path);
        var parts = splitNameExtension(base);
        return {
            '{path}': path,
            '{root}': root,
            '{relative}': rel,
            '{dir}': dir,
            '{basename}': base,
            '{name}': parts.name,
            '{ext}': parts.ext,
            '{dot_ext}': parts.ext ? '.' + parts.ext : '',
            '{mtime}': '1780241000',
            '{size}': '7340032000'
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
            return valueOf('delivery-mode-input') === 'template';
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
        if (deliveryMode === 'same_as_source') {
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
        setCode('preview-source', source);
        setCode('preview-delivery', delivery || 'No delivery target configured');
        setCode('source-template-preview', 'Example: ' + source);
        setCode('delivery-template-preview', deliveryMode === 'template'
            ? 'Example: ' + (delivery || 'No delivery target configured')
            : 'Ignored while delivery target is “same as source location”');
        setCode('suffix-template-preview', 'Example if not overwriting: ' + suffixExample);
        setCode('command-template-preview', command ? 'Example command: ' + command : 'No command template configured');

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
        'scan-roots-input',
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

    var form = byId('automation-rule-form');
    if (form) {
        form.addEventListener('submit', function (event) {
            var errors = validateTemplates();
            if (errors.length > 0) {
                event.preventDefault();
                var summary = byId('template-validation-summary');
                if (summary) {
                    summary.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
    }

    renderPlaceholderChips();
    updateTemplatePreview();
}());
