<?php

declare(strict_types=1);

require __DIR__ . '/../index.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$html = <<<'HTML'
<div id="concursos">
  <div class="ua" id="NACIONAL"></div>
  <div data-url="https://www.pciconcursos.com.br/noticias/nacional">
    <a>Concurso Nacional</a><div class="cc">&nbsp;</div>
    <div class="cd"><span>Analista<br>Superior</span> - 12 vagas - R$ 1.234,56</div>
    <div class="ce">Até <span>12/12/2026</span></div>
  </div>
  <div class="ua" id="SP"></div>
  <div data-url="https://www.pciconcursos.com.br/noticias/municipal">
    <a>Prefeitura &amp; Câmara</a><div class="cc">SP</div>
    <div class="cd"><span>Motorista</span><span>Médio</span></div>
  </div>
</div>
HTML;

$items = parseListing($html);
$nacional = $items['/noticias/nacional'];
$sp = $items['/noticias/municipal'];
expect(count($items) === 2 && route($nacional) === 'NACIONAL' && route($sp) === 'SP', 'Classificação de destino');
expect($nacional['vagas'] === '12' && $nacional['salario'] === 'R$ 1.234,56', 'Extração de vagas e salário');
expect($nacional['cargo'] === 'Analista' && $nacional['nivel'] === 'Superior', 'Extração de cargo e nível');
expect($nacional['data'] === '12/12/2026' && $sp['nivel'] === 'Médio', 'Extração do prazo e nível');

putenv('TELEGRAM_BOT_TOKEN=geral');
putenv('TELEGRAM_CHAT_ID=-1');
putenv('TELEGRAM_BOT_TOKEN_SP=estado');
putenv('TELEGRAM_CHAT_ID_SP');
expect(credentials('SP') === ['geral', '-1'], 'Par regional incompleto deve usar o geral');
putenv('TELEGRAM_CHAT_ID_SP=-2');
expect(credentials('SP') === ['estado', '-2'] && credentials('NACIONAL') === ['geral', '-1'], 'Destinos por tipo');

$updated = parseListing(str_replace('Até <span>', 'Prorrogado até <span>', $html));
$messages = changes($items, $updated);
expect(count($messages) === 1 && str_contains($messages[0]['message'], 'Prazo prorrogado'), 'Prorrogação deve gerar aviso');
$updated = $items;
$updated['/noticias/municipal']['cadastro_reserva'] = true;
$messages = changes($items, $updated);
expect(count($messages) === 1 && str_contains($messages[0]['message'], 'Cadastro reserva'), 'Cadastro reserva deve gerar aviso');
$messages = changes($items, ['/noticias/nacional' => $nacional]);
expect(count($messages) === 1 && $messages[0]['route'] === 'SP' && str_contains($messages[0]['message'], '&amp;'), 'Remoção e escape de HTML');
expect(changes($items, $items) === [], 'Repetição não gera avisos');

try {
    parseListing('<div id="concursos"></div>');
    throw new LogicException('Listagem vazia deveria falhar.');
} catch (RuntimeException $error) {
    expect(str_contains($error->getMessage(), 'sem concursos'), 'Falha de listagem vazia');
}

echo "Parser, comparação e roteamento validados.\n";
