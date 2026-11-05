<?php
// i18n: texts table per bot+lang, English fallback.
require_once __DIR__ . '/Database.php';

class I18n {
    private int $botId;
    private string $lang;
    private array $cache = [];

    public function __construct(int $botId, string $lang = 'en') {
        $this->botId = $botId;
        $this->lang = $lang;
    }
    public function setLang(string $lang): void { $this->lang = $lang; }
    public function lang(): string { return $this->lang; }

    public function t(string $key, array $vars = []): string {
        $v = $this->lookup($this->lang, $key);
        if ($v === null && $this->lang !== 'en') $v = $this->lookup('en', $key);
        if ($v === null) return $key;
        foreach ($vars as $k => $val) $v = str_replace('{' . $k . '}', (string)$val, $v);
        return $v;
    }

    private function lookup(string $lang, string $key): ?string {
        $ck = $lang . ':' . $key;
        if (array_key_exists($ck, $this->cache)) return $this->cache[$ck];
        $st = db()->prepare("SELECT `value` FROM texts WHERE bot_id=? AND lang=? AND `key`=?");
        $st->execute([$this->botId, $lang, $key]);
        $row = $st->fetch();
        $this->cache[$ck] = $row ? $row['value'] : null;
        return $this->cache[$ck];
    }
}
