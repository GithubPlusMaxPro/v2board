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
        var controller = new AbortController();
        var card = el('section', '', container); card.className = 'user-online-card';
        card.setAttribute('aria-labelledby', 'user-online-title'); el('h2', '用户在线与用量', card).id = 'user-online-title';
        el('p', '按用户查询节点在线 IP、在线 IP 历史和流量用量。', card);
        var form = el('form', '', card); form.className = 'user-online-search';
        var id = input(form, '用户 ID', 'number', '输入用户 ID'); id.min = '1'; id.step = '1';
        var email = input(form, '用户邮箱', 'email', '或输入完整邮箱'); email.maxLength = 254;
        id.oninput = function () { if (id.value) email.value = ''; }; email.oninput = function () { if (email.value) id.value = ''; };
        var search = el('button', '查询 / 刷新', form); search.type = 'submit';
        var status = el('p', '请输入用户 ID 或完整邮箱。', card); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var result = el('div', '', card);
        form.onsubmit = async function (event) {
            event.preventDefault();
            var filters = {};
            if (id.value.trim()) filters.user_id = id.value.trim(); if (email.value.trim()) filters.email = email.value.trim();
            if (!Object.keys(filters).length) { status.textContent = '请输入用户 ID 或完整邮箱。'; return; }
            controller.abort(); controller = new AbortController(); var signal = controller.signal;
            result.replaceChildren(); status.textContent = '正在查询…'; search.disabled = id.disabled = email.disabled = true;
            try {
                var response = await request('summary', filters, signal);
                if (signal.aborted) return;
                var data = response.data;
                el('h3', data.user.email + ' · ID ' + data.user.id, result);
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
            } catch (error) { if (!signal.aborted) status.textContent = error.message; }
            finally { if (!signal.aborted) search.disabled = id.disabled = email.disabled = false; }
        };
        return function () { controller.abort(); };
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
