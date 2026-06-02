'use strict';

document.querySelectorAll('[data-preset]').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('request-json').value = button.getAttribute('data-preset');
    });
});
