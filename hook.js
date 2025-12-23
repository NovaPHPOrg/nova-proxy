(function() {
    var PROXY_PREFIX = '{{PREFIX}}';
    var CURRENT_ORIGIN = window.location.origin;  // https://bot.ankio.icu
    
    // 重写 URL
    function rewriteUrl(url) {
        if (!url || typeof url !== 'string') return url;
        
        // 完整 URL：如果是当前 origin，替换成代理前缀
        if (url.startsWith(CURRENT_ORIGIN + '/')) {
            var path = url.substring(CURRENT_ORIGIN.length);  // /api/public/settings
            console.log('[Proxy] rewrite', url, '->', PROXY_PREFIX + path);
            return PROXY_PREFIX + path;
        }
        
        // 其他完整 URL（跨域）不处理
        if (url.startsWith('http://') || url.startsWith('https://')) return url;
        if (url.startsWith('//')) return url;
        if (url.startsWith('data:') || url.startsWith('blob:')) return url;
        
        // 相对路径 /path
        if (url.startsWith('/')) {
            console.log('[Proxy] rewrite', url, '->', PROXY_PREFIX + url);
            return PROXY_PREFIX + url;
        }
        
        return url;
    }
    
    // Hook fetch
    var originalFetch = window.fetch;
    window.fetch = function(input, init) {
        if (typeof input === 'string') {
            input = rewriteUrl(input);
        } else if (input instanceof Request) {
            input = new Request(rewriteUrl(input.url), input);
        }
        return originalFetch.call(this, input, init);
    };
    
    // Hook XMLHttpRequest
    var originalOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        arguments[1] = rewriteUrl(url);
        return originalOpen.apply(this, arguments);
    };
    
    console.log('[Proxy] Request hook installed, prefix:', PROXY_PREFIX);
})();

