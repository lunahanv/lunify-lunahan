<?php
header('Content-Type: application/manifest+json; charset=utf-8');
echo json_encode([
  'name' => 'LUNAHAN',
  'short_name' => 'LUNAHAN',
  'description' => 'Kişisel AI asistanın: dosyalarını oku, mesaj ve e-posta gönder, işleri takip et.',
  'start_url' => 'app.php',
  'scope' => './',
  'display' => 'standalone',
  'orientation' => 'portrait',
  'background_color' => '#f9fafb',
  'theme_color' => '#ffffff',
  'lang' => 'tr',
  'icons' => [
    ['src' => 'assets/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => 'assets/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
    ['src' => 'assets/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
  ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
