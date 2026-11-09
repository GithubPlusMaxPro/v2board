(function () {
    'use strict';

    function element(tag, text, parent) {
        var node = document.createElement(tag);
        if (text) node.textContent = text;
        if (parent) parent.appendChild(node);
        return node;
    }

    function mount(container) {
        var cleanup = function () {}, allowed = null;
        function renderAccess() {
            var next = window.AdminPermissions.has('user.subscribe_log');
            if (next === allowed) return;
            allowed = next;
            cleanup(); container.replaceChildren();
            if (!allowed) {
                element('p', '需要管理员订阅审查权限才能查看记录。', container);
                return;
            }
            cleanup = renderPage(container);
        }
        window.addEventListener('admin-permissions-changed', renderAccess);
        renderAccess();
        return function () {
            window.removeEventListener('admin-permissions-changed', renderAccess);
            cleanup(); container.replaceChildren();
        };
    }

    function renderPage(container) {
        var controller = new AbortController();
        var panel = element('section', '', container);
        panel.className = 'subscription-log-panel';
        panel.setAttribute('aria-labelledby', 'subscription-log-title');
        element('h2', '订阅访问记录', panel).id = 'subscription-log-title';
        element('p', '集中审查用户获取订阅地址和客户端拉取订阅的记录。时间按当前浏览器时区显示。', panel);
        var form = element('form', '', panel);
        form.className = 'subscription-log-filters';
        var fields = {};
        function input(key, label, type, placeholder) {
            var wrapper = element('label', label, form);
            var field = element('input', '', wrapper);
            field.type = type; field.placeholder = placeholder || '';
            fields[key] = field;
            return field;
        }
        var userId = input('user_id', '用户 ID', 'number', '全部用户');
        userId.min = '1'; userId.step = '1';
        input('email', '用户邮箱', 'text', '完整邮箱精确匹配').maxLength = 254;
        var action = element('select', '', element('label', '访问类型', form));
        action.setAttribute('aria-label', '访问类型');
        fields.action = action;
        [['', '全部'], ['address', '获取地址'], ['download', '拉取订阅']].forEach(function (item) {
            element('option', item[1], action).value = item[0];
        });
        input('ip', 'IP', 'text', '完整 IP 精确匹配').maxLength = 45;
        input('start_at', '开始时间', 'datetime-local');
        input('end_at', '结束时间', 'datetime-local');
        var actions = element('div', '', form);
        actions.className = 'subscription-log-actions';
        var apply = element('button', '查询 / 刷新', actions);
        apply.type = 'submit';
        var reset = element('button', '重置', actions);
        reset.type = 'button';
        var status = element('p', '', panel);
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var scroll = element('div', '', panel);
        scroll.className = 'subscription-log-scroll';
        var table = element('table', '', scroll);
        var heading = element('tr', '', element('thead', '', table));
        ['时间', '用户 ID', '用户邮箱', '类型', 'IP', '客户端（User-Agent）', 'HTTP 响应码'].forEach(function (title) {
            element('th', title, heading).scope = 'col';
        });
        var body = element('tbody', '', table);
        var footer = element('div', '', panel);
        footer.className = 'subscription-log-footer';
        var prev = element('button', '上一页', footer);
        var pageLabel = element('span', '', footer);
        var next = element('button', '下一页', footer);
        prev.type = next.type = 'button';
        var page = 1, pageSize = 20, total = 0, busy = false, activeFilters = {};
        function controls() {
            apply.disabled = reset.disabled = busy;
            Object.values(fields).forEach(function (field) { field.disabled = busy; });
            prev.disabled = busy || page <= 1;
            next.disabled = busy || page * pageSize >= total;
            pageLabel.textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / pageSize)) + ' 页，共 ' + total + ' 条';
        }
        function message(text) {
            body.replaceChildren();
            element('td', text, element('tr', '', body)).colSpan = 7;
        }
        async function load(targetPage) {
            if (busy) return;
            busy = true; controls(); status.textContent = '正在加载…'; table.setAttribute('aria-busy', 'true');
            try {
                var query = new URLSearchParams(Object.assign({ current: targetPage, page_size: pageSize }, activeFilters));
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/subscriptionLog/fetch?' + query, {
                    headers: { authorization: localStorage.getItem('authorization') || '', Accept: 'application/json' },
                    signal: controller.signal
                });
                var result = await response.json();
                if (!response.ok) throw new Error(result.errors ? Object.values(result.errors)[0][0] : result.message || '加载失败，请重试');
                if (controller.signal.aborted) return;
                page = result.current; total = result.total;
                body.replaceChildren();
                result.data.forEach(function (item) {
                    var row = element('tr', '', body);
                    element('td', new Date(item.created_at * 1000).toLocaleString('zh-CN', { hour12: false }), row).className = 'subscription-log-time';
                    element('td', String(item.user_id), row);
                    element('td', item.email || '用户已删除', row);
                    element('td', item.action === 'address' ? '获取地址' : '拉取订阅', row);
                    element('td', item.ip || '—', row);
                    element('td', item.user_agent || '—', row).className = 'subscription-log-agent';
                    element('td', String(item.status_code), row);
                });
                if (!result.data.length) message('暂无订阅访问记录');
                status.textContent = '';
            } catch (error) {
                if (!controller.signal.aborted) {
                    status.textContent = error.message;
                    page = 1; total = 0; message('加载失败，请重试');
                }
            } finally {
                if (!controller.signal.aborted) {
                    busy = false; controls(); table.setAttribute('aria-busy', 'false');
                }
            }
        }
        form.onsubmit = function (event) {
            event.preventDefault();
            if (busy) return;
            var filters = {};
            Object.keys(fields).forEach(function (key) {
                var value = fields[key].value.trim();
                if (value) filters[key] = key === 'start_at' || key === 'end_at' ? Math.floor(new Date(value).getTime() / 1000) : value;
            });
            if (filters.start_at !== undefined && filters.end_at !== undefined && filters.end_at < filters.start_at) {
                status.textContent = '结束时间不能早于开始时间'; return;
            }
            activeFilters = filters; load(1);
        };
        reset.onclick = function () { form.reset(); activeFilters = {}; load(1); };
        prev.onclick = function () { load(page - 1); };
        next.onclick = function () { load(page + 1); };
        load(1);
        return function () { controller.abort(); };
    }

    window.SubscriptionHistory = { mount: mount };
}());
