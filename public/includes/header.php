<!doctype html>
<html lang="pt-BR">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?php echo $pageTitle ?? 'Painel de Controle'; ?></title>
    <link rel="icon" href="/favicon.ico" sizes="any" />
    <link rel="icon" type="image/png" sizes="16x16" href="/favicons/favicon-16x16.png" />
    <link rel="icon" type="image/png" sizes="32x32" href="/favicons/favicon-32x32.png" />
    <link rel="apple-touch-icon" sizes="180x180" href="/favicons/favicon-180x180.png" />
    <link rel="manifest" href="/site.webmanifest" />
    <meta name="theme-color" content="#0f172a" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="/css/styles.css" />
    <?php if (isset($extraCss)): foreach ($extraCss as $css): ?>
      <link rel="stylesheet" href="/css/<?php echo $css; ?>" />
    <?php endforeach; endif; ?>
  </head>
  <body>
    <!-- Header -->
    <header class="header">
      <div class="header-left">
        <h1 class="header-title"><?php echo $headerTitle ?? 'Painel de Controle'; ?></h1>
        <p class="header-subtitle"><?php echo $headerSubtitle ?? 'Gerencie seus clientes e mensagens'; ?></p>
      </div>
      <div class="header-actions">
        <?php if (($currentPage ?? '') !== 'dashboard'): ?>
          <a href="/" class="btn-back">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            <span>Voltar</span>
          </a>
        <?php endif; ?>
        
        <div id="user-info" style="display:none; align-items:center; gap:12px;">
            <span class="user-badge" id="user-badge-name" style="font-size: 0.9rem; font-weight: 500;"></span>
            <button id="logout-btn" class="btn btn-secondary btn-sm" style="padding: 4px 12px;">Sair</button>
        </div>

        <button class="theme-toggle" id="themeToggle" aria-label="Alternar tema">
          <svg class="moon-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z" />
          </svg>
          <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="5" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" />
          </svg>
        </button>
      </div>
    </header>
    <?php
      $activeNav = $currentPage ?? '';
      if ($activeNav === 'cliente') {
          $activeNav = 'clientes';
      } elseif ($activeNav === 'pendencias') {
          $activeNav = 'importacao';
      } elseif ($activeNav === 'dashboard') {
          $activeNav = 'templates';
      }

      $navItems = [
          ['key' => 'clientes', 'href' => '/clientes', 'label' => 'Clientes', 'hint' => 'Registros e dividas'],
          ['key' => 'importacao', 'href' => '/importacao', 'label' => 'Importacao', 'hint' => 'Planilhas e lotes'],
          ['key' => 'outbox', 'href' => '/outbox', 'label' => 'Outbox', 'hint' => 'Fila e disparos'],
          ['key' => 'templates', 'href' => '/#templates-section', 'label' => 'Templates', 'hint' => 'E-mails automaticos'],
      ];
    ?>
    <nav class="primary-tabs" id="primary-tabs" aria-label="Navegacao principal" style="display:none;">
      <?php foreach ($navItems as $item): ?>
        <a
          class="primary-tab <?php echo $activeNav === $item['key'] ? 'is-active' : ''; ?>"
          href="<?php echo $item['href']; ?>"
        >
          <strong><?php echo $item['label']; ?></strong>
          <span><?php echo $item['hint']; ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
