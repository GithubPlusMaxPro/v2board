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
        panel.className = 'server-user-traffic-panel audit-page';
        panel.setAttribute('aria-labelledby', 'server-user-traffic-title');
        var header = element('div', '', panel); header.className = 'audit-header';
        var titleBlock = element('div', '', header);
        element('span', '流量分析', titleBlock).className = 'audit-eyebrow';
        element('h2', '节点用户日流量', titleBlock).id = 'server-user-traffic-title';
        var description = element('p', '默认按节点汇总流量，展开节点查看各用户的流量消耗。', titleBlock);
        element('span', '按日统计', header).className = 'audit-header-badge';
        var filterBox = element('div', '', panel); filterBox.className = 'audit-filterbox';
        element('h3', '筛选条件', filterBox);

        var form = element('form', '', filterBox);
        form.className = 'server-user-traffic-filters audit-filters';
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
        actions.className = 'server-user-traffic-actions audit-actions';
        var apply = element('button', '查询 / 刷新', actions);
        apply.type = 'submit';
        var reset = element('button', '重置', actions);
        reset.type = 'button';
        var summary = element('div', '', panel);
        summary.className = 'server-user-traffic-summary audit-summary';
        var countCard = element('div', '', summary); element('span', '匹配节点', countCard); var recordCount = element('strong', '—', countCard); element('small', '全部筛选结果', countCard);
        var nodeCard = element('div', '', summary); element('span', '本页节点', nodeCard); var nodeCount = element('strong', '—', nodeCard); element('small', '按节点类型与 ID 区分', nodeCard);
        panel.insertBefore(summary, filterBox);
        var rawCard = element('div', '', summary);
        element('span', '实际流量合计', rawCard);
        var rawTotal = element('strong', '—', rawCard);
        var billedCard = element('div', '', summary);
        element('span', '倍率扣量合计', billedCard);
        var billedTotal = element('strong', '—', billedCard);
        element('small', '筛选范围内实际用量', rawCard);
        element('small', '筛选范围内倍率扣量', billedCard);
        var summaryNote = element('p', '合计包含当前筛选范围的所有记录。', panel); summaryNote.className = 'audit-summary-note';
        var status = element('p', '', panel);
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var tableIntro = element('div', '', panel); tableIntro.className = 'audit-table-intro';
        element('h3', '节点用量', tableIntro);
        var scroll = element('div', '', panel);
        scroll.className = 'server-user-traffic-scroll audit-table-wrap';
        var table = element('table', '', scroll);
        var heading = element('tr', '', element('thead', '', table));
        ['节点', '用户数', '上传', '下载', '实际合计', '倍率扣量', '操作'].forEach(function (title) {
            element('th', title, heading).scope = 'col';
        });
        var body = element('tbody', '', table);
        var footer = element('div', '', panel);
        footer.className = 'server-user-traffic-footer audit-footer';
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
            pageLabel.textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / pageSize)) + ' 页，共 ' + total + ' 个节点';
        }
        function message(text) {
            body.replaceChildren();
            element('td', text, element('tr', '', body)).colSpan = 7;
        }
        async function requestTraffic(filters) {
            var response = await fetch('/api/v1/' + window.settings.secure_path + '/serverUserTraffic/fetch?' + new URLSearchParams(filters), {
                headers: { authorization: localStorage.getItem('authorization') || '', Accept: 'application/json' },
                signal: controller.signal
            });
            var result = await response.json();
            if (!response.ok) throw new Error(result.errors ? Object.values(result.errors)[0][0] : result.message || '加载失败，请重试');
            return result;
        }
        function userDetails(parent, item, filters, toggle) {
            var detail = element('tr', '', parent); detail.className = 'traffic-detail-row'; detail.id = 'traffic-users-' + item.server_type + '-' + item.server_id; detail.hidden = true;
            var cell = element('td', '', detail); cell.colSpan = 7;
            var content = element('div', '', cell); content.className = 'traffic-user-details';
            element('h4', (item.server_name || '未命名节点') + ' · 用户流量', content);
            element('p', filters.start_date + ' 至 ' + filters.end_date + '，按用户汇总并按实际流量排序。', content);
            var detailStatus = element('p', '', content); detailStatus.setAttribute('role', 'status');
            var wrap = element('div', '', content); wrap.className = 'audit-table-wrap';
            var grid = element('table', '', wrap), head = element('tr', '', element('thead', '', grid));
            ['用户', '上传', '下载', '实际合计', '倍率扣量'].forEach(function (title) { element('th', title, head).scope = 'col'; });
            var rows = element('tbody', '', grid);
            var footer = element('div', '', content); footer.className = 'audit-footer';
            var label = element('span', '', footer), pager = element('div', '', footer); pager.className = 'audit-pagination';
            var previous = element('button', '上一页', pager), following = element('button', '下一页', pager);
            previous.type = following.type = 'button';
            var current = 1, count = 0, loading = false, loaded = false;
            function controls() {
                previous.disabled = loading || current <= 1;
                following.disabled = loading || current * pageSize >= count;
                label.textContent = '第 ' + current + ' / ' + Math.max(1, Math.ceil(count / pageSize)) + ' 页，共 ' + count + ' 位用户';
            }
            async function loadUsers(target) {
                if (loading) return;
                loading = true; controls(); detailStatus.textContent = '正在加载用户流量…'; grid.setAttribute('aria-busy', 'true');
                try {
                    var result = await requestTraffic(Object.assign({}, filters, {
                        group_by: 'user', server_type: item.server_type, server_id: item.server_id, current: target, page_size: pageSize
                    }));
                    if (controller.signal.aborted || !detail.isConnected) return;
                    current = result.current; count = result.total; rows.replaceChildren();
                    result.data.forEach(function (user) {
                        var row = element('tr', '', rows), account = element('td', '', row);
                        element('strong', user.email || '用户已删除', account); element('small', 'ID ' + user.user_id, account).className = 'audit-meta';
                        element('td', bytes(user.u), row); element('td', bytes(user.d), row);
                        element('td', bytes(Number(user.u) + Number(user.d)), row).className = 'traffic-total';
                        element('td', bytes(Number(user.billed_u) + Number(user.billed_d)), row).className = 'traffic-billed';
                    });
                    if (!result.data.length) element('td', '暂无用户流量记录', element('tr', '', rows)).colSpan = 5;
                    detailStatus.textContent = ''; loaded = true;
                } catch (error) {
                    if (!controller.signal.aborted && detail.isConnected) { loaded = false; detailStatus.textContent = error.message + '，可收起后重新展开重试。'; }
                } finally {
                    loading = false; controls(); grid.setAttribute('aria-busy', 'false');
                }
            }
            toggle.setAttribute('aria-controls', detail.id);
            toggle.setAttribute('aria-expanded', 'false');
            toggle.onclick = function () {
                detail.hidden = !detail.hidden; toggle.setAttribute('aria-expanded', String(!detail.hidden));
                toggle.textContent = detail.hidden ? '展开用户' : '收起用户';
                if (!detail.hidden && !loaded) loadUsers(1);
            };
            previous.onclick = function () { loadUsers(current - 1); };
            following.onclick = function () { loadUsers(current + 1); };
            controls();
        }
        async function load(targetPage) {
            if (busy) return;
            busy = true; controls(); status.textContent = '正在加载…'; table.setAttribute('aria-busy', 'true');
            try {
                var result = await requestTraffic(Object.assign({ current: targetPage, page_size: pageSize }, activeFilters, { group_by: 'server' }));
                if (controller.signal.aborted) return;
                page = result.current; total = result.total;
                recordCount.textContent = String(total);
                nodeCount.textContent = String(new Set(result.data.map(function (item) { return item.server_type + ':' + item.server_id; })).size);
                if (!fields.start_date.value) fields.start_date.value = result.start_date;
                if (!fields.end_date.value) fields.end_date.value = result.end_date;
                description.textContent = '按节点汇总所选日期范围的流量，展开查看各用户用量。统计时区：' + result.timezone + '。';
                rawTotal.textContent = bytes(result.summary.total);
                billedTotal.textContent = bytes(result.summary.billed_total);
                summaryNote.textContent = '合计包含 ' + result.start_date + ' 至 ' + result.end_date + ' 所有匹配记录。倍率扣量按每次上报时的倍率累计。';
                body.replaceChildren();
                result.data.forEach(function (item) {
                    var row = element('tr', '', body);
                    var node = element('td', '', row);
                    element('strong', item.server_name || '未命名节点', node);
                    element('small', item.server_type + ' · ID ' + item.server_id, node).className = 'audit-meta';
                    element('td', String(item.user_count), row);
                    element('td', bytes(item.u), row);
                    element('td', bytes(item.d), row);
                    element('td', bytes(Number(item.u) + Number(item.d)), row).className = 'traffic-total';
                    element('td', bytes(Number(item.billed_u) + Number(item.billed_d)), row).className = 'traffic-billed';
                    var toggle = element('button', '展开用户', element('td', '', row)); toggle.type = 'button';
                    userDetails(body, item, Object.assign({}, activeFilters, {start_date: result.start_date, end_date: result.end_date}), toggle);
                });
                if (!result.data.length) message('暂无流量记录');
                status.textContent = '';
            } catch (error) {
                if (!controller.signal.aborted) {
                    status.textContent = error.message; rawTotal.textContent = billedTotal.textContent = recordCount.textContent = nodeCount.textContent = '—';
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
