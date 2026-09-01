(function() {
    var PROXY_PREFIX = '{{PREFIX}}';
    var TARGET_ORIGINS = {{TARGET_ORIGINS}};
    var CURRENT_ORIGIN = window.location.origin;

    function rewriteUrl(url) {
        if (!url || typeof url !== 'string') return url;
        url = url.trim();

        if (url.startsWith(PROXY_PREFIX + '/') || url === PROXY_PREFIX) {
            return url;
        }

        if (url === '' ||
            url.startsWith('#') ||
            url.startsWith('data:') ||
            url.startsWith('javascript:') ||
            url.startsWith('mailto:') ||
            url.startsWith('blob:')) {
            return url;
        }

        // Already /go/{scheme}/{host}/... — do not stack page prefix again
        if (isAlreadyProxied(url)) {
            if (url.startsWith(CURRENT_ORIGIN + '/')) {
                return url.substring(CURRENT_ORIGIN.length);
            }
            return url;
        }

        for (var i = 0; i < TARGET_ORIGINS.length; i++) {
            var origin = TARGET_ORIGINS[i];
            if (url.startsWith(origin)) {
                var path = url.substring(origin.length) || '/';
                if (!path.startsWith('/')) {
                    path = '/' + path;
                }
                return PROXY_PREFIX + path;
            }
        }

        if (url.startsWith(CURRENT_ORIGIN + '/')) {
            var localPath = url.substring(CURRENT_ORIGIN.length);
            if (localPath.startsWith(PROXY_PREFIX + '/') || localPath === PROXY_PREFIX) {
                return localPath;
            }
            if (isAlreadyProxied(localPath)) {
                return localPath;
            }
            return PROXY_PREFIX + localPath;
        }

        if (url.startsWith('http://') || url.startsWith('https://')) {
            return toGoPath(url) || url;
        }
        if (url.startsWith('//')) {
            return toGoPath('https:' + url) || url;
        }

        if (url.startsWith('/')) {
            return PROXY_PREFIX + url;
        }

        return url;
    }

    function isAlreadyProxied(url) {
        if (url.startsWith(CURRENT_ORIGIN + '/go/http') || url.startsWith(CURRENT_ORIGIN + '/go/https')) {
            return true;
        }
        if (url.startsWith('/go/http') || url.startsWith('/go/https')) {
            return true;
        }
        return /(?:^|\/\/[^/]+)\/go\/https?\//i.test(url);
    }

    function toGoPath(absoluteUrl) {
        try {
            var u = new URL(absoluteUrl);
            if (u.protocol !== 'http:' && u.protocol !== 'https:') {
                return null;
            }
            var path = u.pathname || '/';
            return '/go/' + u.protocol.replace(':', '') + '/' + u.host + path + u.search + u.hash;
        } catch (e) {
            return null;
        }
    }

    // SPA apps read location.pathname; strip proxy prefix so routes stay site-rooted.
    var PREFIX_ROOT = PROXY_PREFIX.replace(/\/$/, '');

    function stripProxyPrefix(path) {
        if (!path || typeof path !== 'string') {
            return path;
        }
        if (path === PREFIX_ROOT || path === PREFIX_ROOT + '/') {
            return '/';
        }
        if (path.indexOf(PREFIX_ROOT + '/') === 0) {
            return path.substring(PREFIX_ROOT.length) || '/';
        }
        return path;
    }

    function patchNuxtBase(obj) {
        try {
            if (obj && obj.config && obj.config.app) {
                obj.config.app.baseURL = PREFIX_ROOT + '/';
            }
        } catch (e) {}
        return obj;
    }

    try {
        var pathDesc = Object.getOwnPropertyDescriptor(Location.prototype, 'pathname');
        if (pathDesc && pathDesc.get) {
            Object.defineProperty(Location.prototype, 'pathname', {
                configurable: true,
                enumerable: true,
                get: function () {
                    return stripProxyPrefix(pathDesc.get.call(this));
                }
            });
        }
    } catch (e) {}

    try {
        var hrefDesc = Object.getOwnPropertyDescriptor(Location.prototype, 'href');
        if (hrefDesc && hrefDesc.get && hrefDesc.set) {
            Object.defineProperty(Location.prototype, 'href', {
                configurable: true,
                enumerable: true,
                get: function () {
                    var raw = hrefDesc.get.call(this);
                    try {
                        var u = new URL(raw);
                        u.pathname = stripProxyPrefix(u.pathname);
                        return u.href;
                    } catch (err) {
                        return raw;
                    }
                },
                set: function (v) {
                    hrefDesc.set.call(this, rewriteUrl(String(v)));
                }
            });
        }
    } catch (e) {}

    try {
        var origPushState = history.pushState;
        history.pushState = function (state, title, url) {
            if (url != null && url !== '') {
                url = rewriteUrl(String(url));
            }
            return origPushState.call(this, state, title, url);
        };
        var origReplaceState = history.replaceState;
        history.replaceState = function (state, title, url) {
            if (url != null && url !== '') {
                url = rewriteUrl(String(url));
            }
            return origReplaceState.call(this, state, title, url);
        };
    } catch (e) {}

    try {
        var nuxtValue = window.__NUXT__;
        Object.defineProperty(window, '__NUXT__', {
            configurable: true,
            enumerable: true,
            get: function () {
                return nuxtValue;
            },
            set: function (v) {
                nuxtValue = patchNuxtBase(v);
            }
        });
        if (nuxtValue) {
            patchNuxtBase(nuxtValue);
        }
    } catch (e) {}

    var originalFetch = window.fetch;
    window.fetch = function(input, init) {
        if (typeof input === 'string') {
            input = rewriteUrl(input);
        } else if (input instanceof Request) {
            input = new Request(rewriteUrl(input.url), input);
        }
        return originalFetch.call(this, input, init);
    };

    var originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        arguments[1] = rewriteUrl(url);
        return originalOpen.apply(this, arguments);
    };

    try {
        var origAssign = Location.prototype.assign;
        Location.prototype.assign = function(url) {
            return origAssign.call(this, rewriteUrl(String(url)));
        };
        var origReplace = Location.prototype.replace;
        Location.prototype.replace = function(url) {
            return origReplace.call(this, rewriteUrl(String(url)));
        };
    } catch (e) {}

    var origOpen = window.open;
    window.open = function(url, target, features) {
        if (typeof url === 'string') {
            url = rewriteUrl(url);
        }
        return origOpen.call(window, url, target, features);
    };

    var URL_ATTRS = { src: 1, href: 1, action: 1, 'data-src': 1, 'data-href': 1 };
    var origSetAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function(name, value) {
        if (typeof value === 'string' && URL_ATTRS[String(name).toLowerCase()]) {
            value = rewriteUrl(value);
        }
        return origSetAttribute.call(this, name, value);
    };

    function hookUrlProperty(proto, prop) {
        var desc = Object.getOwnPropertyDescriptor(proto, prop);
        if (!desc || !desc.set) {
            return;
        }
        Object.defineProperty(proto, prop, {
            get: desc.get,
            set: function(val) {
                desc.set.call(this, rewriteUrl(String(val)));
            },
            configurable: true,
            enumerable: desc.enumerable
        });
    }

    if (window.HTMLScriptElement) {
        hookUrlProperty(HTMLScriptElement.prototype, 'src');
    }
    if (window.HTMLLinkElement) {
        hookUrlProperty(HTMLLinkElement.prototype, 'href');
    }
    if (window.HTMLImageElement) {
        hookUrlProperty(HTMLImageElement.prototype, 'src');
    }
    if (window.HTMLFormElement) {
        hookUrlProperty(HTMLFormElement.prototype, 'action');
    }

    function waitForElement(selector, timeout, interval) {
        timeout = timeout || 10000;
        interval = interval || 100;
        return new Promise(function(resolve, reject) {
            var startTime = Date.now();
            var timer = setInterval(function() {
                var element = document.querySelector(selector);
                if (element) {
                    clearInterval(timer);
                    resolve(element);
                    return;
                }
                if (Date.now() - startTime >= timeout) {
                    clearInterval(timer);
                    reject(new Error('[Proxy] Wait element "' + selector + '" timeout (' + timeout + 'ms)'));
                }
            }, interval);
        });
    }

    function simulateTyping(element, text, delay) {
        delay = delay || 100;
        element.focus();
        for (var idx = 0; idx < text.length; idx++) {
            var char = text[idx];
            element.dispatchEvent(new KeyboardEvent('keydown', { key: char }));
            var lastValue = element.value;
            element.value += char;
            var event = new Event('input', { bubbles: true });
            var tracker = element._valueTracker;
            if (tracker) tracker.setValue(lastValue);
            element.dispatchEvent(event);
            element.dispatchEvent(new KeyboardEvent('keyup', { key: char }));
        }
        element.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function simulateHumanClick(element) {
        if (!element) return;
        var eventConfig = { bubbles: true, cancelable: true, view: window, buttons: 1 };
        element.dispatchEvent(new MouseEvent('mouseenter', eventConfig));
        element.dispatchEvent(new MouseEvent('mouseover', eventConfig));
        element.dispatchEvent(new MouseEvent('mousedown', eventConfig));
        if (element.focus) element.focus();
        element.dispatchEvent(new MouseEvent('mouseup', eventConfig));
        element.dispatchEvent(new MouseEvent('click', eventConfig));
    }

    function simulateClickAtCenter(element) {
        var rect = element.getBoundingClientRect();
        var x = rect.left + rect.width / 2;
        var y = rect.top + rect.height / 2;
        var eventConfig = { bubbles: true, cancelable: true, clientX: x, clientY: y };
        element.dispatchEvent(new MouseEvent('mousedown', eventConfig));
        element.dispatchEvent(new MouseEvent('mouseup', eventConfig));
        element.dispatchEvent(new MouseEvent('click', eventConfig));
    }

    window.simulateClickAtCenter = simulateClickAtCenter;
    window.simulateHumanClick = simulateHumanClick;
    window.simulateTyping = simulateTyping;
    window.waitForElement = waitForElement;
})();
