<?php

declare(strict_types=1);

namespace WPCF\FirewallSync\Cloudflare;

use WPCF\FirewallSync\Plugin;

final class Client {
  private string $token;
  private string $zone;
  private string $apiBase = 'https://api.cloudflare.com/client/v4';

  /**
   * Account-list items cached for the lifetime of this Client instance.
   *
   * Keys identify the Cloudflare account and list. Each value maps an IP
   * address to its Cloudflare list-item ID.
   *
   * @var array<string, array<string, string>>
   */
  private array $accountListItemCache = [];

  public function __construct(string $token, string $zone) {
    $this->token = $token;
    $this->zone = $zone;
  }

  public function validate(): bool {
    $url = $this->apiBase . "/zones/{$this->zone}";
    $response = wp_remote_get($url, $this->get_request_args());

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code === 200;
  }

  public function validate_account_list(string $account_id, string $list_id): bool {
    if ($account_id === '' || $list_id === '') {
      return false;
    }

    $url = $this->apiBase . "/accounts/{$account_id}/rules/lists/{$list_id}";
    $response = wp_remote_get($url, $this->get_request_args());

    if (is_wp_error($response)) {
      return false;
    }

    return wp_remote_retrieve_response_code($response) === 200;
  }

  /**
   * Determine whether an IP address already exists in an account list.
   */
  public function account_list_contains_ip(
    string $account_id,
    string $list_id,
    string $ip
  ): bool {
    if (
      $account_id === ''
      || $list_id === ''
      || !filter_var($ip, FILTER_VALIDATE_IP)
    ) {
      return false;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    return $items !== null && array_key_exists($ip, $items);
  }

  /**
   * Add one IP address to an account list.
   *
   * Existing entries are treated as successful so synchronization remains
   * idempotent.
   */
  public function add_ip_to_account_list(
    string $account_id,
    string $list_id,
    string $ip,
    string $comment = ''
  ): bool {
    if (
      $account_id === ''
      || $list_id === ''
      || !filter_var($ip, FILTER_VALIDATE_IP)
    ) {
      return false;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      return false;
    }

    if (array_key_exists($ip, $items)) {
      return true;
    }

    $added = $this->create_account_list_item(
      $account_id,
      $list_id,
      $ip,
      $comment
    );

    if ($added) {
      /*
       * The insertion response is not needed for later operations in this
       * request. An empty ID is sufficient to preserve membership and avoid
       * another full-list lookup.
       */
      $cache_key = $this->account_list_cache_key(
        $account_id,
        $list_id
      );

      $this->accountListItemCache[$cache_key][$ip] = '';
    }

    return $added;
  }

  /**
   * Add a synchronization batch after loading the account list once.
   *
   * @param array<int, array{ip?: string, reason?: string}> $entries
   * @return array<int, string> IP addresses that could not be synchronized.
   */
  public function batch_add_ips_to_account_list(
    string $account_id,
    string $list_id,
    array $entries
  ): array {
    $failed = [];

    if ($account_id === '' || $list_id === '') {
      foreach ($entries as $entry) {
        $failed[] = (string) ($entry['ip'] ?? '');
      }

      return $failed;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      foreach ($entries as $entry) {
        $failed[] = (string) ($entry['ip'] ?? '');
      }

      return $failed;
    }

    $cache_key = $this->account_list_cache_key(
      $account_id,
      $list_id
    );

    foreach ($entries as $entry) {
      $ip = (string) ($entry['ip'] ?? '');

      if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $failed[] = $ip;
        continue;
      }

      if (array_key_exists($ip, $items)) {
        continue;
      }

      $reason = (string) (
        $entry['reason']
        ?? __('Unknown', Plugin::get_text_domain())
      );

      $added = $this->create_account_list_item(
        $account_id,
        $list_id,
        $ip,
        'Wordfence sync: ' . $reason
      );

      if (!$added) {
        $failed[] = $ip;
        continue;
      }

      $items[$ip] = '';
      $this->accountListItemCache[$cache_key][$ip] = '';
    }

    return $failed;
  }

  /**
   * Remove an IP from an account list.
   *
   * An already-absent IP is a successful final state.
   */
  public function remove_ip_from_account_list(
    string $account_id,
    string $list_id,
    string $ip
  ): bool {
    if (
      $account_id === ''
      || $list_id === ''
      || !filter_var($ip, FILTER_VALIDATE_IP)
    ) {
      return false;
    }

    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    if ($items === null) {
      return false;
    }

    if (!array_key_exists($ip, $items)) {
      return true;
    }

    $item_id = $items[$ip];

    /*
     * A newly inserted cached entry may not have its item ID. Refresh only
     * in that unusual same-request add-then-remove case.
     */
    if ($item_id === '') {
      $this->clear_account_list_cache($account_id, $list_id);

      $items = $this->get_account_list_item_map(
        $account_id,
        $list_id
      );

      if ($items === null) {
        return false;
      }

      if (!array_key_exists($ip, $items)) {
        return true;
      }

      $item_id = $items[$ip];

      if ($item_id === '') {
        return false;
      }
    }

    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items/{$item_id}";

    $response = wp_remote_request(
      $url,
      [
        'method' => 'DELETE',
        'headers' => $this->get_headers(true),
      ]
    );

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);
    $deleted = $code >= 200 && $code < 300;

    if ($deleted) {
      $cache_key = $this->account_list_cache_key(
        $account_id,
        $list_id
      );

      unset($this->accountListItemCache[$cache_key][$ip]);
    }

    return $deleted;
  }

  /**
   * Return the current IP addresses in an account list.
   */
  public function get_current_account_list_ips(
    string $account_id,
    string $list_id
  ): array {
    $items = $this->get_account_list_item_map(
      $account_id,
      $list_id
    );

    return $items === null ? [] : array_keys($items);
  }

  /**
   * Submit one account-list item without performing another list lookup.
   */
  private function create_account_list_item(
    string $account_id,
    string $list_id,
    string $ip,
    string $comment = ''
  ): bool {
    $url = $this->apiBase
      . "/accounts/{$account_id}/rules/lists/{$list_id}/items";

    $item = ['ip' => $ip];

    if ($comment !== '') {
      $item['comment'] = $comment;
    }

    $response = wp_remote_post(
      $url,
      [
        'headers' => $this->get_headers(true),
        'body' => wp_json_encode([$item]),
      ]
    );

    if (is_wp_error($response)) {
      return false;
    }

    $code = wp_remote_retrieve_response_code($response);

    return $code >= 200 && $code < 300;
  }

  /**
   * Load and cache all account-list items.
   *
   * Null indicates that Cloudflare could not provide a complete list.
   *
   * @return array<string, string>|null
   */
  private function get_account_list_item_map(
    string $account_id,
    string $list_id
  ): ?array {
    if ($account_id === '' || $list_id === '') {
      return null;
    }

    $cache_key = $this->account_list_cache_key(
      $account_id,
      $list_id
    );

    if (array_key_exists($cache_key, $this->accountListItemCache)) {
      return $this->accountListItemCache[$cache_key];
    }

    $items_by_ip = [];
    $page = 1;

    do {
      $url = $this->apiBase
        . "/accounts/{$account_id}/rules/lists/{$list_id}/items"
        . "?page={$page}&per_page=50";

      $response = wp_remote_get(
        $url,
        $this->get_request_args()
      );

      if (is_wp_error($response)) {
        return null;
      }

      if (wp_remote_retrieve_response_code($response) !== 200) {
        return null;
      }

      $body = json_decode(
        wp_remote_retrieve_body($response),
        true
      );

      if (
        !is_array($body)
        || !isset($body['result'])
        || !is_array($body['result'])
      ) {
        return null;
      }

      foreach ($body['result'] as $item) {
        $ip = (string) ($item['ip'] ?? '');
        $item_id = (string) ($item['id'] ?? '');

        if (
          filter_var($ip, FILTER_VALIDATE_IP)
          && $item_id !== ''
        ) {
          $items_by_ip[$ip] = $item_id;
        }
      }

      $total_pages = max(
        1,
        (int) ($body['result_info']['total_pages'] ?? 1)
      );

      $page++;
    } while ($page <= $total_pages);

    $this->accountListItemCache[$cache_key] = $items_by_ip;

    return $items_by_ip;
  }

  private function account_list_cache_key(
    string $account_id,
    string $list_id
  ): string {
    return $account_id . ':' . $list_id;
  }

  private function clear_account_list_cache(
    string $account_id,
    string $list_id
  ): void {
    unset(
      $this->accountListItemCache[
        $this->account_list_cache_key($account_id, $list_id)
      ]
    );
  }

  public function create_block(string $ip, string $notes = ''): bool {
    $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules";

    $data = [
      'mode' => 'block',
      'configuration' => [
        'target' => 'ip',
        'value' => $ip,
      ],
      'notes' => $notes !== '' ? $notes : __('Wordfence Sync Block', Plugin::get_text_domain()),
    ];

    $response = wp_remote_post($url, [
      'headers' => $this->get_headers(true),
      'body' => json_encode($data),
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
  }

  public function delete_block(string $ip): bool {
    $list_url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&configuration.target=ip&configuration.value={$ip}";
    $list = wp_remote_get($list_url, $this->get_request_args());

    if (is_wp_error($list)) {
      return false;
    }

    $body = json_decode(wp_remote_retrieve_body($list), true);
    $rule_id = $body['result'][0]['id'] ?? null;

    if (!$rule_id) {
      return false;
    }

    $delete_url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules/{$rule_id}";
    $response = wp_remote_request($delete_url, [
      'method' => 'DELETE',
      'headers' => $this->get_headers(true),
    ]);

    return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
  }

  private function get_headers(bool $with_content_type = false): array {
    $headers = [
      'Authorization' => 'Bearer ' . $this->token,
    ];

    if ($with_content_type) {
      $headers['Content-Type'] = 'application/json';
    }

    return $headers;
  }

  private function get_request_args(bool $with_content_type = false): array {
    return [
      'headers' => $this->get_headers($with_content_type),
    ];
  }

  public function get_current_blocked_ips(): array {
    $ip_list = [];
    $page = 1;

    do {
      $url = $this->apiBase . "/zones/{$this->zone}/firewall/access_rules/rules?mode=block&page={$page}&per_page=50";

      $response = wp_remote_get($url, $this->get_request_args());

      if (is_wp_error($response)) {
        break;
      }

      $body = json_decode(wp_remote_retrieve_body($response), true);
      $result = $body['result'] ?? [];

      foreach ($result as $rule) {
        if (($rule['configuration']['target'] ?? '') === 'ip') {
          $ip_list[] = $rule['configuration']['value'];
        }
      }

      $has_more = ($body['result_info']['total_pages'] ?? 1) > $page;
      
      $page += 1;
    } while ($has_more);

    return array_unique($ip_list);
  }

  public function batch_block(array $ips): array {
    $failed = [];

    foreach ($ips as $entry) {
      $ip = $entry['ip'] ?? '';

      if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        $failed[] = $ip;
        continue;
      }

      $notes = __('Wordfence Sync', Plugin::get_text_domain()) . ': ' . ($entry['reason'] ?? __('Unknown', Plugin::get_text_domain()));

      if (!$this->create_block($ip, $notes)) {
        $failed[] = $ip;
      }
    }

    return $failed;
  }
}
