<?php

namespace WHMCS\Database {
    class Capsule
    {
        public static function schema(): mixed {}
        public static function table(string $table): mixed {}
        public static function connection(): mixed {}
    }

    class Schema
    {
        public function hasTable(string $table): bool { return false; }
        public function hasColumn(string $table, string $column): bool { return false; }
        public function create(string $table, callable $callback): void {}
        public function table(string $table, callable $callback): void {}
    }
}

namespace WHMCS\Module\Addon {
    class Setting
    {
        public static function getSettingValueForModule(string $module, string $setting): mixed {}
    }
}

namespace Illuminate\Database\Schema {
    class Blueprint
    {
        public function bigIncrements(string $column): self { return $this; }
        public function bigInteger(string $column): self { return $this; }
        public function integer(string $column): self { return $this; }
        public function string(string $column, ?int $length = null): self { return $this; }
        public function text(string $column): self { return $this; }
        public function dateTime(string $column): self { return $this; }
        public function unsigned(): self { return $this; }
        public function index(): self { return $this; }
        public function unique(): self { return $this; }
        public function nullable(): self { return $this; }
        public function useCurrent(): self { return $this; }
        public function useCurrentOnUpdate(): self { return $this; }
    }
}

namespace {
    const WHMCSDNS_INTEGRATION_API_VERSION = 1;
    function add_hook(string $hook, int $priority, callable $callback): void {}
    function check_token(string $namespace = ''): void {}
    function generate_token(string $type): string { return ''; }
    function logModuleCall(string $module, string $action, mixed $request, mixed $response, mixed $data = null): void {}
    function whmcs_dns_registrable_domain(string $hostname, bool $allowUnderscores = false): ?string { return null; }
    function whmcs_dns_can_manage_domains(int $clientId): bool { return false; }
    /** @return array<string, mixed> */
    function whmcs_dns_integration_status(int $clientId, string $domain): array { return []; }
    /** @return array<int, array<string, mixed>> */
    function whmcs_dns_integration_list_records(int $clientId, string $domain): array { return []; }
    /** @return array<string, mixed> */
    function whmcs_dns_integration_apply_records(int $clientId, string $domain, array $delete, array $upsert, string $operation): array { return []; }
}
