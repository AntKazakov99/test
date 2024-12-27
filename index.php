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

$emailIds = isset($data) ? $data['ids'] : $app->requestEmailIds();
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

$workers = $app->getWorkersData();

$leads = new LeadsCollection();
foreach ($emails as $email) {
    $exists = array_key_exists($email['receiver'], $workers);

    $isKommo = null;
    if ($exists) {
        $isKommo = str_contains($workers[$email['receiver']]['department'], 'Global');
    }

    if (!$email['category'] && !(isset($email['commentary']))) {
        $email['commentary'] = '';
        $email['category'] = '';
    }

    try {
        $lead = $app->createLeadFromData([
            'author' => $email['author'],
            'receiver' => $email['receiver'],
            'date' => $email['requestDate'],
            'category' => $email['category'] ?: $email['commentary'] ?: ''
        ])
            ->setName('Application for access')
            ->setPipelineId(9041154);
        if ($exists) {
            $lead->setStatusId(142);
        } else {
            $lead->setStatusId(143);
        }

        if ($isKommo) {
            $lead->setTags($app->createTags([
                [
                    'id' => 253739,
                    'name' => 'kommo'
                ]
            ]));
        } elseif ($isKommo === false) {
            $lead->setTags($app->createTags([
                [
                    'id' => 248761,
                    'name' => 'amoCRM'
                ]
            ]));
        }

        $leads->add($lead);
    } catch (\AmoCRM\Exceptions\InvalidArgumentException $e) {
        var_dump($e->getMessage());
    }
}

foreach ($leads->chunk(50) as $chunk) {
    try {
        $app->getAmoCrmApiClient()->leads()->add($chunk);
    } catch (\AmoCRM\Exceptions\AmoCRMMissedTokenException|\AmoCRM\Exceptions\AmoCRMoAuthApiException|\AmoCRM\Exceptions\AmoCRMApiException|\Random\RandomException $e) {
        var_dump($e->getMessage());
    }
}
