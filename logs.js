(function () {
    var panelSelector = '#logs-viewer-panel';
    var cardSelector = '#logs-version-card';
    var refreshInterval = 5000;
    var activeController = null;
    var requestId = 0;
    var typingTimer = null;

    function panel() {
        return document.querySelector(panelSelector);
    }

    function versionCard() {
        return document.querySelector(cardSelector);
    }

    function isEditingFilter() {
        var active = document.activeElement;
        return !!(active && active.closest && active.closest('.log-filter-form'));
    }

    function buildUrl(url) {
        var target = new URL(url, window.location.href);
        target.searchParams.set('_cache', String(Date.now()));
        return target;
    }

    function cleanUrl(url) {
        var target = new URL(url, window.location.href);
        target.searchParams.delete('_cache');
        return target;
    }

    function applyHtml(html) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var newPanel = doc.querySelector(panelSelector);
        var newCard = doc.querySelector(cardSelector);
        var currentPanel = panel();
        var currentCard = versionCard();

        if (newPanel && currentPanel) {
            currentPanel.replaceWith(newPanel);
        }
        if (newCard && currentCard) {
            currentCard.replaceWith(newCard);
        }
    }

    function loadUrl(url, pushHistory) {
        var id = ++requestId;
        var target = buildUrl(url);

        if (activeController) {
            activeController.abort();
        }
        activeController = new AbortController();

        return fetch(target.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            signal: activeController.signal
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        })
        .then(function (html) {
            if (id !== requestId) {
                return;
            }
            applyHtml(html);
            if (pushHistory) {
                window.history.pushState({}, '', cleanUrl(target).toString());
            }
        })
        .catch(function (error) {
            if (error.name === 'AbortError') {
                return;
            }
            console.error('Log refresh failed:', error);
        });
    }

    function urlFromFilterForm(form) {
        var params = new URLSearchParams(new FormData(form));
        var target = new URL(form.getAttribute('action') || window.location.pathname, window.location.href);
        target.search = params.toString();
        return target;
    }

    function loadFilterForm(form) {
        loadUrl(urlFromFilterForm(form).toString(), true);
    }

    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('#logs-viewer-panel .log-tabs a');
        if (!link) {
            return;
        }
        event.preventDefault();
        loadUrl(link.href, true);
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest && event.target.closest('#logs-viewer-panel .log-filter-form');
        if (!form) {
            return;
        }
        event.preventDefault();
        loadFilterForm(form);
    });

    document.addEventListener('change', function (event) {
        var field = event.target.closest && event.target.closest('#logs-viewer-panel .log-filter-form select');
        if (!field) {
            return;
        }
        var form = field.closest('.log-filter-form');
        if (form) {
            loadFilterForm(form);
        }
    });

    document.addEventListener('input', function (event) {
        var field = event.target.closest && event.target.closest('#logs-viewer-panel .log-filter-form input[name="q"]');
        if (!field) {
            return;
        }
        window.clearTimeout(typingTimer);
        typingTimer = window.setTimeout(function () {
            var form = field.closest('.log-filter-form');
            if (form) {
                loadFilterForm(form);
            }
        }, 400);
    });

    window.addEventListener('popstate', function () {
        loadUrl(window.location.href, false);
    });

    setInterval(function () {
        if (isEditingFilter()) {
            return;
        }
        loadUrl(window.location.href, false);
    }, refreshInterval);
}());
