<?php

include_once __DIR__ . '/vendor/autoload.php';

$messages = file_get_contents(\Ant\MessageController::FILE_PATH);
$messages = json_decode($messages, true);

$parseConfig = file_get_contents(__DIR__ . '/tmp/parse_config.json');
$parseConfig = json_decode($parseConfig, true);

echo '<pre>';

$parsed = [];
$groups = [];
foreach ($messages as $id => $message) {
    $arrivalDate = $message['overview'][0]['date'];
    $authorEmail = '';
    $employeeEmail = '';
    $commentary = '';
    $categories = [];

    $dom = new DOMDocument();
    @$dom->loadHTML($message['body']);

    $rows = $dom->getElementsByTagName('tr');

    foreach ($rows as $row) {
        $cols = $row->getElementsByTagName('td');
        if (count($cols) !== 2) {
            continue;
        }

        if (str_contains($cols[0]->nodeValue, 'Автор запроса')) {
            $authorEmail = trim($cols[1]->nodeValue);
        }

        if (str_contains($cols[0]->nodeValue, 'Кому выдать доступ')) {
            $employeeEmail = trim($cols[1]->nodeValue);
        }

        if (str_contains($cols[0]->nodeValue, 'Комментарий')) {
            $commentary = trim($cols[1]->nodeValue);
        }

        foreach ($parseConfig['categories'] as $category) {
            if (!str_contains($cols[0]->nodeValue, $category)) {
               continue;
            }

            if ($category)

            foreach (explode(',', $cols[1]->nodeValue) as $subCategory) {
                if (array_key_exists($subCategory, $parseConfig['comparisons'][$category])) {
                    $categories[] = $parseConfig['comparisons'][$category][$subCategory];
                }
            }
        }
    }

    foreach ($categories as $category) {
        $parsed[] = [
            'arrivalDate' => strtotime($arrivalDate),
            'authorEmail' => $authorEmail,
            'employeeEmail' => $employeeEmail,
            'commentary' => trim($commentary),
            'category' => trim($category)   
        ];
    }

    if (empty($categories)) {
        $parsed[] = [
            'arrivalDate' => strtotime($arrivalDate),
            'authorEmail' => $authorEmail,
            'employeeEmail' => $employeeEmail,
            'commentary' => trim($commentary),
            'category' => ""
        ];
    }
}

file_put_contents(__DIR__ . '/tmp/parsed_messages.json', json_encode($parsed));