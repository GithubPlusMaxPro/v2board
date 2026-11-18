(function () {
    'use strict';

    const scopeLabels = { login: '登录', admin: '后台操作', user: '用户操作' };
    const actionLabels = {
        login: { 'auth/login': '密码登录', 'auth/token2Login': '快捷登录', 'auth/twoFactor/enroll': '首次绑定两步验证' },
        admin: {
            'config/save': '修改系统配置',
            'order/assign': '分配订单',
            'order/update': '修改订单',
            'payment/save': '保存支付配置',
            'permission/save': '修改管理员权限',
            'twoFactor/enable': '开启两步验证',
            'twoFactor/disable': '关闭两步验证',
            'twoFactor/policy': '修改两步验证策略',
            'twoFactor/reset': '重置两步验证',
            'user/ban': '封禁用户',
            'user/delUser': '删除用户',
            'user/dumpCSV': '导出用户',
            'user/fetch': '查询用户列表',
            'user/getUserInfoById': '查看用户资料',
            'user/resetSecret': '重置用户订阅',
            'user/update': '修改用户资料'
        },
        user: {
            changePassword: '修改密码',
            getQuickLoginUrl: '获取快捷登录地址',
            getSubscribe: '获取订阅地址',
            removeActiveSession: '移除登录会话',
            resetSecurity: '重置订阅',
            unbindTelegram: '解绑 Telegram',
            update: '修改账号设置'
        }
    };

    function element(tag, text, parent) {
        const node = document.createElement(tag);
        node.textContent = text;
        parent.appendChild(node);
        return node;
    }

    function mount(container) {
        let allowed, dispose;
        function refreshAccess() {
            const canView = window.AdminPermissions.isSuper();
            if (canView === allowed) return;
            allowed = canView;
            if (dispose) dispose();
            container.replaceChildren();
            if (allowed) {
                dispose = renderPage(container);
            } else {
                dispose = null;
                element('p', '此页面仅超级管理员可查看。', container);
            }
        }
        window.addEventListener('admin-permissions-changed', refreshAccess);
        refreshAccess();
        return function () {
            window.removeEventListener('admin-permissions-changed', refreshAccess);
            if (dispose) dispose();
            container.replaceChildren();
        };
    }

    function renderPage(container) {
        const controller = new AbortController();
        const panel = element('section', '', container);
        panel.className = 'audit-page';
        panel.setAttribute('aria-labelledby', 'account-activity-title');
        const header = element('header', '', panel);
        header.className = 'audit-header';
        element('h2', '登录与操作记录', header).id = 'account-activity-title';

        const filterBox = element('div', '', panel);
        filterBox.className = 'audit-filterbox';
        const form = element('form', '', filterBox);
        form.className = 'audit-filters';
        const fields = {};
        function input(key, label, type) {
            const field = element('input', '', element('label', label, form));
            field.type = type;
            fields[key] = field;
            return field;
        }
        input('email', '账号邮箱', 'email').maxLength = 254;
        input('user_id', '用户 ID', 'number').min = '1';
        input('ip', '来源 IP', 'text').maxLength = 45;
        fields.scope = element('select', '', element('label', '记录类型', form));
        element('option', '全部', fields.scope).value = '';
        Object.entries(scopeLabels).forEach(function ([key, label]) {
            element('option', label, fields.scope).value = key;
        });
        input('start_at', '开始时间', 'datetime-local');
        input('end_at', '结束时间', 'datetime-local');

        const actions = element('div', '', form);
        actions.className = 'audit-actions';
        const apply = element('button', '查询', actions);
        apply.type = 'submit';
        const reset = element('button', '清除筛选', actions);
        reset.type = 'button';
        const status = element('p', '', panel);
        status.setAttribute('role', 'status');
        const sessionNote = element('p', '', panel);
        sessionNote.className = 'audit-summary-note';
        sessionNote.hidden = true;
        const scroll = element('div', '', panel);
        scroll.className = 'audit-table-wrap';
        const table = element('table', '', scroll);
        const headings = ['时间', '账号', '操作', '来源 IP', 'User-Agent', 'HTTP 响应', '会话'];
        const heading = element('tr', '', element('thead', '', table));
        headings.forEach(function (label) { element('th', label, heading).scope = 'col'; });
        const body = element('tbody', '', table);

        const footer = element('div', '', panel);
        footer.className = 'audit-footer';
        const pageLabel = element('span', '', footer);
        const pager = element('div', '', footer);
        pager.className = 'audit-pagination';
        const previous = element('button', '上一页', pager);
        const next = element('button', '下一页', pager);
        const pageSize = 20;
        let page = 1, total = 0, busy = false, filters = {};

        function updateControls() {
            apply.disabled = reset.disabled = busy;
            previous.disabled = busy || page === 1;
            next.disabled = busy || page * pageSize >= total;
            pageLabel.textContent = '第 ' + page + ' / ' + Math.max(1, Math.ceil(total / pageSize)) + ' 页，共 ' + total + ' 条';
            sessionNote.hidden = !filters.session_hash;
            sessionNote.textContent = filters.session_hash ? '会话 ' + filters.session_hash.slice(0, 12) : '';
            table.setAttribute('aria-busy', String(busy));
        }

        function message(text) {
            body.replaceChildren();
            element('td', text, element('tr', '', body)).colSpan = headings.length;
        }

        function showRecord(item) {
            const row = element('tr', '', body);
            element('td', new Date(item.created_at * 1000).toLocaleString('zh-CN', { hour12: false }), row);
            const account = element('td', item.email || '—', row);
            if (item.user_id) element('small', 'ID ' + item.user_id, account);
            const operation = element('td', '', row);
            const label = actionLabels[item.scope][item.action] || scopeLabels[item.scope];
            const result = item.scope === 'login' ? (item.user_id ? '成功' : item.status_code >= 400 ? '失败' : '未完成') : '';
            element('strong', label + result, operation);
            element('small', item.method + ' /' + item.action, operation);
            if (item.target_id) element('small', '目标 ID ' + item.target_id, operation);
            element('td', item.ip || '—', row).className = 'audit-ip';
            element('td', item.user_agent || '—', row);
            const code = element('span', String(item.status_code), element('td', '', row));
            code.className = 'audit-chip ' + (item.status_code >= 400 ? 'audit-chip-red'
                : item.status_code < 300 ? 'audit-chip-green' : 'audit-chip-blue');
            const session = element('td', item.session_hash ? '' : '—', row);
            if (item.session_hash) {
                const filter = element('button', item.session_hash.slice(0, 12), session);
                filter.type = 'button';
                filter.title = '查看同一会话的登录与操作';
                filter.onclick = function () {
                    if (busy) return;
                    form.reset();
                    filters = { session_hash: item.session_hash };
                    load(1);
                };
            }
        }

        async function load(targetPage) {
            if (busy) return;
            busy = true;
            updateControls();
            status.textContent = '正在加载…';
            try {
                const query = new URLSearchParams(Object.assign({ current: targetPage, page_size: pageSize }, filters));
                const url = '/api/v1/' + window.settings.secure_path + '/accountActivity/fetch?' + query;
                const response = await fetch(url, {
                    headers: { authorization: localStorage.getItem('authorization') || '', Accept: 'application/json' },
                    signal: controller.signal
                });
                const result = await response.json();
                if (!response.ok) throw new Error(result.message || '读取记录失败');
                if (controller.signal.aborted) return;
                page = result.current;
                total = result.total;
                body.replaceChildren();
                result.data.forEach(showRecord);
                if (!result.data.length) message('暂无符合条件的记录');
                status.textContent = '';
            } catch (error) {
                if (controller.signal.aborted) return;
                page = 1;
                total = 0;
                status.textContent = error.message;
                message('读取失败，请重新查询');
            } finally {
                if (!controller.signal.aborted) {
                    busy = false;
                    updateControls();
                }
            }
        }

        form.onsubmit = function (event) {
            event.preventDefault();
            if (busy) return;
            const query = {};
            Object.entries(fields).forEach(function ([key, field]) {
                if (!field.value.trim()) return;
                query[key] = field.type === 'datetime-local' ? Math.floor(new Date(field.value).getTime() / 1000) : field.value.trim();
            });
            if (query.end_at < query.start_at) {
                status.textContent = '结束时间不能早于开始时间';
                return;
            }
            if (filters.session_hash) query.session_hash = filters.session_hash;
            filters = query;
            load(1);
        };
        reset.onclick = function () { form.reset(); filters = {}; load(1); };
        previous.onclick = function () { load(page - 1); };
        next.onclick = function () { load(page + 1); };
        load(1);
        return function () { controller.abort(); };
    }

    window.AccountActivity = { mount: mount };
}());
