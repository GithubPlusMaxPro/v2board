<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/assets/admin/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/umi.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/custom.css?v={{$version}}">
    <link rel="stylesheet" href="/assets/admin/permissions.css?v={{$version}}-modern-permissions-1">
    <link rel="stylesheet" href="/assets/admin/login.css?v={{$version}}-user-frontend-1">
    <link rel="stylesheet" href="/assets/admin/audit.css?v={{$version}}-modern-audit-1">
    <link rel="stylesheet" href="/assets/admin/subscription-log.css?v={{$version}}-subscription-geo-3">
    <link rel="stylesheet" href="/assets/admin/server-user-traffic.css?v={{$version}}-node-users-2">
    <link rel="stylesheet" href="/assets/admin/user-online.css?v={{$version}}-user-list-2">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            theme: {
                sidebar: '{{$theme_sidebar}}',
                header: '{{$theme_header}}',
                color: '{{$theme_color}}',
            },
            version: '{{$version}}',
            background_url: '{{$background_url}}',
            logo: '{{$logo}}',
            secure_path: '{{$secure_path}}'
        }
    </script>
</head>

<body>
<div id="root"></div>
<script src="/assets/admin/permissions.js?v={{$version}}-modern-permissions-1"></script>
<script src="/assets/admin/subscription-log.js?v={{$version}}-subscription-geo-3"></script>
<script src="/assets/admin/server-user-traffic.js?v={{$version}}-node-users-2"></script>
<script src="/assets/admin/user-online.js?v={{$version}}-ip2region-parallel-5"></script>
<script src="/assets/admin/vendors.async.js?v={{$version}}"></script>
<script src="/assets/admin/components.async.js?v={{$version}}"></script>
<script src="/assets/admin/umi.js?v={{$version}}-node-token-2"></script>
</body>

</html>
