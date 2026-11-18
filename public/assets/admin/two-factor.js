(function () {
    'use strict';

    function element(tag, text, parent) {
        const node = document.createElement(tag);
        node.textContent = text;
        parent.appendChild(node);
        return node;
    }

    async function request(action, data, target) {
        let url = '/api/v1/' + window.settings.secure_path + '/twoFactor/' + action;
        if (action === 'enroll') url = '/api/v1/passport/auth/twoFactor/enroll';
        if (target && !data) url += '?id=' + encodeURIComponent(target.id);
        if (target && data) data = Object.assign({ id: target.id }, data);
        const response = await fetch(url, {
            method: data ? 'POST' : 'GET',
            headers: {
                authorization: localStorage.getItem('admin_authorization') || '',
                Accept: 'application/json',
                'Content-Type': 'application/json'
            },
            body: data ? JSON.stringify(data) : undefined
        });
        const result = await response.json();
        if (!response.ok) throw new Error(result.errors ? Object.values(result.errors)[0][0] : result.message || '请求失败。');
        return result.data;
    }

    function open(signOut, target, enrollment) {
        if (document.getElementById('admin-two-factor')) return;
        const dialog = element('dialog', '', document.body);
        dialog.id = 'admin-two-factor';
        dialog.className = 'audit-page admin-two-factor-dialog';
        dialog.setAttribute('aria-labelledby', 'admin-two-factor-title');
        const header = element('header', '', dialog);
        header.className = 'audit-header';
        element('h2', enrollment ? '首次绑定两步验证' : '两步验证', header).id = 'admin-two-factor-title';
        const close = element('button', '关闭', header);
        close.type = 'button';
        close.onclick = () => dialog.close();
        const content = element('div', '', dialog);
        content.className = 'audit-filterbox';
        const status = element('p', '', dialog);
        status.setAttribute('role', 'status');
        let busy = false;
        let sessionRevoked = false;

        dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); });
        dialog.addEventListener('close', () => {
            dialog.remove();
            if (sessionRevoked) signOut();
        }, { once: true });

        async function run(action, data, success) {
            if (busy) return;
            close.disabled = busy = true;
            const submits = content.querySelectorAll('button[type="submit"]');
            submits.forEach(button => { button.disabled = true; });
            status.textContent = '正在处理…';
            try {
                const result = await request(action, data, target);
                status.textContent = '';
                success(result);
            } catch (error) {
                status.textContent = error.message;
            } finally {
                close.disabled = busy = false;
                submits.forEach(button => { button.disabled = false; });
            }
        }

        function field(form, label, type) {
            const input = element('input', '', element('label', label, form));
            input.type = type;
            return input;
        }

        function formFor(handler) {
            const form = element('form', '', content);
            form.className = 'audit-filters admin-two-factor-form';
            form.onsubmit = event => { event.preventDefault(); handler(event.submitter && event.submitter.value); };
            return [form, (options) => {
                const actions = element('div', '', form);
                actions.className = 'audit-actions';
                options.forEach(([label, value]) => {
                    const button = element('button', label, actions);
                    button.type = 'submit';
                    button.value = value;
                });
            }];
        }

        function showRecovery(result) {
            sessionRevoked = !target && !enrollment;
            content.replaceChildren();
            element('p', target
                ? '绑定成功。请把以下一次性恢复码安全地交给 ' + target.email + '。'
                : '绑定成功。请保存以下一次性恢复码。关闭后请用下一个动态验证码或一枚恢复码重新登录。', content);
            element('pre', result.recovery_codes.join('\n'), content);
            close.textContent = target ? '完成' : '返回登录';
            close.focus();
        }

        function showSetup(result) {
            content.replaceChildren();
            element('p', target
                ? '把新密钥安全地交给 ' + target.email + '，请对方在验证器里添加并提供当前的 6 位验证码。'
                : '在验证器中手动添加账号，选择基于时间的验证码，填入下方密钥。', content);
            const [form, buttons] = formFor(() => {
                const data = { setup: result.setup, otp: otp.value };
                if (required) data.required = required.checked;
                run(enrollment ? 'enroll' : 'enable', data, showRecovery);
            });
            const key = field(form, '设置密钥', 'text');
            key.value = result.secret;
            key.readOnly = true;
            key.onclick = () => key.select();
            const otp = field(form, '验证器显示的 6 位验证码', 'text');
            otp.inputMode = 'numeric';
            otp.autocomplete = 'one-time-code';
            otp.pattern = '[0-9]{6}';
            otp.maxLength = 6;
            otp.required = true;
            let required;
            if (target) {
                required = field(form, '设为必需，管理员无法自行关闭', 'checkbox');
                required.checked = result.required;
            }
            buttons([['确认并绑定', 'enable']]);
            otp.focus();
        }

        function showStatus(result) {
            content.replaceChildren();
            if (target) {
                showManagement(result);
                return;
            }
            element('p', result.enabled ? '两步验证已开启。' : '启用后，后台登录需要密码和验证器生成的动态验证码。', content);
            if (result.enabled && result.required) {
                element('p', '当前账号必须使用两步验证。需要重新绑定时，请联系超级管理员。', content);
                close.focus();
                return;
            }
            const [form, buttons] = formFor(() => {
                const data = { password: password.value };
                if (result.enabled) {
                    data.otp = otp.value;
                    run('disable', data, () => {
                        sessionRevoked = true;
                        content.replaceChildren();
                        element('p', '两步验证已关闭，请重新登录。', content);
                        close.textContent = '重新登录';
                        close.focus();
                    });
                } else {
                    run('begin', data, showSetup);
                }
            });
            const password = field(form, '当前密码', 'password');
            password.autocomplete = 'current-password';
            password.required = true;
            let otp;
            if (result.enabled) {
                otp = field(form, '动态验证码或恢复码', 'text');
                otp.autocomplete = 'one-time-code';
                otp.maxLength = 20;
                otp.required = true;
            }
            buttons([[result.enabled ? '关闭两步验证' : '生成设置密钥', result.enabled ? 'disable' : 'begin']]);
            password.focus();
        }

        function showManagement(result) {
            element('h3', target.email, content);
            element('p', (result.enabled ? '已绑定' : '未绑定') + ' · ' + (result.required ? '登录必须绑定' : '可自行选择'), content);
            const [form, buttons] = formFor(action => {
                if (action === 'reset' && !window.confirm('重置会使该账号的旧验证码、恢复码和会话失效。确定重置？')) return;
                if (action === 'off' && !window.confirm('关闭后，该账号可以只用密码登录。确定关闭？')) return;
                const data = { password: password.value };
                if (otp) data.otp = otp.value;
                if (action === 'policy') data.required = !result.required;
                if (action === 'off') data.required = false;
                run(action === 'off' ? 'reset' : action, data, action === 'begin' ? showSetup : updated => {
                    showStatus(Object.assign({ operator_enabled: result.operator_enabled }, updated));
                    const messages = { reset: '两步验证已重置。', off: '两步验证已关闭。', policy: '策略已保存。' };
                    status.textContent = messages[action];
                });
            });
            const password = field(form, '你的当前密码', 'password');
            password.autocomplete = 'current-password';
            password.required = true;
            let otp;
            if (result.operator_enabled) {
                otp = field(form, '你的动态验证码或恢复码', 'text');
                otp.autocomplete = 'one-time-code';
                otp.maxLength = 20;
                otp.required = true;
            }
            const choices = [
                [result.enabled ? '替换绑定' : '代为绑定', 'begin'],
                [result.required ? '改为可选' : '设为必需', 'policy']
            ];
            if (result.enabled) choices.push(['重置绑定', 'reset'], ['关闭两步验证', 'off']);
            buttons(choices);
            password.focus();
        }

        dialog.showModal();
        if (enrollment) showSetup(enrollment);
        else run('status', undefined, showStatus);
    }

    window.AdminTwoFactor = {
        open: open,
        manage: target => open(null, target),
        enroll: enrollment => open(null, null, enrollment)
    };
}());
