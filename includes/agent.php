<?php
/* LUNAHAN cekirdek shim — kendi cekirdegi (Viktor 20260911-180235, izolasyon Asama 1). */
if (!defined("LH_INC")) define("LH_INC", __DIR__);
$GLOBALS["LH_MODULES"] = [__DIR__ . "/files.php", __DIR__ . "/connect.php"];
require_once __DIR__ . "/../core/agent_core.php";
