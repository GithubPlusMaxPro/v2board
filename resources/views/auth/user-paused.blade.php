<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="#f2f5fa">
    <title>用户中心暂未开放 · {{ $title }}</title>
    <link rel="stylesheet" href="/assets/admin/login.css?v={{ $version }}-login-access-1">
</head>
<body class="user-portal-paused">
    <div class="admin-login-background" aria-hidden="true"></div>
    <main class="user-paused-card">
        <div class="admin-login-brand">
            @if ($logo)
                <img src="{{ $logo }}" alt="{{ $title }}">
            @else
                <span class="admin-login-mark" aria-hidden="true">V</span>
                <span>{{ $title }}</span>
            @endif
        </div>
        <span class="user-paused-status">登录服务已暂停</span>
        <h1>用户中心暂未开放</h1>
        <p>普通用户登录暂时暂停。<br>请稍后再试，感谢你的耐心等待。</p>
        <a class="admin-login-submit" href="{{ url('/') }}">重新检查</a>
    </main>
</body>
</html>
