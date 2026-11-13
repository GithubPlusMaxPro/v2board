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
        panel.className = 'subscription-log-panel audit-page';
        panel.setAttribute('aria-labelledby', 'subscription-log-title');
        var header = element('div', '', panel); header.className = 'audit-header';
        var titleBlock = element('div', '', header);
        element('span', '访问审查', titleBlock).className = 'audit-eyebrow';
        element('h2', '订阅访问记录', titleBlock).id = 'subscription-log-title';
        var description = element('p', '集中查看订阅访问轨迹，按用户、IP 与访问类型快速定位。', titleBlock);
        element('span', '订阅动态', header).className = 'audit-header-badge';
        var filterBox = element('div', '', panel); filterBox.className = 'audit-filterbox';
        element('h3', '筛选条件', filterBox);

        var summary = element('div', '', panel); summary.className = 'audit-summary'; panel.insertBefore(summary, filterBox);
        var statValues = [];
        [['匹配访问', '全部筛选结果'], ['获取地址', '当前页记录'], ['拉取订阅', '当前页记录'], ['异常响应', '当前页 HTTP 400 及以上']].forEach(function (item) {
            var tile = element('div', '', summary); element('span', item[0], tile); statValues.push(element('strong', '—', tile)); element('small', item[1], tile);
        });
        var form = element('form', '', filterBox);
        form.className = 'subscription-log-filters audit-filters';
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
        actions.className = 'subscription-log-actions audit-actions';
        var apply = element('button', '查询 / 刷新', actions);
        apply.type = 'submit';
        var reset = element('button', '重置', actions);
        reset.type = 'button';
        var status = element('p', '', panel);
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var tableIntro = element('div', '', panel); tableIntro.className = 'audit-table-intro';
        element('h3', '访问明细', tableIntro);
        var scroll = element('div', '', panel);
        scroll.className = 'subscription-log-scroll audit-table-wrap';
        var table = element('table', '', scroll);
        var heading = element('tr', '', element('thead', '', table));
        ['时间', '用户', '访问类型', '来源 IP', '客户端', 'HTTP 响应'].forEach(function (title) {
            element('th', title, heading).scope = 'col';
        });
        var body = element('tbody', '', table);
        var footer = element('div', '', panel);
        footer.className = 'subscription-log-footer audit-footer';
        var pageLabel = element('span', '', footer);
        var pager = element('div', '', footer); pager.className = 'audit-pagination';
        var prev = element('button', '上一页', pager);
        var next = element('button', '下一页', pager);
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
            element('td', text, element('tr', '', body)).colSpan = 6;
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
                statValues[0].textContent = String(total);
                statValues[1].textContent = String(result.data.filter(function (item) { return item.action === 'address'; }).length);
                statValues[2].textContent = String(result.data.filter(function (item) { return item.action === 'download'; }).length);
                statValues[3].textContent = String(result.data.filter(function (item) { return Number(item.status_code) >= 400; }).length);
                body.replaceChildren();
                result.data.forEach(function (item) {
                    var row = element('tr', '', body);
                    element('td', new Date(item.created_at * 1000).toLocaleString('zh-CN', { hour12: false }), row).className = 'subscription-log-time';
                    var user = element('td', '', row), identity = element('div', '', user); identity.className = 'audit-identity';
                    element('span', item.email ? item.email.charAt(0).toUpperCase() : '?', identity).className = 'audit-avatar';
                    var account = element('div', '', identity); element('span', item.email || '用户已删除', account); element('small', 'ID ' + item.user_id, account);
                    element('span', item.action === 'address' ? '获取地址' : '拉取订阅', element('td', '', row)).className = 'audit-chip ' + (item.action === 'address' ? 'audit-chip-purple' : 'audit-chip-blue');
                    element('td', item.ip || '—', row).className = 'audit-ip';
                    var agent = element('td', '', row); agent.className = 'subscription-log-agent'; agent.title = item.user_agent || '';
                    var ua = (item.user_agent || '').toLowerCase(), client = '其他客户端';
                    [['v2rayng', 'v2rayNG'], ['v2rayn', 'v2rayN'], ['loon', 'Loon'], ['egern', 'Egern'], ['clash', 'Clash'], ['sing', 'Sing-box'], ['surge', 'Surge'], ['stash', 'Stash'], ['shadowrocket', 'Shadowrocket']].some(function (pair) { if (ua.indexOf(pair[0]) !== -1) { client = pair[1]; return true; } return false; });
                    element('strong', client, agent); element('small', item.user_agent || '未提供 User-Agent', agent);
                    var code = Number(item.status_code);
                    element('span', String(item.status_code), element('td', '', row)).className = 'audit-chip ' + (code >= 400 ? 'audit-chip-red' : code >= 200 && code < 300 ? 'audit-chip-green' : 'audit-chip-blue');
                });
                if (!result.data.length) message('暂无订阅访问记录');
                status.textContent = '';
            } catch (error) {
                if (!controller.signal.aborted) {
                    status.textContent = error.message; statValues.forEach(function (value) { value.textContent = '—'; });
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
