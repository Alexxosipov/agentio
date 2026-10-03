<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>Agentio · {{ $config['project'] }}</title>
    <link rel="stylesheet" href="{{ $styles }}">
</head>
<body>
<div class="app">
    <header class="topbar">
        <div class="topbar-inner">
            <a class="brand" href="#/" aria-label="Agentio — обзор">
                <span class="brand-mark" aria-hidden="true"></span>
                <span class="brand-name">Agentio</span>
            </a>
            <span class="project" data-slot="project">{{ $config['project'] }}</span>
            <div class="status-line" data-slot="status" aria-live="polite">
                <span class="pill pill-muted">загрузка…</span>
            </div>
        </div>
        <nav class="tabs" aria-label="Разделы">
            <a href="#/" data-tab="overview">Обзор</a>
            <a href="#/board" data-tab="board">Канбан</a>
            <a href="#/log" data-tab="log">Журнал цикла</a>
            <a href="#/" data-tab="epic" hidden></a>
        </nav>
    </header>
    <main class="view" data-slot="view"></main>
</div>
<noscript><p class="noscript">Для работы панели нужен JavaScript.</p></noscript>
<script type="application/json" id="agentio-config">{!! json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
<script src="{{ $script }}" defer></script>
</body>
</html>
