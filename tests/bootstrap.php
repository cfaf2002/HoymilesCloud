<?php

declare(strict_types=1);

// SymconStubs: im CI unter tests/stubs geklont, lokal per Umgebungsvariable SYMCON_STUBS
$stubs = getenv('SYMCON_STUBS') ?: __DIR__ . '/stubs';
define('SYMCON_STUBS_DIR', $stubs);
include_once $stubs . '/autoload.php';
include_once $stubs . '/Validator.php';

// Nachgebaute Hoymiles-Cloud starten
$state = sys_get_temp_dir() . '/hoymiles-fake-' . getmypid();
@mkdir($state, 0777, true);
$port = (int) (getenv('HOYMILES_FAKE_PORT') ?: 18099);
$process = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/FakeCloud/server.php'],
    [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
    $pipes,
    null,
    array_merge(getenv(), ['HOYMILES_FAKE_STATE' => $state])
);
for ($i = 0; $i < 50; $i++) {
    $socket = @fsockopen('127.0.0.1', $port);
    if ($socket) {
        fclose($socket);
        break;
    }
    usleep(100000);
}
register_shutdown_function(static function () use ($process): void {
    proc_terminate($process);
});
define('HOYMILES_FAKE_URL', "http://127.0.0.1:$port");
define('HOYMILES_FAKE_STATE', $state);
