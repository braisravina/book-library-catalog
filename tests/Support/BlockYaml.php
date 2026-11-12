<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

/**
 * Strict reader for the block-style YAML subset used by the Compose files
 * and the CI workflow, so their structure can be asserted without a YAML
 * extension. Supported: block mappings, block sequences (of scalars or of
 * mappings, "- key: value"), plain and quoted scalars, and literal block
 * scalars ("|" keeps one final newline, "|-" none). It refuses (rather than
 * guesses at) anything else: anchors, aliases, merge keys, tags, flow
 * collections, folded scalars, tabs, duplicate keys.
 * Scalars stay strings (`true` is "true"); empty values are null.
 */
final class BlockYaml
{
    /** @var list<array{int, string, int}> indent, content, line number */
    private array $lines = [];
    /** @var list<string> */
    private array $raw = [];
    private int $pos = 0;

    /**
     * @return array<mixed>
     */
    public static function parseFile(string $path): array
    {
        $parser = new self((string) file_get_contents($path));
        $value = $parser->block(0);
        if ($parser->pos !== count($parser->lines)) {
            $parser->fail('unexpected indentation');
        }

        return is_array($value) ? $value : [];
    }

    private function __construct(string $source)
    {
        $this->raw = explode("\n", $source);
        foreach ($this->raw as $i => $raw) {
            if (str_contains($raw, "\t")) {
                throw new \UnexpectedValueException('Tab at line ' . ($i + 1));
            }
            $content = self::stripComment($raw);
            if (trim($content) === '') {
                continue;
            }
            $this->lines[] = [strlen($content) - strlen(ltrim($content)), rtrim(ltrim($content)), $i + 1];
        }
    }

    /**
     * @return array<mixed>|string|null
     */
    private function block(int $indent): array|string|null
    {
        if (!isset($this->lines[$this->pos]) || $this->lines[$this->pos][0] < $indent) {
            return null;
        }
        $indent = $this->lines[$this->pos][0];
        $isList = str_starts_with($this->lines[$this->pos][1], '- ') || $this->lines[$this->pos][1] === '-';
        $result = [];
        while (isset($this->lines[$this->pos]) && $this->lines[$this->pos][0] === $indent) {
            [, $content] = $this->lines[$this->pos];
            if ($isList) {
                if (!str_starts_with($content, '- ')) {
                    $this->fail('expected a list item');
                }
                $item = substr($content, 2);
                if (preg_match('/^[^"\'\s]\S*:(\s|$)/', $item) === 1) {
                    // "- key: value": a mapping whose keys are aligned after the dash.
                    $this->lines[$this->pos] = [$indent + 2, $item, $this->lines[$this->pos][2]];
                    $result[] = $this->block($indent + 2);
                    continue;
                }
                $result[] = $this->scalar($item);
                $this->pos++;
                continue;
            }
            if (preg_match('/^("[^"]*"|\'[^\']*\'|[^\s"\'#][^:]*?):(?:\s+(.*))?$/', $content, $m) !== 1) {
                $this->fail('expected "key: value"');
            }
            $key = $this->scalar($m[1]);
            if ($key === '<<' || array_key_exists($key, $result)) {
                $this->fail('merge key or duplicate key "' . $key . '"');
            }
            $value = $m[2] ?? '';
            if ($value === '|' || $value === '|-') {
                $line = $this->lines[$this->pos][2];
                $this->pos++;
                $result[$key] = $this->literal($indent, $line, $value === '|-');
                continue;
            }
            $inline = $value === '' ? null : $this->scalar($value);
            $this->pos++;
            $result[$key] = $inline ?? $this->block($indent + 1);
        }
        if (isset($this->lines[$this->pos]) && $this->lines[$this->pos][0] > $indent) {
            $this->fail('unexpected indentation');
        }

        return $result;
    }

    /**
     * Literal block scalar following the key on line $line (1-based): every
     * following raw line that is blank or indented deeper than the key,
     * comments included, dedented by the first content line's indentation.
     */
    private function literal(int $keyIndent, int $line, bool $strip): string
    {
        $out = [];
        $blockIndent = null;
        for ($i = $line; $i < count($this->raw); $i++) {
            $raw = $this->raw[$i];
            if (trim($raw) === '') {
                $out[] = '';
                continue;
            }
            $indent = strlen($raw) - strlen(ltrim($raw, ' '));
            if ($indent <= $keyIndent) {
                break;
            }
            $blockIndent ??= $indent;
            if ($indent < $blockIndent) {
                throw new \UnexpectedValueException('Literal block less indented than its first line at line ' . ($i + 1));
            }
            $out[] = rtrim(substr($raw, $blockIndent));
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }
        if ($out === []) {
            throw new \UnexpectedValueException('Empty literal block at line ' . $line);
        }
        // Skip the parsed lines that belong to the block.
        while (isset($this->lines[$this->pos]) && $this->lines[$this->pos][2] <= $i) {
            $this->pos++;
        }

        return implode("\n", $out) . ($strip ? '' : "\n");
    }

    private function scalar(string $text): string
    {
        $text = trim($text);
        if ($text === '' || strpbrk($text[0], '&*!|>[{%@`') !== false) {
            $this->fail('unsupported value syntax');
        }
        if ($text[0] === '"') {
            if (preg_match('/^"((?:[^"\\\\]|\\\\["\\\\])*)"$/', $text, $m) !== 1) {
                $this->fail('malformed double-quoted string');
            }

            return (string) preg_replace('/\\\\(["\\\\])/', '$1', $m[1]);
        }
        if ($text[0] === "'") {
            if (preg_match("/^'((?:[^']|'')*)'$/", $text, $m) !== 1) {
                $this->fail('malformed single-quoted string');
            }

            return str_replace("''", "'", $m[1]);
        }
        if (preg_match('/:\s|\s#/', $text) === 1) {
            $this->fail('plain scalar contains ": " or " #"; quote it');
        }

        return $text;
    }

    private static function stripComment(string $line): string
    {
        $quote = null;
        for ($i = 0, $n = strlen($line); $i < $n; $i++) {
            $c = $line[$i];
            if ($quote !== null) {
                $quote = $c === $quote ? null : $quote;
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '#' && ($i === 0 || $line[$i - 1] === ' ')) {
                return substr($line, 0, $i);
            }
        }

        return $line;
    }

    private function fail(string $message): never
    {
        throw new \UnexpectedValueException($message . ' at line ' . ($this->lines[$this->pos][2] ?? 'EOF'));
    }
}
