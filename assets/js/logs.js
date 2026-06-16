(function () {
    'use strict';

    function checkboxesForForm(formId) {
        return Array.prototype.slice.call(document.querySelectorAll('input[data-row-select][form="' + formId + '"]'));
    }

    function updateSelectionCount(formId) {
        var countNode = document.querySelector('[data-selection-count="' + formId + '"]');
        var boxes = checkboxesForForm(formId);
        var selected = boxes.filter(function (box) { return box.checked; }).length;
        var selectAll = document.querySelector('input[data-select-all][data-target-form="' + formId + '"]');

        if (countNode) {
            countNode.textContent = selected + ' selected';
        }
        if (selectAll) {
            selectAll.checked = boxes.length > 0 && selected === boxes.length;
            selectAll.indeterminate = selected > 0 && selected < boxes.length;
        }
    }

    document.addEventListener('change', function (event) {
        var selectAll = event.target.closest && event.target.closest('input[data-select-all]');
        if (selectAll) {
            var formId = selectAll.getAttribute('data-target-form') || '';
            checkboxesForForm(formId).forEach(function (box) {
                box.checked = selectAll.checked;
            });
            updateSelectionCount(formId);
            return;
        }

        var rowSelect = event.target.closest && event.target.closest('input[data-row-select]');
        if (rowSelect) {
            updateSelectionCount(rowSelect.getAttribute('form') || '');
        }
    });

    document.addEventListener('submit', function (event) {
        var submitter = event.submitter;
        if (!submitter || !submitter.getAttribute) {
            return;
        }
        var message = submitter.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    }, true);

    ['job-bulk-form', 'bin-bulk-form'].forEach(updateSelectionCount);
}());
