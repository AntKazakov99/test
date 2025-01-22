<?php

include_once __DIR__ . '/vendor/autoload.php';

const FILE_PATH = __DIR__ . "/tmp/messages.json";

if (!\Ant\MessageController::requestMessages()) {
    header('Location: ' . explode('?', $_SERVER["REQUEST_URI"])[0]);
    die();
} else {
    echo 'Completed';
}