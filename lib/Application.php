<?php

namespace Ant;

use AmoCRM\Client\AmoCRMApiClient;
use AmoCRM\Collections\CustomFieldsValuesCollection;
use AmoCRM\Exceptions\AmoCRMoAuthApiException;
use AmoCRM\Exceptions\InvalidArgumentException;
use AmoCRM\Models\CustomFieldsValues\DateCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextareaCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\TextCustomFieldValuesModel;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\DateCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextareaCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueCollections\TextCustomFieldValueCollection;
use AmoCRM\Models\CustomFieldsValues\ValueModels\DateCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextareaCustomFieldValueModel;
use AmoCRM\Models\CustomFieldsValues\ValueModels\TextCustomFieldValueModel;
use AmoCRM\Models\LeadModel;
use DateTime;
use DOMDocument;
use Exception;
use IMAP\Connection;
use League\OAuth2\Client\Token\AccessToken;
use Random\RandomException;
use Symfony\Component\Dotenv\Dotenv;

class Application
{
    /**
     * todo change to actual file path
     */
    private const ACCESS_TOKEN_FILE_PATH = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . 'access_token.json';
    private static ?self $instance = null;
    private ?array $config = null;
    private ?AmoCRMApiClient $amoCrmApiClient = null;
    private ?AccessToken $accessToken = null;
    private ?Connection $imapConnection = null;
    private const CUSTOM_FIELDS_IDS = [
        'author' => 682713,
        'receiver' => 682717,
        'date' => 682705,
        'category' => 769127
    ];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConfig(): array
    {
        if ($this->config === null) {
            $dotenv = new Dotenv();
            $dotenv->load(__DIR__ . '/../.env');
            $this->config = $_ENV;
        }
        return $this->config;
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws RandomException
     */
    public function getAmoCrmApiClient(): AmoCRMApiClient
    {
        if ($this->amoCrmApiClient === null) {
            $config = $this->getConfig();
            $this->amoCrmApiClient = new AmoCRMApiClient(
                $config['CLIENT_ID'],
                $config['CLIENT_SECRET'],
                $config['CLIENT_REDIRECT_URI']
            );
            $this->amoCrmApiClient->setAccessToken($this->getAccessToken());
            $this->amoCrmApiClient->setAccountBaseDomain($this->getBaseDomain());
        }
        return $this->amoCrmApiClient;
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws RandomException
     */
    public function getAccessToken(): AccessToken
    {
        if ($this->accessToken === null) {
            if (!file_exists($this::ACCESS_TOKEN_FILE_PATH)) {
                return $this->generateAccessToken();
            }
            $accessTokenData = json_decode(file_get_contents($this::ACCESS_TOKEN_FILE_PATH), true);

            if ($accessTokenData['expires'] < time()) {
                return $this->generateAccessToken();
            }

            $this->accessToken = new AccessToken($accessTokenData);
        }
        return $this->accessToken;
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws RandomException
     * @throws Exception
     */
    public function generateAccessToken(): AccessToken
    {
        session_start();
        if (!isset($_GET['code'])) {
            // Redirect
            $state = bin2hex(random_bytes(16));
            $_SESSION['oauth2state'] = $state;
            $authorizationUrl = $this->getAmoCrmApiClient()->getOAuthClient()->getAuthorizeUrl([
                'state' => $state,
                'mode' => 'post_message',
            ]);
            header('Location: ' . $authorizationUrl);
            die;
        } elseif (!isset($_GET['state']) || !isset($_SESSION['oauth2state']) || $_SESSION['oauth2state'] !== $_GET['state']) {
            throw new Exception('Invalid state');
        }

        return $this->requestAccessToken($_GET['code'], $_GET['referer']);
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws Exception
     */
    public function requestAccessToken(string $authCode, string $domain): AccessToken
    {
        $this->getAmoCrmApiClient()->setAccountBaseDomain($domain);
        $accessToken = $this->getAmoCrmApiClient()->getOAuthClient()->getAccessTokenByCode($authCode);

        if ($accessToken->hasExpired()) {
            throw new Exception('Expired access token');
        }

        $tokenData = [
            'access_token' => $accessToken->getToken(),
            'expires' => $accessToken->getExpires(),
            'refresh_token' => $accessToken->getRefreshToken(),
            'base_domain' => $this->getAmoCrmApiClient()->getAccountBaseDomain()
        ];

        $dir = dirname($this::ACCESS_TOKEN_FILE_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($this::ACCESS_TOKEN_FILE_PATH, json_encode($tokenData));

        return new AccessToken($tokenData);
    }

    /**
     * @throws AmoCRMoAuthApiException
     * @throws RandomException
     */
    public function getBaseDomain(): string
    {
        if (!file_exists($this::ACCESS_TOKEN_FILE_PATH)) {
            return $this->getAmoCrmApiClient()->getAccountBaseDomain();
        }

        $accessTokenData = json_decode(file_get_contents($this::ACCESS_TOKEN_FILE_PATH), true);

        return $accessTokenData['base_domain'];
    }

    // Работа с почтой

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

    public function getImapConnection(): Connection
    {
        if (is_null($this->imapConnection)) {
            $config = $this->getConfig();
            $this->imapConnection = imap_open($config['MAILBOX'], $config['USER'], $config['PASSWORD']);
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

    /**
     * @throws InvalidArgumentException
     */
    public function createLeadFromData(array $emailData): LeadModel
    {
        $leadFieldsValues = (new CustomFieldsValuesCollection())
            ->add($this->createTextFieldValue(self::CUSTOM_FIELDS_IDS['author'], $emailData['author']))
            ->add($this->createTextFieldValue(self::CUSTOM_FIELDS_IDS['receiver'], $emailData['receiver']))
            ->add($this->createDateFieldValue(self::CUSTOM_FIELDS_IDS['date'], (int)$emailData['date']))
            ->add($this->createTextareaFieldValue(self::CUSTOM_FIELDS_IDS['category'], $emailData['category']));

        return (new LeadModel())
            ->setCustomFieldsValues($leadFieldsValues);
    }

    public function createTextFieldValue(int $id, string $value): TextCustomFieldValuesModel
    {
        $fieldValue = (new TextCustomFieldValueModel())
            ->setValue($value);

        $valueCollection = (new TextCustomFieldValueCollection())
            ->add($fieldValue);

        return (new TextCustomFieldValuesModel())
            ->setFieldId($id)
            ->setValues($valueCollection);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function createDateFieldValue(int $id, int $value): DateCustomFieldValuesModel
    {
        $fieldValue = (new DateCustomFieldValueModel())
            ->setValue(DateTime::createFromFormat('U', $value));

        $valueCollection = (new DateCustomFieldValueCollection())
            ->add($fieldValue);

        return (new DateCustomFieldValuesModel())
            ->setFieldId($id)
            ->setValues($valueCollection);
    }

    public function createTextareaFieldValue(int $id, string $value): TextareaCustomFieldValuesModel
    {
        $fieldValue = (new TextareaCustomFieldValueModel())
            ->setValue($value);

        $valueCollection = (new TextareaCustomFieldValueCollection())
            ->add($fieldValue);

        return (new TextareaCustomFieldValuesModel())
            ->setFieldId($id)
            ->setValues($valueCollection);
    }
}
