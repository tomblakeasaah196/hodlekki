<?php

namespace HOD\Mail\Ai;

/**
 * Phone numbers and email addresses never reach Google (docs/MAIL.md §7e): they become placeholders ("[email 1]",
 * "[phone 2]") before a prompt leaves, and the same placeholders are put back in what comes back, so a draft can
 * still name them.
 */
final class Privacy
{
    /** @var array<string, string> placeholder => original */
    private array $map = [];

    private int $emails = 0;

    private int $phones = 0;

    public function hide(string $text): string
    {
        $text = (string) preg_replace_callback('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', function (array $m): string {
            return $this->placeholder('email', $m[0]);
        }, $text);

        // +234 801 234 5678, 0801-234-5678, (01) 234 5678: 7+ digits with the usual separators
        return (string) preg_replace_callback('/(?<![\w\/:.])\+?\(?\d[\d ().-]{5,}\d(?![\w\/])/', function (array $m): string {
            return strlen((string) preg_replace('/\D/', '', $m[0])) >= 7 ? $this->placeholder('phone', $m[0]) : $m[0];
        }, $text);
    }

    public function restore(string $text): string
    {
        return strtr($text, $this->map);
    }

    private function placeholder(string $kind, string $value): string
    {
        $found = array_search($value, $this->map, true);
        if ($found !== false) {
            return (string) $found;
        }
        $n = $kind === 'email' ? ++$this->emails : ++$this->phones;
        $key = "[{$kind} {$n}]";
        $this->map[$key] = $value;

        return $key;
    }
}
