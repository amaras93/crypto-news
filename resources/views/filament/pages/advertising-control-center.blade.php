<x-filament-panels::page>
    <p>Advertising starts disabled. Only reviewed direct images can be served. External network advertising remains blocked until production configuration and a real consent-provider integration are complete.</p>
    <dl class="grid gap-4 md:grid-cols-3">@foreach($this->statistics() as $label=>$value)<div><dt class="font-semibold">{{ $label }}</dt><dd>{{ $value }}</dd></div>@endforeach</dl>
    <form wire:submit="save">{{ $this->form }}<div class="mt-6 flex gap-4"><x-filament::button type="submit">Save advertising settings</x-filament::button><x-filament::button type="button" color="danger" wire:click="stop" wire:loading.attr="disabled">Stop all banner and network advertising</x-filament::button></div></form>
    <p>Financial promotions require human review of the complete image, destination, claims, permissions, platform policies and every territory served. Language targeting does not limit countries. No automatic legal approval is provided. Unverified investment offers and guaranteed-profit claims are prohibited.</p>
    <p>Requests and eligible HTML deliveries are origin server counters, not visible impressions. Clicks, CTR, CPM, RPM, revenue and payouts are not measured. No AdSense dashboard is connected.</p>
    <p>Stopping ads prevents new banner/network responses; it cannot recall content already loaded in a browser. Published sponsored articles keep their disclosures. Use the existing editorial unpublish action if a sponsored article must be withdrawn.</p>
    <nav class="flex flex-wrap gap-4">
        <a href="{{ \App\Filament\Resources\Advertisers\AdvertiserResource::getUrl() }}">Advertisers</a>
        <a href="{{ \App\Filament\Resources\AdPlacements\AdPlacementResource::getUrl() }}">Placements</a>
        <a href="{{ \App\Filament\Resources\AdCampaigns\AdCampaignResource::getUrl() }}">Campaigns</a>
        <a href="{{ \App\Filament\Resources\SponsoredContents\SponsoredContentResource::getUrl() }}">Sponsored editorial workflow</a>
        <a href="{{ \App\Filament\Resources\AdDeliveryTotals\AdDeliveryTotalResource::getUrl() }}">Direct delivery reports</a>
    </nav>
    <h2 class="text-lg font-semibold">Private advertising change audit</h2>
    <div class="overflow-x-auto"><table class="w-full text-left"><thead><tr><th>Time (UTC)</th><th>Actor</th><th>Action</th><th>Subject</th><th>Private evidence</th></tr></thead><tbody>
        @forelse($this->recentAudits() as $event)<tr><td>{{ $event->created_at->utc()->toDateTimeString() }}</td><td>{{ $event->actor_id }}</td><td>{{ $event->action }}</td><td>{{ $event->subject_type }} #{{ $event->subject_id }}</td><td><details><summary>Inspect saved audit</summary><pre class="whitespace-pre-wrap">{{ json_encode($event->details,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) }}</pre></details></td></tr>
        @empty<tr><td colspan="5">No advertising changes recorded.</td></tr>@endforelse
    </tbody></table></div>
</x-filament-panels::page>
