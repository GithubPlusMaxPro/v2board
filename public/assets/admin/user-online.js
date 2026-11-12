(function () {
    'use strict';
    function el(tag, text, parent) {
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
    function time(value) { return new Date(value * 1000).toLocaleString('zh-CN', {hour12: false}); }
    async function request(action, filters, signal) {
        var response = await fetch('/api/v1/' + window.settings.secure_path + '/userOnline/' + action + '?' + new URLSearchParams(filters), {
            headers: {authorization: localStorage.getItem('authorization') || '', Accept: 'application/json'}, signal: signal
        });
        var result = await response.json();
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors)[0][0] : result.message || '查询失败，请重试');
        return result;
    }
    function input(parent, label, type, placeholder) {
        var field = el('input', '', el('label', label, parent));
        field.type = type; field.placeholder = placeholder || ''; field.setAttribute('aria-label', label);
        return field;
    }
    function nodeCell(row, item) {
        var cell = el('td', '', row);
        el('strong', item.server_name || '未命名或已删除节点', cell);
        el('small', item.server_type + ' · ID ' + item.server_id, cell);
    }
    function table(parent, titles) {
        var scroll = el('div', '', parent); scroll.className = 'user-online-scroll';
        var table = el('table', '', scroll), heading = el('tr', '', el('thead', '', table));
        titles.forEach(function (title) { el('th', title, heading).scope = 'col'; });
        return {node: table, body: el('tbody', '', table)};
    }
    function message(body, text, columns) { body.replaceChildren(); el('td', text, el('tr', '', body)).colSpan = columns; }

    function geoRenderer(userId, signal) {
        var pending = new Map(), queue = [], running = 0, automatic = 0;
        function pump() {
            while (!signal.aborted && running < 2 && queue.length) {
                var task = queue.shift(); running++;
                (function (task) {
                    request('ipInfo', {user_id: userId, ip: task.ip}, signal)
                        .then(function (result) { task.resolve(result.data); })
                        .catch(function () { task.resolve({status: 'unavailable'}); })
                        .finally(function () { running--; pump(); });
                }(task));
            }
        }
        function lookup(ip) {
            if (!pending.has(ip)) pending.set(ip, new Promise(function (resolve) { queue.push({ip: ip, resolve: resolve}); pump(); }));
            return pending.get(ip);
        }
        return function (ip, cell) {
            var info = el('small', '', cell), button = el('button', '查询归属地', cell);
            button.type = 'button'; button.className = 'user-online-geo';
            async function load() {
                button.disabled = true; info.textContent = '归属地加载中…';
                var data = await lookup(ip);
                if (signal.aborted || !cell.isConnected) return;
                if (data.status === 'ok') {
                    info.textContent = [data.country, data.region, data.city].filter(function (part, i, all) { return part && all.indexOf(part) === i; }).join(' / ') || '暂无地区信息';
                    el('small', [data.organization_name, data.asn ? 'AS' + data.asn : ''].filter(Boolean).join(' · '), cell);
                    button.remove();
                } else if (data.status === 'local') { info.textContent = '内网或保留地址'; button.remove(); }
                else if (data.status === 'disabled') { info.textContent = '归属地查询未启用'; button.remove(); }
                else { info.textContent = '归属地暂不可用'; button.textContent = '重试'; button.disabled = false; }
            }
            button.onclick = function () { if (button.textContent === '重试') pending.delete(ip); load(); };
            if (automatic++ < 20) load();
        };
    }

    function paged(parent, kind, user, date, signal, geo) {
        var form = el('form', '', parent); form.className = 'user-online-filters';
        var start = input(form, '开始日期', 'date'), end = input(form, '结束日期', 'date');
        start.value = end.value = date;
        var type = el('select', '', el('label', '节点类型', form)); type.setAttribute('aria-label', '节点类型');
        ['', 'shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'tuic', 'anytls', 'v2node'].forEach(function (value) { el('option', value || '全部类型', type).value = value; });
        var node = input(form, '节点 ID', 'number', '全部节点'); node.min = '1'; node.step = '1';
        var ip = kind === 'history' ? input(form, 'IP', 'text', '完整 IP 精确匹配') : null;
        var actions = el('div', '', form); actions.className = 'user-online-actions';
        var apply = el('button', '查询 / 刷新', actions); apply.type = 'submit';
        var reset = el('button', '重置', actions); reset.type = 'button';
        var status = el('p', '', parent); status.setAttribute('role', 'status');
        var note = el('p', '', parent);
        var titles = kind === 'history' ? ['日期', 'IP / 归属地', '节点', '当日首次出现', '当日最近出现'] : ['日期', '节点', '上传', '下载', '实际合计', '倍率扣量'];
        var grid = table(parent, titles);
        var footer = el('div', '', parent); footer.className = 'user-online-footer';
        var prev = el('button', '上一页', footer), pageLabel = el('span', '', footer), next = el('button', '下一页', footer);
        prev.type = next.type = 'button';
        var page = 1, total = 0, busy = false, loaded = false, filters = {start_date: date, end_date: date};
        function controls() {
            Array.from(form.elements).forEach(function (field) { field.disabled = busy; });
            prev.disabled = busy || page <= 1; next.disabled = busy || page * 20 >= total;
            pageLabel.textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / 20)) + ' 页，共 ' + total + ' 条';
        }
        async function load(target) {
            if (busy) return;
            busy = true; loaded = true; controls(); status.textContent = '正在加载…'; grid.node.setAttribute('aria-busy', 'true');
            try {
                var result = await request(kind, Object.assign({user_id: user.id, current: target, page_size: 20}, filters), signal);
                if (signal.aborted) return;
                page = result.current; total = result.total; grid.body.replaceChildren();
                result.data.forEach(function (item) {
                    var row = el('tr', '', grid.body); el('td', item.record_date, row);
                    if (kind === 'history') {
                        var cell = el('td', '', row); el('span', item.ip, cell); geo(item.ip, cell);
                        nodeCell(row, item); el('td', time(item.first_seen), row); el('td', time(item.last_seen), row);
                    } else {
                        nodeCell(row, item); el('td', bytes(item.u), row); el('td', bytes(item.d), row);
                        el('td', bytes(Number(item.u) + Number(item.d)), row); el('td', bytes(Number(item.billed_u) + Number(item.billed_d)), row);
                    }
                });
                if (!result.data.length) message(grid.body, '暂无记录', titles.length);
                note.textContent = kind === 'history' ? '历史按天、节点和 IP 汇总，首次及最近出现时间不代表精确的连接或断开时间。' : '筛选范围合计：实际 ' + bytes(result.summary.total) + '，倍率扣量 ' + bytes(result.summary.billed_total) + '。';
                status.textContent = '';
            } catch (error) {
                if (!signal.aborted) { status.textContent = error.message; note.textContent = ''; total = 0; page = 1; message(grid.body, '加载失败，请重试', titles.length); }
            } finally {
                if (!signal.aborted) { busy = false; controls(); grid.node.setAttribute('aria-busy', 'false'); }
            }
        }
        form.onsubmit = function (event) {
            event.preventDefault(); if (busy) return;
            if (start.value && end.value && end.value < start.value) { status.textContent = '结束日期不能早于开始日期'; return; }
            filters = {};
            [[start, 'start_date'], [end, 'end_date'], [type, 'server_type'], [node, 'server_id'], [ip, 'ip']].forEach(function (pair) { if (pair[0] && pair[0].value.trim()) filters[pair[1]] = pair[0].value.trim(); });
            load(1);
        };
        reset.onclick = function () { form.reset(); start.value = end.value = date; filters = {start_date: date, end_date: date}; load(1); };
        prev.onclick = function () { load(page - 1); }; next.onclick = function () { load(page + 1); }; controls();
        return function () { if (!loaded) load(1); };
    }

    function render(container) {
        var controller = new AbortController(), activeDetail = null;
        var card = el('section', '', container); card.className = 'user-online-card uo-page';
        card.setAttribute('aria-labelledby', 'user-online-title');
        var header = el('div', '', card); header.className = 'uo-header';
        var heading = el('div', '', header);
        el('span', '用户监测', heading).className = 'uo-eyebrow';
        el('h2', '用户在线与用量', heading).id = 'user-online-title';
        el('p', '所有用户，一目了然。选择用户查看在线 IP、历史记录与每日流量。', heading);
        var headerRight = el('div', '', header); headerRight.className = 'uo-header-actions';
        var refreshed = el('span', '正在同步', headerRight); refreshed.className = 'uo-sync';
        var refresh = el('button', '刷新列表', headerRight); refresh.type = 'button'; refresh.className = 'uo-button-secondary';
        var stats = el('div', '', card); stats.className = 'uo-stats';
        var values = [];
        [['匹配用户', '全部符合条件的用户'], ['近期在线', '当前页有在线 IP 的用户'], ['今日实际流量', '当前页用户的实际用量'], ['今日倍率扣量', '当前页用户的倍率扣量']].forEach(function (item, index) {
            var tile = el('div', '', stats); tile.className = 'uo-stat uo-stat-' + index;
            el('span', item[0], tile).className = 'uo-stat-label';
            values.push(el('strong', '—', tile)); el('small', item[1], tile);
        });
        var toolbar = el('div', '', card); toolbar.className = 'uo-toolbar';
        var caption = el('div', '', toolbar); el('h3', '用户列表', caption); var captionCount = el('span', '', caption);
        var form = el('form', '', toolbar); form.className = 'uo-list-filters';
        var id = input(form, '用户 ID', 'number', '按 ID 查询'); id.min = '1'; id.step = '1';
        var email = input(form, '用户邮箱', 'text', '搜索邮箱'); email.maxLength = 254;
        var sort = el('select', '', el('label', '排序', form)); sort.setAttribute('aria-label', '排序');
        [['id', '最近注册'], ['traffic', '今日用量最高']].forEach(function (item) { el('option', item[1], sort).value = item[0]; });
        var search = el('button', '搜索', form); search.type = 'submit'; search.className = 'uo-button-primary';
        var reset = el('button', '重置', form); reset.type = 'button'; reset.className = 'uo-button-secondary';
        var status = el('p', '', card); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var grid = table(card, ['用户', '在线状态', '当前 IP', '今日用量', '操作']); grid.node.className = 'uo-user-table';
        var footer = el('div', '', card); footer.className = 'uo-pagination';
        var pageLabel = el('span', '', footer), pageButtons = el('div', '', footer);
        var prev = el('button', '上一页', pageButtons), next = el('button', '下一页', pageButtons); prev.type = next.type = 'button';
        var page = 1, total = 0, busy = false, filters = {};
        function controls() {
            Array.from(form.elements).forEach(function (field) { field.disabled = busy; }); refresh.disabled = busy;
            prev.disabled = busy || page <= 1; next.disabled = busy || page * 20 >= total;
            pageLabel.textContent = '共 ' + total + ' 位用户 · 第 ' + page + ' / ' + Math.max(1, Math.ceil(total / 20)) + ' 页';
        }
        async function load(target) {
            if (busy) return;
            busy = true; controls(); status.textContent = '正在加载用户列表…'; grid.node.setAttribute('aria-busy', 'true');
            message(grid.body, '正在加载用户列表…', 5);
            try {
                var response = await request('fetch', Object.assign({current: target, page_size: 20}, filters), controller.signal);
                if (controller.signal.aborted) return;
                page = response.current; total = response.total;
                values[0].textContent = String(total); values[1].textContent = String(response.summary.page_online);
                values[2].textContent = bytes(response.summary.page_total); values[3].textContent = bytes(response.summary.page_billed_total);
                refreshed.textContent = '更新于 ' + time(response.as_of); captionCount.textContent = response.date + ' · 点击查看明细';
                grid.body.replaceChildren();
                response.data.forEach(function (user) {
                    var row = el('tr', '', grid.body); row.setAttribute('data-user-id', user.id);
                    var account = el('td', '', row), identity = el('div', '', account); identity.className = 'uo-identity';
                    var avatar = el('span', user.email.charAt(0).toUpperCase(), identity); avatar.className = 'uo-avatar uo-avatar-' + (Number(user.id) % 4);
                    var text = el('div', '', identity); el('strong', user.email, text); el('small', 'ID ' + user.id + (user.banned ? ' · 已封禁' : ''), text);
                    var state = el('td', '', row); el('span', user.online_ip_count ? '近期在线' : '暂无在线上报', state).className = 'uo-status ' + (user.online_ip_count ? 'uo-status-online' : 'uo-status-idle');
                    el('small', user.online_ip_count + ' 个 IP · ' + user.online_node_count + ' 个节点', state);
                    var ips = el('td', '', row), ipTags = el('div', '', ips); ipTags.className = 'uo-ip-tags';
                    user.ips.forEach(function (ip) { el('span', ip, ipTags); }); if (!user.ips.length) el('span', '—', ipTags);
                    if (user.online_ip_count > user.ips.length) el('small', '另有 ' + (user.online_ip_count - user.ips.length) + ' 个 IP', ips);
                    var usage = el('td', '', row); el('strong', bytes(user.today_total), usage).className = 'uo-usage'; el('small', '倍率扣量 ' + bytes(user.today_billed_total), usage);
                    var action = el('button', '查看明细', el('td', '', row)); action.type = 'button'; action.className = 'uo-view'; action.onclick = function () { openUser(user, action); };
                });
                if (!response.data.length) message(grid.body, '没有找到匹配的用户，试试调整搜索条件。', 5);
                status.textContent = '';
            } catch (error) {
                if (!controller.signal.aborted) { status.textContent = error.message; total = 0; page = 1; values.forEach(function (value) { value.textContent = '—'; }); message(grid.body, '用户列表加载失败，请刷新重试。', 5); }
            } finally { if (!controller.signal.aborted) { busy = false; controls(); grid.node.setAttribute('aria-busy', 'false'); } }
        }
        function openUser(user, origin) {
            if (activeDetail) activeDetail();
            var detailController = new AbortController(), signal = detailController.signal;
            var overlay = el('div'); overlay.className = 'uo-drawer-overlay'; overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true'); overlay.setAttribute('aria-labelledby', 'uo-detail-title');
            var drawer = el('div', '', overlay); drawer.className = 'uo-drawer';
            var top = el('div', '', drawer); top.className = 'uo-drawer-header';
            var title = el('div', '', top); el('span', '用户明细', title).className = 'uo-eyebrow'; el('h2', user.email, title).id = 'uo-detail-title'; el('small', 'ID ' + user.id, title);
            var closeButton = el('button', '关闭', top); closeButton.type = 'button'; closeButton.setAttribute('aria-label', '关闭用户详情');
            var body = el('div', '', drawer); body.className = 'user-online-card uo-detail-body';
            var status = el('p', '正在加载用户明细…', body); status.setAttribute('role', 'status'); var result = el('div', '', body);
            function close() { detailController.abort(); overlay.remove(); document.removeEventListener('keydown', keydown, true); if (activeDetail === close) activeDetail = null; if (origin.isConnected) origin.focus(); }
            function keydown(event) {
                if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
                if (event.key === 'Tab') {
                    var nodes = Array.from(overlay.querySelectorAll('button,input,select,a[href]')).filter(function (node) { return !node.disabled && node.getClientRects().length; });
                    var first = nodes[0], last = nodes[nodes.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                }
            }
            closeButton.onclick = close; overlay.onclick = function (event) { if (event.target === overlay) close(); };
            document.body.appendChild(overlay); document.addEventListener('keydown', keydown, true); activeDetail = close; closeButton.focus();
            request('summary', {user_id: user.id}, signal).then(function (response) {
                if (signal.aborted) return;
                var data = response.data;
                var cards = el('div', '', result); cards.className = 'user-online-summary';
                [['近期在线 IP', String(data.current.unique_ip_count)], ['在线节点', String(data.current.node_count)],
                    ['当日实际流量', bytes(data.today.total)], ['当日倍率扣量', bytes(data.today.billed_total)]].forEach(function (item) {
                    var tile = el('div', '', cards); el('span', item[0], tile); el('strong', item[1], tile);
                });
                el('p', '用量日期：' + data.date + '（' + data.timezone + '）；状态刷新：' + time(data.current.as_of) + '。在线 IP 按最近 ' + data.current.window_seconds + ' 秒的节点上报判断，不等同于设备数。', result);
                var tabs = el('div', '', result); tabs.className = 'user-online-tabs'; tabs.setAttribute('role', 'tablist'); tabs.setAttribute('aria-label', '用户记录');
                var geo = geoRenderer(data.user.id, signal), buttons = [], panels = [], loaders = [];
                ['当前在线 IP', '在线 IP 历史', '流量用量'].forEach(function (label, index) {
                    var button = el('button', label, tabs); button.type = 'button'; button.setAttribute('role', 'tab'); button.id = 'user-online-tab-' + index;
                    button.setAttribute('aria-controls', 'user-online-panel-' + index); buttons.push(button);
                    var panel = el('div', '', result); panel.id = 'user-online-panel-' + index; panel.setAttribute('role', 'tabpanel'); panel.setAttribute('aria-labelledby', button.id); panels.push(panel);
                    button.onclick = function () { select(index); };
                    button.onkeydown = function (key) {
                        var target = key.key === 'ArrowRight' ? (index + 1) % 3 : key.key === 'ArrowLeft' ? (index + 2) % 3 : key.key === 'Home' ? 0 : key.key === 'End' ? 2 : null;
                        if (target !== null) { key.preventDefault(); select(target); buttons[target].focus(); }
                    };
                });
                var current = table(panels[0], ['IP / 归属地', '节点', '最近上报']);
                data.current.items.forEach(function (item) {
                    var row = el('tr', '', current.body), cell = el('td', '', row); el('span', item.ip, cell); geo(item.ip, cell);
                    nodeCell(row, item); el('td', time(item.last_seen), row);
                });
                if (!data.current.items.length) message(current.body, '暂无近期在线 IP；节点未上报时无法判断当前在线情况。', 3);
                el('p', '归属地由在线 IP 库提供，为近似信息。', panels[0]);
                loaders[1] = paged(panels[1], 'history', data.user, data.date, signal, geo);
                loaders[2] = paged(panels[2], 'traffic', data.user, data.date, signal, geo);
                function select(index) {
                    panels.forEach(function (panel, i) { panel.hidden = i !== index; buttons[i].setAttribute('aria-selected', String(i === index)); buttons[i].tabIndex = i === index ? 0 : -1; });
                    if (loaders[index]) loaders[index]();
                }
                select(0); status.textContent = '';
            }).catch(function (error) { if (!signal.aborted) status.textContent = error.message; });
        }
        form.onsubmit = function (event) { event.preventDefault(); if (busy) return; filters = {sort: sort.value}; if (id.value.trim()) filters.user_id = id.value.trim(); if (email.value.trim()) filters.email = email.value.trim(); load(1); };
        reset.onclick = function () { form.reset(); filters = {}; load(1); }; refresh.onclick = function () { load(page); };
        prev.onclick = function () { load(page - 1); }; next.onclick = function () { load(page + 1); };
        load(1);
        return function () { controller.abort(); if (activeDetail) activeDetail(); };
    }

    function mount(container) {
        var allowed = null, cleanup = function () {};
        function access() {
            var next = window.AdminPermissions.has('online.view'); if (allowed === next) return;
            allowed = next; cleanup(); container.replaceChildren();
            if (next) cleanup = render(container); else el('p', '需要用户在线与用量查看权限。', container);
        }
        window.addEventListener('admin-permissions-changed', access); access();
        return function () { window.removeEventListener('admin-permissions-changed', access); cleanup(); container.replaceChildren(); };
    }
    window.UserOnlineAudit = {mount: mount};
}());
