<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title>{{ config('brand.title') }}</title>
        <meta name="application-name" content="{{ config('brand.title') }}">
        <meta name="apple-mobile-web-app-title" content="{{ config('brand.name') }}">
        @php
            $authUser = auth()->user();
            if ($authUser) {
                $authUser->loadMissing('driver:id,user_id,name,phone,is_active');
            }
            $bootUser = $authUser ? [
                'id' => $authUser->id,
                'name' => $authUser->name,
                'email' => $authUser->email,
                'role' => $authUser->role,
                'role_label' => $authUser->roleLabel(),
                'is_livreur' => $authUser->isLivreur(),
                'is_admin' => $authUser->isAdmin(),
                'driver' => $authUser->driver ? [
                    'id' => $authUser->driver->id,
                    'name' => $authUser->driver->name,
                    'phone' => $authUser->driver->phone,
                    'is_active' => (bool) $authUser->driver->is_active,
                ] : null,
            ] : null;
        @endphp
        <script>
            window.__APP__ = @json(['user' => $bootUser]);
        </script>
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
    </head>
    <body class="antialiased">
        <div id="root"></div>
    </body>
</html>
