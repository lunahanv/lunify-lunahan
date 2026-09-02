<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

/** Lunify Atlas — kullanıcı kılavuzu / user guide (TR + EN). Viktor, 2026-08-28 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/shell.php';
atlas_session();
$L = atlas_lang();
$tr = ($L === 'tr');

$D = $tr ? [
  'title' => 'Kullanım Kılavuzu',
  'sub'   => 'Atlas’ı ilk günden verimli kullanmak için bilmen gereken her şey.',
  'intro_t' => 'Atlas nedir?',
  'intro' => '<p>Lunify Atlas, günlük işlerini <b>normal cümlelerle</b> yaptırabildiğin kişisel asistanındır.
     Panelden ya da <b>WhatsApp’tan</b> yazarsın; Atlas rehberine bakar, mesaj gönderir, e-postanı okur/yazar,
     takvimine randevu koyar, görev ve hatırlatıcı kurar, web’de araştırma yapar.</p>
     <p class="gnote">Komut ezberlemene gerek yok. “Ahmet’e yarınki toplantıyı hatırlat” demen yeterli.</p>',
  'start_t' => 'İlk 5 dakika',
  'start' => '<ol>
     <li><b>Giriş yap</b> — e-posta/şifre ya da “Google ile devam et”.</li>
     <li><b>WhatsApp hattını bağla</b> — sol menüde <b>WhatsApp</b> → telefonunda <i>Ayarlar → Bağlı cihazlar → Cihaz bağla</i> → karekodu okut.</li>
     <li><b>Rehberi aktar</b> — <b>Rehber</b> sayfasında “WhatsApp rehberini içe aktar”. Böylece numara yazmadan “Ahmet’e mesaj at” diyebilirsin.</li>
     <li><b>Gmail/Takvim/Görevler’i bağla</b> — <b>Gmail</b> sayfasındaki “Google ile bağlan”. Tek izinle e-posta, takvim ve görevler açılır.</li>
     <li><b>İlk isteğini yaz</b> — Sohbet ekranına: “Bu haftaki toplantılarım neler?”</li>
   </ol>',
  'chat_t' => 'Sohbet ekranı',
  'chat' => '<ul>
     <li><b>Yeni sohbet</b> — soldaki “+ Yeni sohbet” ile konuyu ayır; eski sohbetler listede kalır.</li>
     <li><b>Mikrofon</b> 🎤 — yazmak yerine konuşarak istek gönder.</li>
     <li><b>Şeffaflık</b> — Atlas hangi aracı kullandıysa mesajın altında görürsün (mail gönderildi, kişi bulundu vb.).</li>
     <li><b>Dil</b> — sol alttaki TR/EN düğmesi tüm paneli ve Atlas’ın cevap dilini değiştirir.</li>
     <li><b>Tema</b> — ay/güneş simgesiyle koyu moda geç.</li>
     <li><b>Menü sırası</b> — sol menüdeki maddeleri sürükleyerek kendi sıranı kaydedebilirsin.</li>
   </ul>',
  'wa_t' => 'WhatsApp’tan kullanmak',
  'wa' => '<p>Atlas’ın en pratik yanı: paneli hiç açmadan <b>WhatsApp’tan</b> yazarak her şeyi yaptırabilmen.</p>
   <ul>
     <li>Hattını QR ile bağladıktan sonra <b>kendi numarana</b> (Kendime mesaj) yazdığın her şey Atlas’a gider.</li>
     <li>Güvenlik: hatta sadece <b>sahibi</b> komut verebilir. Başkalarının yazdığı mesajlar sessizce yok sayılır; sahibi <b>WhatsApp</b> sayfasından sıfırlanabilir.</li>
     <li>Mesaj gönderme: “Ayşe’ye 15:00 randevusunu hatırlat” dersen Atlas mesajı <b>doğrudan senin hattından</b> gönderir.</li>
     <li>Bağlantı koparsa (telefon uzun süre kapalıysa) WhatsApp sayfasından karekodu tekrar okut.</li>
   </ul>',
  'contacts_t' => 'Rehber',
  'contacts' => '<ul>
     <li>WhatsApp rehberini tek tıkla içe aktar; mükerrer kayıt eklenmez.</li>
     <li>Sohbette “Mehmet Yılmaz’ı ekle, numarası 0532…” diyerek de kişi ekleyebilirsin.</li>
     <li>Kişi rehberde yoksa Atlas bağlı WhatsApp hattının rehberinde de arar.</li>
     <li>İsim benzer birden fazla kişi varsa Atlas hangisi olduğunu sorar — yanlış kişiye mesaj gitmez.</li>
   </ul>',
  'mail_t' => 'Gmail, Takvim ve Görevler',
  'mail' => '<ul>
     <li><b>Okuma</b>: “Bugün gelen önemli mailleri özetle”, “Ali’den gelen son maili oku”.</li>
     <li><b>Yazma</b>: “Ali’ye teklif için teşekkür maili yaz.” Atlas <b>önce taslağı</b> (kime / konu / metin) sohbette gösterir; sen “gönder” deyince yollar. Düzeltme istersen yeni taslak hazırlar.</li>
     <li><b>Takvim</b>: “Salı 14:00’te Mehmet ile toplantı ekle”, “Bu haftaki randevularım”, “Cuma’daki toplantıyı sil”.</li>
     <li><b>Görevler</b>: “Perşembeye kadar fatura kesme görevi ekle”, “Açık görevlerim”, “X görevini tamamlandı yap”.</li>
   </ul>
   <p class="gnote">Bağlantı <b>Gmail</b> sayfasından istediğin an kesilebilir; Atlas o andan sonra e-postana erişemez.</p>',
  'mem_t' => 'Hafıza ve hatırlatıcılar',
  'mem' => '<ul>
     <li><b>Kalıcı hafıza</b>: “Şirketimin adı ABV Grup, mailleri hep resmî dille yaz” dersen Atlas bunu hatırlar ve sonraki işlerde uygular.</li>
     <li><b>Unut</b>: “Şu bilgiyi unut” dediğinde siler.</li>
     <li><b>Geçmiş arama</b>: “Geçen ay konuştuğumuz fiyat neydi?” — eski sohbetlerde arar.</li>
     <li><b>Hatırlatıcılar</b>: “Yarın 09:00’da beni ara-hatırlat” → <b>Görevler</b> sayfasında listelenir.</li>
     <li><b>Arama</b>: Atlas telefon araması yapamaz; istediğinde numarayı bulup sana verir.</li>
   </ul>',
  'ex_t' => 'Örnek istekler',
  'ex' => '',
  'plan_t' => 'Paket ve kullanım',
  'plan' => '<p><b>Hesabım</b> sayfasında paketini, bu ayki kullanımını ve kalan hakkını görürsün. Paket yükseltme talebini de oradan gönderirsin; ekip seninle iletişime geçer.</p>
   <ul><li>Şifreni yine <b>Hesabım</b> sayfasından değiştirebilirsin (en az 8 karakter).</li>
   <li>Şifreni unuttuysan giriş ekranındaki “Şifremi unuttum” bağlantısı e-posta ile sıfırlama linki gönderir (1 saat geçerli).</li></ul>',
  'faq_t' => 'Sık sorulanlar / sorun giderme',
  'faq' => '<ul>
     <li><b>WhatsApp “bağlı değil” diyor</b> — WhatsApp sayfasında karekodu tekrar okut. Telefonun internetinin açık olması gerekir.</li>
     <li><b>Rehber boş geldi</b> — hattı yeniden bağladıktan sonra birkaç dakika bekleyip “WhatsApp rehberini içe aktar”ı tekrar dene.</li>
     <li><b>Mail gönderemedi</b> — Gmail bağlı değildir; <b>Gmail</b> sayfasından bağla. Atlas gönderemediğinde bunu açıkça söyler, sessizce “gönderdim” demez.</li>
     <li><b>Yanlış kişiye gitmesin</b> — Atlas mesajı göndermeden önce kime gideceğini söyler; e-postada mutlaka taslak gösterir.</li>
     <li><b>Verilerim</b> — sohbetlerin, rehberin ve hafızan yalnızca senin hesabına bağlıdır; diğer kullanıcılar göremez.</li>
   </ul>',
] : [
  'title' => 'User Guide',
  'sub'   => 'Everything you need to get productive with Atlas from day one.',
  'intro_t' => 'What is Atlas?',
  'intro' => '<p>Lunify Atlas is your personal assistant: you ask for things in <b>plain language</b>, from the web panel
     or straight from <b>WhatsApp</b>. Atlas looks up contacts, sends messages, reads and writes your email,
     books calendar events, keeps tasks and reminders, and researches the web for you.</p>
     <p class="gnote">No commands to memorise — “remind Ahmet about tomorrow’s meeting” is enough.</p>',
  'start_t' => 'Your first 5 minutes',
  'start' => '<ol>
     <li><b>Sign in</b> — email/password or “Continue with Google”.</li>
     <li><b>Connect WhatsApp</b> — <b>WhatsApp</b> in the sidebar → on your phone <i>Settings → Linked devices → Link a device</i> → scan the QR code.</li>
     <li><b>Import contacts</b> — on the <b>Contacts</b> page click “Import WhatsApp contacts”, so you can say “message Ahmet” without typing numbers.</li>
     <li><b>Connect Gmail/Calendar/Tasks</b> — “Connect with Google” on the <b>Gmail</b> page. One consent enables all three.</li>
     <li><b>Ask something</b> — in chat: “What meetings do I have this week?”</li>
   </ol>',
  'chat_t' => 'The chat screen',
  'chat' => '<ul>
     <li><b>New chat</b> — start a separate thread per topic; previous chats stay in the list.</li>
     <li><b>Microphone</b> 🎤 — dictate instead of typing.</li>
     <li><b>Transparency</b> — the tools Atlas used appear under each reply (email sent, contact found, …).</li>
     <li><b>Language</b> — the TR/EN switch changes the whole panel and Atlas’ reply language.</li>
     <li><b>Theme</b> — moon/sun icon toggles dark mode.</li>
     <li><b>Menu order</b> — drag sidebar items to save your own order.</li>
   </ul>',
  'wa_t' => 'Using Atlas from WhatsApp',
  'wa' => '<p>The most practical part: you never have to open the panel — just message Atlas on <b>WhatsApp</b>.</p>
   <ul>
     <li>After linking your line, anything you write to <b>your own number</b> (Message yourself) goes to Atlas.</li>
     <li>Security: only the line <b>owner</b> can give commands. Messages from anyone else are silently ignored; the owner can be reset on the <b>WhatsApp</b> page.</li>
     <li>Sending: “tell Ayşe about the 3 pm appointment” — Atlas sends it <b>directly from your own line</b>.</li>
     <li>If the session drops (phone offline for a long time), scan the QR code again.</li>
   </ul>',
  'contacts_t' => 'Contacts',
  'contacts' => '<ul>
     <li>Import your WhatsApp contacts in one click — duplicates are skipped.</li>
     <li>You can also say “add Mehmet Yılmaz, number 0532…” in chat.</li>
     <li>If a person is not in Atlas, it also looks them up in the linked WhatsApp line’s contacts.</li>
     <li>If several names match, Atlas asks which one — no message goes to the wrong person.</li>
   </ul>',
  'mail_t' => 'Gmail, Calendar and Tasks',
  'mail' => '<ul>
     <li><b>Reading</b>: “summarise today’s important emails”, “open the last email from Ali”.</li>
     <li><b>Writing</b>: “write Ali a thank-you email for the quote.” Atlas <b>shows the draft first</b> (to / subject / body) and only sends after you confirm. Ask for edits and you get a new draft.</li>
     <li><b>Calendar</b>: “add a meeting with Mehmet Tuesday 2 pm”, “what’s on this week”, “delete Friday’s meeting”.</li>
     <li><b>Tasks</b>: “add a task to invoice by Thursday”, “my open tasks”, “mark X as done”.</li>
   </ul>
   <p class="gnote">You can disconnect any time on the <b>Gmail</b> page; Atlas loses access instantly.</p>',
  'mem_t' => 'Memory and reminders',
  'mem' => '<ul>
     <li><b>Long-term memory</b>: tell it “my company is ABV Group, always write emails formally” and it applies that later.</li>
     <li><b>Forget</b>: “forget that” removes a stored fact.</li>
     <li><b>Search history</b>: “what price did we discuss last month?” — it searches older chats.</li>
     <li><b>Reminders</b>: “remind me tomorrow at 9” → shown on the <b>Tasks</b> page.</li>
     <li><b>Calls</b>: Atlas cannot dial; it finds and gives you the number.</li>
   </ul>',
  'ex_t' => 'Example requests',
  'ex' => '',
  'plan_t' => 'Plan and usage',
  'plan' => '<p>The <b>Account</b> page shows your plan, this month’s usage and what’s left. You can request an upgrade there and the team will get in touch.</p>
   <ul><li>Change your password on the <b>Account</b> page (min. 8 characters).</li>
   <li>Forgot it? The “Forgot password” link on the sign-in screen emails a reset link, valid for 1 hour.</li></ul>',
  'faq_t' => 'FAQ / troubleshooting',
  'faq' => '<ul>
     <li><b>WhatsApp says “not connected”</b> — rescan the QR code; your phone needs to be online.</li>
     <li><b>Contact import found nothing</b> — wait a couple of minutes after linking, then import again.</li>
     <li><b>Email was not sent</b> — Gmail is probably not connected. Atlas always tells you when a send fails; it never pretends.</li>
     <li><b>Wrong recipient</b> — Atlas states the recipient before sending and always shows email drafts first.</li>
     <li><b>Your data</b> — chats, contacts and memory belong to your account only; other users cannot see them.</li>
   </ul>',
];

$EX = $tr ? [
  ['message-circle','WhatsApp','“Ahmet’e yaz: toplantıyı 15:00’e aldık.”'],
  ['mail','Gmail','“Ali’ye teklif için teşekkür maili hazırla.”'],
  ['calendar','Takvim','“Salı 14:00 Mehmet ile toplantı ekle.”'],
  ['check-circle','Görev','“Perşembeye kadar faturaları gönder görevi ekle.”'],
  ['bell','Hatırlatıcı','“Yarın 09:00’da kargoyu sormamı hatırlat.”'],
  ['search','Araştırma','“Bu firmayı araştır, ne iş yapıyorlar?”'],
  ['users','Rehber','“Selin’in numarasını bul.”'],
  ['brain','Hafıza','“Unutma: faturaları hep ayın 5’inde keserim.”'],
] : [
  ['message-circle','WhatsApp','“Message Ahmet: we moved the meeting to 3 pm.”'],
  ['mail','Gmail','“Draft a thank-you email to Ali for the quote.”'],
  ['calendar','Calendar','“Add a meeting with Mehmet, Tuesday 2 pm.”'],
  ['check-circle','Task','“Add a task: send invoices by Thursday.”'],
  ['bell','Reminder','“Remind me tomorrow at 9 to chase the shipment.”'],
  ['search','Research','“Research this company — what do they do?”'],
  ['users','Contacts','“Find Selin’s number.”'],
  ['brain','Memory','“Remember: I always invoice on the 5th.”'],
];

$IMG = [
  'start'    => ['login.png',    $tr ? 'Giriş ekranı — e-posta/şifre ya da Google ile devam et.' : 'Sign-in screen — email/password or Continue with Google.'],
  'chat'     => ['chat.png',     $tr ? 'Sohbet ekranı: solda sohbet listesi, ortada yazışma, altta komut kutusu ve mikrofon.' : 'Chat screen: conversation list on the left, the thread in the middle, command box and microphone below.'],
  'wa'       => ['wa.png',       $tr ? 'WhatsApp sayfası: karekodu telefonundan okut; altta hattın sahibi görünür.' : 'WhatsApp page: scan the QR code with your phone; the line owner is shown below.'],
  'contacts' => ['contacts.png', $tr ? 'Rehber: tek tıkla WhatsApp kişilerini içe aktar, kayıtlı kişileri gör.' : 'Contacts: import WhatsApp contacts in one click and see saved people.'],
  'mail'     => ['gmail.png',    $tr ? 'Google bağlantısı: tek izinle Gmail, Takvim ve Görevler açılır.' : 'Google connection: one consent enables Gmail, Calendar and Tasks.'],
  'mem'      => ['tasks.png',    $tr ? 'Görevler sayfası: bekleyen işler ve kurduğun hatırlatıcılar.' : 'Tasks page: pending actions and your reminders.'],
  'plan'     => ['account.png',  $tr ? 'Hesabım: paketin, bu ayki kullanımın ve paket yükseltme.' : 'Account: your plan, monthly usage and upgrade options.'],
];
function g_shot(array $IMG, string $k, string $lang): void {
  if (!isset($IMG[$k])) return;
  [$file, $cap] = $IMG[$k];
  $src = 'assets/guide/' . $lang . '/' . $file;
  echo '<figure class="gshot"><img src="' . $src . '" alt="' . htmlspecialchars($cap) . '" loading="lazy" onclick="gZoom(this.src)">'
     . '<figcaption>' . htmlspecialchars($cap) . '</figcaption></figure>';
}

require_once __DIR__ . '/includes/onboarding.php';
ob_mark('ob_guide_seen');
atlas_header($D['title'], 'guide', $D['sub']);
?>
<style>
.atlas-page .gcard{padding:20px 22px}
.atlas-page .ghead{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.atlas-page .ghead h2{margin:0;font-size:16px;font-weight:700;color:#111827}
.dark .atlas-page .ghead h2{color:#f3f4f6}
.atlas-page .gicon{width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;
  background:#eff6ff;color:#2563eb;font-size:17px;flex:none}
.dark .atlas-page .gicon{background:rgba(37,99,235,.18);color:#93c5fd}
.atlas-page .gbody{font-size:14.5px;line-height:1.7;color:#374151}
.dark .atlas-page .gbody{color:#d1d5db}
.atlas-page .gbody ul{margin:0;padding-left:20px;list-style:disc}
.atlas-page .gbody ol{margin:0;padding-left:20px;list-style:decimal}
.atlas-page .gbody li::marker{color:#9ca3af}
.atlas-page .gbody li{margin:5px 0}
.atlas-page .gbody p{margin:0 0 10px}
.atlas-page .gnote{background:#f9fafb;border-left:3px solid #2563eb;border-radius:0 8px 8px 0;padding:9px 12px;font-size:13.5px;color:#4b5563}
.dark .atlas-page .gnote{background:#111827;color:#9ca3af}
.atlas-page .gtoc{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
.atlas-page .gtoc a{font-size:13px;padding:6px 12px;border-radius:999px;border:1px solid #e5e7eb;background:#fff;color:#374151;text-decoration:none}
.atlas-page .gtoc a:hover{border-color:#2563eb;color:#2563eb}
.dark .atlas-page .gtoc a{background:#1f2937;border-color:#374151;color:#d1d5db}
.atlas-page .gex{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:10px}
.atlas-page .gex div{border:1px solid #e5e7eb;border-radius:11px;padding:11px 13px;background:#fbfcfe}
.dark .atlas-page .gex div{background:#111827;border-color:#374151}
.atlas-page .gshot{margin:14px 0 2px}
.atlas-page .gshot img{width:100%;border:1px solid #e5e7eb;border-radius:12px;box-shadow:0 4px 16px rgba(16,24,40,.08);cursor:zoom-in;display:block}
.dark .atlas-page .gshot img{border-color:#374151}
.atlas-page .gshot figcaption{font-size:12.5px;color:#6b7280;margin-top:7px;text-align:center}
.dark .atlas-page .gshot figcaption{color:#9ca3af}
#gLightbox{position:fixed;inset:0;background:rgba(15,23,42,.85);display:none;align-items:center;justify-content:center;z-index:80;padding:24px;cursor:zoom-out}
#gLightbox img{max-width:100%;max-height:100%;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
.atlas-page .gex b{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:3px}
.atlas-page .gex span{font-size:14px;color:#111827}
.dark .atlas-page .gex span{color:#e5e7eb}
</style>

<div class="gtoc">
  <?php foreach (['intro','start','chat','wa','contacts','mail','mem','ex','plan','faq'] as $k):
    $lbl = $D[$k . '_t']; ?>
    <a href="#g-<?= $k ?>"><?= htmlspecialchars($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php
$icons = ['intro'=>'sparkles','start'=>'rocket','chat'=>'message-square','wa'=>'message-circle',
          'contacts'=>'users','mail'=>'mail','mem'=>'brain','plan'=>'credit-card','faq'=>'help-circle'];
foreach (['intro','start','chat','wa','contacts','mail','mem'] as $k): ?>
  <section class="acard gcard" id="g-<?= $k ?>">
    <div class="ghead"><span class="gicon"><i class="icon-<?= $icons[$k] ?>"></i></span><h2><?= htmlspecialchars($D[$k . '_t']) ?></h2></div>
    <div class="gbody"><?= $D[$k] ?><?php g_shot($IMG, $k, $L); ?></div>
  </section>
<?php endforeach; ?>

<section class="acard gcard" id="g-ex">
  <div class="ghead"><span class="gicon"><i class="icon-list"></i></span><h2><?= htmlspecialchars($D['ex_t']) ?></h2></div>
  <div class="gbody gex">
    <?php foreach ($EX as [$ic, $lbl, $txt]): ?>
      <div><b><?= htmlspecialchars($lbl) ?></b><span><?= htmlspecialchars($txt) ?></span></div>
    <?php endforeach; ?>
  </div>
</section>

<?php foreach (['plan','faq'] as $k): ?>
  <section class="acard gcard" id="g-<?= $k ?>">
    <div class="ghead"><span class="gicon"><i class="icon-<?= $icons[$k] ?>"></i></span><h2><?= htmlspecialchars($D[$k . '_t']) ?></h2></div>
    <div class="gbody"><?= $D[$k] ?><?php g_shot($IMG, $k, $L); ?></div>
  </section>
<?php endforeach; ?>

<p class="muted" style="text-align:center;font-size:12.5px;margin:6px 0 18px">
  <?= $tr ? 'Takıldığın bir yer olursa Atlas’a sohbetten sorabilirsin.' : 'Stuck? Just ask Atlas in chat.' ?>
</p>
<div id="gLightbox" onclick="this.style.display='none'"><img id="gLightboxImg" src="" alt=""></div>
<script>
function gZoom(src){ var b=document.getElementById('gLightbox'); document.getElementById('gLightboxImg').src=src; b.style.display='flex'; }
document.addEventListener('keydown', function(e){ if(e.key==='Escape') document.getElementById('gLightbox').style.display='none'; });
</script>
<?php atlas_footer(); ?>
