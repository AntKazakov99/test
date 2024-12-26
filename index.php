<?php

use AmoCRM\Collections\Leads\LeadsCollection;
use Ant\Application;

include_once __DIR__ . '/vendor/autoload.php';

$maxExecTime = ini_get('max_execution_time');

$app = Application::getInstance();

$cacheFilePath = __DIR__ . '/tmp/cache.json';

if (isset($_GET['forced']) && $_GET['forced'] === 'true') {
    unlink($cacheFilePath);
}

if (file_exists($cacheFilePath)) {
    $data = json_decode(file_get_contents($cacheFilePath), true);
}

$emailIds = isset($data)  ? $data['ids'] : $app->requestEmailIds('ALL');

$startTime = microtime(true);


$emails = isset($data) ? $data['emails'] : [];
foreach ($emailIds as $key => $emailId) {
    if (microtime(true) - $startTime >= $maxExecTime - 10) {
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

file_put_contents($cacheFilePath, json_encode(['ids' => $emailIds, 'current' => count($emails), 'emails' => $emails]));

$leads = new LeadsCollection();
foreach ($emails as $email) {
    try {
        $leads->add(
            $app->createLeadFromData([
                'author' => $email['author'],
                'receiver' => $email['receiver'],
                'date' => $email['requestDate'],
                'category' => $email['category'] ?: $email['commentary']
            ])
            ->setName('Application for access')
            ->setPipelineId(9041154)
        );
    } catch (\AmoCRM\Exceptions\InvalidArgumentException $e) {
        var_dump($e->getMessage());
    }
}

foreach ($leads->chunk(50) as $chunk) {
    $app->getAmoCrmApiClient()->leads()->add($chunk);
}
