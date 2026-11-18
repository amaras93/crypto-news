<x-filament-panels::page>
    <p>Reader engagement uses the existing cached market and publication services. Sending starts disabled. No subscriber is created from account registration, and candidate/AI drafts cannot enter a digest.</p>
    <dl class="grid gap-4 md:grid-cols-3">
        @foreach($this->statistics() as $label => $value)<div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach
    </dl>
    <form wire:submit="save">
        {{ $this->form }}
        <div class="mt-6 flex gap-4"><x-filament::button type="submit">Save engagement controls</x-filament::button><x-filament::button color="danger" type="button" wire:click="stop" wire:loading.attr="disabled">Stop engagement sending</x-filament::button></div>
    </form>
    <p>Schedules use UTC: daily covers the previous UTC day; weekly covers the previous completed Monday–Sunday week. Existing editorial picks rank first, then publication date and article ID; at most six stories. Empty or changed content is skipped.</p>
    <h2 class="text-lg font-semibold">Recent stored delivery records</h2>
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Kind</th><th>Channel</th><th>Language</th><th>State</th><th>Error</th></tr></thead><tbody>
        @forelse($this->recentDeliveries() as $delivery)<tr><td>{{ $delivery->kind }}</td><td>{{ $delivery->channel }}</td><td>{{ $delivery->locale }}</td><td>{{ $delivery->status }}</td><td>{{ $delivery->error_code ?? '—' }}</td></tr>
        @empty<tr><td colspan="5">No delivery records. No engagement or email metrics are assumed.</td></tr>@endforelse
    </tbody></table></div>
    <p>Unknown SMTP outcomes remain uncertain and are never automatically resent. Restore queue health, inspect provider records and recover only work with no send attempt. Credentials stay in server configuration.</p>
</x-filament-panels::page>

