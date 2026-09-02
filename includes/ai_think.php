<?php
/* Lunify AI: DeepSeek V4 modellerinde "thinking" varsayilan ACIK gelir; max_tokens
   dusukse tum butce reasoning'e gider ve content BOS doner. Bu yardimci, dogrudan
   DeepSeek endpoint'ine giden v4 isteklerinde thinking'i kapatir. (Viktor, 2026-09-02) */
if (!function_exists('aiThinkFix')) {
    function aiThinkFix($model): array {
        $m = strtolower(trim((string)$model));
        if ($m !== '' && strpos($m, 'deepseek-v4') !== false && strpos($m, '/') === false) {
            return ['thinking' => ['type' => 'disabled']];
        }
        return [];
    }
}
