@if(session('community_status'))<p class="community-notice" role="status">{{ session('community_status') }}</p>@endif
@if($errors->any())
    <div class="community-errors" role="alert"><p>{{ __('community.invalid') }}</p><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif

