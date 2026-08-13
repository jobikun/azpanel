<?php
declare(strict_types=1);

require __DIR__ . '/../app/service/AlibabaHttpDnsZone.php';

use app\service\AlibabaHttpDnsZone;

$class = new ReflectionClass(AlibabaHttpDnsZone::class);
$findEndpoint = $class->getMethod('findDohEndpoint');
$normalizeEndpoint = $class->getMethod('normalizeDohEndpoint');
$configurationId = $class->getMethod('configurationIdFromEndpoint');

$cases = [
    ['find complete URL', $findEndpoint, [['Data' => ['DohUrl' => 'https://12345-AbCd.alidns.com/dns-query']]], 'https://12345-abcd.alidns.com/dns-query'],
    ['find hostname', $findEndpoint, [['Endpoint' => '12345-systemtoken.alidns.com']], 'https://12345-systemtoken.alidns.com/dns-query'],
    ['ignore unrelated strings', $findEndpoint, [['SecretKey' => 'not-an-endpoint']], ''],
    ['normalize endpoint', $normalizeEndpoint, ['HTTPS://12345-SystemToken.AliDNS.com/dns-query/'], 'https://12345-systemtoken.alidns.com/dns-query'],
    ['reject other provider', $normalizeEndpoint, ['https://dns.example.com/dns-query'], ''],
    ['configuration ID from encrypted address', $configurationId, ['https://12345-systemtoken.alidns.com/dns-query'], '12345'],
    ['configuration ID from short address', $configurationId, ['https://12345.alidns.com/dns-query'], '12345'],
];

$failures = 0;
foreach ($cases as [$name, $method, $arguments, $expected]) {
    $actual = $method->invokeArgs(null, $arguments);
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
