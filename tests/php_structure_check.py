#!/usr/bin/env python3
"""
Lightweight PHP structure checker.

This is NOT a substitute for `php -l` (which CI runs on every push) — it is a
sanity net for environments that have no PHP binary available. It walks each
file with a small state machine that understands PHP open/close tags, the three
comment styles, single/double quoted strings and heredocs, then reports:

  * unbalanced (), [] or {}
  * unterminated strings, comments or heredocs
  * a stray closing bracket

Usage:
    python3 tests/php_structure_check.py [path ...]     # defaults to repo root
"""

import sys
import os

PAIRS = {')': '(', ']': '[', '}': '{'}
OPENERS = set(PAIRS.values())


def check(path):
    with open(path, 'r', encoding='utf-8', errors='replace') as handle:
        src = handle.read()

    problems = []
    stack = []
    i = 0
    n = len(src)
    in_php = False
    line = 1

    def at(idx, text):
        return src.startswith(text, idx)

    while i < n:
        ch = src[i]
        if ch == '\n':
            line += 1

        if not in_php:
            if at(i, '<?php') or at(i, '<?='):
                in_php = True
                i += 5 if at(i, '<?php') else 3
                continue
            i += 1
            continue

        # ---- inside PHP ----
        if at(i, '?>'):
            in_php = False
            i += 2
            continue

        if at(i, '//') or ch == '#':
            end = src.find('\n', i)
            i = n if end == -1 else end
            continue

        if at(i, '/*'):
            end = src.find('*/', i + 2)
            if end == -1:
                problems.append(f'{path}:{line}: unterminated /* block comment')
                break
            line += src.count('\n', i, end)
            i = end + 2
            continue

        if ch in ("'", '"'):
            quote = ch
            j = i + 1
            while j < n:
                if src[j] == '\\':
                    j += 2
                    continue
                if src[j] == quote:
                    break
                if src[j] == '\n':
                    line += 1
                j += 1
            if j >= n:
                problems.append(f'{path}:{line}: unterminated {quote} string')
                break
            i = j + 1
            continue

        if at(i, '<<<'):
            j = i + 3
            while j < n and src[j] in ' \t':
                j += 1
            quote = ''
            if j < n and src[j] in ('"', "'"):
                quote = src[j]
                j += 1
            start = j
            while j < n and (src[j].isalnum() or src[j] == '_'):
                j += 1
            label = src[start:j]
            if quote:
                j += 1
            end = src.find('\n' + label, j)
            if not label or end == -1:
                # try indented closer
                problems.append(f'{path}:{line}: unterminated heredoc <<<{label}')
                break
            line += src.count('\n', i, end)
            i = end + 1 + len(label)
            continue

        if ch in OPENERS:
            stack.append((ch, line))
        elif ch in PAIRS:
            if not stack:
                problems.append(f'{path}:{line}: stray closing {ch!r}')
                break
            opener, opened_line = stack.pop()
            if opener != PAIRS[ch]:
                problems.append(
                    f'{path}:{line}: {ch!r} closes {opener!r} opened on line {opened_line}'
                )
                break

        i += 1

    if stack:
        opener, opened_line = stack[-1]
        problems.append(f'{path}: unclosed {opener!r} opened on line {opened_line}')

    return problems


def collect(roots):
    files = []
    for root in roots:
        if os.path.isfile(root):
            files.append(root)
            continue
        for base, dirs, names in os.walk(root):
            dirs[:] = [d for d in dirs if d not in ('vendor', 'node_modules', '.git')]
            for name in names:
                if name.endswith('.php'):
                    files.append(os.path.join(base, name))
    return sorted(files)


def main():
    roots = sys.argv[1:] or ['.']
    failures = 0
    files = collect(roots)

    for path in files:
        for problem in check(path):
            print(problem)
            failures += 1

    print(f'\nchecked {len(files)} file(s), {failures} problem(s)')
    return 1 if failures else 0


if __name__ == '__main__':
    sys.exit(main())
