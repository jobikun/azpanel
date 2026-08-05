<?php
declare(strict_types=1);

/**
 * Table-driven tests for app\service\AwsCredentialParser.
 *
 * No PHPUnit dependency — run directly:
 *   php tests/AwsCredentialParserTest.php
 * Exits 0 when every case passes, 1 otherwise.
 */

require __DIR__ . '/../app/service/AwsCredentialParser.php';

use app\service\AwsCredentialParser;

// Deliberately obvious synthetic fixtures. Never paste live credentials here.
const AK  = 'AKIAAAAAAAAAAAAAAAAA';                       // AKIA + 16 chars
const AK2 = 'AKIABBBBBBBBBBBBBBBB';
const SK  = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa+';   // 39 chars + "+"
const SK2 = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb/';
const MFA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
const B40 = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'; // 40-char base32 (looks 40 but is MFA-shaped)

/**
 * Each case: [name, input, expected records]. A record is [email, passwd, ak, sk].
 */
$cases = [
    // --- tilde-delimited target format ----------------------------------
    ['tilde + mfa, 2 accounts (with BOM)',
        "\xEF\xBB\xBF" . 'fixture-one@example.com ~ TestPass!123 ~ ' . MFA . ' ~ ' . AK . ' ~ ' . SK . "\n\n"
        . 'fixture-two@example.com ~ TestPass!123 ~ ' . MFA . ' ~ ' . AK2 . ' ~ ' . SK2,
        [
            ['fixture-one@example.com', 'TestPass!123', AK, SK],
            ['fixture-two@example.com', 'TestPass!123', AK2, SK2],
        ],
    ],

    // --- other delimiters -----------------------------------------------
    ['pipe, unlabelled password',
        'u@x.com | passX | ' . AK . ' | ' . SK,
        [['u@x.com', 'passX', AK, SK]]],
    ['semicolon separated',
        'user@example.com;pass123;' . AK . ';' . SK,
        [['user@example.com', 'pass123', AK, SK]]],
    ['tab separated',
        "tab@x.com\t" . AK . "\t" . SK . "\tpw12345",
        [['tab@x.com', 'pw12345', AK, SK]]],
    ['single spaces, no password',
        'sp@x.com ' . AK . ' ' . SK,
        [['sp@x.com', '', AK, SK]]],

    // --- labelled / mixed order -----------------------------------------
    ['labelled multi-line, spaces in password',
        "Email: a@b.com\nPassword: My Secret Pass\nAccess Key ID: " . AK . "\nSecret Access Key: " . SK,
        [['a@b.com', 'My Secret Pass', AK, SK]]],
    ['comma-glued key=value, out of order',
        'secret=' . SK . ', akey=' . AK . ', mail=lab@x.com, password=P@ssw0rd',
        [['lab@x.com', 'P@ssw0rd', AK, SK]]],
    ['key-first ordering',
        AK . ' ; ' . SK . ' ; kf@x.com ; kfpass',
        [['kf@x.com', 'kfpass', AK, SK]]],

    // --- legacy / per-line ----------------------------------------------
    ['legacy four unlabelled lines',
        "a@b.com\nMyPass123\n" . AK . "\n" . SK,
        [['a@b.com', 'MyPass123', AK, SK]]],
    ['legacy four-line base32 value is not accepted as secret key',
        "a@b.com\nMyPass123\n" . AK . "\n" . B40,
        []],
    ['legacy four-line arbitrary shapes are rejected',
        "a@b.com\nMyPass123\n" . str_repeat('Z', 20) . "\n" . str_repeat('q', 40),
        []],
    ['five lines with mfa in the middle',
        "a@b.com\nMyPass123\n" . MFA . "\n" . AK . "\n" . SK,
        [['a@b.com', 'MyPass123', AK, SK]]],
    ['per-line, two accounts, no blank separator',
        "one@x.com\n" . AK . "\n" . SK . "\ntwo@x.com\n" . AK2 . "\n" . SK2,
        [['one@x.com', '', AK, SK], ['two@x.com', '', AK2, SK2]]],

    // --- noise / safety (the review's failure cases) --------------------
    ['stray email line is dropped, not a phantom account',
        "support@example.net\nuser@example.com | pass123 | " . AK . ' | ' . SK,
        [['user@example.com', 'pass123', AK, SK]]],
    ['40-char base32 is never taken as the secret key',
        'user@example.com | ' . AK . ' | ' . B40,
        []],
    ['ambiguous: two distinct 40-char secrets -> not guessed',
        'amb@x.com | ' . AK . ' | ' . SK . ' | ' . SK2,
        []],
    ['labelled other-field is not lifted as password',
        'email=user@example.com | country=Japan | ak=' . AK . ' | sk=' . SK,
        [['user@example.com', '', AK, SK]]],
    ['labelled sk token is not re-read as password',
        'email=user@example.com | ak=' . AK . ' | sk=' . SK,
        [['user@example.com', '', AK, SK]]],
    ['junk fields interleaved',
        'junk@x.com ~ P@ss ~ US ~ 5000 ~ ' . AK . ' ~ ' . SK . ' ~ enabled',
        [['junk@x.com', '', AK, SK]]],
    ['ambiguous bare country and password are not guessed',
        'country@x.com ~ Japan ~ RealPass ~ ' . AK . ' ~ ' . SK,
        [['country@x.com', '', AK, SK]]],

    // --- guaranteed shape: email + password + 2 keys, MFA optional -------
    ['numeric password is captured',
        'num@x.com ~ 12345678 ~ ' . MFA . ' ~ ' . AK . ' ~ ' . SK,
        [['num@x.com', '12345678', AK, SK]]],
    ['same shape without the MFA column',
        'nomfa@x.com ~ TestPass!123 ~ ' . AK . ' ~ ' . SK,
        [['nomfa@x.com', 'TestPass!123', AK, SK]]],
    ['incomplete (no secret key) is dropped',
        'only@x.com ' . AK,
        []],
    ['empty input',
        "   \n  ",
        []],
];

$failures = 0;
foreach ($cases as [$name, $input, $expected]) {
    $records = AwsCredentialParser::parse($input);
    $actual = array_map(
        static fn ($r) => [$r['email'], $r['passwd'], $r['ak'], $r['sk']],
        $records
    );

    if ($actual === $expected) {
        echo "PASS  {$name}\n";
        continue;
    }

    $failures++;
    echo "FAIL  {$name}\n";
    echo '      expected: ' . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n";
    echo '      actual:   ' . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n" . ($failures === 0 ? 'All ' . count($cases) . ' cases passed.' : "{$failures} case(s) failed.") . "\n";
exit($failures === 0 ? 0 : 1);
