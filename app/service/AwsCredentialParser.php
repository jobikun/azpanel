<?php
declare(strict_types=1);

namespace app\service;

/**
 * Extract AWS account credentials from loosely formatted text.
 *
 * Unknown fields (MFA, limits, country, notes, etc.) are deliberately ignored.
 */
class AwsCredentialParser
{
    private const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    private const ACCESS_KEY_PATTERN = '/\b(?:AKIA|ASIA|AIDA|AROA|AIPA|ANPA|ANVA|AGPA)[A-Z0-9]{16}\b/';

    public static function parse(string $text): array
    {
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

            $email = self::labeledValue($line, ['e-?mail', '邮箱', '账号', '账户']);
            if ($email === null && preg_match(self::EMAIL_PATTERN, $line, $match)) {
                $email = $match[0];
            }

            $accessKey = self::labeledValue($line, [
                'access[\s_-]*key(?:[\s_-]*id)?',
                'aws[\s_-]*ak',
                'accesskey',
                '访问密钥(?:id)?',
                'ak',
            ]);
            if (($accessKey === null || !preg_match(self::ACCESS_KEY_PATTERN, $accessKey, $match))
                && preg_match(self::ACCESS_KEY_PATTERN, $line, $match)) {
                $accessKey = $match[0];
            }

            $secretKey = self::labeledValue($line, [
                'secret[\s_-]*(?:access[\s_-]*)?key',
                'aws[\s_-]*sk',
                'secretkey',
                '秘密访问密钥',
                '私密密钥',
                'sk',
            ]);
            $password = self::labeledValue($line, ['password', 'passwd', 'pass', '登录密码', '密码']);

            // A new email after a complete credential pair is a reliable account boundary.
            if ($email !== null && $current['email'] !== '' && self::hasCredentialPair($current)) {
                $records[] = $current;
                $current = self::emptyRecord();
            }

            if ($email !== null && preg_match(self::EMAIL_PATTERN, $email, $match)) {
                $current['email'] = $match[0];
            }
            if ($accessKey !== null && preg_match(self::ACCESS_KEY_PATTERN, strtoupper($accessKey), $match)) {
                if ($current['ak'] !== '' && self::hasCredentialPair($current)) {
                    $records[] = $current;
                    $current = self::emptyRecord();
                }
                $current['ak'] = $match[0];
            }
            if ($secretKey !== null) {
                $candidate = self::cleanValue($secretKey);
                if (preg_match('/^[A-Za-z0-9+\/=]{40}$/', $candidate)) {
                    $current['sk'] = $candidate;
                }
            }
            if ($password !== null) {
                $current['passwd'] = self::cleanValue($password);
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

        return $records;
    }

    private static function labeledValue(string $line, array $labels): ?string
    {
        $labelPattern = implode('|', $labels);
        if (preg_match('/^\s*(?:["\']?\s*(?:' . $labelPattern . ')\s*["\']?)\s*[:：=|\-]\s*(.+?)\s*[,;]?\s*$/iu', $line, $match)) {
            return self::cleanValue($match[1]);
        }

        return null;
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
            $records[] = [
                'email' => $lines[$i],
                'passwd' => $lines[$i + 1],
                'ak' => strtoupper($lines[$i + 2]),
                'sk' => $lines[$i + 3],
            ];
        }
        return $records;
    }
}
