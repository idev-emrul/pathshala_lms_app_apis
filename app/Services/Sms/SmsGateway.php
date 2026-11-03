<?php

namespace App\Services\Sms;

use App\Services\HwAssignment\HomeworkTime;
use Illuminate\Database\Connection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A school's own SMS gateway, used the way the web system's Sms_lib uses it:
 *
 * - the school's web token comes from pathshala.cloud by EIIN; with it, iSMS gives the gateway
 *   credentials, whether SMS is switched on, and the recharged SMS;
 * - balance = SMS in stock (message_stock_info, not expired) + iSMS recharges - SMS already sent
 *   (message_receiver_info, from 1 January last year);
 * - three gateways, picked by the API version: MIM (101, 102), Elitbuzz (103, 104), SylSMS (105, 106).
 *
 * Unlike Sms_lib there is no built-in default gateway key: without credentials, nothing is sent.
 * SSL certificates are checked (Sms_lib turns the check off).
 */
class SmsGateway
{
    /**
     * The school's gateway settings, or null when SMS cannot be sent (no token, no credentials, or the
     * school's SMS service is off).
     *
     * @return ?array{token: string, sender_id: string, api_key: string, api_token: string, version: int, delivery_report: bool}
     */
    public function settings(Connection $db): ?array
    {
        $eiin = trim((string) $db->table('institute_info')->value('institute_EIIN'));
        if ($eiin === '') {
            return null;
        }

        $token = $this->json(rtrim(config('pathshala.sms.web_token_url'), '/').'/'.rawurlencode($eiin));
        if (! is_string($token) || $token === '') {
            return null;
        }

        $status = $this->json($this->isms('get_client_sms_status', $token));
        if (! is_object($status) || (int) ($status->sms_service ?? 0) !== 1) {
            return null;
        }

        $record = $this->json($this->isms('get_client_smsAPI', $token));
        $record = is_array($record) ? ($record[0] ?? null) : null;
        if (! is_object($record)) {
            return null;
        }

        $credentials = json_decode((string) ((int) ($record->sms_type ?? 0) === 1 ? ($record->client_api_credentials ?? '') : ($record->api_version_credentials ?? '')));
        if (! is_object($credentials) || empty($credentials->apiKey)) {
            return null;
        }

        return [
            'token' => $token,
            'sender_id' => (string) ($credentials->senderID ?? ''),
            'api_key' => (string) $credentials->apiKey,
            'api_token' => (string) ($credentials->apiToken ?? ''),
            'version' => (int) ($record->sms_api_version_id ?? 0),
            'delivery_report' => (int) ($status->sms_delivery_report_api ?? 0) === 1,
        ];
    }

    /**
     * SMS the school can still send, as sms_model::get_sms_balance works it out.
     */
    public function balance(Connection $db, string $token): int
    {
        $today = HomeworkTime::today();
        $year = (int) substr($today, 0, 4);

        $stock = (int) $db->table('message_stock_info')->whereRaw('CAST(sms_expire_date AS DATE) >= ?', [$today])->sum('total_sms');
        $recharged = (int) $this->body($this->isms('get_client_recharge_by_daterange', $token, '2024-01-01', $year.'-12-31'));

        $sent = (int) $db->table('message_receiver_info')
            ->whereRaw('CAST(msg_sent_doc AS DATE) BETWEEN ? AND ?', [($year - 1).'-01-01', $today])
            ->sum('msg_counter');

        return max(0, $stock + $recharged - $sent);
    }

    /**
     * Number of SMS parts for a text, as Sms_lib::GET_SMS_SIZE counts them.
     */
    public function size(string $text): int
    {
        $limits = $this->isUnicode($text) ? [70, 134, 201, 268, 335, 402] : [160, 306, 459, 612, 765, 918];
        $length = mb_strlen($text);

        foreach ($limits as $index => $max) {
            if ($length <= $max) {
                return $index + 1;
            }
        }

        return count($limits);
    }

    /**
     * Sends one SMS. $number is the full number without "+", e.g. 8801711000000.
     *
     * @return array{sent: bool, id: ?string, error: ?string} id is the gateway's SMS id; error is the
     *     gateway's refusal, or null when the gateway could not be reached.
     */
    public function send(array $settings, string $number, string $text): array
    {
        try {
            return match (true) {
                in_array($settings['version'], [101, 102], true) => $this->sendMim($settings, $number, $text),
                in_array($settings['version'], [103, 104], true) => $this->sendElitbuzz($settings, $number, $text),
                in_array($settings['version'], [105, 106], true) => $this->sendSylSms($settings, $number, $text),
                default => ['sent' => false, 'id' => null, 'error' => 'Unknown SMS API version'],
            };
        } catch (Throwable $e) {
            report($e);

            return ['sent' => false, 'id' => null, 'error' => null];
        }
    }

    private function sendMim(array $settings, string $number, string $text): array
    {
        $response = $this->http()->post('http://mimsms.com.bd/smsAPI?'.http_build_query([
            'sendsms' => '',
            'apikey' => $settings['api_key'],
            'apitoken' => $settings['api_token'],
            'type' => $this->isUnicode($text) ? 'unicode' : 'sms',
            'from' => $settings['sender_id'],
            'to' => $number,
            'text' => $text,
            'route' => 0,
        ]))->object();

        if (in_array($response->status ?? null, ['queued', 'sent'], true)) {
            return ['sent' => true, 'id' => (string) $response->group_id, 'error' => null];
        }

        return ['sent' => false, 'id' => null, 'error' => (string) ($response->message ?? 'No response')];
    }

    private function sendElitbuzz(array $settings, string $number, string $text): array
    {
        $response = trim($this->http()->asForm()->post('https://msg.elitbuzz-bd.com/smsapi', [
            'api_key' => $settings['api_key'],
            'type' => 'text',
            'contacts' => $number,
            'senderid' => $settings['sender_id'],
            'msg' => $text,
        ])->body());

        // "SMS SUBMITTED: ID - <id>" on success, a numeric code otherwise.
        $dashes = substr_count($response, '-');
        $parts = explode(' - ', $response);
        if (($dashes === 1 || $dashes === 2) && isset($parts[1])) {
            return ['sent' => true, 'id' => ltrim($parts[1]), 'error' => null];
        }

        return ['sent' => false, 'id' => null, 'error' => 'Response : '.$response];
    }

    private function sendSylSms(array $settings, string $number, string $text): array
    {
        $response = $this->http()->asForm()->post('https://web.sylsms.xyz/api/smsapi', [
            'api_key' => $settings['api_key'],
            'type' => 'text',
            'number' => $number,
            'senderid' => $settings['sender_id'],
            'message' => $text,
        ])->object();

        if (! empty($response->message_id)) {
            return ['sent' => true, 'id' => (string) $response->message_id, 'error' => null];
        }

        return ['sent' => false, 'id' => null, 'error' => 'Response : '.($response->response_code ?? 'none')];
    }

    private function isUnicode(string $text): bool
    {
        return strlen($text) !== mb_strlen($text);
    }

    private function isms(string $endpoint, string ...$parts): string
    {
        return rtrim(config('pathshala.sms.isms_url'), '/').'/'.$endpoint.'/'.implode('/', array_map('rawurlencode', $parts));
    }

    private function http(): PendingRequest
    {
        return Http::timeout(config('pathshala.sms.timeout'));
    }

    private function body(string $url): string
    {
        try {
            $response = $this->http()->get($url);

            return $response->successful() ? trim($response->body()) : '';
        } catch (Throwable $e) {
            report($e);

            return '';
        }
    }

    private function json(string $url): mixed
    {
        return json_decode($this->body($url));
    }
}
