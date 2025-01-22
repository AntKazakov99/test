<?php

namespace Ant;

class MessageController
{
    public const FILE_PATH = __DIR__ . "/../tmp/messages.json";
    public const TMP_FILE_PATH = __DIR__ . "/../tmp/tmp.json";

    static public function requestMessages(): bool
    {
        $maxExecTime = ini_get("max_execution_time");
        $startTime = microtime(true);

        $imapClient = \Ant\Application::getInstance()->getImapClient();

        $tmp = file_exists(self::TMP_FILE_PATH) ? json_decode(file_get_contents(self::TMP_FILE_PATH), true) : [];
        $ids = empty($tmp) ? $imapClient->getMessageIds() : $tmp['ids'];
        $current = empty($tmp) ? 0 : $tmp['current'];

        $messages = file_exists(self::FILE_PATH) ? json_decode(file_get_contents(self::FILE_PATH), true) : [];

        $dir = dirname(self::FILE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $dir = dirname(self::TMP_FILE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        foreach ($ids as $id) {
            if (microtime(true) - $startTime >= $maxExecTime - 10) {
                file_put_contents(self::TMP_FILE_PATH, json_encode(['ids' => $ids, 'current' => $id]));
                file_put_contents(self::FILE_PATH, json_encode($messages));
                return false;
            }

            if ($id < $current) {
                continue;
            }

            $messages[$id] = $imapClient->getEmailData($id);
        }

        $test = file_put_contents(self::FILE_PATH, json_encode($messages));

        var_dump($test);

        return true;
    }
}
