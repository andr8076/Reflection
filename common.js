(function () {
    'use strict';

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-confirm]');
        if (!form) {
            return;
        }

        if (!window.confirm(form.getAttribute('data-confirm') || 'Continue?')) {
            event.preventDefault();
        }
    }, true);
}());
