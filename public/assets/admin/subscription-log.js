(function () {
    'use strict';

    function element(tag, text, parent) {
        var node = document.createElement(tag);
        if (text) node.textContent = text;
        if (parent) parent.appendChild(node);
        return node;
    }

    function open(user) {
        if (!user || !user.id || !window.AdminPermissions.has('user.subscribe_log') || document.getElementById('subscription-log-dialog')) return;
        var previousFocus = document.activeElement;
        var controller = new AbortController();
        var overlay = element('div');
        overlay.id = 'subscription-log-dialog';
        overlay.className = 'subscription-log-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'subscription-log-title');
        function close() {
            controller.abort();
            overlay.remove();
            document.removeEventListener('keydown', onKey, true);
            if (previousFocus && previousFocus.isConnected) previousFocus.focus();
        }
        function onKey(event) {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
            if (event.key === 'Tab') {
                var nodes = Array.from(overlay.querySelectorAll('button,input,select')).filter(function (node) { return !node.disabled; });
                var first = nodes[0], last = nodes[nodes.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        }
        var panel = element('div', '', overlay);
        panel.className = 'subscription-log-panel';
        var header = element('div', '', panel);
        header.className = 'subscription-log-header';
        element('h3', '订阅历史 · ' + (user.email || user.id), header).id = 'subscription-log-title';
        var closeButton = element('button', '关闭', header);
        closeButton.type = 'button'; closeButton.onclick = close;
        element('p', '记录启用后的面板获取地址和客户端拉取订阅。时间按当前浏览器时区显示。', panel);
        var form = element('form', '', panel);
        form.className = 'subscription-log-filters';
        var actionLabel = element('label', '类型 ', form);
        var action = element('select', '', actionLabel);
        [['', '全部'], ['address', '获取地址'], ['download', '拉取订阅']].forEach(function (item) {
            element('option', item[1], action).value = item[0];
        });
        var ipLabel = element('label', 'IP ', form);
        var ip = element('input', '', ipLabel);
        ip.type = 'text'; ip.placeholder = '按完整 IP 筛选'; ip.maxLength = 45;
        var apply = element('button', '查询 / 刷新', form);
        apply.type = 'submit';
        var status = element('p', '', panel);
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var scroll = element('div', '', panel);
        scroll.className = 'subscription-log-scroll';
        var table = element('table', '', scroll);
        var heading = element('tr', '', element('thead', '', table));
        ['时间', '类型', 'IP', '客户端（User-Agent）', 'HTTP 响应码'].forEach(function (title) {
            element('th', title, heading).scope = 'col';
        });
        var body = element('tbody', '', table);
        var footer = element('div', '', panel);
        footer.className = 'subscription-log-footer';
        var prev = element('button', '上一页', footer);
        var pageLabel = element('span', '', footer);
        var next = element('button', '下一页', footer);
        prev.type = next.type = 'button';
        var page = 1, pageSize = 20, total = 0, busy = false;
        var activeFilters = { action: '', ip: '' };
        function controls() {
            apply.disabled = action.disabled = ip.disabled = busy;
            prev.disabled = busy || page <= 1;
            next.disabled = busy || page * pageSize >= total;
            pageLabel.textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / pageSize)) + ' 页，共 ' + total + ' 条';
        }
        async function load(targetPage) {
            if (busy) return;
            busy = true; controls(); status.textContent = '正在加载…'; table.setAttribute('aria-busy', 'true');
            try {
                var query = new URLSearchParams({ id: user.id, current: targetPage, page_size: pageSize });
                if (activeFilters.action) query.set('action', activeFilters.action);
                if (activeFilters.ip) query.set('ip', activeFilters.ip);
                var response = await fetch('/api/v1/' + window.settings.secure_path + '/user/getSubscribeLog?' + query, {
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
                    element('td', new Date(item.created_at * 1000).toLocaleString(), row);
                    element('td', item.action === 'address' ? '获取地址' : '拉取订阅', row);
                    element('td', item.ip || '—', row);
                    element('td', item.user_agent || '—', row).className = 'subscription-log-agent';
                    element('td', String(item.status_code), row);
                });
                if (!result.data.length) {
                    var cell = element('td', '暂无订阅记录', element('tr', '', body));
                    cell.colSpan = 5;
                }
                status.textContent = '';
            } catch (error) {
                if (error.name !== 'AbortError') status.textContent = error.message;
            } finally {
                busy = false; controls(); table.setAttribute('aria-busy', 'false');
            }
        }
        form.onsubmit = function (event) {
            event.preventDefault();
            if (busy) return;
            activeFilters = { action: action.value, ip: ip.value.trim() };
            load(1);
        };
        prev.onclick = function () { load(page - 1); };
        next.onclick = function () { load(page + 1); };
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKey, true);
        closeButton.focus();
        load(1);
    }

    window.SubscriptionHistory = { open: open };
}());
