<?php

declare(strict_types=1);

const SOURCE = 'https://www.pciconcursos.com.br/concursos/';
const ROUTES = ['NACIONAL', 'AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];
const FIELDS = [
    'titulo' => 'Título', 'uf' => 'UF', 'cargo' => 'Cargo', 'vagas' => 'Vagas',
    'salario' => 'Salário', 'nivel' => 'Nível', 'cadastro_reserva' => 'Cadastro reserva',
    'data' => 'Prazo', 'data_prorrogada' => 'Prazo prorrogado',
];

function request(string $url, ?array $body = null): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => $body === null,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $body === null ? 30 : 10,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'ConcursosCheck/1.0',
    ]);
    if ($body !== null) {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
        ]);
    }
    $response = curl_exec($curl);
    if ($response === false) {
        throw new RuntimeException('Falha de transporte cURL, código ' . curl_errno($curl));
    }
    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $response];
}

function clean(?string $text): string
{
    return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $text ?? '') ?? '');
}

function byClass(string $class): string
{
    return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
}

function listingId(string $url): string
{
    $parts = parse_url($url);
    if (!isset($parts['scheme'], $parts['host'], $parts['path'])
        || !in_array($parts['scheme'], ['http', 'https'], true)
        || !in_array(strtolower($parts['host']), ['www.pciconcursos.com.br', 'pciconcursos.com.br'], true)) {
        throw new RuntimeException('A listagem contém um link de publicação inválido.');
    }
    return rtrim($parts['path'], '/');
}

function parseListing(string $html): array
{
    $dom = new DOMDocument();
    if ($html === '' || !$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET)) {
        throw new RuntimeException('HTML inválido na origem.');
    }
    $xpath = new DOMXPath($dom);
    $list = $xpath->query('//div[@id="concursos"]')->item(0);
    if ($list === null) {
        throw new RuntimeException('Seção de concursos ausente na origem.');
    }

    $items = [];
    $region = null;
    $markers = './/div[@data-url or (@id and ' . byClass('ua') . ')]';
    foreach ($xpath->query($markers, $list) as $entry) {
        if (!$entry->hasAttribute('data-url')) {
            $region = strtoupper($entry->getAttribute('id'));
            continue;
        }

        $url = trim($entry->getAttribute('data-url'));
        $id = listingId($url);
        $title = clean($xpath->query('.//a', $entry)->item(0)?->textContent);
        if ($region === null || $title === '' || isset($items[$id])) {
            throw new RuntimeException('Registro incompleto ou identificador duplicado na listagem.');
        }

        $cd = $xpath->query('.//div[' . byClass('cd') . ']', $entry)->item(0);
        $details = clean($cd?->textContent);
        $positions = $cd ? $xpath->query('.//span', $cd) : null;
        $first = $positions?->item(0);
        $positionHtml = $first ? $dom->saveHTML($first) : '';
        $parts = preg_split('/<br\s*\/?>/i', $positionHtml, 2);
        $cargo = $first ? clean(html_entity_decode(strip_tags($parts[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
        $nivel = isset($parts[1])
            ? clean(html_entity_decode(strip_tags($parts[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            : clean($positions?->item(1)?->textContent);
        $dateText = clean($xpath->query('.//div[' . byClass('ce') . ']', $entry)->item(0)?->textContent);
        $uf = strtoupper(clean($xpath->query('.//div[' . byClass('cc') . ']', $entry)->item(0)?->textContent));
        preg_match('/(\d+(?:\.\d+)?)\s*vagas?/i', $details, $vacancies);
        preg_match('/R\$\s*([\d.]+,\d{2})/', $details, $salary);
        preg_match('/\b\d{2}\/\d{2}\/\d{4}\b/', $dateText, $date);

        $items[$id] = [
            'titulo' => $title,
            'url' => $url,
            'regiao' => $region,
            'uf' => $uf,
            'cargo' => $cargo,
            'vagas' => $vacancies[1] ?? '',
            'salario' => isset($salary[1]) ? 'R$ ' . $salary[1] : '',
            'nivel' => $nivel,
            'cadastro_reserva' => (bool) preg_match('/cadastro\s+(?:de\s+)?reserva/i', $details),
            'data' => $date[0] ?? '',
            'data_prorrogada' => stripos($dateText, 'Prorrogado') !== false,
        ];
    }
    if ($items === []) {
        throw new RuntimeException('A origem retornou uma listagem sem concursos.');
    }
    ksort($items);
    return $items;
}

function route(array $item): string
{
    if ($item['regiao'] === 'NACIONAL') {
        return 'NACIONAL';
    }
    return in_array($item['uf'], ROUTES, true) ? $item['uf'] : $item['regiao'];
}

function credentials(string $route): array
{
    if (in_array($route, ROUTES, true)) {
        $token = trim(getenv("TELEGRAM_BOT_TOKEN_$route") ?: '');
        $chat = trim(getenv("TELEGRAM_CHAT_ID_$route") ?: '');
        if ($token !== '' && $chat !== '') {
            return [$token, $chat];
        }
    }
    $token = trim(getenv('TELEGRAM_BOT_TOKEN') ?: '');
    $chat = trim(getenv('TELEGRAM_CHAT_ID') ?: '');
    if ($token === '' || $chat === '') {
        throw new RuntimeException('Configure o par geral TELEGRAM_BOT_TOKEN e TELEGRAM_CHAT_ID.');
    }
    return [$token, $chat];
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function comparedValue(?string $before, string $current, callable $format): string
{
    $formatted = $format($current);
    if ($before === null || $before === '' || $before === $current) {
        return $formatted;
    }
    return '<s>' . $format($before) . '</s> → ' . $formatted;
}

function notification(string $kind, array $item, ?array $before = null): array
{
    $heading = match ($kind) {
        'novo' => '🆕 NOVO CONCURSO',
        'modificado' => '📝 CONCURSO ATUALIZADO',
        'removido' => '❌ CONCURSO REMOVIDO',
    };
    $separator = '═════════════════';
    $title = comparedValue($before['titulo'] ?? null, $item['titulo'], 'escape');
    $lines = [$separator, "<b>$heading</b>", $separator, '', "📌 $title", ''];

    if ($item['cadastro_reserva']) {
        $lines[] = '⚠️ CADASTRO RESERVA ⚠️';
    }

    $dateIcon = $item['data_prorrogada'] ? '⚠️' : '📅';
    foreach ([
        'cargo' => '💼 Cargo',
        'vagas' => '📊 Vagas',
        'salario' => '💰 Salário',
        'nivel' => '🎓 Nível',
        'regiao' => '📍 Local',
        'data' => "$dateIcon Data limite",
    ] as $key => $label) {
        if ($item[$key] !== '') {
            $lines[] = $label . ': ' . comparedValue($before[$key] ?? null, $item[$key], 'escape');
        }
    }
    if ($item['data'] !== '' && $item['data_prorrogada']) {
        $lines[] = '⚠️ Prorrogado!';
    }

    $lines[] = '🔗 Link: ' . comparedValue($before['url'] ?? null, $item['url'], static function (string $url): string {
        $safe = escape($url);
        return "<a href=\"$safe\">$safe</a>";
    });
    return ['route' => route($item), 'message' => implode("\n", $lines), 'attempts' => 0];
}

function groupByContest(array $items): array
{
    $groups = [];
    foreach ($items as $id => $item) {
        $key = json_encode(
            [$item['regiao'], $item['uf'], $item['titulo'], $item['cargo'], $item['nivel']],
            JSON_THROW_ON_ERROR
        );
        $groups[$key][] = $id;
    }
    return $groups;
}

function changes(array $previous, array $current): array
{
    $messages = [];
    $removed = array_diff_key($previous, $current);
    $added = array_diff_key($current, $previous);
    $removedGroups = groupByContest($removed);
    $relinked = [];
    foreach (groupByContest($added) as $key => $newIds) {
        $oldIds = $removedGroups[$key] ?? [];
        if (count($newIds) === 1 && count($oldIds) === 1) {
            $relinked[$newIds[0]] = $removed[$oldIds[0]];
            unset($removed[$oldIds[0]]);
        }
    }

    $tracked = FIELDS + ['regiao' => true, 'url' => true];
    foreach ($current as $id => $item) {
        $before = $previous[$id] ?? $relinked[$id] ?? null;
        if ($before === null) {
            $messages[] = notification('novo', $item);
        } elseif (array_intersect_key($item, $tracked) != array_intersect_key($before, $tracked)) {
            $messages[] = notification('modificado', $item, $before);
        }
    }
    foreach ($removed as $item) {
        $messages[] = notification('removido', $item);
    }
    return $messages;
}

function loadState(string $path): array
{
    if (!file_exists($path)) {
        return ['snapshot' => null, 'queue' => []];
    }
    $state = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($state) || !array_key_exists('snapshot', $state)
        || ($state['snapshot'] !== null && !is_array($state['snapshot'])) || !is_array($state['queue'] ?? null)) {
        throw new RuntimeException('Estado persistido inválido.');
    }
    return $state;
}

function saveState(string $path, array $state): void
{
    $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $temporary = $path . '.tmp';
    if (file_put_contents($temporary, $json . "\n") === false || !rename($temporary, $path)) {
        throw new RuntimeException('Falha ao gravar estado local.');
    }
}

function sendQueue(string $path): int
{
    $state = loadState($path);
    $sent = $failed = 0;
    $deadline = microtime(true) + 90;
    foreach ($state['queue'] as $index => $notice) {
        if (microtime(true) >= $deadline || $sent + $failed >= 20) {
            break;
        }
        [$token, $chat] = credentials($notice['route']);
        try {
            [$status, $body] = request("https://api.telegram.org/bot$token/sendMessage", [
                'chat_id' => $chat,
                'text' => $notice['message'],
                'parse_mode' => 'HTML',
                'link_preview_options' => ['is_disabled' => true],
            ]);
        } catch (RuntimeException $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
            $status = 0;
            $body = '';
        }
        if ($status === 429) {
            fwrite(STDERR, "Telegram limitou o envio. Fila mantida para a próxima rodada.\n");
            $failed++;
            break;
        }
        if ($status === 200 && (json_decode($body, true)['ok'] ?? false) === true) {
            unset($state['queue'][$index]);
            $sent++;
        } else {
            $attempt = ++$state['queue'][$index]['attempts'];
            $failed++;
            fwrite(STDERR, "Falha no envio para {$notice['route']} (HTTP $status, tentativa $attempt/3).\n");
            if ($attempt >= 3) {
                $state['failed'][] = $state['queue'][$index];
                $state['failed'] = array_slice($state['failed'], -100);
                unset($state['queue'][$index]);
            }
        }
        saveState($path, [...$state, 'queue' => array_values($state['queue'])]);
        usleep(500000);
    }
    printf("Enviadas: %d | Falhas: %d | Pendentes: %d\n", $sent, $failed, count($state['queue']));
    return $failed === 0 ? 0 : 1;
}

function main(array $argv): int
{
    $mode = $argv[1] ?? '';
    $path = $argv[2] ?? '';
    if ($mode === 'send' && $path !== '') {
        return sendQueue($path);
    }
    if ($mode !== 'check' && !($mode === 'collect' && $path !== '')) {
        throw new RuntimeException('Uso: php index.php check | collect <estado.json> | send <estado.json>');
    }
    [$status, $html] = request(SOURCE);
    if ($status !== 200) {
        throw new RuntimeException("PCI Concursos retornou HTTP $status.");
    }
    $items = parseListing($html);
    printf("Concursos coletados: %d\n", count($items));
    if ($mode === 'check') {
        return 0;
    }
    $state = loadState($path);
    $messages = $state['snapshot'] === null ? [] : changes($state['snapshot'], $items);
    if ($state['snapshot'] === null) {
        echo "Referência inicial criada sem emitir avisos.\n";
    }
    $state['snapshot'] = $items;
    array_push($state['queue'], ...$messages);
    saveState($path, $state);
    printf("Novos avisos na fila: %d\n", count($messages));
    return 0;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        exit(main($argv));
    } catch (Throwable $error) {
        fwrite(STDERR, 'Erro: ' . $error->getMessage() . "\n");
        exit(1);
    }
}
