<?php

use IMAP\Connection;



$maxExecTime = ini_get('max_execution_time');

$cacheFilePath = __DIR__ . '/tmp/cache.json';

if (isset($_GET['forced']) && $_GET['forced'] === 'true') {
    unlink($cacheFilePath);
}

if (file_exists($cacheFilePath)) {
    $data = json_decode(file_get_contents($cacheFilePath), true);
}

class Application
{
    private static ?self $instance = null;
    private ?Connection $imapConnection = null;
    private const MAILBOX = '{imap.yandex.ru:993/imap/ssl}INBOX';
    private const USER = '';
    private const PASSWORD = '';


    private const SEARCH_PATTERNS = [
        [
            'parsed' => true,
            'header' => 'Автор запроса',
            'code' => 'author'
        ],
        [
            'parsed' => true,
            'header' => 'Кому выдать доступ',
            'code' => 'receiver'
        ],
        [
            'parsed' => true,
            'header' => 'Общие доступы',
            'code' => 'category'
        ],
        [
            'parsed' => true,
            'header' => 'Комментарий',
            'code' => 'commentary'
        ]
    ];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getImapConnection(): Connection
    {
        if (is_null($this->imapConnection)) {
            $this->imapConnection = imap_open(self::MAILBOX, self::USER, self::PASSWORD);
        }
        return $this->imapConnection;
    }

    public function requestEmailIds(string $criteria = 'ALL'): array
    {
        $emailIds = imap_search($this->getImapConnection(), $criteria) ?: [];
        rsort($emailIds);
        return $emailIds;
    }

    public function parseEmail(string $id): ?array
    {
        $data = [];

        $overview = imap_fetch_overview($this->getImapConnection(), $id);
        $message = imap_fetchbody($this->getImapConnection(), $id, 1);

        if ($message === false || $overview === false) {
            return null;
        }

        $data['requestDate'] = $overview[0]->udate;

        $dom = new DOMDocument();
        @$dom->loadHTML($message);

        $rows = $dom->getElementsByTagName('tr');

        foreach ($rows as $row) {
            $cols = $row->getElementsByTagName('td');
            if (count($cols) !== 2) {
                continue;
            }

            foreach (self::SEARCH_PATTERNS as $pattern) {
                if (str_contains($cols[0]->nodeValue, $pattern['header'])) {
                    $data[$pattern['code']] = $cols[1]->nodeValue;
                }
            }
        }

        if (!isset($data['author']) || !isset($data['receiver'])) {
            $authorMatches = [];
            $receiverMatches = [];
            $categoryMatches = [];
            preg_match('/(?<=От кого: )([\w\.\-]+@[\w\.\-]+\.\w+)/', $message, $authorMatches);
            preg_match('/(?<=Кому: )([\w\.\-]+@[\w\.\-]+\.\w+)/', $message, $receiverMatches);
            preg_match('/(?<=Продукты: ).*(?=\r)/', $message, $categoryMatches);

            if (!count($authorMatches) || !count($receiverMatches) || !count($categoryMatches)) {
                return null;
            }

            $data['author'] = $authorMatches[0];
            $data['receiver'] = $receiverMatches[0];
            $data['category'] = $categoryMatches[0];

            return $data;
        }

        return $data;
    }
}

$app = Application::getInstance();

$emailIds = isset($data) ? $data['ids'] : $app->requestEmailIds();

$startTime = microtime(true);

$emails = isset($data) ? $data['emails'] : [];
foreach ($emailIds as $key => $emailId) {
    if (microtime(true) - $startTime >= $maxExecTime - 10) {
        $dir = dirname($cacheFilePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($cacheFilePath, json_encode(['ids' => $emailIds, 'current' => $key, 'emails' => $emails]));
        header('Location: ' . explode('?', $_SERVER["REQUEST_URI"])[0]);
        die();
    }

    if (isset($data) && $key < $data['current']) {
        continue;
    }

    $parsedEmail = $app->parseEmail($emailId);

    if (!is_null($parsedEmail)) {
        $emails[] = $parsedEmail;
    }
}

$filePath = __DIR__ . '/tmp/messages.json';

$dir = dirname($filePath);
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}

file_put_contents($filePath, json_encode($emails));
