<?php
/**
 * css_cascade.php — a small CSS cascade resolver, for the regression tests.
 *
 * WHY IT EXISTS
 *   issue-024 §8 asks for a LIVE assertion that "checks that the CSS applied
 *   (a computed style, not a byte count)". The image has no browser and no
 *   JavaScript engine, so the only way to answer that question is to
 *   resolve the cascade here — in PHP — over the stylesheets the running
 *   server actually serves.
 *
 *   `ext-dom` IS present, but DOMElement::matches() is NOT: the PHP
 *   8.5.12 build in litespeedtech/openlitespeed:1.9.4-lsphp85 does not
 *   ship it. Every ->matches() call raises
 *   "Call to undefined method DOMElement::matches()". So the matcher below
 *   is hand-written.
 *
 * WHAT IT IS NOT
 *   It is not a browser. It implements the part of CSS the shipped
 *   stylesheets use, and it REFUSES — it throws — on anything it does not
 *   understand. A resolver that quietly ignored a selector it could not
 *   evaluate would report a computed value that no browser agrees with,
 *   which is worse than no answer at all: it would be a green test that
 *   proves nothing.
 *
 * THE SUPPORTED SUBSET
 *   - at-rules: @media (parsed) and @keyframes (parsed and discarded).
 *     Every other at-rule is refused.
 *   - media features: prefers-color-scheme, prefers-reduced-motion,
 *     min-width, max-width, min-height, max-height, orientation.
 *   - selectors: type, universal, class, id, attribute (with the six
 *     operators), :root, :not(), :first-child, :last-child, :only-child,
 *     :nth-child(), :first-of-type, :last-of-type, :checked, :disabled,
 *     :enabled, :empty — and the descendant, child, adjacent-sibling and
 *     general-sibling combinators.
 *   - the pseudo-classes that describe a live state (:hover, :focus,
 *     :focus-visible, :focus-within, :active, :target) never match: a
 *     static resolution describes the RESTING state, and the resting state
 *     is the one the page is judged by.
 *   - the pseudo-elements (::before, ::after, ...) never match the element
 *     itself: they style a generated box, not the element.
 *
 * THE PUBLIC SURFACE
 *   css_parse_stylesheet(string $css): array
 *   css_parse_html(string $html): DOMDocument
 *   css_find_element(DOMDocument $doc, string $selector): ?DOMElement
 *   css_computed_value(array $rules, DOMElement $el, string $prop, array $env): ?string
 *   css_computed_declarations(array $rules, DOMElement $el, array $env): array
 *
 * $env is the environment the page is rendered in:
 *   ['prefers-color-scheme' => 'light' | 'dark',
 *    'prefers-reduced-motion' => 'no-preference' | 'reduce',
 *    'width' => int, 'height' => int]
 *
 * The values are the ones the application really ships, so the resolver
 * answers the question the acceptance criterion asks: not "is the CSS
 * file there" but "what colour does this page actually paint its
 * background in".
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Low-level scanners
// ---------------------------------------------------------------------------

/**
 * The offset just past the string literal that starts at the given offset.
 *
 * The scanners below are string-aware on purpose: a "}" inside a quoted
 * content string would otherwise close a block early, and a dropped rule
 * is a silently wrong computed value.
 */
function css_skip_string(string $text, int $start): int
{
    $quote = $text[$start];
    $len   = strlen($text);
    $i     = $start + 1;

    while ($i < $len) {
        if ($text[$i] === '\\') {
            $i += 2;
            continue;
        }

        if ($text[$i] === $quote) {
            return $i + 1;
        }

        $i++;
    }

    throw new RuntimeException('an unterminated string in the stylesheet');
}

/**
 * The offset of a character at the top level, or null.
 *
 * "Top level" means outside brackets and outside strings.
 */
function css_find_char(string $text, int $from, int $to, string $char): ?int
{
    $depth = 0;
    $len   = strlen($text);

    for ($i = $from; $i < $to && $i < $len; $i++) {
        $ch = $text[$i];

        if ($ch === '"' || $ch === "'") {
            $i = css_skip_string($text, $i) - 1;
            continue;
        }

        if ($ch === '(' || $ch === '[') {
            $depth++;
            continue;
        }

        if ($ch === ')' || $ch === ']') {
            $depth--;
            continue;
        }

        if ($depth === 0 && $ch === $char) {
            return $i;
        }
    }

    return null;
}

/**
 * The offset of the ")" that closes the "(" at the given offset.
 */
function css_find_matching_paren(string $text, int $open): ?int
{
    $depth = 0;
    $len   = strlen($text);

    for ($i = $open; $i < $len; $i++) {
        $ch = $text[$i];

        if ($ch === '"' || $ch === "'") {
            $i = css_skip_string($text, $i) - 1;
            continue;
        }

        if ($ch === '(') {
            $depth++;
            continue;
        }

        if ($ch === ')') {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * Split a string on a separator that is not inside brackets or strings.
 */
function css_split_top(string $text, string $separator): array
{
    $parts = [];
    $buf   = '';
    $depth = 0;
    $len   = strlen($text);
    $i     = 0;

    while ($i < $len) {
        $ch = $text[$i];

        if ($ch === '"' || $ch === "'") {
            $end  = css_skip_string($text, $i);
            $buf .= substr($text, $i, $end - $i);
            $i    = $end;
            continue;
        }

        if ($ch === '(' || $ch === '[') {
            $depth++;
        } elseif ($ch === ')' || $ch === ']') {
            $depth--;
        }

        if ($depth === 0 && $ch === $separator) {
            $parts[] = $buf;
            $buf     = '';
            $i++;
            continue;
        }

        $buf .= $ch;
        $i++;
    }

    $parts[] = $buf;

    return $parts;
}

// ---------------------------------------------------------------------------
// The stylesheet
// ---------------------------------------------------------------------------

/**
 * Remove the CSS comments.
 *
 * The shipped stylesheets carry no string literal that contains "/*", so
 * the regular expression is enough. The block scanner below IS
 * string-aware; this one is not, and the comment says so.
 */
function css_strip_comments(string $css): string
{
    $stripped = preg_replace('#/\*.*?\*/#s', ' ', $css);

    return $stripped === null ? $css : $stripped;
}

/**
 * Read the block that starts at the "{" at the given offset.
 *
 * @return array{text:string,start:int,end:int,next:int}
 */
function css_read_block(string $css, int $open): array
{
    $depth  = 0;
    $len    = strlen($css);
    $start  = $open + 1;
    $i      = $open;

    while ($i < $len) {
        $ch = $css[$i];

        if ($ch === '"' || $ch === "'") {
            $i = css_skip_string($css, $i);
            continue;
        }

        if ($ch === '{') {
            $depth++;
            $i++;
            continue;
        }

        if ($ch === '}') {
            $depth--;

            if ($depth === 0) {
                return [
                    'text'  => substr($css, $start, $i - $start),
                    'start' => $start,
                    'end'   => $i,
                    'next'  => $i + 1,
                ];
            }

            $i++;
            continue;
        }

        $i++;
    }

    throw new RuntimeException('unbalanced braces in the stylesheet');
}

/**
 * Parse a declaration block.
 *
 * @return array<string,array{value:string,important:bool}>
 */
function css_parse_declarations(string $block): array
{
    $decls = [];

    foreach (css_split_top($block, ';') as $declaration) {
        $declaration = trim($declaration);

        if ($declaration === '') {
            continue;
        }

        $colon = css_find_char($declaration, 0, strlen($declaration), ':');

        if ($colon === null) {
            // A declaration with no colon is not a declaration. It is
            // ignored, exactly as a browser ignores it.
            continue;
        }

        $prop  = strtolower(trim(substr($declaration, 0, $colon)));
        $value = trim(substr($declaration, $colon + 1));

        if ($prop === '') {
            continue;
        }

        $important = false;

        if (preg_match('/!\s*important\s*$/i', $value) === 1) {
            $important = true;
            $value     = trim((string) preg_replace('/!\s*important\s*$/i', '', $value));
        }

        $existing = $decls[$prop] ?? null;

        // Inside one block the LAST declaration wins — but an important one
        // is not displaced by a later unimportant one.
        if ($existing !== null && $existing['important'] && ! $important) {
            continue;
        }

        $decls[$prop] = ['value' => $value, 'important' => $important];
    }

    return $decls;
}

/**
 * Read one at-rule.
 *
 * @param list<array> $rules
 */
function css_parse_at_rule(string $css, int $start, int $end, ?string $media, array &$rules): int
{
    $len = strlen($css);
    $i   = $start + 1;

    while ($i < $end && (ctype_alnum($css[$i]) || $css[$i] === '-')) {
        $i++;
    }

    $name = strtolower(substr($css, $start + 1, $i - $start - 1));

    // The prelude runs to the "{" or the ";".
    $j = $i;

    while ($j < $end && $css[$j] !== '{' && $css[$j] !== ';') {
        if ($css[$j] === '"' || $css[$j] === "'") {
            $j = css_skip_string($css, $j);
            continue;
        }

        $j++;
    }

    if ($j >= $end) {
        throw new RuntimeException('an at-rule is not closed: @' . $name);
    }

    $prelude = trim(substr($css, $i, $j - $i));

    if ($css[$j] === ';') {
        if (! in_array($name, ['import', 'charset', 'namespace'], true)) {
            throw new RuntimeException('unsupported at-rule: @' . $name);
        }

        return $j + 1;
    }

    $block = css_read_block($css, $j);

    if ($name === 'media') {
        $inner = $media === null ? $prelude : $media . ' and ' . $prelude;
        css_parse_rules($css, $block['start'], $block['end'], $inner, $rules);

        return $block['next'];
    }

    if ($name === 'keyframes' || $name === '-webkit-keyframes') {
        // The keyframes describe an animation, not a style. They are read so
        // that the parser stays in step with the file, and discarded.
        return $block['next'];
    }

    throw new RuntimeException('unsupported at-rule: @' . $name);
}

/**
 * Parse the rules between two offsets.
 *
 * @param list<array> $rules
 */
function css_parse_rules(string $css, int $start, int $end, ?string $media, array &$rules): void
{
    $i = $start;

    while ($i < $end) {
        while ($i < $end && (ctype_space($css[$i]) || $css[$i] === ';')) {
            $i++;
        }

        if ($i >= $end) {
            break;
        }

        if ($css[$i] === '@') {
            $i = css_parse_at_rule($css, $i, $end, $media, $rules);
            continue;
        }

        $brace = css_find_char($css, $i, $end, '{');

        if ($brace === null) {
            throw new RuntimeException('a rule has no declaration block: ' . trim(substr($css, $i, 60)));
        }

        $selector = trim(substr($css, $i, $brace - $i));
        $block    = css_read_block($css, $brace);
        $i        = $block['next'];

        if ($selector === '') {
            continue;
        }

        $rules[] = [
            'media'     => $media,
            'selector'  => $selector,
            'complexes' => css_parse_selector_list($selector),
            'decls'     => css_parse_declarations($block['text']),
            'order'     => count($rules),
        ];
    }
}

/**
 * Parse a stylesheet into a flat list of rules.
 *
 * @return list<array{media:?string,selector:string,complexes:list<array>,decls:array<string,array{value:string,important:bool}>,order:int}>
 */
function css_parse_stylesheet(string $css): array
{
    $rules = [];
    $css   = css_strip_comments($css);

    css_parse_rules($css, 0, strlen($css), null, $rules);

    return $rules;
}

// ---------------------------------------------------------------------------
// The media queries
// ---------------------------------------------------------------------------

/**
 * Split a media query on the "and" keyword, outside brackets.
 *
 * @return list<string>
 */
function css_split_on_and(string $query): array
{
    $parts = [];
    $buf   = '';
    $depth = 0;
    $len   = strlen($query);
    $i     = 0;

    while ($i < $len) {
        $ch = $query[$i];

        if ($ch === '(') {
            $depth++;
            $buf .= $ch;
            $i++;
            continue;
        }

        if ($ch === ')') {
            $depth--;
            $buf .= $ch;
            $i++;
            continue;
        }

        if ($depth === 0 && ctype_space($ch)) {
            $j = $i;

            while ($j < $len && ctype_space($query[$j])) {
                $j++;
            }

            if (substr(strtolower($query), $j, 3) === 'and') {
                $after = $j + 3;

                if ($after >= $len || ctype_space($query[$after])) {
                    $parts[] = trim($buf);
                    $buf     = '';
                    $i       = $after;
                    continue;
                }
            }

            $buf .= ' ';
            $i    = $j;
            continue;
        }

        $buf .= $ch;
        $i++;
    }

    $parts[] = trim($buf);

    return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
}

/**
 * Does one parenthesised media condition hold in the given environment?
 */
function css_media_condition_matches(string $condition, array $env): bool
{
    $matched = preg_match('/^\(\s*([a-zA-Z-]+)\s*(?::\s*(.*?)\s*)?\)$/s', $condition, $m);

    if ($matched !== 1) {
        throw new RuntimeException('unsupported media condition: ' . $condition);
    }

    $feature = strtolower($m[1]);
    $value   = strtolower(trim($m[2] ?? ''));
    $number  = (int) preg_replace('/[^0-9]/', '', $value);

    switch ($feature) {
        case 'prefers-color-scheme':
            return ($env['prefers-color-scheme'] ?? 'light') === $value;

        case 'prefers-reduced-motion':
            return ($env['prefers-reduced-motion'] ?? 'no-preference') === $value;

        case 'orientation':
            $landscape = (int) ($env['width'] ?? 0) >= (int) ($env['height'] ?? 0);

            return ($landscape ? 'landscape' : 'portrait') === $value;

        case 'min-width':
            return (int) ($env['width'] ?? 0) >= $number;

        case 'max-width':
            return (int) ($env['width'] ?? 0) <= $number;

        case 'min-height':
            return (int) ($env['height'] ?? 0) >= $number;

        case 'max-height':
            return (int) ($env['height'] ?? 0) <= $number;
    }

    throw new RuntimeException('unsupported media feature: ' . $feature);
}

/**
 * Does one comma-separated alternative of a media query hold?
 */
function css_media_alternative_matches(string $query, array $env): bool
{
    $query = trim($query);

    if ($query === '') {
        return true;
    }

    $lower = strtolower($query);

    if (str_starts_with($lower, 'not ') || str_starts_with($lower, 'only ')) {
        throw new RuntimeException('unsupported media query: ' . $query);
    }

    // A leading media type is a condition of its own. The two types that
    // make sense for a screen are "screen" and "all"; anything else (a
    // printer, a TV) is refused rather than ignored.
    if (preg_match('/^\s*([a-zA-Z-]+)\s+(.*)$/s', $query, $m) === 1 && ! str_contains($m[1], '(')) {
        $type = strtolower($m[1]);

        if ($type !== 'screen' && $type !== 'all') {
            throw new RuntimeException('unsupported media type: ' . $type);
        }

        $query = trim($m[2]);
    }

    foreach (css_split_on_and($query) as $condition) {
        if (! css_media_condition_matches($condition, $env)) {
            return false;
        }
    }

    return true;
}

/**
 * Does the media condition of a rule hold in the given environment?
 */
function css_media_matches(?string $media, array $env): bool
{
    if ($media === null || trim($media) === '') {
        return true;
    }

    foreach (css_split_top($media, ',') as $alternative) {
        if (css_media_alternative_matches(trim($alternative), $env)) {
            return true;
        }
    }

    return false;
}

// ---------------------------------------------------------------------------
// The selectors
// ---------------------------------------------------------------------------

/**
 * Read one simple selector.
 *
 * @return array{0:array,1:int} the simple selector and the offset after it
 */
function css_read_simple_selector(string $text, int $start): array
{
    $len = strlen($text);
    $ch  = $text[$start];

    if ($ch === '*') {
        return [['type', '*'], $start + 1];
    }

    if ($ch === '.' || $ch === '#') {
        $j = $start + 1;

        while ($j < $len && (ctype_alnum($text[$j]) || $text[$j] === '-' || $text[$j] === '_' || ord($text[$j]) > 127)) {
            $j++;
        }

        $name = substr($text, $start + 1, $j - $start - 1);

        if ($name === '') {
            throw new RuntimeException('a class or id selector has no name in: ' . $text);
        }

        return [[$ch === '.' ? 'class' : 'id', $name], $j];
    }

    if ($ch === '[') {
        // The closing bracket is found by counting, not by scanning for a
        // "]": the value of an attribute selector may itself hold brackets.
        $j     = $start + 1;
        $depth = 1;

        while ($j < $len) {
            $c = $text[$j];

            if ($c === '"' || $c === "'") {
                $j = css_skip_string($text, $j);
                continue;
            }

            if ($c === '[') {
                $depth++;
            } elseif ($c === ']') {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            }

            $j++;
        }

        if ($j >= $len) {
            throw new RuntimeException('an unterminated attribute selector: ' . $text);
        }

        $inner = substr($text, $start + 1, $j - $start - 1);

        return [['attrib', ...css_parse_attribute_selector($inner)], $j + 1];
    }

    if ($ch === ':') {
        $double = ($start + 1 < $len && $text[$start + 1] === ':');
        $j      = $start + ($double ? 2 : 1);
        $nameAt = $j;

        while ($j < $len && (ctype_alnum($text[$j]) || $text[$j] === '-')) {
            $j++;
        }

        $name = strtolower(substr($text, $nameAt, $j - $nameAt));

        if ($name === '') {
            throw new RuntimeException('a pseudo selector has no name in: ' . $text);
        }

        $args = null;

        if ($j < $len && $text[$j] === '(') {
            $close = css_find_matching_paren($text, $j);

            if ($close === null) {
                throw new RuntimeException('an unterminated pseudo argument in: ' . $text);
            }

            $args = substr($text, $j + 1, $close - $j - 1);
            $j    = $close + 1;
        }

        return [[$double ? 'pseudo-element' : 'pseudo', $name, $args], $j];
    }

    if (ctype_alpha($ch) || $ch === '-' || ord($ch) > 127) {
        $j = $start;

        while ($j < $len && (ctype_alnum($text[$j]) || $text[$j] === '-' || $text[$j] === '_' || ord($text[$j]) > 127)) {
            $j++;
        }

        return [['type', substr($text, $start, $j - $start)], $j];
    }

    throw new RuntimeException('unsupported selector syntax at "' . $ch . '" in: ' . $text);
}

/**
 * Parse the inside of an attribute selector.
 *
 * @return array{0:string,1:string,2:string,3:bool} the name, the operator,
 *         the value and whether the comparison is case-insensitive
 */
function css_parse_attribute_selector(string $inner): array
{
    $matched = preg_match(
        '/^\s*([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*(?:([~^$*|]?=)\s*(.*?)\s*(?:\s+([iIsS]))?)?\s*$/s',
        $inner,
        $m
    );

    if ($matched !== 1) {
        throw new RuntimeException('unsupported attribute selector: [' . $inner . ']');
    }

    $name  = $m[1];
    $op    = $m[2] ?? '';
    $value = $m[3] ?? '';

    if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
        $value = substr($value, 1, -1);
    }

    return [$name, $op, $value, strtolower($m[4] ?? '') === 'i'];
}

/**
 * Parse a complex selector — a sequence of compounds and combinators.
 *
 * @return array{parts:list<array{combinator:string,compound:list<array>}>,specificity:array{int,int,int}}
 */
function css_parse_complex(string $text): array
{
    $parts   = [];
    $current = [];
    $link    = ' ';
    $len     = strlen($text);
    $i       = 0;

    while ($i < $len) {
        $ch = $text[$i];

        if (ctype_space($ch)) {
            $j = $i;

            while ($j < $len && ctype_space($text[$j])) {
                $j++;
            }

            if ($j >= $len) {
                break;
            }

            $next = $text[$j];

            if ($next === '>' || $next === '+' || $next === '~') {
                // The whitespace is padding in front of the combinator.
                $i = $j;
                continue;
            }

            if ($current === []) {
                $i = $j;
                continue;
            }

            $parts[] = ['combinator' => $link, 'compound' => $current];
            $current = [];
            $link    = ' ';
            $i       = $j;
            continue;
        }

        if ($ch === '>' || $ch === '+' || $ch === '~') {
            if ($current === []) {
                throw new RuntimeException('a combinator has no left-hand side in: ' . $text);
            }

            $parts[] = ['combinator' => $link, 'compound' => $current];
            $current = [];
            $link    = $ch;
            $i++;
            continue;
        }

        [$simple, $i] = css_read_simple_selector($text, $i);
        $current[]    = $simple;
    }

    if ($current !== []) {
        $parts[] = ['combinator' => $link, 'compound' => $current];
    }

    if ($parts === []) {
        throw new RuntimeException('an empty selector: ' . $text);
    }

    return ['parts' => $parts, 'specificity' => css_specificity($parts)];
}

/**
 * Parse a selector list — the comma-separated form.
 *
 * @return list<array{parts:list<array>,specificity:array{int,int,int}}>
 */
function css_parse_selector_list(string $selector): array
{
    $complexes = [];

    foreach (css_split_top($selector, ',') as $one) {
        $one = trim($one);

        if ($one === '') {
            continue;
        }

        $complexes[] = css_parse_complex($one);
    }

    return $complexes;
}

/**
 * The specificity of a parsed selector: (ids, classes, types).
 *
 * @return array{int,int,int}
 */
function css_specificity(array $parts): array
{
    $a = 0;
    $b = 0;
    $c = 0;

    foreach ($parts as $part) {
        foreach ($part['compound'] as $simple) {
            switch ($simple[0]) {
                case 'id':
                    $a++;
                    break;

                case 'class':
                case 'attrib':
                    $b++;
                    break;

                case 'type':
                case 'pseudo-element':
                    $c++;
                    break;

                case 'pseudo':
                    if ($simple[1] === 'not') {
                        // :not() takes the specificity of its argument.
                        $inner = [0, 0, 0];

                        foreach (css_parse_selector_list((string) $simple[2]) as $complex) {
                            if (css_compare_specificity($complex['specificity'], $inner) > 0) {
                                $inner = $complex['specificity'];
                            }
                        }

                        $a += $inner[0];
                        $b += $inner[1];
                        $c += $inner[2];
                        break;
                    }

                    $b++;
                    break;

                default:
                    throw new RuntimeException('unsupported simple selector: ' . $simple[0]);
            }
        }
    }

    return [$a, $b, $c];
}

/**
 * Compare two specificities.
 */
function css_compare_specificity(array $a, array $b): int
{
    for ($i = 0; $i < 3; $i++) {
        if ($a[$i] !== $b[$i]) {
            return $a[$i] <=> $b[$i];
        }
    }

    return 0;
}

// ---------------------------------------------------------------------------
// The matcher
// ---------------------------------------------------------------------------

/**
 * Does the element carry the class?
 */
function css_has_class(DOMElement $el, string $name): bool
{
    $class = $el->getAttribute('class');

    if ($class === '') {
        return false;
    }

    return preg_match('/(?:^|\s)' . preg_quote($name, '/') . '(?:\s|$)/', $class) === 1;
}

/**
 * Does the element satisfy the attribute selector?
 */
function css_matches_attribute(DOMElement $el, array $simple): bool
{
    // The simple selector carries its type tag at index 0, so the name,
    // the operator, the value and the case flag start at index 1.
    [, $name, $op, $value, $caseInsensitive] = $simple;

    if (! $el->hasAttribute($name)) {
        return false;
    }

    if ($op === '') {
        return true;
    }

    $actual = $el->getAttribute($name);
    $a      = $caseInsensitive ? strtolower($actual) : $actual;
    $b      = $caseInsensitive ? strtolower($value) : $value;

    switch ($op) {
        case '=':
            return $a === $b;

        case '~=':
            return $b !== '' && in_array($b, preg_split('/\s+/', trim($a)), true);

        case '|=':
            return $a === $b || str_starts_with($a, $b . '-');

        case '^=':
            return $b !== '' && str_starts_with($a, $b);

        case '$=':
            return $b !== '' && str_ends_with($a, $b);

        case '*=':
            return $b !== '' && str_contains($a, $b);
    }

    throw new RuntimeException('unsupported attribute operator: ' . $op);
}

/**
 * The 1-based index of the element among its element siblings.
 */
function css_sibling_index(DOMElement $el): int
{
    $index = 1;
    $node  = $el->previousElementSibling;

    while ($node instanceof DOMElement) {
        $index++;
        $node = $node->previousElementSibling;
    }

    return $index;
}

/**
 * How many element siblings share the element's tag name?
 */
function css_type_sibling_count(DOMElement $el): int
{
    $count = 0;
    $node  = $el->parentNode?->firstChild;

    while ($node !== null) {
        if ($node instanceof DOMElement && strtolower($node->tagName) === strtolower($el->tagName)) {
            $count++;
        }

        $node = $node->nextSibling;
    }

    return $count;
}

/**
 * Does the element satisfy the pseudo-class?
 */
function css_matches_pseudo(DOMElement $el, array $simple): bool
{
    $name = $simple[1];
    $args = $simple[2];

    switch ($name) {
        case 'root':
            $doc = $el->ownerDocument;

            return $doc !== null && $doc->documentElement === $el;

        case 'not':
            foreach (css_parse_selector_list((string) $args) as $complex) {
                if (css_matches_complex($el, $complex)) {
                    return false;
                }
            }

            return true;

        case 'first-child':
            return $el->previousElementSibling === null;

        case 'last-child':
            return $el->nextElementSibling === null;

        case 'only-child':
            return $el->previousElementSibling === null && $el->nextElementSibling === null;

        case 'nth-child':
            $index = css_sibling_index($el);
            $arg   = strtolower(trim((string) $args));

            if ($arg === 'odd') {
                return $index % 2 === 1;
            }

            if ($arg === 'even') {
                return $index % 2 === 0;
            }

            if (preg_match('/^\d+$/', $arg) === 1) {
                return $index === (int) $arg;
            }

            throw new RuntimeException('unsupported :nth-child() argument: ' . $arg);

        case 'first-of-type':
            return css_sibling_index_of_type($el) === 1;

        case 'last-of-type':
            return css_sibling_index_of_type($el) === css_type_sibling_count($el);

        case 'checked':
            return $el->hasAttribute('checked');

        case 'disabled':
            return $el->hasAttribute('disabled');

        case 'enabled':
            return ! $el->hasAttribute('disabled');

        case 'empty':
            return $el->childNodes->length === 0;

        case 'hover':
        case 'focus':
        case 'focus-visible':
        case 'focus-within':
        case 'active':
        case 'target':
        case 'visited':
        case 'link':
            // A live state. A static resolution describes the resting state,
            // so these never match.
            return false;
    }

    throw new RuntimeException('unsupported pseudo-class: :' . $name);
}

/**
 * The 1-based index of the element among the siblings of its own type.
 */
function css_sibling_index_of_type(DOMElement $el): int
{
    $index = 1;
    $node  = $el->previousElementSibling;

    while ($node instanceof DOMElement) {
        if (strtolower($node->tagName) === strtolower($el->tagName)) {
            $index++;
        }

        $node = $node->previousElementSibling;
    }

    return $index;
}

/**
 * Does the element satisfy the compound selector?
 */
function css_matches_compound(DOMElement $el, array $compound): bool
{
    $tag = strtolower($el->tagName);

    foreach ($compound as $simple) {
        switch ($simple[0]) {
            case 'type':
                if ($simple[1] !== '*' && strtolower($simple[1]) !== $tag) {
                    return false;
                }
                break;

            case 'class':
                if (! css_has_class($el, $simple[1])) {
                    return false;
                }
                break;

            case 'id':
                if (strtolower($el->getAttribute('id')) !== strtolower($simple[1])) {
                    return false;
                }
                break;

            case 'attrib':
                if (! css_matches_attribute($el, $simple)) {
                    return false;
                }
                break;

            case 'pseudo-element':
                // A pseudo-element styles a generated box, not the element.
                return false;

            case 'pseudo':
                if (! css_matches_pseudo($el, $simple)) {
                    return false;
                }
                break;

            default:
                throw new RuntimeException('unsupported simple selector: ' . $simple[0]);
        }
    }

    return true;
}

/**
 * Do the parts to the LEFT of the already-matched part match?
 *
 * @param list<array{combinator:string,compound:list<array>}> $parts
 */
function css_matches_ancestors(DOMElement $el, array $parts, int $index): bool
{
    if ($index < 0) {
        return true;
    }

    $combinator = $parts[$index + 1]['combinator'];
    $target     = $parts[$index]['compound'];

    switch ($combinator) {
        case ' ':
            $parent = $el->parentNode;

            while ($parent instanceof DOMElement) {
                if (css_matches_compound($parent, $target) && css_matches_ancestors($parent, $parts, $index - 1)) {
                    return true;
                }

                $parent = $parent->parentNode;
            }

            return false;

        case '>':
            $parent = $el->parentNode;

            if (! ($parent instanceof DOMElement)) {
                return false;
            }

            return css_matches_compound($parent, $target)
                && css_matches_ancestors($parent, $parts, $index - 1);

        case '+':
            $sibling = $el->previousElementSibling;

            if (! ($sibling instanceof DOMElement)) {
                return false;
            }

            return css_matches_compound($sibling, $target)
                && css_matches_ancestors($sibling, $parts, $index - 1);

        case '~':
            $sibling = $el->previousElementSibling;

            while ($sibling instanceof DOMElement) {
                if (css_matches_compound($sibling, $target) && css_matches_ancestors($sibling, $parts, $index - 1)) {
                    return true;
                }

                $sibling = $sibling->previousElementSibling;
            }

            return false;
    }

    throw new RuntimeException('unsupported combinator: ' . $combinator);
}

/**
 * Does the complex selector match the element?
 */
function css_matches_complex(DOMElement $el, array $complex): bool
{
    $parts     = $complex['parts'];
    $lastIndex = count($parts) - 1;

    if (! css_matches_compound($el, $parts[$lastIndex]['compound'])) {
        return false;
    }

    // The parts are NOT popped: the combinator that links a part to the one
    // on its right lives in the RIGHT-hand part, so the indices have to
    // stay where they are.
    return css_matches_ancestors($el, $parts, $lastIndex - 1);
}

// ---------------------------------------------------------------------------
// The cascade
// ---------------------------------------------------------------------------

/**
 * The declarations that apply to an element, after the cascade.
 *
 * @return array<string,array{value:string,important:bool,specificity:array{int,int,int},order:int}>
 */
function css_cascade(array $rules, DOMElement $el, array $env): array
{
    $winners = [];

    foreach ($rules as $rule) {
        if (! css_media_matches($rule['media'], $env)) {
            continue;
        }

        foreach ($rule['complexes'] as $complex) {
            if (! css_matches_complex($el, $complex)) {
                continue;
            }

            foreach ($rule['decls'] as $prop => $decl) {
                $candidate = [
                    'value'       => $decl['value'],
                    'important'   => $decl['important'],
                    'specificity' => $complex['specificity'],
                    'order'       => $rule['order'],
                ];

                $current = $winners[$prop] ?? null;

                if ($current === null || css_candidate_wins($candidate, $current)) {
                    $winners[$prop] = $candidate;
                }
            }
        }
    }

    return $winners;
}

/**
 * Does the candidate displace the incumbent in the cascade?
 */
function css_candidate_wins(array $candidate, array $incumbent): bool
{
    if ($candidate['important'] !== $incumbent['important']) {
        return $candidate['important'];
    }

    $bySpecificity = css_compare_specificity($candidate['specificity'], $incumbent['specificity']);

    if ($bySpecificity !== 0) {
        return $bySpecificity > 0;
    }

    return $candidate['order'] > $incumbent['order'];
}

/**
 * The effective custom properties on an element.
 *
 * Custom properties are inherited, so the element's own declarations win
 * over the ones it inherits.
 *
 * @return array<string,string>
 */
function css_custom_properties(array $rules, DOMElement $el, array $env): array
{
    $inherited = [];
    $parent    = $el->parentNode;

    if ($parent instanceof DOMElement) {
        $inherited = css_custom_properties($rules, $parent, $env);
    }

    $own = [];

    foreach (css_cascade($rules, $el, $env) as $prop => $decl) {
        if (str_starts_with($prop, '--')) {
            $own[$prop] = $decl['value'];
        }
    }

    return array_merge($inherited, $own);
}

/**
 * Substitute the var() references in a value.
 *
 * Returns null when the value is invalid at computed-value time — an
 * undefined custom property with no fallback — because that is what a
 * browser does: the declaration is dropped.
 */
function css_substitute_var(string $value, array $customProps, array $seen): ?string
{
    $out = '';
    $i   = 0;
    $len = strlen($value);

    while ($i < $len) {
        $pos = strpos($value, 'var(', $i);

        if ($pos === false) {
            $out .= substr($value, $i);
            break;
        }

        $out  .= substr($value, $i, $pos - $i);
        $close = css_find_matching_paren($value, $pos + 3);

        if ($close === null) {
            throw new RuntimeException('an unterminated var() in: ' . $value);
        }

        $inner  = substr($value, $pos + 4, $close - $pos - 4);
        $parts  = css_split_top($inner, ',');
        $name   = trim($parts[0]);
        $hasFallback = count($parts) > 1;
        $fallback    = $hasFallback ? trim(implode(',', array_slice($parts, 1))) : null;

        if (preg_match('/^--[a-zA-Z0-9_-]+$/', $name) !== 1) {
            throw new RuntimeException('a var() without a custom-property name: ' . $value);
        }

        if (array_key_exists($name, $customProps)) {
            if (isset($seen[$name])) {
                throw new RuntimeException('a cyclic custom property: ' . $name);
            }

            $seen[$name] = true;
            $resolved    = css_resolve_value($customProps[$name], $customProps, $seen);
            unset($seen[$name]);

            if ($resolved === null) {
                return null;
            }

            $out .= $resolved;
        } elseif ($fallback !== null) {
            $resolved = css_resolve_value($fallback, $customProps, $seen);

            if ($resolved === null) {
                return null;
            }

            $out .= $resolved;
        } else {
            return null;
        }

        $i = $close + 1;
    }

    return $out;
}

/**
 * Resolve the var() references in a declared value.
 */
function css_resolve_value(string $value, array $customProps, array $seen = []): ?string
{
    if (! str_contains($value, 'var(')) {
        return trim($value);
    }

    return css_substitute_var($value, $customProps, $seen);
}

// ---------------------------------------------------------------------------
// The public surface
// ---------------------------------------------------------------------------

/**
 * Parse an HTML document.
 */
function css_parse_html(string $html): DOMDocument
{
    $doc = new DOMDocument();

    $previous = libxml_use_internal_errors(true);

    // The encoding declaration goes in front: without it libxml guesses,
    // and a guess would mangle the attribute values the matcher reads.
    $doc->loadHTML(
        '<?xml encoding="UTF-8">' . $html,
        LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
    );

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $doc;
}

/**
 * The first element the selector matches, or null.
 */
function css_find_element(DOMDocument $doc, string $selector): ?DOMElement
{
    $complexes = css_parse_selector_list($selector);
    $nodes     = $doc->getElementsByTagName('*');

    foreach ($nodes as $node) {
        if (! ($node instanceof DOMElement)) {
            continue;
        }

        foreach ($complexes as $complex) {
            if (css_matches_complex($node, $complex)) {
                return $node;
            }
        }
    }

    return null;
}

/**
 * Is the property inherited?
 *
 * Only the properties the application actually inherits are listed. The
 * list is deliberately short: a property that is not on it is treated as
 * not inherited, which is the right answer for the overwhelming majority
 * of them.
 */
function css_is_inherited_property(string $prop): bool
{
    return in_array($prop, [
        'border-collapse',
        'border-spacing',
        'caption-side',
        'color',
        'cursor',
        'direction',
        'empty-cells',
        'font',
        'font-family',
        'font-size',
        'font-style',
        'font-variant',
        'font-weight',
        'letter-spacing',
        'line-height',
        'list-style',
        'list-style-position',
        'list-style-type',
        'orphans',
        'quotes',
        'tab-size',
        'text-align',
        'text-indent',
        'text-shadow',
        'text-transform',
        'visibility',
        'white-space',
        'widows',
        'word-spacing',
    ], true);
}

/**
 * The computed value of a property on an element.
 *
 * The value is what the property resolves to AFTER the cascade, the
 * inheritance of the custom properties and the substitution of the var()
 * references. It is null when the element does not declare the property
 * and the property is not inherited.
 */
function css_computed_value(array $rules, DOMElement $el, string $prop, array $env): ?string
{
    $candidates = css_cascade($rules, $el, $env);

    if (! isset($candidates[$prop])) {
        // An inherited property the element does not declare takes the
        // value of the parent — which is what makes the `color` of a <p>
        // the colour of the body.
        if (css_is_inherited_property($prop)) {
            $parent = $el->parentNode;

            if ($parent instanceof DOMElement) {
                return css_computed_value($rules, $parent, $prop, $env);
            }
        }

        return null;
    }

    return css_resolve_value($candidates[$prop]['value'], css_custom_properties($rules, $el, $env));
}

/**
 * Every computed declaration on an element.
 *
 * @return array<string,string>
 */
function css_computed_declarations(array $rules, DOMElement $el, array $env): array
{
    $custom = css_custom_properties($rules, $el, $env);
    $out    = [];

    foreach (css_cascade($rules, $el, $env) as $prop => $decl) {
        $resolved = css_resolve_value($decl['value'], $custom);

        if ($resolved !== null) {
            $out[$prop] = $resolved;
        }
    }

    return $out;
}
