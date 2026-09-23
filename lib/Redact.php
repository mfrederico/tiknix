<?php
/**
 * Redact — what may leave the install through an observation tool.
 *
 * The observation tools (last_error, read_log_entries, database_query) hand text to an agent
 * that may be a jailed subprocess with no identity. Two things must never be in that text:
 * credential-shaped strings (ConceptLint::SECRET_PATTERNS — the same shapes the catalog
 * linter refuses to publish) and the columns a schema keeps secrets in. Redaction is
 * visible — "[redacted]" and "[redacted <what>]" — so a reader knows a value was there and
 * was withheld, which is a different fact from the value being empty.
 */

namespace app;

class Redact {

    public const MARK = '[redacted]';

    /** Column names whose values never leave: credentials, hashes, second factors, encrypted blobs. */
    public const SENSITIVE_COLUMN_RE = '/(password|passwd|secret|token|api_key|apikey|key_hash|_enc$|^enc_|hash|totp|recovery|credential|private_key|salt)/i';

    /** Replace every credential-shaped substring with a labelled marker. */
    public static function secrets(string $text): string {
        foreach (ConceptLint::SECRET_PATTERNS as $what => $re) {
            $text = preg_replace($re, '[redacted ' . $what . ']', $text) ?? $text;
        }
        return $text;
    }

    public static function isSensitiveColumn(string $column): bool {
        return (bool) preg_match(self::SENSITIVE_COLUMN_RE, $column);
    }

    /**
     * A result row with sensitive columns withheld and secret shapes scrubbed from the rest.
     * Withholding is by column NAME, not by value: a hash that happens to look harmless is
     * still a hash. NULL and '' stay as they are — an empty secret is information too.
     */
    public static function row(array $row): array {
        foreach ($row as $col => $v) {
            if (self::isSensitiveColumn((string) $col)) {
                if ($v !== null && $v !== '') $row[$col] = self::MARK;
            } elseif (is_string($v)) {
                $row[$col] = self::secrets($v);
            }
        }
        return $row;
    }
}
