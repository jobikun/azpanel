<?php
declare(strict_types=1);

namespace app\service;

/**
 * Extract AWS account credentials from loosely formatted text.
 *
 * The three fields the panel actually needs have fixed, self-identifying shapes,
 * so they are recognised by type rather than by position or delimiter:
 *   - email       standard address
 *   - access key  exactly 20 chars, AKIA/ASIA/... prefix + 16 [A-Z0-9]
 *   - secret key  exactly 40 base64 chars
 *
 * Password is optional (the server never validates it) and is recovered on a
 * best-effort basis. Everything else on a line — MFA/base32 seeds, region,
 * limits, country, notes — is ignored and never mistaken for a secret key.
 *
 * Any delimiter works (~ | ; , tab, runs of spaces), fields may appear in any
 * order, labelled or bare, one account per line or one field per line. Only
 * complete accounts (email + AK + SK) are returned; ambiguous or half-filled
 * input is dropped rather than guessed at.
 */
class AwsCredentialParser
{
    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    private const ACCESS_KEY_PATTERN = '/\b(?:AKIA|ASIA|AIDA|AROA|AIPA|ANPA|ANVA|AGPA)[A-Z0-9]{16}\b/';
    private const SECRET_KEY_PATTERN = '/^[A-Za-z0-9+\/=]{40}$/';
    // Base32 seeds (MFA/TOTP and similar) must never be read as a secret key.
    private const BASE32_PATTERN = '/^[A-Z2-7]{16,}$/';

    private const EMAIL_LABELS = ['e-?mail', '邮箱', '账号', '账户'];
    private const ACCESS_LABELS = ['access[\s_-]*key(?:[\s_-]*id)?', 'aws[\s_-]*ak', 'accesskey', '访问密钥(?:id)?', 'ak'];
    private const SECRET_LABELS = ['secret[\s_-]*(?:access[\s_-]*)?key', 'aws[\s_-]*sk', 'secretkey', '秘密访问密钥', '私密密钥', 'sk', 'secret'];
    private const PASSWORD_LABELS = ['password', 'passwd', 'pass', '登录密码', '密码'];

    // Tokens that are clearly a labelled field name / status, never a password.
    private const PASSWORD_STOP_WORDS = [
        'mfa', '2fa', 'otp', 'totp', 'note', 'notes', 'remark', 'remarks',
        'country', 'region', 'regions', 'status', 'limit', 'limits', 'quota',
        'enabled', 'disabled', 'enable', 'disable', 'active', 'inactive',
        'null', 'none', 'true', 'false', 'account', 'email', 'mail',
        'password', 'passwd', 'pass', 'secret', 'key', 'access', 'aws',
    ];

    public static function parse(string $text): array
    {
        // Strip a leading UTF-8 BOM before trimming (a copy/paste artefact).
        $text = preg_replace('/^\x{FEFF}/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return [];
        }

        $records = [];
        $current = self::emptyRecord();

        foreach (explode("\n", $text) as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $fields = self::extractFields($line);

            // Account boundary: a field that is already filled arriving with a
            // different value means we have moved on to the next account.
            foreach (['email', 'ak', 'sk'] as $key) {
                if ($fields[$key] !== '' && $current[$key] !== '' && $fields[$key] !== $current[$key]) {
                    $records[] = $current;
                    $current = self::emptyRecord();
                    break;
                }
            }

            foreach (['email', 'ak', 'sk'] as $key) {
                if ($fields[$key] !== '') {
                    $current[$key] = $fields[$key];
                }
            }

            // Password is low priority: a label wins outright, otherwise take a
            // packed line's leftover token, or a lone token sitting between the
            // email and the keys.
            if ($fields['passwd'] !== '') {
                $current['passwd'] = $fields['passwd'];
            } elseif ($current['passwd'] === '') {
                if ($fields['pw_packed'] !== '') {
                    $current['passwd'] = $fields['pw_packed'];
                } elseif ($fields['pw_lone'] !== '' && $current['email'] !== '' && $current['ak'] === '') {
                    $current['passwd'] = $fields['pw_lone'];
                }
            }
        }

        if (array_filter($current, static fn ($value) => $value !== '')) {
            $records[] = $current;
        }

        // Backwards compatibility: four unlabelled lines per account.
        if (empty($records) || !self::hasAnyCompleteRecord($records)) {
            $legacy = self::parseLegacyFourLineFormat($text);
            if (!empty($legacy)) {
                return $legacy;
            }
        }

        // Only surface fully-formed accounts. Stray emails or half-filled
        // fragments left over from the surrounding noise are dropped here so
        // they cannot turn into a phantom account that fails the whole import.
        return array_values(array_filter($records, static fn ($record) => self::isCompleteRecord($record)));
    }

    /**
     * Pull every credential field a single line carries, identifying the three
     * fixed-shape fields by type in any order, labelled or bare.
     */
    private static function extractFields(string $line): array
    {
        $out = self::emptyRecord();
        $out['pw_packed'] = '';
        $out['pw_lone'] = '';

        $tokens = self::splitFields($line);
        $packed = self::isPackedRecord($line, $tokens);

        // --- email --------------------------------------------------------
        $email = self::labeledValue($line, self::EMAIL_LABELS) ?? self::labeledFromTokens($tokens, self::EMAIL_LABELS);
        if ($email === null && preg_match(self::EMAIL_PATTERN, $line, $match)) {
            $email = $match[0];
        }
        if ($email !== null && preg_match(self::EMAIL_PATTERN, $email, $match)) {
            $out['email'] = $match[0];
        }

        // --- access key ---------------------------------------------------
        $accessKey = self::labeledValue($line, self::ACCESS_LABELS) ?? self::labeledFromTokens($tokens, self::ACCESS_LABELS);
        if (($accessKey === null || !preg_match(self::ACCESS_KEY_PATTERN, strtoupper($accessKey)))
            && preg_match(self::ACCESS_KEY_PATTERN, strtoupper($line), $match)) {
            $accessKey = $match[0];
        }
        if ($accessKey !== null && preg_match(self::ACCESS_KEY_PATTERN, strtoupper($accessKey), $match)) {
            $out['ak'] = $match[0];
        }

        // --- secret key ---------------------------------------------------
        $secretKey = self::labeledValue($line, self::SECRET_LABELS) ?? self::labeledFromTokens($tokens, self::SECRET_LABELS);
        if ($secretKey !== null) {
            $candidate = self::cleanValue($secretKey);
            if (preg_match(self::SECRET_KEY_PATTERN, $candidate)) {
                $out['sk'] = $candidate;
            }
        }
        if ($out['sk'] === '') {
            $out['sk'] = self::pickSecret($tokens, $out['ak']);
        }

        // --- password (optional) ------------------------------------------
        $password = self::labeledValue($line, self::PASSWORD_LABELS) ?? self::labeledFromTokens($tokens, self::PASSWORD_LABELS);
        if ($password !== null) {
            $out['passwd'] = self::cleanValue($password);
        } else {
            if ($packed) {
                $candidates = [];
                foreach ($tokens as $token) {
                    if (self::isPasswordCandidate($token)) {
                        $candidate = self::cleanValue($token);
                        $candidates[$candidate] = $candidate;
                    }
                }
                // Password has no self-identifying shape. Guess only when the
                // packed record leaves exactly one plausible bare value.
                if (count($candidates) === 1) {
                    $out['pw_packed'] = array_values($candidates)[0];
                }
            } elseif (count($tokens) === 1 && self::isPasswordCandidate($tokens[0])) {
                $out['pw_lone'] = self::cleanValue($tokens[0]);
            }
        }

        return $out;
    }

    /**
     * Choose the secret key among a line's bare tokens. A candidate must be
     * exactly 40 base64 chars, not the access key, and not a base32 seed (an MFA
     * secret is base32 and must never be read as a secret key). Selection is only
     * made when it is unambiguous: if several distinct 40-char tokens qualify we
     * return nothing and leave the record incomplete for the user to resolve,
     * rather than silently guessing a wrong key.
     */
    private static function pickSecret(array $tokens, string $accessKey): string
    {
        $candidates = [];
        foreach ($tokens as $token) {
            $candidate = self::cleanValue($token);
            if (!preg_match(self::SECRET_KEY_PATTERN, $candidate)) {
                continue;
            }
            if (preg_match(self::BASE32_PATTERN, $candidate)) {
                continue;
            }
            if (strtoupper($candidate) === $accessKey || preg_match(self::ACCESS_KEY_PATTERN, strtoupper($candidate))) {
                continue;
            }
            $candidates[$candidate] = true;
        }

        return count($candidates) === 1 ? array_key_first($candidates) : '';
    }

    private static function isPasswordCandidate(string $token): bool
    {
        $candidate = self::cleanValue($token);
        if (mb_strlen($candidate) < 4) {
            return false;
        }
        // A token carrying its own "name: value" / "name=value" pairing is a
        // labelled field for something else (country, region, note, ...), not a
        // bare password — never lift it as the password. (A purely numeric token
        // is allowed: login passwords may be all digits.)
        if (strpbrk($candidate, ':：=') !== false) {
            return false;
        }
        if (preg_match(self::EMAIL_PATTERN, $candidate)
            || preg_match(self::ACCESS_KEY_PATTERN, strtoupper($candidate))
            || preg_match(self::SECRET_KEY_PATTERN, $candidate)
            || preg_match(self::BASE32_PATTERN, $candidate)) {
            return false;
        }

        return !in_array(strtolower($candidate), self::PASSWORD_STOP_WORDS, true);
    }

    private static function labeledValue(string $line, array $labels): ?string
    {
        $labelPattern = implode('|', $labels);
        // The value stops at the next field delimiter so a packed line such as
        // "secret=<sk>, ak=<ak>" does not swallow everything after the first field.
        if (preg_match('/^\s*(?:["\']?\s*(?:' . $labelPattern . ')\s*["\']?)\s*[:：=|\-]\s*([^,;|\t~]+?)\s*[,;]?\s*$/iu', $line, $match)) {
            return self::cleanValue($match[1]);
        }

        return null;
    }

    private static function labeledFromTokens(array $tokens, array $labels): ?string
    {
        foreach ($tokens as $token) {
            $value = self::labeledValue($token, $labels);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * A line is a packed record when it uses an explicit delimiter, or when it
     * carries an email together with an access key on the same line.
     */
    private static function isPackedRecord(string $line, array $tokens): bool
    {
        if (preg_match('/[~|;,\t]|\s{2,}/u', $line)) {
            return true;
        }
        if (count($tokens) < 2) {
            return false;
        }

        return (bool) (preg_match(self::EMAIL_PATTERN, $line) && preg_match(self::ACCESS_KEY_PATTERN, strtoupper($line)));
    }

    private static function splitFields(string $line): array
    {
        if (preg_match('/[~|;,\t]|\s{2,}/u', $line)) {
            $parts = preg_split('/\s*[~|;,\t]\s*|\s{2,}/u', $line) ?: [];
        } else {
            $parts = preg_split('/\s+/u', $line) ?: [];
        }

        return array_values(array_filter(array_map('trim', $parts), static fn ($part) => $part !== ''));
    }

    private static function cleanValue(string $value): string
    {
        return trim(trim($value), " \t\n\r\0\x0B\"'`,;");
    }

    private static function emptyRecord(): array
    {
        return ['email' => '', 'passwd' => '', 'ak' => '', 'sk' => ''];
    }

    private static function hasCredentialPair(array $record): bool
    {
        return $record['ak'] !== '' && $record['sk'] !== '';
    }

    private static function isCompleteRecord(array $record): bool
    {
        return $record['email'] !== '' && self::hasCredentialPair($record);
    }

    private static function hasAnyCompleteRecord(array $records): bool
    {
        foreach ($records as $record) {
            if ($record['email'] !== '' && self::hasCredentialPair($record)) {
                return true;
            }
        }
        return false;
    }

    private static function parseLegacyFourLineFormat(string $text): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), static fn ($line) => $line !== ''));
        if (count($lines) === 0 || count($lines) % 4 !== 0) {
            return [];
        }

        $records = [];
        for ($i = 0; $i < count($lines); $i += 4) {
            $record = [
                'email' => $lines[$i],
                'passwd' => $lines[$i + 1],
                'ak' => strtoupper($lines[$i + 2]),
                'sk' => $lines[$i + 3],
            ];
            if (self::isValidLegacyRecord($record)) {
                $records[] = $record;
            }
        }
        return $records;
    }

    private static function isValidLegacyRecord(array $record): bool
    {
        if (!preg_match(self::EMAIL_PATTERN, $record['email'], $emailMatch)
            || $emailMatch[0] !== $record['email']) {
            return false;
        }
        if (!preg_match(self::ACCESS_KEY_PATTERN, $record['ak'], $accessMatch)
            || $accessMatch[0] !== $record['ak']) {
            return false;
        }

        return (bool) preg_match(self::SECRET_KEY_PATTERN, $record['sk'])
            && !preg_match(self::BASE32_PATTERN, $record['sk']);
    }
}
