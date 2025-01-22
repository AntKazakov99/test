<?php

namespace Ant;

use IMAP\Connection;

class ImapClient
{
    private Connection $connection;

    public function __construct(string $mailbox, string $login, string $password, int $flags = 0)
    {
        $this->connection = imap_open($mailbox, $login, $password, $flags);
    }

    public function getMessageIds(string $criteria = 'ALL'): array
    {
        return imap_search($this->connection, $criteria) ?: [];
    }

    public function getEmailData($id): array
    {
        $overview = imap_fetch_overview($this->connection, $id);
        $body = imap_fetchbody($this->connection, $id, 1);

        return ['overview' => $overview, 'body' => $body];
    }
}
