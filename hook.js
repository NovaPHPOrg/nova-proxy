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
    /**
     * 轮询等待元素出现
     * @param {string} selector - CSS 选择器
     * @param {number} timeout - 超时时间 (ms)，默认 10 秒
     * @param {number} interval - 轮询频率 (ms)，默认 100 毫秒
     * @returns {Promise<Element>}
     */
    function waitForElement(selector, timeout = 10000, interval = 100) {
        return new Promise((resolve, reject) => {
            const startTime = Date.now();

            const timer = setInterval(() => {
                const element = document.querySelector(selector);

                // 如果找到了元素
                if (element) {
                    clearInterval(timer);
                    resolve(element);
                    return;
                }

                // 如果超时
                if (Date.now() - startTime >= timeout) {
                    clearInterval(timer);
                    reject(new Error(`[Proxy] Wait element "${selector}" timeout (${timeout}ms)`));
                }
            }, interval);
        });
    }

    /**
     * 模拟人手输入文字
     * @param {HTMLElement} element - 输入框元素
     * @param {string} text - 要输入的文本
     * @param {number} delay - 每个字符之间的延迟（毫秒），模拟打字速度
     */
    function simulateTyping(element, text, delay = 100) {
        element.focus();

        for (let char of text) {
            // 1. 触发键盘按下
            element.dispatchEvent(new KeyboardEvent('keydown', { key: char }));

            // 2. 更新值 (模拟字符进入输入框)
            // 注意：如果是 React 框架，可能需要特殊处理 Object.getOwnPropertyDescriptor
            const lastValue = element.value;
            element.value += char;

            // 3. 触发 input 事件（这是让框架感知变化的关键）
            const event = new Event('input', { bubbles: true });
            // 兼容 React 的特殊处理：
            const tracker = element._valueTracker;
            if (tracker) tracker.setValue(lastValue);

            element.dispatchEvent(event);

            // 4. 触发键盘弹起
            element.dispatchEvent(new KeyboardEvent('keyup', { key: char }));

        }

        // 最后触发 change 事件
        element.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /**
     * 模拟真实点击
     * @param {HTMLElement} element - 目标元素
     */
    function simulateHumanClick(element) {
        if (!element) return;

        // 定义通用的事件配置
        const eventConfig = {
            bubbles: true,
            cancelable: true,
            view: window,
            buttons: 1 // 模拟左键
        };

        // 1. 鼠标移入
        element.dispatchEvent(new MouseEvent('mouseenter', eventConfig));
        element.dispatchEvent(new MouseEvent('mouseover', eventConfig));

        // 2. 鼠标按下
        element.dispatchEvent(new MouseEvent('mousedown', eventConfig));

        // 3. 获得焦点 (如果是输入框或按钮)
        if (element.focus) element.focus();

        // 4. 鼠标弹起
        element.dispatchEvent(new MouseEvent('mouseup', eventConfig));

        // 5. 触发点击
        element.dispatchEvent(new MouseEvent('click', eventConfig));
    }

    function simulateClickAtCenter(element) {
        const rect = element.getBoundingClientRect();

        // 计算元素中心点的坐标
        const x = rect.left + rect.width / 2;
        const y = rect.top + rect.height / 2;

        const eventConfig = {
            bubbles: true,
            cancelable: true,
            clientX: x,
            clientY: y
        };

        // 在中心位置触发 mousedown 和 mouseup
        element.dispatchEvent(new MouseEvent('mousedown', eventConfig));
        element.dispatchEvent(new MouseEvent('mouseup', eventConfig));
        element.dispatchEvent(new MouseEvent('click', eventConfig));
    }

    window.simulateClickAtCenter = simulateClickAtCenter;

    window.simulateHumanClick = simulateHumanClick;

    window.simulateTyping = simulateTyping;
    window.waitForElement = waitForElement;

    console.log('[Proxy] Request hook installed, prefix:', PROXY_PREFIX);
})();

