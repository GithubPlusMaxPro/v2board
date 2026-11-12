(function () {
    'use strict';

    function element(tag, text, parent) {
        var node = document.createElement(tag);
        if (text) node.textContent = text;
        if (parent) parent.appendChild(node);
        return node;
    }

    function bytes(value) {
        var size = Number(value), units = ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB'], index = 0;
        while (size >= 1024 && index < units.length - 1) { size /= 1024; index++; }
        return size.toFixed(index ? 2 : 0) + ' ' + units[index];
    }

    function mount(container) {
        var cleanup = function () {}, allowed = null;
        function renderAccess() {
            var next = window.AdminPermissions.has('traffic.view');
            if (next === allowed) return;
            allowed = next;
            cleanup(); container.replaceChildren();
            if (!allowed) {
                element('p', '需要节点用户日流量查看权限。', container);
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
        panel.className = 'server-user-traffic-panel';
        panel.setAttribute('aria-labelledby', 'server-user-traffic-title');
        element('h2', '节点用户日流量', panel).id = 'server-user-traffic-title';
        var description = element('p', '按节点、用户和自然日累计上传及下载量。仅统计功能启用后节点上报的数据。', panel);
        var form = element('form', '', panel);
        form.className = 'server-user-traffic-filters';
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
        var type = element('select', '', element('label', '节点类型', form));
        type.setAttribute('aria-label', '节点类型');
        fields.server_type = type;
        [['', '全部类型'], ['shadowsocks', 'Shadowsocks'], ['vmess', 'VMess'], ['vless', 'VLESS'],
            ['trojan', 'Trojan'], ['tuic', 'TUIC'], ['hysteria', 'Hysteria'], ['anytls', 'AnyTLS'], ['v2node', 'V2Node']].forEach(function (item) {
            element('option', item[1], type).value = item[0];
        });
        var nodeId = input('server_id', '节点 ID', 'number', '全部节点');
        nodeId.min = '1'; nodeId.step = '1';
        input('start_date', '开始日期', 'date');
        input('end_date', '结束日期', 'date');
        var actions = element('div', '', form);
        actions.className = 'server-user-traffic-actions';
        var apply = element('button', '查询 / 刷新', actions);
        apply.type = 'submit';
        var reset = element('button', '重置', actions);
        reset.type = 'button';
        var summary = element('div', '', panel);
        summary.className = 'server-user-traffic-summary';
        var rawCard = element('div', '', summary);
        element('span', '实际流量合计', rawCard);
        var rawTotal = element('strong', '—', rawCard);
        var billedCard = element('div', '', summary);
        element('span', '倍率扣量合计', billedCard);
        var billedTotal = element('strong', '—', billedCard);
        var summaryNote = element('p', '合计包含当前筛选范围的所有记录。', panel);
        var status = element('p', '', panel);
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var scroll = element('div', '', panel);
        scroll.className = 'server-user-traffic-scroll';
        var table = element('table', '', scroll);
        var heading = element('tr', '', element('thead', '', table));
        ['日期', '节点', '用户', '上传', '下载', '实际合计', '倍率扣量'].forEach(function (title) {
            element('th', title, heading).scope = 'col';
        });
        var body = element('tbody', '', table);
        var footer = element('div', '', panel);
        footer.className = 'server-user-traffic-footer';
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
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/serverUserTraffic/fetch?' + query, {
                    headers: { authorization: localStorage.getItem('authorization') || '', Accept: 'application/json' },
                    signal: controller.signal
                });
                var result = await response.json();
                if (!response.ok) throw new Error(result.errors ? Object.values(result.errors)[0][0] : result.message || '加载失败，请重试');
                if (controller.signal.aborted) return;
                page = result.current; total = result.total;
                if (!fields.start_date.value) fields.start_date.value = result.start_date;
                if (!fields.end_date.value) fields.end_date.value = result.end_date;
                description.textContent = '按节点、用户和自然日累计上传及下载量。统计时区：' + result.timezone + '。';
                rawTotal.textContent = bytes(result.summary.total);
                billedTotal.textContent = bytes(result.summary.billed_total);
                summaryNote.textContent = '合计包含 ' + result.start_date + ' 至 ' + result.end_date + ' 所有匹配记录。倍率扣量按每次上报时的倍率累计。';
                body.replaceChildren();
                result.data.forEach(function (item) {
                    var row = element('tr', '', body);
                    element('td', item.record_date, row);
                    var node = element('td', '', row);
                    element('strong', item.server_name || '未命名节点', node);
                    element('small', item.server_type + ' · ID ' + item.server_id, node);
                    var user = element('td', '', row);
                    element('span', item.email || '用户已删除', user);
                    element('small', 'ID ' + item.user_id, user);
                    element('td', bytes(item.u), row);
                    element('td', bytes(item.d), row);
                    element('td', bytes(Number(item.u) + Number(item.d)), row);
                    element('td', bytes(Number(item.billed_u) + Number(item.billed_d)), row);
                });
                if (!result.data.length) message('暂无流量记录');
                status.textContent = '';
            } catch (error) {
                if (!controller.signal.aborted) {
                    status.textContent = error.message; rawTotal.textContent = billedTotal.textContent = '—';
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
                if (value) filters[key] = value;
            });
            if (filters.start_date && filters.end_date && filters.end_date < filters.start_date) {
                status.textContent = '结束日期不能早于开始日期'; return;
            }
            if (filters.server_id && !filters.server_type) {
                status.textContent = '按节点 ID 查询时请选择节点类型'; return;
            }
            activeFilters = filters; load(1);
        };
        reset.onclick = function () { form.reset(); activeFilters = {}; load(1); };
        prev.onclick = function () { load(page - 1); };
        next.onclick = function () { load(page + 1); };
        load(1);
        return function () { controller.abort(); };
    }

    window.ServerUserTraffic = { mount: mount };
}());
