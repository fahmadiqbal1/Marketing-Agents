<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Login') — Marketing Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #0a0d14; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .auth-card { background: #111520; border: 1px solid rgba(255,255,255,.08); border-radius: 1rem; padding: 2.5rem; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,.5); }
        .auth-logo { text-align: center; margin-bottom: 2rem; }
        .auth-logo-icon { font-size: 2.5rem; margin-bottom: .75rem; display: block; }
        .form-control, .form-select { background: #1a1f2e; border-color: rgba(255,255,255,.12); color: #e2e8f0; }
        .form-control:focus { background: #1a1f2e; border-color: #0d6efd; color: #e2e8f0; box-shadow: 0 0 0 .2rem rgba(13,110,253,.2); }
        .form-control::placeholder { color: rgba(255,255,255,.25); }
        .form-label { font-size: .85rem; color: rgba(255,255,255,.65); }
        a { color: #3d8bfd; }
        a:hover { color: #6ea8fe; }
    </style>
</head>
<body>
    <div class="container">
        @yield('content')
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
