(function () {
    'use strict';
    var current = null;
    var permissionMenus = [
        { title: '仪表盘', modules: ['dashboard', 'stat'] },
        { title: '订阅访问记录', permissions: ['user.subscribe_log'] },
        { title: '节点用户日流量', modules: ['traffic'] },
        { title: '用户在线与用量', modules: ['online'] },
        { title: '系统配置', modules: ['config'] },
        { title: '支付配置', modules: ['payment'] },
        { title: '主题配置', modules: ['theme'] },
        { title: '服务器管理', modules: ['server'] },
        { title: '订阅管理', modules: ['plan'] },
        { title: '订单管理', modules: ['order'] },
        { title: '优惠券管理', modules: ['coupon'] },
        { title: '礼品卡管理', modules: ['giftcard'] },
        { title: '用户管理', modules: ['user'] },
        { title: '公告管理', modules: ['notice'] },
        { title: '工单管理', modules: ['ticket'] },
        { title: '知识库管理', modules: ['knowledge'] },
        { title: '队列与系统监控', modules: ['system'] }
    ];
    var pages = {
        '/dashboard': 'dashboard.view', '/config/system': 'config.view',
        '/config/payment': 'payment.view', '/config/theme': 'theme.view',
        '/server/manage': 'server.view', '/server/group': 'server.group.view', '/server/route': 'server.view',
        '/plan': 'plan.view', '/order': 'order.view', '/coupon': 'coupon.view', '/giftcard': 'giftcard.view',
        '/user': 'user.view', '/notice': 'notice.view', '/ticket': 'ticket.view',
        '/subscription-log': 'user.subscribe_log',
        '/server-user-traffic': 'traffic.view',
        '/user-online': 'online.view',
        '/knowledge': 'knowledge.view', '/queue': 'system.view'
    };

    function has(permission) {
        return !!current && (current.is_super_admin || current.permissions.indexOf(permission) !== -1);
    }
    function canPage(path) {
        if (path === '/admin-access') return true;
        if (path.indexOf('/ticket/') === 0) path = '/ticket';
        return !current || !!pages[path] && has(pages[path]);
    }
    function landing(path) {
        if (path && canPage(path)) return path;
        return Object.keys(pages).find(canPage) || '/admin-access';
    }
    function setCurrent(value) {
        if (!value) return;
        current = value;
        window.dispatchEvent(new Event('admin-permissions-changed'));
        var path = window.location.hash.slice(1).split('?')[0];
        if (path && path !== '/' && path !== '/login' && !canPage(path) && window.g_history) {
            window.g_history.replace(landing());
        }
    }
    async function request(path, body) {
        var response = await fetch('/api/v1/' + window.settings.secure_path + '/permission/' + path, {
            method: body ? 'POST' : 'GET',
            headers: { authorization: localStorage.getItem('authorization') || '', 'Content-Type': 'application/json', Accept: 'application/json' },
            body: body ? JSON.stringify(body) : undefined
        });
        var result = await response.json();
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors)[0][0] : result.message || '请求失败');
        return result.data;
    }
    function element(tag, text, parent) {
        var node = document.createElement(tag);
        if (text) node.textContent = text;
        if (parent) parent.appendChild(node);
        return node;
    }

    async function open() {
        if (!current || !current.is_super_admin || document.getElementById('admin-permission-dialog')) return;
        var overlay = element('div');
        overlay.id = 'admin-permission-dialog';
        overlay.className = 'admin-permission-overlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-labelledby', 'admin-permission-title');
        var previousFocus = document.activeElement;
        function close() { overlay.remove(); document.removeEventListener('keydown', onKey); if (previousFocus && previousFocus.isConnected) previousFocus.focus(); }
        function onKey(event) {
            if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); close(); }
            if (event.key === 'Tab') {
                var focusable = Array.from(overlay.querySelectorAll('button,select,input')).filter(function (node) { return !node.disabled && node.getClientRects().length; });
                var first = focusable[0], last = focusable[focusable.length - 1];
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        }
        var panel = element('div', '', overlay);
        panel.className = 'admin-permission-panel';
        var header = element('div', '', panel);
        header.className = 'admin-permission-header';
        var heading = element('div', '', header);
        element('span', '访问控制', heading).className = 'admin-permission-eyebrow';
        element('h3', '管理员权限', heading).id = 'admin-permission-title';
        element('p', '按功能模块分配权限，清晰管理每个管理员的访问范围。', heading);
        var closeButton = element('button', '关闭', header);
        closeButton.type = 'button'; closeButton.onclick = close;
        var content = element('div', '', panel); content.className = 'admin-permission-body';
        var account = element('div', '', content); account.className = 'admin-permission-account';
        var accountHeading = element('div', '', account); accountHeading.className = 'admin-permission-account-heading';
        element('strong', '选择管理员', accountHeading);
        var modeBadge = element('span', '正在加载', accountHeading); modeBadge.className = 'admin-permission-mode';
        var select = element('select', '', account);
        select.setAttribute('aria-label', '选择管理员');
        element('p', '先在用户管理中将账号设为管理员，再在这里分配权限。新管理员默认没有操作权限。', account);
        var form = element('div', '', content);
        var footer = element('div', '', panel); footer.className = 'admin-permission-footer';
        var status = element('p', '正在加载…', footer);
        status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
        var save = element('button', '保存权限', footer);
        save.type = 'button'; save.className = 'admin-permission-save'; save.disabled = true;
        document.body.appendChild(overlay);
        document.addEventListener('keydown', onKey);
        closeButton.focus();
        try {
            var admins = await request('fetch');
            admins.forEach(function (admin) {
                var option = element('option', admin.email + (admin.banned ? '（已封禁）' : ''), select);
                option.value = admin.id;
            });
            function render() {
                form.replaceChildren(); status.textContent = '';
                var admin = admins.find(function (item) { return item.id === Number(select.value); });
                if (!admin) { status.textContent = '没有可管理的管理员账号'; return; }
                var fullLabel = element('label', '', form);
                fullLabel.className = 'admin-permission-full';
                var full = element('input', '', fullLabel);
                full.type = 'checkbox'; full.checked = admin.admin_permissions === null;
                var fullText = element('span', '', fullLabel);
                element('strong', '完整管理权限', fullText);
                element('small', '允许访问所有功能，并管理其他管理员的权限。', fullText);
                if (admin.id === current.id) full.disabled = true;
                element('p', admin.id === current.id ? '不能撤销自己的完整管理权限。' : '按主菜单勾选整组权限，或展开后单独选择。保存时自动补齐查看和页面查询权限。系统和支付配置可能包含密钥，请谨慎授权。', form);
                var overview = element('div', '', form); overview.className = 'admin-permission-overview';
                var summaryValues = [];
                ['已选功能', '可分配功能', '已选模块'].forEach(function (text) {
                    var tile = element('div', '', overview); element('span', text, tile); summaryValues.push(element('strong', '—', tile));
                });
                var sectionHeading = element('div', '', form); sectionHeading.className = 'admin-permission-section-heading';
                element('h4', '功能权限', sectionHeading); element('span', '整组选中，或展开单独配置', sectionHeading);
                var grid = element('div', '', form); grid.className = 'admin-permission-groups';
                var keys = Object.keys(current.catalog);
                var assigned = new Set();
                function updateOverview() {
                    summaryValues[0].textContent = String(full.checked ? keys.length : grid.querySelectorAll('input[data-permission-key]:checked').length);
                    summaryValues[1].textContent = String(keys.length);
                    summaryValues[2].textContent = String(full.checked ? grid.querySelectorAll('section').length : grid.querySelectorAll('section.is-selected').length);
                    modeBadge.textContent = full.checked ? '完整权限' : '受限权限';
                    modeBadge.classList.toggle('is-full', full.checked);
                    fullLabel.classList.toggle('is-active', full.checked);
                }

                function renderMenu(menu, permissionKeys, index) {
                    if (!permissionKeys.length) return;
                    var section = element('section', '', grid);
                    section.className = 'admin-permission-group';
                    section.setAttribute('aria-label', menu.title);
                    var heading = element('div', '', section);
                    heading.className = 'admin-permission-group-header';
                    var label = element('label', '', heading);
                    var selectAll = element('input', '', label);
                    selectAll.type = 'checkbox'; selectAll.className = 'admin-permission-group-select';
                    selectAll.setAttribute('aria-label', menu.title + '全部权限');
                    element('strong', menu.title, label);
                    var expand = element('button', '', heading);
                    expand.type = 'button'; expand.className = 'admin-permission-group-expand';
                    expand.setAttribute('aria-controls', 'admin-permission-options-' + index);
                    var options = element('div', '', section);
                    options.id = 'admin-permission-options-' + index;
                    options.className = 'admin-permission-grid';
                    var inputs = permissionKeys.map(function (key) {
                        assigned.add(key);
                        var optionLabel = element('label', '', options);
                        var checkbox = element('input', '', optionLabel);
                        checkbox.type = 'checkbox'; checkbox.value = key;
                        checkbox.setAttribute('data-permission-key', key);
                        checkbox.checked = Array.isArray(admin.admin_permissions) && admin.admin_permissions.indexOf(key) !== -1;
                        optionLabel.appendChild(document.createTextNode(' ' + current.catalog[key]));
                        return checkbox;
                    });
                    var expanded = inputs.some(function (input) { return input.checked; });
                    function sync() {
                        var count = inputs.filter(function (input) { return input.checked; }).length;
                        selectAll.checked = count === inputs.length;
                        section.classList.toggle('is-selected', count > 0);
                        selectAll.indeterminate = count > 0 && count < inputs.length;
                        options.hidden = !expanded;
                        expand.setAttribute('aria-expanded', String(expanded));
                        expand.textContent = count + '/' + inputs.length + ' ' + (expanded ? '收起' : '展开');
                        expand.setAttribute('aria-label', (expanded ? '收起' : '展开') + menu.title + '，已选 ' + count + '/' + inputs.length);
                        updateOverview();
                    }
                    selectAll.onchange = function () {
                        inputs.forEach(function (input) { input.checked = selectAll.checked; });
                        if (selectAll.checked) expanded = true;
                        sync();
                    };
                    inputs.forEach(function (input) { input.onchange = sync; });
                    expand.onclick = function () { expanded = !expanded; sync(); };
                    sync();
                }
                permissionMenus.forEach(function (menu, index) {
                    renderMenu(menu, keys.filter(function (key) { return !assigned.has(key) && (menu.permissions ? menu.permissions.indexOf(key) !== -1 : menu.modules.indexOf(key.split('.')[0]) !== -1); }), index);
                });
                renderMenu({ title: '其他权限' }, keys.filter(function (key) { return !assigned.has(key); }), permissionMenus.length);
                function toggle() {
                    grid.querySelectorAll('input').forEach(function (checkbox) { checkbox.disabled = full.checked; });
                    grid.style.opacity = full.checked ? '0.5' : '1';
                    updateOverview();
                }
                full.onchange = toggle; toggle(); save.disabled = false;
                save.onclick = async function () {
                    var selectedId = admin.id;
                    save.disabled = true; select.disabled = true; status.textContent = '正在保存…';
                    try {
                        await request('save', { id: selectedId, is_super_admin: full.checked,
                            permissions: Array.from(grid.querySelectorAll('input[data-permission-key]:checked')).map(function (item) { return item.value; }) });
                        admins = await request('fetch');
                        select.value = String(selectedId); render(); status.textContent = '权限已保存，下一次请求立即生效。';
                    } catch (error) { status.textContent = error.message; }
                    finally { save.disabled = false; select.disabled = false; }
                };
            }
            select.onchange = render; render();
        } catch (error) { status.textContent = error.message; }
    }

    window.AdminPermissions = { has: has, canPage: canPage, landing: landing, setCurrent: setCurrent, open: open,
        isSuper: function () { return !!current && current.is_super_admin; } };
}());
