<?php

use Symfony\Component\Dotenv\Dotenv;

include_once __DIR__ . '/vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/.env');

$mailbox = $_ENV['MAILBOX'];
$user = $_ENV['USER'];
$password = $_ENV['PASSWORD'];

$inbox = imap_open($mailbox, $user, $password);

$emails = imap_search($inbox, 'ALL');

if ($emails) {
    // Sort emails by newest first
    rsort($emails);

    echo '<pre>';
    // Loop through each email
    foreach ($emails as $email_number) {
        $searchPatterns = [
            [
                'header' => 'Автор запроса',
                'code' => 'author'
            ],
            [
                'header' => 'Кому выдать доступ',
                'code' => 'receiver'
            ],
            [
                'header' => 'Общие доступы',
                'code' => 'category'
            ],
            [
                'header' => 'Комментарий',
                'code' => 'commentary'
            ]
        ];

        $overview = imap_fetch_overview($inbox, $email_number);

        $message = imap_fetchbody($inbox, $email_number, 1);

        $dom = new DOMDocument();
        @$dom->loadHTML($message);

        $rows = $dom->getElementsByTagName('tr');

        $messageData = [];
        foreach ($rows as $row) {
            $cols = $row->getElementsByTagName('td');
            if (count($cols) !== 2) {
                continue;
            }

            foreach ($searchPatterns as $pattern) {
                if (str_contains($cols[0]->nodeValue, $pattern['header'])) {
                    $messageData[$pattern['code']] = $cols[1]->nodeValue;
                }
            }
        }

        foreach ($searchPatterns as $pattern) {
            echo $pattern['header'] . ': ' . (array_key_exists($pattern['code'], $messageData) ? $messageData[$pattern['code']] : '-') . PHP_EOL;
        }

        $messageData['requestDate'] = $overview[0]->udate;

        echo 'Дата запроса: ' . date('d.m.Y H:i:s', $messageData['requestDate']) . PHP_EOL;
        echo '----' . PHP_EOL;
        echo PHP_EOL;
    }
} else {
    echo "No emails found.\n";
}
