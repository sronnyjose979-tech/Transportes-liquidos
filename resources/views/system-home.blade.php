<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inicio · Transportes Líquidos</title>
    @if ($section === 'driver')
    <link rel="manifest" href="/driver.webmanifest">
    <meta name="theme-color" content="#1e40af">
    @endif
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
</head>
<body><div id="system-app" data-section="{{ $section }}"></div></body>
</html>
