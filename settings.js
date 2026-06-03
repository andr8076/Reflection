(function () {
    var editor = document.querySelector('[data-machine-editor]');
    var tbody = document.getElementById('machine-editor-body');
    var template = document.getElementById('machine-row-template');
    var addButton = document.querySelector('[data-add-machine-row]');

    if (!editor || !tbody || !template || !addButton) {
        return;
    }

    var nextIndex = tbody.querySelectorAll('tr').length;

    function updateLegacyMachineList() {
        var legacy = document.querySelector('.legacy-machine-list');
        if (!legacy) {
            return;
        }

        var lines = [];
        tbody.querySelectorAll('tr').forEach(function (row) {
            var pcId = row.querySelector('input[name^="machine_pc_id"]');
            var mac = row.querySelector('input[name^="machine_mac"]');
            var minSoc = row.querySelector('input[name^="machine_min_soc_percent"]');
            var wake = row.querySelector('input[name^="machine_wake_enabled"]');
            var layer = row.querySelector('input[name^="machine_shutdown_layer"]');

            var pcValue = pcId ? pcId.value.trim() : '';
            var macValue = mac ? mac.value.trim() : '';
            if (pcValue === '' && macValue === '') {
                return;
            }

            lines.push([
                pcValue,
                macValue,
                minSoc ? minSoc.value.trim() : '',
                wake && wake.checked ? '1' : '0',
                layer ? layer.value.trim() || '0' : '0'
            ].join(','));
        });

        legacy.value = lines.join('\n');
    }

    function ensureOneBlankRow() {
        if (tbody.querySelectorAll('tr').length > 0) {
            return;
        }
        addRow();
    }

    function addRow() {
        var html = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
        nextIndex += 1;
        tbody.insertAdjacentHTML('beforeend', html);
        updateLegacyMachineList();
    }

    addButton.addEventListener('click', function () {
        addRow();
    });

    tbody.addEventListener('click', function (event) {
        var button = event.target.closest('[data-remove-machine-row]');
        if (!button) {
            return;
        }

        var row = button.closest('tr');
        if (row) {
            row.remove();
        }
        ensureOneBlankRow();
        updateLegacyMachineList();
    });

    tbody.addEventListener('input', updateLegacyMachineList);
    tbody.addEventListener('change', updateLegacyMachineList);
    updateLegacyMachineList();
}());
