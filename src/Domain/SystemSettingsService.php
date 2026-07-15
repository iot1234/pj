<?php
declare(strict_types=1);

namespace Dormitory\Domain;

use Dormitory\Application;
use Dormitory\Http\HttpException;
use Dormitory\Security\SecretCipher;
use PDO;
use RuntimeException;

final class SystemSettingsService
{
    /** @var list<string> */
    private const UPDATE_FIELDS = [
        'promptpay_target',
        'promptpay_name',
        'payment_receiver_account_tail',
        'line_channel_access_token',
        'line_channel_access_token_clear',
        'line_max_attempts',
        'notification_batch_size',
        'slip_provider',
        'slipok_api_key',
        'slipok_api_key_clear',
        'slipok_branch_id',
        'easyslip_api_key',
        'easyslip_api_key_clear',
        'slip_max_bytes',
        'slip_time_tolerance_seconds',
    ];

    /** @var array<string,string> */
    private const VALUE_COLUMNS = [
        'PROMPTPAY_TARGET' => 'promptpay_target',
        'PROMPTPAY_NAME' => 'promptpay_name',
        'PAYMENT_RECEIVER_ACCOUNT_TAIL' => 'payment_receiver_account_tail',
        'LINE_MAX_ATTEMPTS' => 'line_max_attempts',
        'NOTIFICATION_BATCH_SIZE' => 'notification_batch_size',
        'SLIP_PROVIDER' => 'slip_provider',
        'SLIPOK_BRANCH_ID' => 'slipok_branch_id',
        'SLIP_MAX_BYTES' => 'slip_max_bytes',
        'SLIP_TIME_TOLERANCE_SECONDS' => 'slip_time_tolerance_seconds',
    ];

    /** @var array<string,array{column:string,aad:string}> */
    private const SECRET_COLUMNS = [
        'LINE_CHANNEL_ACCESS_TOKEN' => [
            'column' => 'line_channel_access_token_enc',
            'aad' => 'line_channel_access_token',
        ],
        'SLIPOK_API_KEY' => [
            'column' => 'slipok_api_key_enc',
            'aad' => 'slipok_api_key',
        ],
        'EASYSLIP_API_KEY' => [
            'column' => 'easyslip_api_key_enc',
            'aad' => 'easyslip_api_key',
        ],
    ];

    /** @var array<string,int> */
    private const INTEGER_DEFAULTS = [
        'line_max_attempts' => 5,
        'notification_batch_size' => 25,
        'slip_max_bytes' => 4_194_304,
        'slip_time_tolerance_seconds' => 300,
    ];

    private readonly SecretCipher $cipher;

    public function __construct(private readonly Application $app)
    {
        $this->cipher = new SecretCipher($app->config);
    }

    /**
     * Return admin-safe settings metadata. Plaintext and encrypted secret
     * values are never present in this response.
     *
     * @return array<string,mixed>
     */
    public function publicSettings(): array
    {
        $row = $this->row() ?? $this->defaults();
        $lineSecret = $this->secretMetadata($row, 'LINE_CHANNEL_ACCESS_TOKEN');
        $slipOkSecret = $this->secretMetadata($row, 'SLIPOK_API_KEY');
        $easySlipSecret = $this->secretMetadata($row, 'EASYSLIP_API_KEY');

        $promptPayReady = $this->nullableString($row['promptpay_target'] ?? null) !== null;
        $lineReady = $lineSecret['configured'];
        $provider = (string) ($row['slip_provider'] ?? 'none');
        $receiverConfigured = $this->nullableString($row['payment_receiver_account_tail'] ?? null) !== null;
        $slipReady = $receiverConfigured && match ($provider) {
            'slipok' => $slipOkSecret['configured']
                && $this->nullableString($row['slipok_branch_id'] ?? null) !== null,
            'easyslip' => $easySlipSecret['configured'],
            default => false,
        };

        $configuredFields = [];
        foreach (['promptpay_target', 'promptpay_name', 'payment_receiver_account_tail', 'slipok_branch_id'] as $field) {
            if ($this->nullableString($row[$field] ?? null) !== null) {
                $configuredFields[] = $field;
            }
        }
        if ($provider !== 'none') {
            $configuredFields[] = 'slip_provider';
        }
        foreach ([
            'line_channel_access_token' => $lineSecret['configured'],
            'slipok_api_key' => $slipOkSecret['configured'],
            'easyslip_api_key' => $easySlipSecret['configured'],
        ] as $field => $configured) {
            if ($configured) {
                $configuredFields[] = $field;
            }
        }

        return [
            'promptpay_target' => $this->nullableString($row['promptpay_target'] ?? null),
            'promptpay_name' => $this->nullableString($row['promptpay_name'] ?? null),
            'payment_receiver_account_tail' => $this->nullableString($row['payment_receiver_account_tail'] ?? null),
            'line_max_attempts' => $this->databaseInteger($row, 'line_max_attempts'),
            'notification_batch_size' => $this->databaseInteger($row, 'notification_batch_size'),
            'slip_provider' => $provider,
            'slipok_branch_id' => $this->nullableString($row['slipok_branch_id'] ?? null),
            'slip_max_bytes' => $this->databaseInteger($row, 'slip_max_bytes'),
            'slip_time_tolerance_seconds' => $this->databaseInteger($row, 'slip_time_tolerance_seconds'),
            'line_channel_access_token_configured' => $lineSecret['configured'],
            'line_channel_access_token_hint' => $lineSecret['hint'],
            'slipok_api_key_configured' => $slipOkSecret['configured'],
            'slipok_api_key_hint' => $slipOkSecret['hint'],
            'easyslip_api_key_configured' => $easySlipSecret['configured'],
            'easyslip_api_key_hint' => $easySlipSecret['hint'],
            'configured_fields' => $configuredFields,
            'readiness' => [
                'promptpay' => $promptPayReady,
                'line' => $lineReady,
                'slip_verification' => $slipReady,
            ],
            'promptpay_ready' => $promptPayReady,
            'line_ready' => $lineReady,
            'slip_verification_ready' => $slipReady,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    /**
     * Partially update the singleton settings row. Empty secret inputs keep
     * the existing value; only an explicit matching *_clear=true removes it.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(array $input, int $adminId): array
    {
        $this->assertOnlyAllowedFields($input);
        if ($adminId < 1) {
            throw $this->validation('updated_by', 'A valid administrator is required');
        }

        $this->app->database()->transaction(function (PDO $pdo) use ($input, $adminId): void {
            $statement = $pdo->query('SELECT * FROM integration_settings WHERE id=1 FOR UPDATE');
            $existing = $statement->fetch();
            $settings = $existing ? array_replace($this->defaults(), $existing) : $this->defaults();

            if (array_key_exists('promptpay_target', $input)) {
                $settings['promptpay_target'] = $this->promptPayTarget($input['promptpay_target']);
            }
            if (array_key_exists('promptpay_name', $input)) {
                $settings['promptpay_name'] = $this->optionalText($input['promptpay_name'], 'promptpay_name', 120);
            }
            if (array_key_exists('payment_receiver_account_tail', $input)) {
                $settings['payment_receiver_account_tail'] = $this->receiverAccountTail($input['payment_receiver_account_tail']);
            }
            if (array_key_exists('line_max_attempts', $input)) {
                $settings['line_max_attempts'] = $this->boundedInteger($input['line_max_attempts'], 'line_max_attempts', 1, 20);
            }
            if (array_key_exists('notification_batch_size', $input)) {
                $settings['notification_batch_size'] = $this->boundedInteger($input['notification_batch_size'], 'notification_batch_size', 1, 100);
            }
            if (array_key_exists('slip_provider', $input)) {
                $provider = strtolower($this->requiredString($input['slip_provider'], 'slip_provider'));
                if (!in_array($provider, ['none', 'slipok', 'easyslip'], true)) {
                    throw $this->validation('slip_provider', 'slip_provider must be none, slipok, or easyslip');
                }
                $settings['slip_provider'] = $provider;
            }
            if (array_key_exists('slipok_branch_id', $input)) {
                $branch = $this->optionalText($input['slipok_branch_id'], 'slipok_branch_id', 80);
                if ($branch !== null && !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $branch)) {
                    throw $this->validation('slipok_branch_id', 'slipok_branch_id contains unsupported characters');
                }
                $settings['slipok_branch_id'] = $branch;
            }
            if (array_key_exists('slip_max_bytes', $input)) {
                $settings['slip_max_bytes'] = $this->boundedInteger($input['slip_max_bytes'], 'slip_max_bytes', 1_024, 4_194_304);
            }
            if (array_key_exists('slip_time_tolerance_seconds', $input)) {
                $settings['slip_time_tolerance_seconds'] = $this->boundedInteger($input['slip_time_tolerance_seconds'], 'slip_time_tolerance_seconds', 0, 3_600);
            }

            $this->mergeSecret($settings, $input, 'line_channel_access_token', 'line_channel_access_token_enc');
            $this->mergeSecret($settings, $input, 'slipok_api_key', 'slipok_api_key_enc');
            $this->mergeSecret($settings, $input, 'easyslip_api_key', 'easyslip_api_key_enc');
            $this->assertProviderReady($settings);

            $upsert = $pdo->prepare(
                'INSERT INTO integration_settings
                 (id,promptpay_target,promptpay_name,payment_receiver_account_tail,
                  line_channel_access_token_enc,line_max_attempts,notification_batch_size,
                  slip_provider,slipok_api_key_enc,slipok_branch_id,easyslip_api_key_enc,
                  slip_max_bytes,slip_time_tolerance_seconds,updated_by,updated_at)
                 VALUES (1,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))
                 ON DUPLICATE KEY UPDATE
                  promptpay_target=VALUES(promptpay_target),
                  promptpay_name=VALUES(promptpay_name),
                  payment_receiver_account_tail=VALUES(payment_receiver_account_tail),
                  line_channel_access_token_enc=VALUES(line_channel_access_token_enc),
                  line_max_attempts=VALUES(line_max_attempts),
                  notification_batch_size=VALUES(notification_batch_size),
                  slip_provider=VALUES(slip_provider),
                  slipok_api_key_enc=VALUES(slipok_api_key_enc),
                  slipok_branch_id=VALUES(slipok_branch_id),
                  easyslip_api_key_enc=VALUES(easyslip_api_key_enc),
                  slip_max_bytes=VALUES(slip_max_bytes),
                  slip_time_tolerance_seconds=VALUES(slip_time_tolerance_seconds),
                  updated_by=VALUES(updated_by),
                  updated_at=UTC_TIMESTAMP(6)'
            );
            $upsert->execute([
                $settings['promptpay_target'],
                $settings['promptpay_name'],
                $settings['payment_receiver_account_tail'],
                $settings['line_channel_access_token_enc'],
                $settings['line_max_attempts'],
                $settings['notification_batch_size'],
                $settings['slip_provider'],
                $settings['slipok_api_key_enc'],
                $settings['slipok_branch_id'],
                $settings['easyslip_api_key_enc'],
                $settings['slip_max_bytes'],
                $settings['slip_time_tolerance_seconds'],
                $adminId,
            ]);
        });

        return $this->publicSettings();
    }

    /**
     * Read a setting by its environment-style name without consulting the
     * environment. Secret fields are authenticated and decrypted here.
     */
    public function value(string $key, ?string $default = null): ?string
    {
        $normalized = strtoupper(trim($key));
        $row = $this->row();
        if (isset(self::SECRET_COLUMNS[$normalized])) {
            if ($row === null) {
                return $default;
            }
            $metadata = self::SECRET_COLUMNS[$normalized];
            $encrypted = $row[$metadata['column']] ?? null;
            if (!is_string($encrypted) || trim($encrypted) === '') {
                return $default;
            }
            $plaintext = $this->cipher->decrypt($encrypted, $metadata['aad']);
            return $plaintext === '' ? $default : $plaintext;
        }
        if (!isset(self::VALUE_COLUMNS[$normalized])) {
            throw new \InvalidArgumentException("Unknown integration setting: {$key}");
        }
        if ($row === null) {
            return $default;
        }
        $value = $row[self::VALUE_COLUMNS[$normalized]] ?? null;
        return $value === null ? $default : (string) $value;
    }

    public function intValue(string $key, int $default): int
    {
        $normalized = strtoupper(trim($key));
        $column = self::VALUE_COLUMNS[$normalized] ?? null;
        if ($column === null || !array_key_exists($column, self::INTEGER_DEFAULTS)) {
            throw new \InvalidArgumentException("Integration setting is not an integer: {$key}");
        }
        $value = $this->value($normalized);
        if ($value === null) {
            return $default;
        }
        if (!preg_match('/^-?\d+$/D', $value)) {
            throw new RuntimeException("Stored integration setting {$normalized} is not an integer");
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        if ($parsed === false) {
            throw new RuntimeException("Stored integration setting {$normalized} is outside the supported integer range");
        }
        return (int) $parsed;
    }

    /**
     * Perform a non-destructive readiness check. LINE is verified against
     * the fixed bot-info endpoint; PromptPay is checked locally; slip
     * credentials are checked against each provider's fixed, non-consuming
     * quota/info endpoint (no real slip is submitted).
     *
     * @return array<string,mixed>
     */
    public function testConnection(string $integration): array
    {
        $integration = strtolower(trim($integration));
        if ($integration === 'promptpay') {
            $target = trim((string) $this->value('PROMPTPAY_TARGET', ''));
            if ($target === '') {
                throw new HttpException(422, 'PromptPay is not configured', 'PROMPTPAY_NOT_CONFIGURED');
            }
            // Building a deterministic one-baht payload validates the target
            // format and EMV/CRC path without initiating a transaction.
            PromptPayService::payload($target, '1.00');
            return ['integration' => 'promptpay', 'ready' => true, 'target_hint' => '********' . substr($target, -4)];
        }

        if ($integration === 'slip') {
            $settings = $this->publicSettings();
            if (($settings['slip_verification_ready'] ?? false) !== true) {
                throw new HttpException(422, 'Slip verification settings are incomplete', 'SLIP_NOT_CONFIGURED');
            }
            $provider=(string)$settings['slip_provider'];
            if($provider==='slipok'){
                $branch=rawurlencode((string)$settings['slipok_branch_id']);
                $probe=$this->fixedJsonGet(
                    'https://api.slipok.com/api/line/apikey/'.$branch.'/quota',
                    ['x-authorization: '.(string)$this->value('SLIPOK_API_KEY','')],
                    'SLIP_TEST_FAILED',
                );
                if($probe['status']!==200||($probe['body']['success']??false)!==true){
                    throw new HttpException(422,'SlipOK ปฏิเสธ API Key หรือ Branch ID ที่บันทึกไว้ (HTTP '.$probe['status'].')','SLIP_TEST_FAILED');
                }
                $quota=$probe['body']['data']['quota']??null;
                return ['integration'=>'slip','ready'=>true,'provider'=>'slipok','quota_remaining'=>is_numeric($quota)?(int)$quota:null];
            }
            $probe=$this->fixedJsonGet(
                'https://api.easyslip.com/v2/info',
                ['Authorization: Bearer '.(string)$this->value('EASYSLIP_API_KEY','')],
                'SLIP_TEST_FAILED',
            );
            if($probe['status']!==200||($probe['body']['success']??false)!==true){
                throw new HttpException(422,'EasySlip ปฏิเสธ API Key/Branch ที่บันทึกไว้ (HTTP '.$probe['status'].')','SLIP_TEST_FAILED');
            }
            $branchActive=$probe['body']['data']['branch']['isActive']??true;
            if($branchActive!==true)throw new HttpException(422,'EasySlip branch ถูกปิดใช้งาน','SLIP_TEST_FAILED');
            $quota=$probe['body']['data']['application']['quota']['remaining']??null;
            return ['integration'=>'slip','ready'=>true,'provider'=>'easyslip','quota_remaining'=>is_numeric($quota)?(int)$quota:null];
        }

        if ($integration !== 'line') {
            throw $this->validation('integration', 'integration must be promptpay, line, or slip');
        }
        $token = trim((string) $this->value('LINE_CHANNEL_ACCESS_TOKEN', ''));
        if ($token === '') {
            throw new HttpException(422, 'LINE Messaging is not configured', 'LINE_NOT_CONFIGURED');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is required');
        }
        $ch = curl_init('https://api.line.me/v2/bot/info');
        if ($ch === false) {
            throw new RuntimeException('Cannot initialize cURL');
        }
        $response = '';
        $tooLarge = false;
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > 65_536) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);
        $executed = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $networkError = curl_error($ch);
        curl_close($ch);
        if ($tooLarge) {
            throw new HttpException(502, 'LINE returned an oversized response', 'LINE_TEST_FAILED');
        }
        if ($executed === false) {
            throw new HttpException(502, 'Cannot connect to LINE: ' . substr($networkError, 0, 160), 'LINE_TEST_FAILED');
        }
        if ($status !== 200) {
            throw new HttpException(422, 'LINE rejected the configured token (HTTP ' . $status . ')', 'LINE_TEST_FAILED');
        }
        try {
            $decoded = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new HttpException(502, 'LINE returned malformed JSON', 'LINE_TEST_FAILED');
        }
        if (!is_array($decoded)) {
            throw new HttpException(502, 'LINE returned an invalid response', 'LINE_TEST_FAILED');
        }
        return [
            'integration' => 'line',
            'ready' => true,
            'display_name' => isset($decoded['displayName']) && is_string($decoded['displayName']) ? $decoded['displayName'] : null,
            'basic_id' => isset($decoded['basicId']) && is_string($decoded['basicId']) ? $decoded['basicId'] : null,
        ];
    }

    /** @param list<string> $headers @return array{status:int,body:array<string,mixed>} */
    private function fixedJsonGet(string $url,array $headers,string $errorCode): array
    {
        if(!function_exists('curl_init'))throw new RuntimeException('PHP cURL extension is required');
        $ch=curl_init($url);if($ch===false)throw new RuntimeException('Cannot initialize cURL');
        $response='';$tooLarge=false;
        curl_setopt_array($ch,[
            CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>array_merge($headers,['Accept: application/json']),
            CURLOPT_RETURNTRANSFER=>false,CURLOPT_HEADER=>false,CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>10,
            CURLOPT_MAXREDIRS=>0,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_NOSIGNAL=>true,
            CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response,&$tooLarge):int{
                if(strlen($response)+strlen($chunk)>65_536){$tooLarge=true;return 0;}$response.=$chunk;return strlen($chunk);
            },
        ]);
        $executed=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$networkError=curl_error($ch);curl_close($ch);
        if($tooLarge)throw new HttpException(502,'ผู้ให้บริการส่งข้อมูลตอบกลับใหญ่เกินกำหนด',$errorCode);
        if($executed===false)throw new HttpException(502,'ติดต่อผู้ให้บริการไม่ได้: '.substr($networkError,0,160),$errorCode);
        try{$decoded=json_decode($response,true,24,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new HttpException(502,'ผู้ให้บริการส่ง JSON ไม่ถูกต้อง',$errorCode);}
        if(!is_array($decoded))throw new HttpException(502,'ผู้ให้บริการส่งข้อมูลตอบกลับไม่ถูกต้อง',$errorCode);
        return ['status'=>$status,'body'=>$decoded];
    }

    /** @return array<string,mixed>|null */
    private function row(): ?array
    {
        $row = $this->app->database()->pdo()->query('SELECT * FROM integration_settings WHERE id=1')->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed> */
    private function defaults(): array
    {
        return [
            'id' => 1,
            'promptpay_target' => null,
            'promptpay_name' => null,
            'payment_receiver_account_tail' => null,
            'line_channel_access_token_enc' => null,
            'line_max_attempts' => self::INTEGER_DEFAULTS['line_max_attempts'],
            'notification_batch_size' => self::INTEGER_DEFAULTS['notification_batch_size'],
            'slip_provider' => 'none',
            'slipok_api_key_enc' => null,
            'slipok_branch_id' => null,
            'easyslip_api_key_enc' => null,
            'slip_max_bytes' => self::INTEGER_DEFAULTS['slip_max_bytes'],
            'slip_time_tolerance_seconds' => self::INTEGER_DEFAULTS['slip_time_tolerance_seconds'],
            'updated_by' => null,
            'updated_at' => null,
        ];
    }

    /** @param array<string,mixed> $row @return array{configured:bool,hint:?string} */
    private function secretMetadata(array $row, string $key): array
    {
        $metadata = self::SECRET_COLUMNS[$key];
        $encrypted = $row[$metadata['column']] ?? null;
        if (!is_string($encrypted) || trim($encrypted) === '') {
            return ['configured' => false, 'hint' => null];
        }
        $plaintext = $this->cipher->decrypt($encrypted, $metadata['aad']);
        if ($plaintext === '') {
            return ['configured' => false, 'hint' => null];
        }
        $suffix = strlen($plaintext) > 4 ? substr($plaintext, -4) : '';
        return ['configured' => true, 'hint' => '********' . $suffix];
    }

    /** @param array<string,mixed> $settings @param array<string,mixed> $input */
    private function mergeSecret(array &$settings, array $input, string $field, string $encryptedColumn): void
    {
        $clearField = $field . '_clear';
        $clear = array_key_exists($clearField, $input) ? $this->boolean($input[$clearField], $clearField) : false;
        $plaintext = null;
        if (array_key_exists($field, $input)) {
            if ($input[$field] === null) {
                $plaintext = '';
            } elseif (!is_string($input[$field])) {
                throw $this->validation($field, "{$field} must be a string");
            } else {
                $plaintext = trim($input[$field]);
            }
        }

        if ($clear && $plaintext !== null && $plaintext !== '') {
            throw $this->validation($field, "{$field} cannot be set and cleared in the same request");
        }
        if ($clear) {
            $settings[$encryptedColumn] = null;
            return;
        }
        // Missing, null, empty and whitespace-only secret values all mean keep.
        if ($plaintext === null || $plaintext === '') {
            return;
        }
        if (strlen($plaintext) > 8_192 || !preg_match('/^[\x21-\x7E]+$/D', $plaintext)) {
            throw $this->validation($field, "{$field} must contain 1-8192 visible ASCII characters");
        }
        $settings[$encryptedColumn] = $this->cipher->encrypt($plaintext, $field);
    }

    /** @param array<string,mixed> $settings */
    private function assertProviderReady(array $settings): void
    {
        $provider = (string) $settings['slip_provider'];
        if ($provider === 'none') {
            return;
        }
        if ($this->nullableString($settings['payment_receiver_account_tail'] ?? null) === null) {
            throw $this->validation('payment_receiver_account_tail', 'A 6-20 digit receiver account reference is required for automatic slip verification');
        }
        if ($provider === 'slipok') {
            if (!$this->hasEncryptedSecret($settings, 'SLIPOK_API_KEY')) {
                throw $this->validation('slipok_api_key', 'SlipOK API key is required when slip_provider is slipok');
            }
            if ($this->nullableString($settings['slipok_branch_id'] ?? null) === null) {
                throw $this->validation('slipok_branch_id', 'SlipOK branch ID is required when slip_provider is slipok');
            }
            return;
        }
        if ($provider === 'easyslip' && !$this->hasEncryptedSecret($settings, 'EASYSLIP_API_KEY')) {
            throw $this->validation('easyslip_api_key', 'EasySlip API key is required when slip_provider is easyslip');
        }
    }

    /** @param array<string,mixed> $settings */
    private function hasEncryptedSecret(array $settings, string $key): bool
    {
        $metadata = self::SECRET_COLUMNS[$key];
        $encrypted = $settings[$metadata['column']] ?? null;
        if (!is_string($encrypted) || trim($encrypted) === '') {
            return false;
        }
        return $this->cipher->decrypt($encrypted, $metadata['aad']) !== '';
    }

    /** @param array<string,mixed> $input */
    private function assertOnlyAllowedFields(array $input): void
    {
        $unknown = [];
        foreach (array_keys($input) as $field) {
            if (!is_string($field) || !in_array($field, self::UPDATE_FIELDS, true)) {
                $unknown[] = (string) $field;
            }
        }
        if ($unknown !== []) {
            throw new HttpException(422, 'The request contains unsupported settings', 'UNKNOWN_FIELDS', ['fields' => $unknown]);
        }
    }

    private function promptPayTarget(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw $this->validation('promptpay_target', 'promptpay_target must be a string');
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^[0-9+().\s-]+$/D', $raw)) {
            throw $this->validation('promptpay_target', 'PromptPay target must be a Thai phone number or 13-digit tax ID');
        }
        $compact = preg_replace('/[().\s-]+/', '', $raw) ?? '';
        if (str_starts_with($compact, '+66')) {
            $compact = '0' . substr($compact, 3);
        } elseif (str_starts_with($compact, '66') && strlen($compact) === 11) {
            $compact = '0' . substr($compact, 2);
        }
        if (!preg_match('/^(?:0\d{9}|\d{13})$/D', $compact)) {
            throw $this->validation('promptpay_target', 'PromptPay target must be a Thai phone number or 13-digit tax ID');
        }
        return $compact;
    }

    private function receiverAccountTail(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw $this->validation('payment_receiver_account_tail', 'payment_receiver_account_tail must be a string');
        }
        $raw = trim($value);
        if ($raw === '') {
            return null;
        }
        if (!preg_match('/^[0-9\s-]+$/D', $raw)) {
            throw $this->validation('payment_receiver_account_tail', 'Receiver account reference must contain only digits');
        }
        $digits = preg_replace('/[\s-]+/', '', $raw) ?? '';
        if (!preg_match('/^\d{6,20}$/D', $digits)) {
            throw $this->validation('payment_receiver_account_tail', 'Receiver account reference must contain 6-20 digits');
        }
        return $digits;
    }

    private function optionalText(mixed $value, string $field, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            throw $this->validation($field, "{$field} must be a string");
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        if ($length > $maximum || preg_match('/[\x00-\x1F\x7F]/u', $value)) {
            throw $this->validation($field, "{$field} must contain at most {$maximum} characters without control characters");
        }
        return $value;
    }

    private function requiredString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw $this->validation($field, "{$field} is required");
        }
        return trim($value);
    }

    private function boundedInteger(mixed $value, string $field, int $minimum, int $maximum): int
    {
        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $minimum, 'max_range' => $maximum],
        ]);
        if ($validated === false) {
            throw $this->validation($field, "{$field} must be an integer between {$minimum} and {$maximum}");
        }
        return (int) $validated;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        throw $this->validation($field, "{$field} must be a boolean");
    }

    /** @param array<string,mixed> $row */
    private function databaseInteger(array $row, string $field): int
    {
        $value = $row[$field] ?? self::INTEGER_DEFAULTS[$field];
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || !preg_match('/^\d+$/D', $value)) {
            throw new RuntimeException("Stored integration setting {$field} is invalid");
        }
        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function validation(string $field, string $message): HttpException
    {
        return new HttpException(422, $message, 'VALIDATION_ERROR', ['field' => $field]);
    }
}
