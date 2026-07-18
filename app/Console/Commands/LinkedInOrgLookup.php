<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\ConnectedAccount;
use App\Services\Publishing\LinkedInOrgResolver;
use Illuminate\Console\Command;

class LinkedInOrgLookup extends Command
{
    protected $signature = 'linkedin:org-lookup {reference : company URL, vanity slug, org URN, or numeric id} {--account= : ConnectedAccount id; defaults to the first connected LinkedIn account}';

    protected $description = 'Resolve a LinkedIn organization reference to its URN + canonical name, and probe whether this app has org-lookup (Community Management API) access.';

    public function handle(LinkedInOrgResolver $resolver): int
    {
        $account = $this->resolveAccount();

        if ($account === null) {
            $this->error('No connected LinkedIn account found. Connect one, or pass --account=<id>.');

            return self::FAILURE;
        }

        $reference = (string) $this->argument('reference');
        $result = $resolver->resolve($account, $reference);

        if ($result->error !== null) {
            $this->error($result->error);

            return self::FAILURE;
        }

        if ($result->gated) {
            $this->error('Partner wall (HTTP 403): the Community Management API is not approved for this app.');
            $this->line('Org lookup by vanity name is unavailable — enter the org URN (urn:li:organization:ID) manually.');

            return self::FAILURE;
        }

        if (! $result->isSuccessful()) {
            $this->error("No LinkedIn organization found for \"{$reference}\".");

            return self::FAILURE;
        }

        $this->info('Resolved LinkedIn organization.');
        $this->table(['Field', 'Value'], [
            ['URN', $result->urn],
            ['Name', $result->name ?? '(unknown — reference already carried a numeric id, no lookup performed)'],
        ]);

        if ($result->name !== null) {
            $this->line('Mention tag: @['.$result->name.']('.$result->urn.')');
        } else {
            $this->line('Mention tag: @[Organization Name]('.$result->urn.')  — replace with the org\'s exact display name.');
        }

        return self::SUCCESS;
    }

    private function resolveAccount(): ?ConnectedAccount
    {
        $query = ConnectedAccount::query()
            ->withoutGlobalScopes()
            ->where('platform', Platform::LinkedIn->value);

        $accountId = $this->option('account');

        if ($accountId !== null) {
            return $query->whereKey($accountId)->first();
        }

        return $query->oldest()->first();
    }
}
