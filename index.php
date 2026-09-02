<?php
/**
 * Copyright (c) 2025-2026 Lunify A.Ş. Tum haklari saklidir / All rights reserved.
 * Bu kaynak kod Lunify A.Ş.'nin ticari sirridir. Izinsiz kopyalanmasi, dagitilmasi,
 * tersine muhendisligi veya turev calisma uretilmesi yasaktir.
 * Unauthorized copying, distribution or reverse engineering is strictly prohibited.
 */

// Lunify Atlas — tanıtım (landing) sayfası
session_start();
require_once __DIR__ . '/includes/i18n.php';
$logged = !empty($_SESSION['uid']);
$L = atlas_lang();
$app = ($logged ? 'app.php' : 'login.php') . '?lang=' . $L;
$signup = 'login.php?mode=up&lang=' . $L;
$signup = 'login.php?mode=up&lang=' . $L;
?><!doctype html>
<html lang="<?= $L ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= t('site_title') ?></title>
<meta name="description" content="<?= htmlspecialchars(t('site_desc')) ?>">
<meta property="og:title" content="<?= htmlspecialchars(t('site_title')) ?>">
<meta property="og:description" content="<?= htmlspecialchars(t('site_desc')) ?>">
<link rel="alternate" hreflang="tr" href="?lang=tr">
<link rel="alternate" hreflang="en" href="?lang=en">
<link rel="icon" href="assets/lunify-lockup.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/landing.css">
</head>
<body>

<header class="nav">
  <div class="nav-in">
    <a class="brand" href="#top">
      <img class="logo" src="assets/lunify-lockup.png" alt="Lunify">
      <span class="tag">ATLAS</span>
    </a>
    <nav class="nav-links" onclick="document.body.classList.remove('nav-open')">
      <a href="#neden"><?= t('nav_why') ?></a>
      <a href="#neler"><?= t('nav_what') ?></a>
      <a href="#nasil"><?= t('nav_how') ?></a>
      <a href="#fiyat"><?= t('nav_price') ?></a>
      <a href="#sss"><?= t('nav_faq') ?></a>
      <a href="guide.php?lang=<?= $L ?>"><?= $L === 'tr' ? 'Kılavuz' : 'Guide' ?></a>
    </nav>
    <div class="nav-right">
      <?= atlas_lang_switch() ?>
      <a class="btn btn-primary" href="<?= $app ?>"><?= $logged ? t('nav_back') : t('nav_enter') ?></a>
      <button class="nav-burger" type="button" aria-label="<?= $L === 'tr' ? 'Menü' : 'Menu' ?>"
              onclick="document.body.classList.toggle('nav-open')">&#9776;</button>
    </div>
  </div>
</header>

<section class="hero" id="top">
  <div class="aurora-bg"></div>
  <div class="wrap hero-grid">
    <div class="reveal">
      <span class="eyebrow"><span class="dot"></span><span class="mono"><?= t('hero_eyebrow') ?></span></span>
      <h1><?= t('hero_h1_a') ?><br><em><?= t('hero_h1_b') ?></em> <?= t('hero_h1_c') ?></h1>
      <p class="lead"><?= t('hero_lead') ?> <b><?= t('hero_lead_b') ?></b></p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="<?= $logged ? $app : $signup ?>"><?= t('hero_cta1') ?></a>
        <a class="btn btn-ghost" href="#nasil"><?= t('hero_cta2') ?></a>
      </div>
      <p class="hero-note"><?= t('hero_note') ?></p>
    </div>

    <div class="reveal">
      <div class="phone">
        <div class="phone-bar"><b>◆ Atlas</b><span><?= t('ph_now') ?></span></div>
        <div class="phone-body">
          <div class="b me"><?= t('ph_me1') ?></div>
          <div class="b bot"><?= t('ph_bot1') ?><br><b>Mehmet Yılmaz</b> · +90 5•• ••• 44 21<br><i><?= t('ph_bot1_msg') ?></i><br><br><?= t('ph_bot1_ask') ?></div>
          <div class="approve"><span class="chip ok"><?= t('ph_ok') ?></span><span class="chip no"><?= t('ph_no') ?></span></div>
          <div class="b me"><?= t('ph_me2') ?></div>
          <div class="b bot"><?= t('ph_bot2') ?><br><span style="font-size:11.5px;color:#7b7589"><?= t('ph_src') ?></span></div>
        </div>
        <div class="phone-foot">
          <div class="fake-in"><?= t('ph_input') ?></div>
          <div class="fmic">🎤</div>
        </div>
      </div>
    </div>
  </div>
</section>

<section id="neden" class="alt">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('why_eyebrow') ?></span>
      <h2><?= t('why_h2') ?></h2>
      <p><?= t('why_p') ?></p>
    </div>
    <div class="cards why3">
      <?php foreach ([['⏰',1],['✅',2],['📱',3]] as [$ic,$i]): ?>
        <div class="card-x reveal"><div class="ic"><?= $ic ?></div>
          <h3><?= t("why{$i}_h") ?></h3><p><?= t("why{$i}_p") ?></p></div>
      <?php endforeach; ?>
    </div>

    <p class="pullquote reveal"><?= t('why_line') ?></p>

    <div class="cmp reveal">
      <h3 class="cmp-h"><?= t('cmp_h') ?></h3>
      <table class="cmp-t">
        <thead><tr><th></th><th><?= t('cmp_col1') ?></th><th class="hi"><?= t('cmp_col2') ?></th></tr></thead>
        <tbody>
        <?php for ($i=1;$i<=6;$i++): ?>
          <tr>
            <td class="q"><?= t("cmp{$i}_q") ?></td>
            <td class="no"><?= t("cmp{$i}_a") ?></td>
            <td class="yes"><?= t("cmp{$i}_b") ?></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<section id="neler">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('cap_eyebrow') ?></span>
      <h2><?= t('cap_h2') ?></h2>
      <p><?= t('cap_p') ?></p>
    </div>
    <div class="cards">
      <?php foreach ([['📞',1],['💬',2],['✉️',3],['🔎',4],['⏰',5],['👤',6]] as [$ic,$i]): ?>
        <div class="card-x reveal"><div class="ic"><?= $ic ?></div>
          <h3><?= t("cap{$i}_h") ?></h3><p><?= t("cap{$i}_p") ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="kimler" class="alt">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('who_eyebrow') ?></span>
      <h2><?= t('who_h2') ?></h2>
      <p><?= t('who_p') ?></p>
    </div>
    <div class="cards who4">
      <?php foreach ([['🏪',1],['🩺',2],['💼',3],['👥',4]] as [$ic,$i]): ?>
        <div class="card-x reveal"><div class="ic"><?= $ic ?></div>
          <h3><?= t("who{$i}_h") ?></h3><p><?= t("who{$i}_p") ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="emlak">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('mod_eyebrow') ?></span>
      <h2><?= t('mod_h2') ?></h2>
      <p><?= t('mod_p') ?></p>
    </div>
    <div class="cards who4">
      <?php foreach ([['🏠',1],['🤝',2],['⏰',3],['💬',4]] as [$ic,$i]): ?>
        <div class="card-x reveal"><div class="ic"><?= $ic ?></div>
          <h3><?= t("mod{$i}_h") ?></h3><p><?= t("mod{$i}_p") ?></p></div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section id="nasil">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('how_eyebrow') ?></span>
      <h2><?= t('how_h2') ?></h2>
      <p><?= t('how_p') ?></p>
    </div>
    <div class="steps">
      <?php for ($i=1;$i<=3;$i++): ?>
        <div class="step reveal"><h3><?= t("how{$i}_h") ?></h3><p><?= t("how{$i}_p") ?></p></div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<section id="guven" class="alt">
  <div class="wrap trust">
    <div class="reveal">
      <span class="mono"><?= t('tr_eyebrow') ?></span>
      <h2 style="font-size:clamp(28px,3.6vw,42px);margin:12px 0 14px"><?= t('tr_h2_a') ?><br><?= t('tr_h2_b') ?></h2>
      <p style="font-size:17px"><?= t('tr_p_a') ?> <b><?= t('tr_p_b') ?></b><?= t('tr_p_c') ?></p>
    </div>
    <div class="tlist reveal">
      <?php for ($i=1;$i<=4;$i++): ?>
        <div><span class="v">✓</span><p><b><?= t("tr{$i}_b") ?></b> <?= t("tr{$i}_p") ?></p></div>
      <?php endfor; ?>
    </div>
  </div>
</section>

<section id="fiyat">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('pr_eyebrow') ?></span>
      <h2><?= t('pr_h2') ?></h2>
      <p><?= t('pr_p') ?></p>
    </div>
    <div class="plans">
      <div class="plan reveal">
        <h3><?= t('pr1_h') ?></h3>
        <div class="price"><?= t('pr1_price') ?><small><?= t('pr1_per') ?></small></div>
        <ul><?php for($i=1;$i<=4;$i++) echo '<li>'.t("pr1_$i").'</li>'; ?></ul>
        <a class="btn btn-ghost" href="<?= $logged ? $app : $signup ?>"><?= t('pr1_cta') ?></a>
      </div>
      <div class="plan hot reveal">
        <span class="flag"><?= t('pr2_flag') ?></span>
        <h3><?= t('pr2_h') ?></h3>
        <div class="price"><?= t('pr2_price') ?><small><?= t('pr2_per') ?></small></div>
        <ul><?php for($i=1;$i<=5;$i++) echo '<li>'.t("pr2_$i").'</li>'; ?></ul>
        <a class="btn btn-primary" href="<?= $logged ? $app : $signup ?>"><?= t('pr2_cta') ?></a>
      </div>
      <div class="plan reveal">
        <h3><?= t('pr3_h') ?></h3>
        <div class="price"><?= t('pr3_price') ?><small><?= t('pr3_per') ?></small></div>
        <ul><?php for($i=1;$i<=5;$i++) echo '<li>'.t("pr3_$i").'</li>'; ?></ul>
        <a class="btn btn-ghost" href="#iletisim"><?= t('pr3_cta') ?></a>
      </div>
    </div>
  </div>
</section>

<section id="sss" class="alt">
  <div class="wrap">
    <div class="sec-head reveal">
      <span class="mono"><?= t('faq_eyebrow') ?></span>
      <h2><?= t('faq_h2') ?></h2>
    </div>
    <div class="faq">
      <?php for ($i=1;$i<=6;$i++): ?>
        <details class="faq-i reveal"<?= $i===1?' open':'' ?>>
          <summary><?= t("faq{$i}_q") ?></summary>
          <p><?= t("faq{$i}_a") ?></p>
        </details>
      <?php endfor; ?>
    </div>
  </div>
</section>

<section id="iletisim">
  <div class="wrap">
    <div class="cta-box reveal">
      <span class="mono"><?= t('cta_eyebrow') ?></span>
      <h2><?= t('cta_h2') ?></h2>
      <p><?= t('cta_p') ?></p>
      <a class="btn btn-primary" href="<?= $logged ? $app : $signup ?>"><?= t('cta_btn') ?></a>
    </div>
  </div>
</section>

<footer>
  <div class="wrap foot">
    <div>
      <div class="brand" style="margin-bottom:10px"><img class="logo" src="assets/lunify-lockup.png" alt="Lunify" style="height:34px"><span class="tag">ATLAS</span></div>
      <div><?= t('ft_part') ?></div>
    </div>
    <div>
      <div style="margin-bottom:6px"><b style="color:var(--ink)"><?= t('ft_other') ?></b></div>
      <div><a href="https://nexo.lunify.world">Nexo</a> · <a href="https://aura.lunify.world">Aura</a> · <a href="https://pulse.lunify.world">Pulse</a></div>
    </div>
    <div>© <?= date('Y') ?> Lunify A.Ş. <?= t('ft_rights') ?></div>
  </div>
</footer>

<script>
const io=new IntersectionObserver(es=>es.forEach(e=>{if(e.isIntersecting){e.target.classList.add('in');io.unobserve(e.target)}}),{threshold:.12});
document.querySelectorAll('.reveal').forEach(el=>io.observe(el));
</script>
</body>
</html>
