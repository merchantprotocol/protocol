<?php
/**
 * Webhook notification helper for SOC 2 event alerting.
 */
namespace Gitcd\Helpers;

use Gitcd\Utils\Json;

class Webhook
{
    /**
     * Send a notification to all configured webhooks.
     *
     * @param string $event   Event type (deploy, security_audit, soc2_check, incident, rollback, key_rotation)
     * @param array  $payload Structured event data
     * @param string|null $repoDir Repository directory for reading config
     */
    public static function notify(string $event, array $payload, ?string $repoDir = null): void
    {
        $webhooks = self::getWebhooks($repoDir);
        if (empty($webhooks)) return;

        $enabledEvents = self::getEnabledEvents($repoDir);
        if (!empty($enabledEvents) && !in_array($event, $enabledEvents)) return;

        $data = [
            'event' => $event,
            'timestamp' => date('c'),
            'hostname' => gethostname(),
            'user' => get_current_user(),
            'payload' => $payload,
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES);

        foreach ($webhooks as $webhook) {
            self::send($webhook, $json);
        }
    }

    /**
     * Send JSON payload to a single webhook URL.
     */
    private static function send(string $url, string $json): void
    {
        $cmd = "curl -s -o /dev/null -w '%{http_code}' -X POST "
            . escapeshellarg($url)
            . " -H 'Content-Type: application/json'"
            . " -d " . escapeshellarg($json)
            . " --connect-timeout 5 --max-time 10 2>/dev/null";

        Shell::run($cmd);
    }

    /**
     * Get configured webhook URLs from protocol.json.
     */
    public static function getWebhooks(?string $repoDir = null): array
    {
        // Support both single URL and array of URLs
        $url = Json::read('notifications.webhook', null, $repoDir);
        if (!$url) {
            $urls = Json::read('notifications.webhooks', [], $repoDir);
            return is_array($urls) ? $urls : [];
        }

        return is_array($url) ? $url : [$url];
    }

    /**
     * Get the list of enabled event types.
     * Empty array means all events are enabled.
     */
    public static function getEnabledEvents(?string $repoDir = null): array
    {
        $events = Json::read('notifications.events', [], $repoDir);
        return is_array($events) ? $events : [];
    }

    /**
     * Check if webhooks are configured.
     */
    public static function isConfigured(?string $repoDir = null): bool
    {
        return !empty(self::getWebhooks($repoDir));
    }

    /**
     * Send a deploy notification.
     */
    public static function notifyDeploy(string $repoDir, string $from, string $to, string $status, string $scope = 'global'): void
    {
        self::notify('deploy', [
            'from' => $from,
            'to' => $to,
            'status' => $status,
            'scope' => $scope,
            'repo' => $repoDir,
        ], $repoDir);
    }

    /**
     * Send an audit notification (security_audit or soc2_check) — only on failures/warnings.
     */
    public static function notifyAudit(string $event, string $repoDir, array $results, bool $passed): void
    {
        if ($passed) return;

        $failures = array_filter($results, fn($r) => in_array($r['status'], ['fail', 'warn']));
        self::notify($event, [
            'passed' => false,
            'issues' => array_map(fn($r) => $r['name'] . ': ' . $r['message'], $failures),
            'repo' => $repoDir,
        ], $repoDir);
    }

    /**
     * Send an incident notification.
     */
    public static function notifyIncident(string $repoDir, string $severity, string $title, string $description, ?string $issueUrl = null): void
    {
        self::notify('incident', [
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'issue_url' => $issueUrl,
            'repo' => $repoDir,
        ], $repoDir);
    }
}
