@php /** @var \Laravel\Passport\Client $client */ @endphp
@php /** @var \Illuminate\Support\Collection $workspaces */ @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Authorize {{ $client->name }} · {{ config('app.name') }}</title>

    @include('partials.theme-script')

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon-32x32.png" type="image/png" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png" sizes="180x180">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#101010">

    @fonts
    @vite(['resources/css/app.css'])
</head>
<body class="font-sans antialiased">
    <div class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 text-foreground md:p-10">
        <div class="w-full max-w-sm">
            <div class="flex flex-col gap-8">
                <div class="flex flex-col items-center gap-4">
                    <div class="flex h-9 w-9 items-center justify-center rounded-md">
                        <svg viewBox="0 0 473 452" fill="none" stroke="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="size-9 text-primary">
                            <path d="M193.5 86C258.89 87.6845 322.799 66.3814 374.1 25.8C377.294 23.4043 381.093 21.9455 385.069 21.5869C389.046 21.2283 393.044 21.9842 396.615 23.7698C400.186 25.5555 403.19 28.3003 405.289 31.6968C407.388 35.0933 408.5 39.0072 408.5 43C408.5 43 426.432 125.182 426.271 175.8C426.109 226.418 408.5 301 408.5 301C408.5 304.993 407.388 308.907 405.289 312.303C403.19 315.7 400.186 318.445 396.615 320.23C393.044 322.016 389.046 322.772 385.069 322.413C381.093 322.055 377.294 320.596 374.1 318.2C322.799 277.619 258.89 256.316 193.5 258H64.5C53.0957 258 42.1585 253.47 34.0944 245.406C26.0303 237.342 21.5 226.404 21.5 215V129C21.5 117.596 26.0303 106.658 34.0944 98.5944C42.1585 90.5304 53.0957 86 64.5 86H193.5Z" stroke-width="43" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M409.036 23.9573C333.967 105.698 315.784 232.306 404.413 321.72" stroke-width="43" />
                            <path d="M86 258C86 313.824 104.106 368.141 137.6 412.8C144.443 421.923 154.629 427.955 165.919 429.568C177.209 431.181 188.677 428.243 197.8 421.4C206.923 414.557 212.955 404.371 214.568 393.081C216.181 381.791 213.243 370.323 206.4 361.2C184.071 331.427 172 295.216 172 258" stroke-width="43" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M267.179 84.1788C251.679 98.3455 211.579 147.479 251.179 172.679C290.779 197.879 247.845 241.845 223.179 249.679" stroke-width="30" />
                            <path d="M234.179 85.1788C218.679 99.3455 162.079 160.479 201.679 185.679C241.279 210.879 205.345 242.345 180.679 250.179" stroke-width="30" />
                        </svg>
                    </div>

                    <div class="space-y-2 text-center">
                        <h1 class="text-xl font-medium">Authorize {{ $client->name }}</h1>
                        <p class="text-sm text-muted-foreground">
                            <span class="font-medium text-foreground">{{ $client->name }}</span> wants to act on your behalf in one {{ config('app.name') }} workspace.
                        </p>
                    </div>
                </div>

                <div class="flex flex-col gap-6">
                    @if (count($scopes) > 0)
                        <div class="rounded-md border p-4">
                            <p class="mb-2 text-sm font-medium">This app will be able to</p>
                            <ul class="space-y-1 text-sm text-muted-foreground">
                                @foreach ($scopes as $scope)
                                    <li class="flex items-center gap-2">
                                        <span class="size-1.5 shrink-0 rounded-full bg-primary"></span>
                                        <span>{{ $scope->description ?: $scope->id }}</span>
                                        @if ($scope->description)
                                            <code class="ml-auto text-xs">{{ $scope->id }}</code>
                                        @endif
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="grid gap-6">
                        @csrf
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">

                        <div class="grid gap-2">
                            <label for="workspace_id" class="text-sm leading-none font-medium select-none">Workspace</label>
                            <div class="relative">
                                <select name="workspace_id" id="workspace_id" required class="h-8 w-full min-w-0 appearance-none rounded-2xl border border-transparent bg-input/50 py-1 pr-8 pl-2.5 text-base outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/30 md:text-sm">
                                    @foreach ($workspaces as $workspace)
                                        <option value="{{ $workspace->id }}">{{ $workspace->name }}</option>
                                    @endforeach
                                </select>
                                <svg class="pointer-events-none absolute top-1/2 right-2.5 size-4 -translate-y-1/2 text-muted-foreground" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
                            </div>
                            <p class="text-xs text-muted-foreground">The connection only works in this workspace. Reconnect to switch.</p>
                        </div>

                        <button type="submit" class="inline-flex h-8 w-full items-center justify-center rounded-2xl border border-(--primary-gradient-edge) bg-primary-gradient bg-clip-padding px-3 text-sm font-medium text-primary-foreground inset-shadow-[0_1px_0_0_var(--primary-gradient-highlight)] outline-none select-none hover:[--primary-gradient-top:var(--primary-gradient-highlight)] focus-visible:ring-3 focus-visible:ring-ring/30 active:translate-y-px">
                            Authorize
                        </button>
                    </form>

                    <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="-mt-3">
                        @csrf
                        @method('DELETE')
                        <input type="hidden" name="auth_token" value="{{ $authToken }}">
                        <input type="hidden" name="client_id" value="{{ $client->getKey() }}">

                        <button type="submit" class="inline-flex h-8 w-full items-center justify-center rounded-2xl border border-border bg-background px-3 text-sm font-medium outline-none select-none hover:bg-muted focus-visible:ring-3 focus-visible:ring-ring/30 active:translate-y-px dark:bg-transparent dark:hover:bg-input/30">
                            Cancel
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
