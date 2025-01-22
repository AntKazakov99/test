<?php

use AmoCRM\Collections\Leads\LeadsCollection;

include_once __DIR__ . '/vendor/autoload.php';

$app = \Ant\Application::getInstance();

$workers = $app->getWorkersData();

$messages = file_get_contents(__DIR__ . '/tmp/parsed_messages.json');
$messages = json_decode($messages, true);

$current = file_get_contents(__DIR__ . '/tmp/export_tmp.txt');

$maxExecTime = ini_get("max_execution_time");
$startTime = microtime(true);

$leads = new LeadsCollection();
foreach ($messages as $key => $message) {
    $exists = array_key_exists($message['employeeEmail'], $workers);

    $isKommo = null;
    if ($exists) {
        $isKommo = str_contains($workers[$message['employeeEmail']]['department'], 'Global');
    }

    if (!$message['category'] && !(isset($message['commentary']))) {
        $message['commentary'] = '';
        $message['category'] = '';
    }

    try {
        $lead = $app->createLeadFromData($message)
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

foreach ($leads->chunk(50) as $key => $chunk) {
    if (microtime(true) - $startTime >= $maxExecTime - 10) {
        file_put_contents(__DIR__ . '/tmp/export_tmp.txt', $key);
        header('Location: ' . explode('?', $_SERVER["REQUEST_URI"])[0]);
        die();
    }

    if ($key < $current) {
        continue;
    }

    try {
        $app->getAmoCrmApiClient()->leads()->add($chunk);
    } catch (\AmoCRM\Exceptions\AmoCRMMissedTokenException|\AmoCRM\Exceptions\AmoCRMoAuthApiException|\AmoCRM\Exceptions\AmoCRMApiException|\Random\RandomException $e) {
        var_dump($e->getMessage());
    }
}
