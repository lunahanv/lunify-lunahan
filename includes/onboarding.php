<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/**
 * Lunify Atlas — Baslangic Rehberi / Getting Started
 * Nexo & Pulse ile ayni mantik: kullanici bazli kontrol listesi, durum `settings`
 * tablosunda tutulur, sema degisikligi yok.  (Viktor, 2026-08-28)
 *
 * Durum anahtarlari (kullanici bazli, settings(k,v,user_id)):
 *   ob_hidden        '1' => kullanici kutuyu gizledi
 *   ob_seen          '1' => karsilama ekranini bir kez gordu
 *   ob_guide_seen    '1' => kilavuzu acti
 *   ob_account_seen  '1' => hesap/paket sayfasini acti
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/i18n.php';

function ob_is_tr(): bool { return atlas_lang() === 'tr'; }
function ob_t(string $en, string $tr): string { return ob_is_tr() ? $tr : $en; }

/** Aktif kullanicinin satir sayisi (tablo yoksa 0). */
function ob_count(string $table, string $where = ''): int {
    try {
        $sql = 'SELECT COUNT(*) c FROM `' . $table . '` WHERE user_id = ?' . ($where !== '' ? " AND $where" : '');
        $s = db()->prepare($sql); $s->execute([atlas_uid()]);
        return (int)($s->fetch()['c'] ?? 0);
    } catch (Throwable $e) { return 0; }
}

function ob_has_setting(string $k): bool { return trim((string)setting($k, '')) !== ''; }

/** Kontrol listesi. */
function ob_steps(): array {
    $tr = ob_is_tr();
    $steps = [
        [
            'key' => 'chat', 'url' => 'app.php', 'icon' => 'message-square',
            'title' => ob_t('Send your first command', 'İlk komutunuzu verin'),
            'desc'  => ob_t('Write in plain language — “remind me tomorrow at 9”, “search for…”, “write an e-mail”.',
                            'Normal cümleyle yazın — “yarın 9’da hatırlat”, “şunu araştır”, “şu maili yaz”.'),
            'done'  => fn() => ob_count('commands') > 0,
        ],
        [
            'key' => 'wa', 'url' => 'wa.php', 'icon' => 'message-circle',
            'title' => ob_t('Connect your WhatsApp line', 'WhatsApp hattınızı bağlayın'),
            'desc'  => ob_t('Scan the QR code once; then you can talk to Atlas from WhatsApp, from your own number.',
                            'QR kodu bir kez okutun; sonra Atlas ile kendi numaranızdan WhatsApp üzerinden konuşun.'),
            'done'  => fn() => ob_has_setting('wa_owner'),
        ],
        [
            'key' => 'contacts', 'url' => 'contacts.php', 'icon' => 'users',
            'title' => ob_t('Fill your contact list', 'Rehberinizi doldurun'),
            'desc'  => ob_t('Add people by hand or import your WhatsApp contacts in one click — Atlas needs them to message or e-mail anyone.',
                            'Kişileri elle ekleyin ya da WhatsApp rehberinizi tek tıkla aktarın — Atlas mesaj/mail gönderirken burayı kullanır.'),
            'done'  => fn() => ob_count('contacts') > 0,
        ],
        [
            'key' => 'gmail', 'url' => 'gmail.php', 'icon' => 'mail',
            'title' => ob_t('Connect Gmail, Calendar and Tasks', 'Gmail, Takvim ve Görevler’i bağlayın'),
            'desc'  => ob_t('One Google sign-in lets Atlas read and send mail, see your calendar and manage tasks.',
                            'Tek bir Google izniyle Atlas mailinizi okur/gönderir, takviminizi görür ve görevlerinizi yönetir.'),
            'done'  => fn() => ob_has_setting('gmail_refresh_token'),
        ],
        [
            'key' => 'reminder', 'url' => 'tasks.php', 'icon' => 'check-circle',
            'title' => ob_t('Create your first reminder', 'İlk hatırlatmanızı kurun'),
            'desc'  => ob_t('Say “remind me on Friday at 10 to call the accountant” — it appears on the Tasks page.',
                            '“Cuma 10’da mali müşaviri aramamı hatırlat” deyin — Görevler sayfasında görünür.'),
            'done'  => fn() => ob_count('reminders') > 0,
        ],
        [
            'key' => 'memory', 'url' => 'app.php', 'icon' => 'brain',
            'title' => ob_t('Teach Atlas about you', 'Atlas’a kendinizi tanıtın'),
            'desc'  => ob_t('“Remember: my company is …, I write in Turkish, my working hours are …” — Atlas keeps these facts.',
                            '“Şunu hatırla: firmam …, Türkçe yazarım, mesai saatlerim …” — Atlas bu bilgileri saklar.'),
            'done'  => fn() => ob_count('memories') > 0,
        ],
        [
            'key' => 'guide', 'url' => 'guide.php', 'icon' => 'book-open',
            'title' => ob_t('Skim the user guide', 'Kullanım kılavuzuna göz atın'),
            'desc'  => ob_t('Ten short sections with screenshots — what Atlas can do and how to ask for it.',
                            'Ekran görüntülü on kısa bölüm — Atlas neler yapar, nasıl istenir.'),
            'done'  => fn() => setting('ob_guide_seen', '') === '1',
        ],
        [
            'key' => 'account', 'url' => 'account.php', 'icon' => 'credit-card',
            'title' => ob_t('Check your plan and password', 'Paketinizi ve şifrenizi kontrol edin'),
            'desc'  => ob_t('Your first month is free with every feature open. See usage and change your password here.',
                            'İlk ayınız tüm özellikler açık şekilde ücretsiz. Kullanımınızı görün, şifrenizi buradan değiştirin.'),
            'done'  => fn() => setting('ob_account_seen', '') === '1',
        ],
    ];
    return $steps;
}

/** [adimlar, tamamlanan, toplam, yuzde] */
function ob_progress(): array {
    $steps = ob_steps(); $done = 0;
    foreach ($steps as $i => $s) {
        $ok = false;
        try { $ok = (bool)($s['done'])(); } catch (Throwable $e) { $ok = false; }
        $steps[$i]['is_done'] = $ok;
        if ($ok) $done++;
    }
    $total = max(1, count($steps));
    return [$steps, $done, count($steps), (int)round($done * 100 / $total)];
}

function ob_is_hidden(): bool { return setting('ob_hidden', '') === '1'; }
function ob_was_seen(): bool  { return setting('ob_seen', '') === '1'; }
function ob_mark_seen(): void { set_setting('ob_seen', '1'); }
function ob_mark(string $k): void { if (setting($k, '') !== '1') set_setting($k, '1'); }

/** Sol menude "Başlangıç" maddesi gorunsun mu? */
function ob_show_nav(string $active = ''): bool {
    if ($active === 'start') return true;
    if (ob_is_hidden()) return false;
    [, $done, $total] = ob_progress();
    return $done < $total;
}

/** ?ob=hide|show islemleri — sayfa cizilmeden once cagrilir. */
function ob_handle_action(): void {
    $a = $_GET['ob'] ?? '';
    if ($a !== 'hide' && $a !== 'show') return;
    set_setting('ob_hidden', $a === 'hide' ? '1' : '0');
    $qs = $_GET; unset($qs['ob']);
    $base = basename($_SERVER['PHP_SELF'] ?: 'getting-started.php');
    header('Location: ' . $base . ($qs ? '?' . http_build_query($qs) : ''));
    exit;
}

/** Karsilama paneli (ilk giriste). */
function ob_render_welcome(): void {
    $tr = ob_is_tr();
    $u  = current_user() ?: [];
    $name = trim((string)($u['name'] ?? ''));
    $hello = $tr ? ('Hoş geldiniz' . ($name !== '' ? ', ' . $name : '') . '!')
                 : ('Welcome' . ($name !== '' ? ', ' . $name : '') . '!');
    $items = $tr ? [
        ['Bağlayın', 'WhatsApp hattınızı ve Google hesabınızı bağlayın — Atlas sizin adınıza yazsın.'],
        ['Tanıtın', 'Rehberinizi aktarın, Atlas’a kendinizden bahsedin; sizi tanıdıkça isabetli çalışır.'],
        ['İş yaptırın', 'Hatırlatma, araştırma, mail taslağı, WhatsApp mesajı — düz cümleyle isteyin.'],
        ['Her yerden', 'Panelden ya da WhatsApp’tan; Atlas aynı hafızayla cevap verir.'],
    ] : [
        ['Connect', 'Link your WhatsApp line and Google account so Atlas can act for you.'],
        ['Introduce', 'Import your contacts and tell Atlas about yourself — context makes it accurate.'],
        ['Delegate', 'Reminders, research, e-mail drafts, WhatsApp messages — just ask in plain words.'],
        ['Anywhere', 'From the panel or from WhatsApp — same assistant, same memory.'],
    ];
    ?>
    <div class="rounded-xl p-6 mb-6 text-white" style="background:linear-gradient(135deg,#4b3f8f,#2563eb)">
      <h2 class="text-2xl font-bold"><?= htmlspecialchars($hello) ?></h2>
      <p class="mt-1 text-sm text-white/80">
        <?= htmlspecialchars($tr
            ? 'Bu sayfa sizi 4 hamlede çalışır duruma getirir. Aşağıdaki listeyi sırayla tamamlamanız yeterli.'
            : 'This page gets you running in four moves. Just work through the checklist below.') ?>
      </p>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mt-5">
        <?php foreach ($items as $i => $it): ?>
          <div class="bg-white/10 rounded-lg p-3">
            <div class="text-xs text-white/60 mb-1"><?= $i + 1 ?></div>
            <div class="font-semibold text-sm"><?= htmlspecialchars($it[0]) ?></div>
            <div class="text-xs text-white/80 mt-1"><?= htmlspecialchars($it[1]) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php
}

/** Kontrol listesi. $mode = 'page' | 'widget' */
function ob_render(string $mode = 'page'): void {
    [$steps, $done, $total, $pct] = ob_progress();
    $tr = ob_is_tr();
    $L  = atlas_lang();
    $allDone = $done >= $total;
    ?>
    <div class="ob-box bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 mb-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <h3 class="text-lg font-bold text-gray-900 dark:text-white">
            <?= htmlspecialchars($allDone ? ($tr ? 'Kurulum tamamlandı 🎉' : 'Setup complete 🎉')
                                          : ($tr ? 'Başlangıç Rehberi' : 'Getting Started')) ?>
          </h3>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
            <?= htmlspecialchars($allDone
                ? ($tr ? 'Tüm başlangıç adımlarını bitirdiniz. Detaylar için Kılavuz’a bakabilirsiniz.'
                       : 'You have finished every setup step. See the Guide for details.')
                : ($tr ? 'Atlas’ı tam kapasite kullanmak için şu adımları tamamlayın — her biri 1-2 dakika.'
                       : 'Finish these steps to get the most out of Atlas — each takes 1-2 minutes.')) ?>
          </p>
        </div>
        <div class="text-right shrink-0">
          <div class="text-2xl font-bold text-primary-600 dark:text-primary-400"><?= $done ?>/<?= $total ?></div>
          <div class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($tr ? 'tamamlandı' : 'completed') ?></div>
        </div>
      </div>

      <div class="w-full h-2 bg-gray-100 dark:bg-gray-700 rounded-full mt-4 overflow-hidden">
        <div class="h-2 bg-primary-600 rounded-full transition-all" style="width: <?= (int)$pct ?>%"></div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-4">
        <?php foreach ($steps as $s): ?>
          <a href="<?= htmlspecialchars($s['url']) ?>?lang=<?= $L ?>"
             class="flex items-start gap-3 p-3 rounded-lg border <?= $s['is_done']
                 ? 'border-green-200 dark:border-green-800 bg-green-50/60 dark:bg-green-900/10'
                 : 'border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-500' ?> transition-colors">
            <div class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center text-sm font-semibold <?= $s['is_done']
                 ? 'bg-green-600 text-white' : 'bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-300' ?>">
              <?php if ($s['is_done']): ?>&#10003;<?php else: ?><i class="icon-<?= $s['icon'] ?>" style="font-size:14px"></i><?php endif; ?>
            </div>
            <div class="min-w-0">
              <div class="text-sm font-semibold <?= $s['is_done'] ? 'text-gray-500 dark:text-gray-400 line-through' : 'text-gray-900 dark:text-white' ?>">
                <?= htmlspecialchars($s['title']) ?>
              </div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?= htmlspecialchars($s['desc']) ?></div>
              <?php if (!$s['is_done']): ?>
                <div class="text-xs mt-1 text-primary-600 dark:text-primary-400 font-medium">
                  <?= htmlspecialchars($tr ? 'Adımı aç' : 'Open step') ?> &rarr;
                </div>
              <?php endif; ?>
            </div>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="flex flex-wrap items-center gap-3 mt-4 pt-3 border-t border-gray-100 dark:border-gray-700">
        <a href="guide.php?lang=<?= $L ?>" class="text-sm text-primary-600 dark:text-primary-400 font-medium">
          <?= htmlspecialchars($tr ? 'Kullanım Kılavuzu' : 'User Guide') ?> &rarr;
        </a>
        <a href="app.php?lang=<?= $L ?>" class="text-sm text-primary-600 dark:text-primary-400 font-medium">
          <?= htmlspecialchars($tr ? 'Sohbete git' : 'Go to chat') ?> &rarr;
        </a>
        <?php if ($mode === 'widget'): ?>
          <a href="getting-started.php?lang=<?= $L ?>" class="text-sm text-gray-500 dark:text-gray-400 hover:text-primary-600">
            <?= htmlspecialchars($tr ? 'Rehberi tam sayfa aç' : 'Open the full guide') ?>
          </a>
        <?php endif; ?>
        <?php if (ob_is_hidden()): ?>
          <a href="getting-started.php?lang=<?= $L ?>&amp;ob=show" class="text-sm text-gray-400 hover:text-gray-600 ml-auto">
            <?= htmlspecialchars($tr ? 'Menüde yeniden göster' : 'Show again in the menu') ?>
          </a>
        <?php else: ?>
          <a href="getting-started.php?lang=<?= $L ?>&amp;ob=hide" class="text-sm text-gray-400 hover:text-gray-600 ml-auto">
            <?= htmlspecialchars($tr ? 'Menüden gizle' : 'Hide from the menu') ?>
          </a>
        <?php endif; ?>
      </div>
    </div>
    <?php
}
