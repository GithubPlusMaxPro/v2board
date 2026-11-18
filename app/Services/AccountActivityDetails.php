<?php

namespace App\Services;

use App\Http\Requests\Admin\ConfigSave;
use App\Models\ServerRoute;

class AccountActivityDetails
{
    private const VISIBLE_CONFIG = [
        'ticket_status', 'invite_force', 'invite_commission', 'invite_gen_limit', 'invite_never_expire',
        'commission_first_time_enable', 'commission_auto_check_enable', 'commission_withdraw_limit',
        'withdraw_close_enable', 'commission_distribution_enable', 'force_https', 'stop_register',
        'user_frontend_enable', 'try_out_enable', 'try_out_plan_id', 'try_out_hour', 'plan_change_enable',
        'reset_traffic_method', 'surplus_enable', 'allow_new_period', 'show_info_to_server_enable',
        'show_subscribe_method', 'show_subscribe_expire', 'server_pull_interval', 'server_push_interval',
        'device_limit_mode', 'server_node_report_min_traffic', 'server_device_online_min_traffic',
        'telegram_bot_enable', 'email_whitelist_enable', 'email_gmail_limit_enable', 'recaptcha_enable',
        'email_verify', 'safe_mode_enable', 'register_limit_by_ip_enable', 'register_limit_count',
        'register_limit_expire', 'password_limit_enable', 'password_limit_count', 'password_limit_expire',
    ];

    public static function fields($fields): array
    {
        if (!is_array($fields)) return [];

        $details = [];
        foreach (['id', 'user_id', 'actor_id', 'target_id', 'plan_id', 'group_id', 'server_id',
            'route_id', 'order_id', 'payment_id', 'ticket_id', 'invite_user_id', 'status', 'sort', 'quantity'] as $field) {
            $value = $fields[$field] ?? null;
            if (is_int($value) || is_string($value)) {
                $integer = filter_var($value, FILTER_VALIDATE_INT);
                if ($integer !== false) $details[$field] = $integer;
            }
        }
        foreach (['is_admin', 'is_super_admin', 'is_staff', 'banned', 'enabled', 'required', 'show', 'paid', 'auto_renewal'] as $field) {
            $value = $fields[$field] ?? null;
            if (in_array($value, [true, false, 0, 1, '0', '1'], true)) $details[$field] = (bool)$value;
        }
        $email = $fields['email'] ?? null;
        if (is_string($email) && strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $details['email'] = $email;
        }

        $permissions = config('admin_permissions.labels', []);
        foreach (['permissions', 'admin_permissions', 'before', 'after'] as $field) {
            if (!isset($fields[$field]) || !is_array($fields[$field])) continue;
            $details[$field] = array_values(array_filter($fields[$field], function ($value) use ($permissions) {
                return is_string($value) && isset($permissions[$value]);
            }));
        }
        return $details;
    }

    public static function configChanges(array $before, array $updates): array
    {
        $changes = [];
        foreach ($updates as $key => $new) {
            $old = $before[$key] ?? null;
            if ($old === $new) continue;
            $changes[$key] = ['before' => $old, 'after' => $new];
        }
        return self::configDetails($changes);
    }

    public static function serverRoute(array $fields): array
    {
        $action = $fields['action'] ?? null;
        if (!in_array($action, ServerRoute::ACTIONS, true)) return [];

        $match = $action === 'default_out' ? [] : ($fields['match'] ?? []);
        if (is_string($match)) $match = json_decode($match, true);
        $match = is_array($match) ? array_filter($match, 'is_string') : [];
        $rules = [];
        $remaining = 60000; // v2_log.data is TEXT and also holds the other audit fields.
        foreach ($match as $rule) {
            $rule = self::routeText($rule);
            $remaining -= strlen(json_encode($rule)) + 1;
            if ($remaining < 0) break;
            $rules[] = $rule;
        }
        $details = [
            'action' => $action,
            'match' => $rules,
            'match_count' => max(count($match), is_int($fields['match_count'] ?? null) ? $fields['match_count'] : 0),
        ];
        if (is_string($fields['remarks'] ?? null)) {
            $details['remarks'] = mb_strcut(self::routeText($fields['remarks']), 0, 1024, 'UTF-8');
        }
        $value = $fields['action_value'] ?? null;
        if ($action === 'dns' && is_string($value) && filter_var($value, FILTER_VALIDATE_IP)) {
            $details['action_value'] = $value;
        } elseif (($value !== null && $value !== '') || ($fields['action_value_hidden'] ?? false) === true) {
            $details['action_value_hidden'] = true;
        }
        return $details;
    }

    private static function routeText(string $value): string
    {
        $text = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        return preg_replace('/[\x00-\x1F\x7F]/', ' ', $text);
    }

    public static function configDetails(array $changes): array
    {
        $details = [];
        foreach (array_intersect_key($changes, ConfigSave::RULES) as $key => $values) {
            if (in_array($key, self::VISIBLE_CONFIG, true) && is_array($values)
                && array_key_exists('before', $values) && array_key_exists('after', $values)
                && self::canShowNumber($values['before']) && self::canShowNumber($values['after'])) {
                $before = $values['before'] === null ? null : $values['before'] + 0;
                $after = $values['after'] === null ? null : $values['after'] + 0;
                if ($before != $after || ($before === null) !== ($after === null)) {
                    $details[$key] = ['before' => $before, 'after' => $after];
                }
            } else {
                $details[$key] = ['value_hidden' => true];
            }
        }
        return $details;
    }

    private static function canShowNumber($value): bool
    {
        return $value === null || is_bool($value) || (is_numeric($value) && is_finite((float)$value));
    }
}
